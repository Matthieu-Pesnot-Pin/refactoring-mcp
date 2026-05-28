import fs from "fs/promises";
import { createTwoFilesPatch } from "diff";
import { validateAndResolvePath, checkFileSize } from "./files.js";

function trimBlankLines(lines: string[]): string[] {
  let start = 0;
  while (start < lines.length && lines[start].trim() === "") start++;
  let end = lines.length - 1;
  while (end > start && lines[end].trim() === "") end--;
  return lines.slice(start, end + 1);
}

function adjustDiffLineNumbers(
  patch: string,
  offsetA: number,
  offsetB: number
): string {
  return patch.replace(
    /@@ -(\d+),(\d+) \+(\d+),(\d+) @@/g,
    (_, a1, a2, b1, b2) =>
      `@@ -${parseInt(a1) + offsetA},${a2} +${parseInt(b1) + offsetB},${b2} @@`
  );
}

export interface CompareParams {
  fileA: string;
  startLineA: number;
  endLineA: number;
  fileB: string;
  startLineB: number;
  endLineB: number;
}

export async function compareCodeSections(params: CompareParams): Promise<string> {
  const pathA = await validateAndResolvePath(params.fileA);
  const pathB = await validateAndResolvePath(params.fileB);

  await checkFileSize(pathA);
  await checkFileSize(pathB);

  const contentA = await fs.readFile(pathA, "utf-8");
  const contentB = await fs.readFile(pathB, "utf-8");

  const linesA = contentA.split(/\r?\n/);
  const linesB = contentB.split(/\r?\n/);

  const { startLineA, endLineA, startLineB, endLineB } = params;

  if (startLineA < 1 || endLineA > linesA.length || startLineA > endLineA) {
    throw new Error(
      `Lignes invalides pour le fichier A (demandées: ${startLineA}-${endLineA}, fichier de ${linesA.length} lignes)`
    );
  }
  if (startLineB < 1 || endLineB > linesB.length || startLineB > endLineB) {
    throw new Error(
      `Lignes invalides pour le fichier B (demandées: ${startLineB}-${endLineB}, fichier de ${linesB.length} lignes)`
    );
  }

  const rawSectionA = linesA.slice(startLineA - 1, endLineA);
  const rawSectionB = linesB.slice(startLineB - 1, endLineB);

  const sectionA = trimBlankLines(rawSectionA);
  const sectionB = trimBlankLines(rawSectionB);

  // Offset = number of blank lines trimmed from the start of each section
  const trimOffsetA = rawSectionA.indexOf(sectionA[0] ?? "");
  const trimOffsetB = rawSectionB.indexOf(sectionB[0] ?? "");

  const textA = sectionA.join("\n") + "\n";
  const textB = sectionB.join("\n") + "\n";

  if (textA === textB) {
    return "Les sections sont identiques.";
  }

  const rawPatch = createTwoFilesPatch(
    `${params.fileA} (lignes ${startLineA}-${endLineA})`,
    `${params.fileB} (lignes ${startLineB}-${endLineB})`,
    textA,
    textB
  );

  const absoluteOffsetA = startLineA - 1 + trimOffsetA;
  const absoluteOffsetB = startLineB - 1 + trimOffsetB;

  return adjustDiffLineNumbers(rawPatch, absoluteOffsetA, absoluteOffsetB);
}
