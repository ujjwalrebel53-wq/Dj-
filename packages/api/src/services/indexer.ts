import { readFile, writeFile, mkdir } from "node:fs/promises";
import { join } from "node:path";
import { glob } from "glob";
import { config } from "../config.js";
import { embedText } from "./llm.js";
import type { SearchResult } from "@dj-ai/shared";

interface IndexedChunk {
  path: string;
  startLine: number;
  endLine: number;
  text: string;
  embedding: number[];
}

function cosineSimilarity(a: number[], b: number[]): number {
  let dot = 0;
  let normA = 0;
  let normB = 0;
  for (let i = 0; i < a.length; i++) {
    dot += a[i]! * b[i]!;
    normA += a[i]! * a[i]!;
    normB += b[i]! * b[i]!;
  }
  return dot / (Math.sqrt(normA) * Math.sqrt(normB) || 1);
}

function chunkFile(path: string, content: string, chunkSize = 40): IndexedChunk[] {
  const lines = content.split("\n");
  const chunks: IndexedChunk[] = [];
  for (let i = 0; i < lines.length; i += chunkSize) {
    const slice = lines.slice(i, i + chunkSize);
    chunks.push({
      path,
      startLine: i + 1,
      endLine: i + slice.length,
      text: slice.join("\n"),
      embedding: [],
    });
  }
  return chunks;
}

function indexPath(workspaceRoot: string): string {
  const safe = workspaceRoot.replace(/[^a-zA-Z0-9_-]/g, "_");
  return join(config.dataDir, "indexes", safe, "chunks.json");
}

export async function indexWorkspace(workspaceRoot: string, paths?: string[]): Promise<number> {
  const files =
    paths ??
    (await glob("**/*.{ts,tsx,js,jsx,py,go,rs,java,md,json}", {
      cwd: workspaceRoot,
      nodir: true,
      ignore: ["**/node_modules/**", "**/.git/**", "**/dist/**"],
    }));

  const allChunks: IndexedChunk[] = [];
  for (const file of files.slice(0, 500)) {
    const content = await readFile(join(workspaceRoot, file), "utf8");
    allChunks.push(...chunkFile(file, content));
  }

  for (const chunk of allChunks) {
    chunk.embedding = await embedText(`${chunk.path}\n${chunk.text}`);
  }

  const out = indexPath(workspaceRoot);
  await mkdir(join(out, ".."), { recursive: true });
  await writeFile(out, JSON.stringify(allChunks));
  return allChunks.length;
}

export async function searchWorkspace(
  workspaceRoot: string,
  query: string,
  limit = 8,
): Promise<SearchResult[]> {
  const out = indexPath(workspaceRoot);
  let chunks: IndexedChunk[] = [];
  try {
    chunks = JSON.parse(await readFile(out, "utf8"));
  } catch {
    return [];
  }

  const queryEmbedding = await embedText(query);
  const ranked = chunks
    .map((chunk) => ({
      path: chunk.path,
      score: cosineSimilarity(queryEmbedding, chunk.embedding),
      snippet: chunk.text.slice(0, 300),
    }))
    .sort((a, b) => b.score - a.score)
    .slice(0, limit);

  return ranked;
}
