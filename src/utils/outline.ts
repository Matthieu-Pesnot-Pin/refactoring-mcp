import fs from "fs/promises";
import { validateAndResolvePath } from "./files.js";

export interface OutlineSymbol {
  name: string;
  type: "function" | "class" | "interface" | "type" | "const" | "method";
  startLine: number;
  endLine: number;
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
  // Méthodes dans une classe (indentées d'au moins 2 espaces/tab)
  // On exclut les mots-clés JS/TS pour éviter les faux positifs (if, for, while, switch, catch, return...)
  {
    regex: /^[ \t]{2,}(?:(?:public|private|protected|static|async|override)\s+)*(?!(if|for|while|switch|catch|return|throw|new|typeof|instanceof|await|yield|delete|void|case|else|do)\b)(\w+)\s*[(<]/,
    type: "method",
  },
];

export async function getFileOutline(filePath: string): Promise<OutlineSymbol[]> {
  const resolved = validateAndResolvePath(filePath);
  const content = await fs.readFile(resolved, "utf-8");
  const lines = content.split(/\r?\n/);
  const symbols: OutlineSymbol[] = [];

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    for (const { regex, type } of SYMBOL_PATTERNS) {
      const match = line.match(regex);
      if (match && match[1]) {
        // Pour le pattern "method", le groupe capturant est match[2] car match[1] est le lookahead
        const name = match[2] ?? match[1];
        symbols.push({ name, type, startLine: i + 1, endLine: 0 });
        break;
      }
    }
  }

  // Calculer les lignes de fin : fin du symbole = début du suivant - 1 (ou fin du fichier)
  for (let i = 0; i < symbols.length; i++) {
    symbols[i].endLine =
      i + 1 < symbols.length ? symbols[i + 1].startLine - 1 : lines.length;
  }

  return symbols;
}
