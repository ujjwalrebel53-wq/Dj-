import { execFile } from "node:child_process";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { promisify } from "node:util";
import { glob } from "glob";
import type { ToolCall } from "@dj-ai/shared";

const execFileAsync = promisify(execFile);

function assertInsideWorkspace(workspaceRoot: string, targetPath: string): string {
  const full = resolve(workspaceRoot, targetPath);
  const root = resolve(workspaceRoot);
  if (!full.startsWith(root)) {
    throw new Error(`Path escapes workspace: ${targetPath}`);
  }
  return full;
}

export async function executeTool(
  workspaceRoot: string,
  call: ToolCall,
): Promise<string> {
  switch (call.name) {
    case "read_file": {
      const path = String(call.arguments.path ?? "");
      const full = assertInsideWorkspace(workspaceRoot, path);
      return await readFile(full, "utf8");
    }
    case "write_file": {
      const path = String(call.arguments.path ?? "");
      const content = String(call.arguments.content ?? "");
      const full = assertInsideWorkspace(workspaceRoot, path);
      await mkdir(dirname(full), { recursive: true });
      await writeFile(full, content, "utf8");
      return `Wrote ${path} (${content.length} bytes)`;
    }
    case "grep": {
      const pattern = String(call.arguments.pattern ?? "");
      const globPattern = String(call.arguments.glob ?? "**/*");
      const files = await glob(globPattern, {
        cwd: workspaceRoot,
        nodir: true,
        ignore: ["**/node_modules/**", "**/.git/**"],
      });
      const regex = new RegExp(pattern, "gm");
      const hits: string[] = [];
      for (const file of files.slice(0, 200)) {
        const full = join(workspaceRoot, file);
        const content = await readFile(full, "utf8");
        const lines = content.split("\n");
        for (let i = 0; i < lines.length; i++) {
          if (regex.test(lines[i]!)) {
            hits.push(`${file}:${i + 1}: ${lines[i]}`);
            regex.lastIndex = 0;
          }
        }
        if (hits.length >= 50) break;
      }
      return hits.length ? hits.join("\n") : "No matches found";
    }
    case "run_terminal": {
      const command = String(call.arguments.command ?? "");
      const cwd = call.arguments.cwd
        ? assertInsideWorkspace(workspaceRoot, String(call.arguments.cwd))
        : workspaceRoot;
      const { stdout, stderr } = await execFileAsync("bash", ["-lc", command], {
        cwd,
        maxBuffer: 1024 * 1024,
      });
      return [stdout, stderr].filter(Boolean).join("\n") || "(no output)";
    }
    default:
      throw new Error(`Unknown tool: ${call.name}`);
  }
}
