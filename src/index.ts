#!/usr/bin/env node
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { z } from "zod";
import { setupLogging } from "./logger.js";
import { executeRefactor } from "./utils/files.js";
import { getFileOutline } from "./utils/outline.js";
import { compareCodeSections } from "./utils/compare.js";

setupLogging("MASTER");

const server = new Server(
  { name: "refactoring-mcp", version: "1.0.0" },
  { capabilities: { tools: {} } }
);

const RefactorSchema = z.object({
  sourceFile: z.string().describe("Chemin absolu complet du fichier d'origine"),
  startLine: z
    .number()
    .int()
    .positive()
    .describe("Ligne de début du bloc de code (1-indexé)"),
  endLine: z
    .number()
    .int()
    .positive()
    .describe("Ligne de fin du bloc de code incluse (1-indexé)"),
  operationType: z
    .enum(["copy", "cut"])
    .describe("Type d'opération : copy (copie) ou cut (coupure/déplacement)"),
  destinationFile: z
    .string()
    .describe(
      "Chemin absolu complet du fichier de destination (créé automatiquement si inexistant)"
    ),
  destinationLine: z
    .number()
    .int()
    .positive()
    .optional()
    .describe(
      "Optionnel : ligne d'insertion dans le fichier de destination (1-indexé). Ajout en fin de fichier par défaut."
    ),
});

const OutlineSchema = z.object({
  filePath: z
    .string()
    .describe("Chemin absolu complet du fichier à analyser"),
  depth: z
    .number()
    .int()
    .min(0)
    .optional()
    .describe(
      "Optionnel : profondeur maximale des symboles à retourner. 0 = premier niveau uniquement (classes, fonctions top-level), 1 = +1 niveau imbriqué (méthodes d'une classe), etc. Par défaut : tous les niveaux."
    ),
  startLine: z
    .number()
    .int()
    .positive()
    .optional()
    .describe(
      "Optionnel : première ligne à analyser (1-indexé). Utile pour cibler une section du fichier. Par défaut : début du fichier."
    ),
  endLine: z
    .number()
    .int()
    .positive()
    .optional()
    .describe(
      "Optionnel : dernière ligne à analyser (1-indexé). Utile pour cibler une section du fichier. Par défaut : fin du fichier."
    ),
});

