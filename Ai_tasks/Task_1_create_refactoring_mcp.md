# Task 1 — Création du MCP Refactoring Helper

## Objectif

Créer un nouveau serveur MCP TypeScript nommé `refactoring-mcp` sans interface graphique, permettant de copier ou de couper (déplacer) du code d'un fichier source vers un fichier destination. 

Ce serveur doit s'exécuter via le protocole stdio, valider ses entrées de manière rigoureuse avec `zod`, et offrir un mécanisme de confinement optionnel restreignant les opérations d'écriture et de lecture à un dossier de travail spécifique configuré par variable d'environnement.

---

## Contexte et Choix Techniques

Le dossier `refactoring-mcp` est actuellement vide. S'appuyer sur les conventions éprouvées des MCP voisins :
- **Langage & Runtime** : TypeScript, Node.js v18+ avec module ESM (`"type": "module"`).
- **Protocole** : SDK MCP `@modelcontextprotocol/sdk`.
- **Validation** : validation stricte du schéma d'arguments des outils avec `zod`.
- **Transport** : stdio (`stdin` / `stdout` de Node).
- **Logs** : centralisés, déportés sur `stderr` pour ne pas polluer `stdout` (sécurité du protocole MCP) et sauvegardés dans un fichier de logs dédié.
- **Sécurité de chemin** : rejet des chemins relatifs, normalisation stricte pour interdire les traversées de chemin (`..`), et variable d'enfermement (`REFACTORING_ALLOWED_DIR`).

---

## Architecture du Projet

```text
refactoring-mcp/
├── Ai_tasks/
│   └── Task_1_create_refactoring_mcp.md   ← ce fichier de tâche
├── src/
│   ├── index.ts                           ← serveur MCP principal
│   ├── logger.ts                          ← gestion des logs (stderr + fichier)
│   └── utils/
│       ├── config.ts                      ← gestion dynamique de la configuration (.env)
│       ├── files.ts                       ← logique métier copie/coupure + validateAndResolvePath (exportée)
│       └── outline.ts                     ← analyse structurelle d'un fichier (get_file_outline)
├── .env.example                           ← modèle de configuration
├── tsconfig.json                          ← configuration TypeScript
└── package.json                           ← métadonnées et scripts npm
```

---

## Initialisation et Dépendances

Exécuter les commandes npm à la racine du projet `refactoring-mcp` :

```bash
npm init -y
npm install @modelcontextprotocol/sdk zod dotenv
npm install -D typescript @types/node tsx
```

### Scripts dans `package.json`

Ajouter ou modifier les scripts suivants de manière manuelle après l'initialisation :

```json
{
  "type": "module",
  "scripts": {
    "build": "tsc",
    "start": "node dist/index.js",
    "dev": "tsx src/index.ts"
  },
  "bin": {
    "refactoring-mcp": "./dist/index.js"
  }
}
```

### Configuration TypeScript (`tsconfig.json`)

Utiliser la configuration stricte standard :

```json
{
  "compilerOptions": {
    "target": "ESNext",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "outDir": "./dist",
    "rootDir": "./src",
    "strict": true,
    "skipLibCheck": true,
    "esModuleInterop": true
  },
  "include": ["src/**/*"]
}
```

---

## Configuration et Confinement (`.env.example`)

Créer un fichier `.env.example` décrivant les variables d'environnement supportées :

```env
# Dossier absolu auquel les opérations d'écriture et de lecture sont confinées.
# Si configuré, toute tentative d'accès en dehors de ce dossier lèvera une erreur.
# Laisser vide ou non défini pour ne pas limiter les dossiers.
REFACTORING_ALLOWED_DIR=C:/Users/Imena/Documents/code/Perso/workspace
```

---

## Logique de Configuration (`src/utils/config.ts`)

Pour respecter le pattern de rechargement à chaud de la configuration, la fonction `getConfig()` doit recharger `.env` à chaque appel.

```typescript
import path from "path";
import { fileURLToPath } from "url";
import dotenv from "dotenv";

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const envPath = path.join(rootDir, ".env");

export interface Config {
  allowedDir: string | null;
}

export function getConfig(): Config {
  dotenv.config({ path: envPath, override: true });
  
  const allowedDir = process.env.REFACTORING_ALLOWED_DIR 
    ? path.resolve(process.env.REFACTORING_ALLOWED_DIR) 
    : null;

  return { allowedDir };
}
```

---

## Centralisation des Logs (`src/logger.ts`)

Reprendre le pattern de journalisation robuste (redirection stdout vers stderr pour protéger le protocole MCP, et écriture synchrone/asynchrone dans un fichier de logs).

