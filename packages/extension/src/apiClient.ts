import type { ChatChunk, ChatMessage, ContextFile } from "./types.js";

export class DjApiClient {
  constructor(private readonly baseUrl: string) {}

  async *chat(params: {
    messages: ChatMessage[];
    workspaceRoot: string;
    contextFiles?: ContextFile[];
    rules?: string[];
    model?: string;
    mode?: "chat" | "agent" | "composer";
  }): AsyncGenerator<ChatChunk> {
    const response = await fetch(`${this.baseUrl}/v1/chat`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...params, stream: true }),
    });

    if (!response.ok || !response.body) {
      throw new Error(`API error: ${response.status} ${response.statusText}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      const parts = buffer.split("\n\n");
      buffer = parts.pop() ?? "";

      for (const part of parts) {
        const line = part.trim();
        if (!line.startsWith("data: ")) continue;
        const payload = JSON.parse(line.slice(6)) as ChatChunk;
        yield payload;
        if (payload.type === "done" || payload.type === "error") return;
      }
    }
  }

  async indexWorkspace(workspaceRoot: string): Promise<number> {
    const response = await fetch(`${this.baseUrl}/v1/index`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ workspaceRoot }),
    });
    if (!response.ok) throw new Error(`Index failed: ${response.status}`);
    const data = (await response.json()) as { indexedChunks: number };
    return data.indexedChunks;
  }
}
