import fs from "fs";
import path from "path";

const logDir =
  process.platform === "win32"
    ? "C:\\var\\log\\refactoring-mcp"
    : "/var/log/refactoring-mcp";
const logFile = path.join(logDir, "server.log");

export function setupLogging(prefix: string): void {
  if (!fs.existsSync(logDir)) {
    fs.mkdirSync(logDir, { recursive: true });
  }

  const logToFile = (msg: string): void => {
    const timestamp = new Date().toISOString();
    try {
      fs.appendFileSync(
        logFile,
        `[${timestamp}] [PID ${process.pid}] [${prefix}] ${msg}\n`,
        "utf-8"
      );
    } catch {
      // Silently ignore log write failures to avoid crashing the server
    }
  };

  // Rediriger console.log vers stderr pour protéger le flux JSON-RPC MCP
  console.log = (...args: unknown[]): void => {
    const msg = args
      .map((arg) => (typeof arg === "object" ? JSON.stringify(arg) : String(arg)))
      .join(" ");
    logToFile(`[INFO] ${msg}`);
    process.stderr.write(msg + "\n");
  };

  // Surcharger console.error pour journalisation automatique
  const originalError = console.error.bind(console);
  console.error = (...args: unknown[]): void => {
    const msg = args
      .map((arg) => (typeof arg === "object" ? JSON.stringify(arg) : String(arg)))
      .join(" ");
    logToFile(`[ERROR] ${msg}`);
    originalError(msg);
  };
}