```typescript
import fs from "fs";
import path from "path";

const logDir = process.platform === "win32" ? "C:\\var\\log\\refactoring-mcp" : "/var/log/refactoring-mcp";
const logFile = path.join(logDir, "server.log");

export function setupLogging(prefix: string) {
  if (!fs.existsSync(logDir)) {
    fs.mkdirSync(logDir, { recursive: true });
  }

  const logToFile = (msg: string) => {
    const timestamp = new Date().toISOString();
    fs.appendFileSync(logFile, `[${timestamp}] [PID ${process.pid}] [${prefix}] ${msg}\n`, "utf-8");
  };

  // Rediriger stdout vers stderr pour protéger le flux JSON-RPC de MCP
  const originalLog = console.log;
  console.log = (...args) => {
    const msg = args.map(arg => typeof arg === "object" ? JSON.stringify(arg) : arg).join(" ");
    logToFile(`[INFO] ${msg}`);
    console.error(msg);
  };

  // Surcharger console.error pour journalisation automatique
  const originalError = console.error;
  console.error = (...args) => {
    const msg = args.map(arg => typeof arg === "object" ? JSON.stringify(arg) : arg).join(" ");
    logToFile(`[ERROR] ${msg}`);
    originalError.apply(console, args);
  };
}
```

---

## Logique Métier & Sécurité de Chemin (`src/utils/files.ts`)

Cette classe ou ce module gère les opérations sur les fichiers de manière sécurisée en appliquant la variable d'enfermement.

### Règles de sécurité à appliquer :
1. Les chemins fournis doivent obligatoirement être **absolus** (`path.isAbsolute(filePath) === true`). Sinon, rejeter immédiatement l'opération.
2. Résoudre le chemin avec `path.resolve(filePath)` pour éliminer les traversées de dossier de type `./` ou `../`.
3. Si `allowedDir` est configuré, vérifier que le chemin résolu commence par `allowedDir`. Sinon, lever une erreur d'autorisation.

### Logique d'opération Copie/Coupure :

```typescript
import fs from "fs/promises";
import path from "path";
import { getConfig } from "./config.js";

function validateAndResolvePath(filePath: string): string {
  if (!path.isAbsolute(filePath)) {
    throw new Error(`Le chemin doit être absolu : ${filePath}`);
  }

  const resolved = path.resolve(filePath);
  const { allowedDir } = getConfig();

  if (allowedDir) {
    // S'assurer que le chemin résolu commence bien par le chemin autorisé
    if (!resolved.startsWith(allowedDir)) {
      throw new Error(`Accès interdit : le chemin n'est pas confiné dans le dossier autorisé (${allowedDir})`);
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

