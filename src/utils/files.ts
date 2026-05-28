import fs from "fs/promises";
import path from "path";
import { getConfig } from "./config.js";

export function validateAndResolvePath(filePath: string): string {
  if (!path.isAbsolute(filePath)) {
    throw new Error(`Le chemin doit être absolu : "${filePath}"`);
  }

  const resolved = path.resolve(filePath);
  const { allowedDir } = getConfig();

  if (allowedDir) {
    // Normaliser avec séparateur pour éviter les faux positifs (ex: /foo vs /foobar)
    const normalizedResolved = resolved.endsWith(path.sep)
      ? resolved
      : resolved + path.sep;
    const normalizedAllowed = allowedDir.endsWith(path.sep)
      ? allowedDir
      : allowedDir + path.sep;

    if (
      !normalizedResolved.startsWith(normalizedAllowed) &&
      resolved !== allowedDir
    ) {
      throw new Error(
        `Accès interdit : le chemin "${resolved}" n'est pas confiné dans le dossier autorisé "${allowedDir}"`
      );
    }
  }

  return resolved;
}

export interface RefactorParams {
  sourceFile: string;
  startLine: number;
  endLine: number;
  operationType: "copy" | "cut";
  destinationFile: string;
  destinationLine?: number;
}

export async function executeRefactor(params: RefactorParams): Promise<{
  linesMoved: number;
  operation: "copy" | "cut";
  sourceLength: number;
  destLength: number;
}> {
  const sourcePath = validateAndResolvePath(params.sourceFile);
  const destPath = validateAndResolvePath(params.destinationFile);

  // Lire le fichier source
  const sourceContent = await fs.readFile(sourcePath, "utf-8");
  const sourceLines = sourceContent.split(/\r?\n/);

  const { startLine, endLine } = params;
  if (startLine < 1 || endLine > sourceLines.length || startLine > endLine) {
    throw new Error(
      `Lignes source invalides (demandées: ${startLine}-${endLine}, fichier source de ${sourceLines.length} lignes)`
    );
  }

  // Extraire le segment
  const segment = sourceLines.slice(startLine - 1, endLine);

  // Si coupure, supprimer les lignes du fichier source
  if (params.operationType === "cut") {
    sourceLines.splice(startLine - 1, endLine - startLine + 1);
    await fs.writeFile(sourcePath, sourceLines.join("\n"), "utf-8");
  }

  // Préparer le fichier de destination (création automatique si inexistant)
  const destDir = path.dirname(destPath);
  await fs.mkdir(destDir, { recursive: true });

  let destLines: string[] = [];
  try {
    const destContent = await fs.readFile(destPath, "utf-8");
    destLines = destContent.split(/\r?\n/);
  } catch (error: unknown) {
    if ((error as NodeJS.ErrnoException).code !== "ENOENT") throw error;
    // Fichier inexistant : on part d'un tableau vide
  }

  // Insérer le segment dans la destination
  if (params.destinationLine !== undefined && params.destinationLine > 0) {
    const insertIndex = Math.max(
      0,
      Math.min(destLines.length, params.destinationLine - 1)
    );
    destLines.splice(insertIndex, 0, ...segment);
  } else {
    destLines.push(...segment);
  }

  await fs.writeFile(destPath, destLines.join("\n"), "utf-8");

  return {
    linesMoved: segment.length,
    operation: params.operationType,
    sourceLength: sourceLines.length,
    destLength: destLines.length,
  };
}
