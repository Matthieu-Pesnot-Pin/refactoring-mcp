import fs from "fs/promises";
import path from "path";
import { getConfig } from "./config.js";

const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

export async function validateAndResolvePath(filePath: string): Promise<string> {
  if (!path.isAbsolute(filePath)) {
    throw new Error(`Le chemin doit être absolu : "${filePath}"`);
  }

  const resolved = path.resolve(filePath);

  // Résoudre les symlinks pour éviter les contournements de confinement
  // Si le fichier n'existe pas encore (destination), on résout le dossier parent
  let realResolved: string;
  try {
    realResolved = await fs.realpath(resolved);
  } catch {
    const parent = path.dirname(resolved);
    try {
      const realParent = await fs.realpath(parent);
      realResolved = path.join(realParent, path.basename(resolved));
    } catch {
      // Le dossier parent n'existe pas non plus — on conserve le chemin résolu
      realResolved = resolved;
    }
  }

  const { allowedDir } = getConfig();

  if (allowedDir) {
    // Normaliser avec séparateur pour éviter les faux positifs (ex: /foo vs /foobar)
    const normalizedResolved = realResolved.endsWith(path.sep)
      ? realResolved
      : realResolved + path.sep;
    const normalizedAllowed = allowedDir.endsWith(path.sep)
      ? allowedDir
      : allowedDir + path.sep;

    if (
      !normalizedResolved.startsWith(normalizedAllowed) &&
      realResolved !== allowedDir
    ) {
      throw new Error(
        `Accès interdit : le chemin "${realResolved}" n'est pas confiné dans le dossier autorisé "${allowedDir}"`
      );
    }
  }

  return realResolved;
}

export async function checkFileSize(filePath: string): Promise<void> {
  const stats = await fs.stat(filePath);
  if (stats.size > MAX_FILE_SIZE_BYTES) {
    throw new Error(
      `Fichier trop volumineux : ${Math.round(stats.size / 1024 / 1024)} MB (maximum ${MAX_FILE_SIZE_BYTES / 1024 / 1024} MB)`
    );
  }
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
  const sourcePath = await validateAndResolvePath(params.sourceFile);
  const destPath = await validateAndResolvePath(params.destinationFile);

  // Vérifier la taille du fichier source avant lecture
  await checkFileSize(sourcePath);

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

export interface InsertionEntry {
  lineNumber: number;
  text: string;
}

export interface InsertTextParams {
  filePath: string;
  insertions: InsertionEntry[];
}

export async function insertTextAtLine(params: InsertTextParams): Promise<{
  linesInserted: number;
  fileLength: number;
}> {
  const resolvedPath = await validateAndResolvePath(params.filePath);

  await checkFileSize(resolvedPath);

  const content = await fs.readFile(resolvedPath, "utf-8");
  const fileLines = content.split(/\r?\n/);
  const originalLength = fileLines.length;

  for (const insertion of params.insertions) {
    if (insertion.lineNumber < 1 || insertion.lineNumber > originalLength + 1) {
      throw new Error(
        `Numéro de ligne invalide (demandé: ${insertion.lineNumber}, fichier de ${originalLength} lignes — valeurs acceptées : 1 à ${originalLength + 1})`
      );
    }
  }

  // Tri du bas vers le haut pour éviter les décalages d'offset
  const sorted = [...params.insertions].sort((a, b) => b.lineNumber - a.lineNumber);

  let totalLinesInserted = 0;
  for (const insertion of sorted) {
    const newLines = insertion.text.split(/\r?\n/);
    fileLines.splice(insertion.lineNumber - 1, 0, ...newLines);
    totalLinesInserted += newLines.length;
  }

  await fs.writeFile(resolvedPath, fileLines.join("\n"), "utf-8");

  return {
    linesInserted: totalLinesInserted,
    fileLength: fileLines.length,
  };
}