export async function executeRefactor(params: RefactorParams) {
  const sourcePath = validateAndResolvePath(params.sourceFile);
  const destPath = validateAndResolvePath(params.destinationFile);

  // 1. Lire le fichier source
  const sourceContent = await fs.readFile(sourcePath, "utf-8");
  const sourceLines = sourceContent.split(/\r?\n/);

  const { startLine, endLine } = params;
  if (startLine < 1 || endLine > sourceLines.length || startLine > endLine) {
    throw new Error(`Lignes source invalides (demandées: ${startLine}-${endLine}, fichier source de ${sourceLines.length} lignes)`);
  }

  // Extraire le segment
  const segment = sourceLines.slice(startLine - 1, endLine);

  // 2. Si coupure, modifier le fichier source
  if (params.operationType === "cut") {
    sourceLines.splice(startLine - 1, endLine - startLine + 1);
    await fs.writeFile(sourcePath, sourceLines.join("\n"), "utf-8");
  }

  // 3. Préparer le fichier de destination (création automatique des dossiers et du fichier si inexistants)
  const destDir = path.dirname(destPath);
  await fs.mkdir(destDir, { recursive: true });

  let destLines: string[] = [];
  try {
    const destContent = await fs.readFile(destPath, "utf-8");
    destLines = destContent.split(/\r?\n/);
  } catch (error: any) {
    if (error.code !== "ENOENT") throw error;
    // Fichier inexistant : destLines reste vide []
  }

  // 4. Insérer le segment dans la destination
  if (params.destinationLine !== undefined && params.destinationLine > 0) {
    const insertIndex = Math.max(0, Math.min(destLines.length, params.destinationLine - 1));
    destLines.splice(insertIndex, 0, ...segment);
  } else {
    // Optionnel : si non fourni, insertion à la fin
    destLines.push(...segment);
  }

  // 5. Sauvegarder la destination
  await fs.writeFile(destPath, destLines.join("\n"), "utf-8");

  return {
    linesMoved: segment.length,
    operation: params.operationType,
    sourceLength: sourceLines.length,
    destLength: destLines.length
  };
}
```

---

## Outil `get_file_outline` (`src/utils/outline.ts`)

Cet outil analyse un fichier texte et retourne la liste des symboles de premier niveau (fonctions, classes, méthodes, interfaces, types, constantes exportées) avec leurs numéros de lignes de début et de fin.

### Approche : regex sans dépendance externe

Utiliser des expressions régulières pour détecter les déclarations courantes dans les fichiers TypeScript/JavaScript. Pas de parser AST pour rester minimal.

Patterns à détecter :
- `export function foo(` / `async function foo(`
- `export class Foo {`
- `export interface Foo {`
- `export type Foo =`
- `export const foo =`
- `  methodName(` (méthodes à l'intérieur d'une classe, indentation de 2 ou 4 espaces)

La fin d'un symbole est estimée à la ligne précédant le symbole suivant (ou la fin du fichier).

```typescript
import fs from "fs/promises";
import { validateAndResolvePath } from "./files.js";

export interface OutlineSymbol {
  name: string;
  type: "function" | "class" | "interface" | "type" | "const" | "method" | "other";
  startLine: number;
  endLine: number;
}

const SYMBOL_PATTERNS: { regex: RegExp; type: OutlineSymbol["type"] }[] = [
  { regex: /^(?:export\s+)?(?:async\s+)?function\s+(\w+)/, type: "function" },
  { regex: /^(?:export\s+)?(?:abstract\s+)?class\s+(\w+)/, type: "class" },
  { regex: /^(?:export\s+)?interface\s+(\w+)/, type: "interface" },
  { regex: /^(?:export\s+)?type\s+(\w+)\s*=/, type: "type" },
  { regex: /^(?:export\s+)?const\s+(\w+)\s*[=:]/, type: "const" },
  { regex: /^[ \t]{2,}(?:async\s+)?(\w+)\s*\(/, type: "method" },
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
      if (match) {
        symbols.push({ name: match[1], type, startLine: i + 1, endLine: 0 });
        break;
      }
    }
  }

  // Calculer les lignes de fin : fin = début du symbole suivant - 1 (ou fin du fichier)
  for (let i = 0; i < symbols.length; i++) {
    symbols[i].endLine = i + 1 < symbols.length ? symbols[i + 1].startLine - 1 : lines.length;
  }

  return symbols;
}
```

### Remarque : `validateAndResolvePath` doit être exportée depuis `files.ts`

La fonction de validation/résolution de chemin doit être rendue exportable car elle est partagée entre `files.ts` et `outline.ts`.

---

## Serveur MCP (`src/index.ts`)

Le point d'entrée principal déclare le serveur MCP et enregistre les deux outils.

```typescript
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
  {
    name: "refactoring-mcp",
    version: "1.0.0",
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// Schéma Zod pour valider l'appel de l'outil move_or_copy_code
const RefactorSchema = z.object({
  sourceFile: z.string().describe("Chemin absolu complet du fichier d'origine"),
  startLine: z.number().int().positive().describe("Ligne de début du bloc de code (1-indexé)"),
  endLine: z.number().int().positive().describe("Ligne de fin du bloc de code inclus (1-indexé)"),
  operationType: z.enum(["copy", "cut"]).describe("Type d'opération : copy (copie) ou cut (coupure/déplacement)"),
  destinationFile: z.string().describe("Chemin absolu complet du fichier de destination (créé si inexistant)"),
  destinationLine: z.number().int().positive().optional().describe("Optionnel : Ligne d'insertion dans le fichier destination (1-indexé, inséré à la fin par défaut)")
});

const OutlineSchema = z.object({
  filePath: z.string().describe("Chemin absolu complet du fichier à analyser")
});

server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: "move_or_copy_code",
        description: "Permet de copier ou déplacer un bloc de lignes de code d'un fichier absolu d'origine vers un autre fichier absolu de destination. Crée le fichier cible et les dossiers parents s'ils n'existent pas.",
        inputSchema: {
          type: "object",
          properties: {
            sourceFile: { type: "string", description: "Chemin absolu complet du fichier d'origine" },
            startLine: { type: "number", description: "Ligne de début du bloc de code (1-indexé)" },
            endLine: { type: "number", description: "Ligne de fin du bloc de code inclus (1-indexé)" },
            operationType: { type: "string", enum: ["copy", "cut"], description: "Type d'opération : copy ou cut" },
            destinationFile: { type: "string", description: "Chemin absolu complet du fichier de destination" },
            destinationLine: { type: "number", description: "Optionnel : Ligne d'insertion dans la destination (à la fin par défaut)" }
          },
          required: ["sourceFile", "startLine", "endLine", "operationType", "destinationFile"]
        }
      },
      {
        name: "get_file_outline",
        description: "Retourne la liste des symboles de premier niveau d'un fichier (fonctions, classes, interfaces, types, constantes, méthodes) avec leurs numéros de lignes de début et de fin. Utile pour identifier les coordonnées précises d'un bloc de code avant de le déplacer.",
        inputSchema: {
          type: "object",
          properties: {
            filePath: { type: "string", description: "Chemin absolu complet du fichier à analyser" }
          },
          required: ["filePath"]
        }
      }
    ]
  };
});

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  if (name === "get_file_outline") {
    try {
      const { filePath } = OutlineSchema.parse(args);
      const symbols = await getFileOutline(filePath);
      return {
        content: [{ type: "text", text: JSON.stringify(symbols, null, 2) }]
      };
    } catch (error: any) {
      return {
        content: [{ type: "text", text: `Erreur : ${error.message}` }],
        isError: true,
      };
    }
  }

  if (name !== "move_or_copy_code") {
    return {
      content: [{ type: "text", text: `Outil inconnu : ${name}` }],
      isError: true,
    };
  }

  try {
    const validatedArgs = RefactorSchema.parse(args);
    const result = await executeRefactor({
      sourceFile: validatedArgs.sourceFile,
      startLine: validatedArgs.startLine,
      endLine: validatedArgs.endLine,
      operationType: validatedArgs.operationType,
      destinationFile: validatedArgs.destinationFile,
      destinationLine: validatedArgs.destinationLine,
    });

    return {
      content: [
        {
          type: "text",
          text: `Succès : opération "${result.operation}" effectuée avec succès.\n` +
                `- Lignes traitées : ${result.linesMoved}\n` +
                `- Fichier d'origine modifié (nouvelle taille : ${result.sourceLength} lignes)\n` +
                `- Fichier de destination modifié (nouvelle taille : ${result.destLength} lignes)`
        }
      ]
    };
  } catch (error: any) {
    return {
      content: [{ type: "text", text: `Erreur d'opération : ${error.message}` }],
      isError: true,
    };
  }
});

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Serveur MCP Refactoring Helper démarré en stdio.");
}

