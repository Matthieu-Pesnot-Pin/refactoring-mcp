import fs from "fs/promises";
import { validateAndResolvePath, checkFileSize } from "./files.js";

export interface OutlineSymbol {
  name: string;
  type: "function" | "class" | "interface" | "type" | "const" | "method";
  startLine: number;
  endLine: number;
  depth: number;
}

export interface OutlineOptions {
  depth?: number;
  startLine?: number;
  endLine?: number;
}

const SYMBOL_PATTERNS: { regex: RegExp; type: OutlineSymbol["type"] }[] = [
  {
    regex: /^(?:export\s+(?:default\s+)?)?(?:async\s+)?function\s+(\w+)/,
    type: "function",
  },
  {
    regex: /^(?:export\s+)?(?:abstract\s+)?class\s+(\w+)/,
    type: "class",
  },
  {
    regex: /^(?:export\s+)?interface\s+(\w+)/,
    type: "interface",
  },
  {
    regex: /^(?:export\s+)?type\s+(\w+)\s*[=<]/,
    type: "type",
  },
  {
    regex: /^(?:export\s+)?(?:const|let|var)\s+(\w+)\s*[=:]/,
    type: "const",
  },
  // Méthodes PHP et autres langages : mot-clé `function` indenté
  {
    regex: /^[ \t]+(?:(?:public|private|protected|static|abstract|final|async|override)\s+)*function\s+(\w+)/,
    type: "method",
  },
  // Méthodes TypeScript/JS indentées sans mot-clé `function`
  // On exclut les mots-clés pour éviter les faux positifs
  {
    regex: /^[ \t]{2,}(?:(?:public|private|protected|static|async|override)\s+)*(?!(if|for|while|switch|catch|return|throw|new|typeof|instanceof|await|yield|delete|void|case|else|do)\b)(\w+)\s*[(<]/,
    type: "method",
  },
];

function countBracesInLine(line: string): number {
  let count = 0;
  let inSingleQuote = false;
  let inDoubleQuote = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === "'" && !inDoubleQuote) inSingleQuote = !inSingleQuote;
    else if (ch === '"' && !inSingleQuote) inDoubleQuote = !inDoubleQuote;
    else if (!inSingleQuote && !inDoubleQuote) {
      if (ch === "{") count++;
      else if (ch === "}") count--;
    }
  }
  return count;
}

export async function getFileOutline(
  filePath: string,
  options: OutlineOptions = {}
): Promise<OutlineSymbol[]> {
  const resolved = await validateAndResolvePath(filePath);
  await checkFileSize(resolved);
  const content = await fs.readFile(resolved, "utf-8");
  const lines = content.split(/\r?\n/);

  const rangeStart = options.startLine ?? 1;
  const rangeEnd = options.endLine ?? lines.length;

  const symbols: OutlineSymbol[] = [];
  let braceDepth = 0;

  for (let i = 0; i < lines.length; i++) {
    const lineNum = i + 1;
    const line = lines[i];

    if (lineNum >= rangeStart && lineNum <= rangeEnd) {
      for (const { regex, type } of SYMBOL_PATTERNS) {
        const match = line.match(regex);
        if (match) {
          // Pour le pattern TS method, le groupe capturant est match[2] (match[1] = lookahead interne)
          const name = match[2] ?? match[1];
          if (name) {
            symbols.push({ name, type, startLine: lineNum, endLine: 0, depth: braceDepth });
            break;
          }
        }
      }
    }

    braceDepth += countBracesInLine(line);
    if (braceDepth < 0) braceDepth = 0;
  }

  // Calculer endLine en tenant compte de la profondeur :
  // un symbole se termine juste avant le prochain symbole de même niveau ou supérieur
  for (let i = 0; i < symbols.length; i++) {
    const currentDepth = symbols[i].depth;
    let nextBoundaryLine = rangeEnd + 1;
    for (let j = i + 1; j < symbols.length; j++) {
      if (symbols[j].depth <= currentDepth) {
        nextBoundaryLine = symbols[j].startLine;
        break;
      }
    }
    symbols[i].endLine = Math.min(nextBoundaryLine - 1, rangeEnd);
  }

  if (options.depth !== undefined) {
    return symbols.filter((s) => s.depth <= options.depth!);
  }

  return symbols;
}