const CompareSchema = z.object({
  fileA: z.string().describe("Chemin absolu complet du premier fichier"),
  startLineA: z
    .number()
    .int()
    .positive()
    .describe("Ligne de début de la section dans le fichier A (1-indexé)"),
  endLineA: z
    .number()
    .int()
    .positive()
    .describe("Ligne de fin de la section dans le fichier A (1-indexé, incluse)"),
  fileB: z.string().describe("Chemin absolu complet du second fichier"),
  startLineB: z
    .number()
    .int()
    .positive()
    .describe("Ligne de début de la section dans le fichier B (1-indexé)"),
  endLineB: z
    .number()
    .int()
    .positive()
    .describe("Ligne de fin de la section dans le fichier B (1-indexé, incluse)"),
});

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
    {
      name: "move_or_copy_code",
      description:
        "Copie ou déplace un bloc de lignes de code d'un fichier source vers un fichier de destination. " +
        "Le fichier de destination (ainsi que ses dossiers parents) est créé automatiquement s'il n'existe pas. " +
        "Tous les chemins doivent être absolus.",
      inputSchema: {
        type: "object",
        properties: {
          sourceFile: {
            type: "string",
            description: "Chemin absolu complet du fichier d'origine",
          },
          startLine: {
            type: "number",
            description: "Ligne de début du bloc de code (1-indexé)",
          },
          endLine: {
            type: "number",
            description: "Ligne de fin du bloc de code incluse (1-indexé)",
          },
          operationType: {
            type: "string",
            enum: ["copy", "cut"],
            description: "Type d'opération : copy ou cut",
          },
          destinationFile: {
            type: "string",
            description: "Chemin absolu complet du fichier de destination",
          },
          destinationLine: {
            type: "number",
            description:
              "Optionnel : ligne d'insertion dans la destination (ajout en fin de fichier par défaut)",
          },
        },
        required: [
          "sourceFile",
          "startLine",
          "endLine",
          "operationType",
          "destinationFile",
        ],
      },
    },
    {
      name: "get_file_outline",
      description:
        "Retourne la liste des symboles d'un fichier (fonctions, classes, interfaces, types, constantes, méthodes) " +
        "avec leurs numéros de lignes de début et de fin, et leur profondeur d'imbrication (depth). " +
        "Supporte tous les langages à accolades (TypeScript, JavaScript, PHP, Java, C#…). " +
        "Paramètres optionnels : depth pour filtrer par niveau d'imbrication, startLine/endLine pour cibler une section. " +
        "Le chemin doit être absolu.",
      inputSchema: {
        type: "object",
        properties: {
          filePath: {
            type: "string",
            description: "Chemin absolu complet du fichier à analyser",
          },
          depth: {
            type: "number",
            description:
              "Optionnel : profondeur maximale à retourner. 0 = premier niveau uniquement (classes, fonctions top-level), 1 = +1 niveau (méthodes d'une classe), etc. Par défaut : tous les niveaux.",
          },
          startLine: {
            type: "number",
            description:
              "Optionnel : première ligne à analyser (1-indexé). Par défaut : début du fichier.",
          },
          endLine: {
            type: "number",
            description:
              "Optionnel : dernière ligne à analyser (1-indexé). Par défaut : fin du fichier.",
          },
        },
        required: ["filePath"],
      },
    },
    {
      name: "compare_code_sections",
      description:
        "Compare deux sections de code (délimitées par des numéros de lignes) issues de deux fichiers. " +
        "Retourne un diff au format unifié (style git) avec les numéros de lignes réels dans les fichiers. " +
        "Utile pour vérifier qu'un bloc de code a été correctement recopié d'un fichier à l'autre. " +
        "Les lignes vides en début et en fin de chaque section sont ignorées pour éviter les faux positifs. " +
        "Tous les chemins doivent être absolus.",
      inputSchema: {
        type: "object",
        properties: {
          fileA: {
            type: "string",
            description: "Chemin absolu complet du premier fichier",
          },
          startLineA: {
            type: "number",
            description: "Ligne de début de la section dans le fichier A (1-indexé)",
          },
          endLineA: {
            type: "number",
            description: "Ligne de fin de la section dans le fichier A (1-indexé, incluse)",
          },
          fileB: {
            type: "string",
            description: "Chemin absolu complet du second fichier",
          },
          startLineB: {
            type: "number",
            description: "Ligne de début de la section dans le fichier B (1-indexé)",
          },
          endLineB: {
            type: "number",
            description: "Ligne de fin de la section dans le fichier B (1-indexé, incluse)",
          },
        },
        required: ["fileA", "startLineA", "endLineA", "fileB", "startLineB", "endLineB"],
      },
    },
  ],
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  if (name === "get_file_outline") {
    try {
      const { filePath, depth, startLine, endLine } = OutlineSchema.parse(args);
      const symbols = await getFileOutline(filePath, { depth, startLine, endLine });
      return {
        content: [{ type: "text", text: JSON.stringify(symbols, null, 2) }],
      };
    } catch (error: unknown) {
      return {
        content: [
          {
            type: "text",
            text: `Erreur : ${(error as Error).message}`,
          },
        ],
        isError: true,
      };
    }
  }

  if (name === "move_or_copy_code") {
    try {
      const validatedArgs = RefactorSchema.parse(args);
      const result = await executeRefactor(validatedArgs);
      return {
        content: [
          {
            type: "text",
            text:
              `Succès : opération "${result.operation}" effectuée.\n` +
              `- Lignes traitées : ${result.linesMoved}\n` +
              `- Fichier source : ${result.sourceLength} lignes après opération\n` +
              `- Fichier destination : ${result.destLength} lignes après opération`,
          },
        ],
      };
    } catch (error: unknown) {
      return {
        content: [
          {
            type: "text",
            text: `Erreur : ${(error as Error).message}`,
          },
        ],
        isError: true,
      };
    }
  }

  if (name === "compare_code_sections") {
    try {
      const validatedArgs = CompareSchema.parse(args);
      const result = await compareCodeSections(validatedArgs);
      return {
        content: [{ type: "text", text: result }],
      };
    } catch (error: unknown) {
      return {
        content: [
          {
            type: "text",
            text: `Erreur : ${(error as Error).message}`,
          },
        ],
        isError: true,
      };
    }
  }

  return {
    content: [{ type: "text", text: `Outil inconnu : ${name}` }],
    isError: true,
  };
});

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Serveur MCP Refactoring Helper démarré en stdio.");
}

main().catch((error: unknown) => {
  console.error("Erreur critique au démarrage :", error);
  process.exit(1);
});