main().catch((error) => {
  console.error("Erreur critique au démarrage du serveur :", error);
  process.exit(1);
});
```

---

## Tests et Vérification

La validation doit s'appuyer sur des scénarios bien définis :

1. **Compilation** : `npm run build` doit s'exécuter sans erreur TS.
2. **Exécution stdio** : Tester l'outil en direct via l'inspecteur MCP ou via un client (Cursor).
3. **Cas de test fonctionnels** :
   - **Copie simple** : Copier de la ligne 2 à 4 d'un fichier `A` existant vers la fin d'un fichier `B` existant.
   - **Coupure avec insertion** : Couper de la ligne 1 à 2 d'un fichier `A` vers la ligne 3 d'un fichier `B`. Les lignes d'origine doivent disparaître de `A`.
   - **Création de fichier automatique** : Copier des lignes de `A` vers un nouveau fichier `C` dans un sous-dossier non créé. Le dossier et le fichier doivent se créer.
   - **Comportement optionnel de ligne** : Ne pas spécifier `destinationLine` et vérifier que le code est bien ajouté en fin de fichier.
   - **Outline** : Appeler `get_file_outline` sur un fichier TypeScript contenant des fonctions et une classe. Vérifier que chaque symbole est retourné avec des numéros de lignes corrects.
4. **Cas d'erreurs et Sécurité** :
   - **Chemin relatif** : Fournir `"./file.ts"` à la place d'un chemin absolu. Attendu : échec explicite.
   - **Confinement (Succès)** : Configurer `REFACTORING_ALLOWED_DIR` sur un dossier exact. Appeler l'outil sur des fichiers dans ce dossier. Attendu : Succès.
   - **Confinement (Échec)** : Essayer de cibler un fichier en dehors de ce dossier (ex: `C:/Windows/System32/cmd.exe` ou `../../`). Attendu : rejet immédiat avec un message clair.
   - **Bornes invalides** : Demander de copier de la ligne 10 à 5, ou au-delà de la taille réelle du fichier source. Attendu : Erreur explicite retournée avec `isError: true`.

---

## Critères d'Acceptation

- Le MCP démarre proprement en stdio.
- Les logs ne polluent jamais `stdout`.
- Les deux outils `move_or_copy_code` et `get_file_outline` sont exposés.
- Les entrées sont validées par `Zod`.
- Les chemins non absolus et les tentatives de traversée de répertoire sont bloqués.
- Le confinement (`REFACTORING_ALLOWED_DIR`) est fonctionnel et restrictif si configuré.
- La copie et la coupure de blocs de lignes fonctionnent avec insertion précise ou ajout final.
- La création automatique du fichier et du répertoire de destination est effective si inexistant.
- `get_file_outline` retourne la liste des symboles avec leurs numéros de lignes pour les fichiers TypeScript/JavaScript.
