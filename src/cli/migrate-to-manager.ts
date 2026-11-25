/**
 * Ce que `--migrate-to-manager` sait de refactoring-mcp (cf. `manager-bundle.ts`) :
 * le MCP n'a pas de dossier de stockage, seule sa configuration est exportée.
 * `REFACTORING_ALLOWED_DIR`, qui confine les accès du MCP, est reprise telle
 * quelle.
 */
import { APP_VERSION } from "../version.js";
import { runManagerExport, type ManagerExportSpec } from "./manager-bundle.js";

const spec: ManagerExportSpec = {
  packageName: "@imenam/refactoring-mcp",
  packageVersion: APP_VERSION,
};

export function runMigrateToManager(argv: string[], cwd: string): Promise<number> {
  return runManagerExport(argv, cwd, spec);
}
