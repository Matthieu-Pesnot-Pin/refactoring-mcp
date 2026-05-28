import path from "path";
import { fileURLToPath } from "url";
import dotenv from "dotenv";

const rootDir = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../.."
);
const envPath = path.join(rootDir, ".env");

export interface Config {
  allowedDir: string | null;
}

export function getConfig(): Config {
  dotenv.config({ path: envPath, override: true });

  const rawDir = process.env.REFACTORING_ALLOWED_DIR;
  const allowedDir = rawDir ? path.resolve(rawDir) : null;

  return { allowedDir };
}
