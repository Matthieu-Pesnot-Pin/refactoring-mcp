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
        "Retourne la liste des symboles de premier niveau d'un fichier " +
        "(fonctions, classes, interfaces, types, constantes, méthodes) avec leurs numéros de lignes de début et de fin. " +
        "Utile pour identifier les coordonnées précises d'un bloc de code avant de le déplacer. " +
        "Le chemin doit être absolu.",
      inputSchema: {
        type: "object",
        properties: {
          filePath: {
            type: "string",
            description: "Chemin absolu complet du fichier à analyser",
          },
        },
        required: ["filePath"],
      },
    },
  ],
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  if (name === "get_file_outline") {
    try {
      const { filePath } = OutlineSchema.parse(args);
      const symbols = await getFileOutline(filePath);
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
