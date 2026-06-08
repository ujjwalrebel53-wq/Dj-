import type { FastifyInstance } from "fastify";
import { z } from "zod";
import {
  DEFAULT_TOOLS,
  type ChatChunk,
  type ChatMessage,
  type ContextFile,
} from "@dj-ai/shared";
import { buildSystemPrompt, streamChat } from "../services/llm.js";
import { executeTool } from "../tools/executor.js";
import { searchWorkspace } from "../services/indexer.js";

const chatBodySchema = z.object({
  messages: z.array(
    z.object({
      role: z.enum(["system", "user", "assistant", "tool"]),
      content: z.string(),
      name: z.string().optional(),
      toolCallId: z.string().optional(),
    }),
  ),
  model: z.string().optional(),
  mode: z.enum(["chat", "agent", "composer"]).optional(),
  workspaceRoot: z.string().optional(),
  contextFiles: z
    .array(
      z.object({
        path: z.string(),
        content: z.string(),
        language: z.string().optional(),
      }),
    )
    .optional(),
  rules: z.array(z.string()).optional(),
  stream: z.boolean().optional(),
});

function formatContext(files: ContextFile[]): string {
  return files
    .map((file) => `--- ${file.path} ---\n${file.content}`)
    .join("\n\n");
}

async function runAgentLoop(
  workspaceRoot: string,
  baseMessages: ChatMessage[],
  rules: string[],
  contextFiles: ContextFile[],
  send: (chunk: ChatChunk) => void,
  model?: string,
): Promise<void> {
  const messages: ChatMessage[] = [...baseMessages];
  const tools = DEFAULT_TOOLS;
  const maxSteps = 12;

  for (let step = 0; step < maxSteps; step++) {
    const lastUser = [...messages].reverse().find((m) => m.role === "user");
    let contextBlock = formatContext(contextFiles);
    if (workspaceRoot && lastUser) {
      const hits = await searchWorkspace(workspaceRoot, lastUser.content, 5);
      if (hits.length) {
        contextBlock += `\n\nSemantic search hits:\n${hits
          .map((h) => `${h.path} (score ${h.score.toFixed(2)})\n${h.snippet}`)
          .join("\n\n")}`;
      }
    }

    const systemMessage: ChatMessage = {
      role: "system",
      content: buildSystemPrompt(rules, contextBlock),
    };

    let assistantText = "";
    const toolCalls: Array<{ id: string; name: string; arguments: Record<string, unknown> }> = [];

    for await (const event of streamChat({
      messages: [systemMessage, ...messages],
      tools,
      model,
    })) {
      if (event.type === "text") {
        assistantText += event.content;
        send({ type: "text", content: event.content });
      }
      if (event.type === "tool_call") {
        toolCalls.push(event.toolCall);
        send({ type: "tool_call", toolCall: event.toolCall });
      }
    }

    if (!toolCalls.length) {
      if (assistantText) {
        messages.push({ role: "assistant", content: assistantText });
      }
      break;
    }

    messages.push({ role: "assistant", content: assistantText || "(calling tools)" });

    for (const call of toolCalls) {
      try {
        const output = await executeTool(workspaceRoot, call);
        send({ type: "tool_result", toolResult: { id: call.id, output } });
        messages.push({
          role: "tool",
          content: output,
          toolCallId: call.id,
          name: call.name,
        });
      } catch (error) {
        const output = error instanceof Error ? error.message : "Tool failed";
        send({ type: "tool_result", toolResult: { id: call.id, output } });
        messages.push({
          role: "tool",
          content: output,
          toolCallId: call.id,
          name: call.name,
        });
      }
    }
  }

  send({ type: "done" });
}

export async function registerChatRoutes(app: FastifyInstance): Promise<void> {
  app.post("/v1/chat", async (request, reply) => {
    const body = chatBodySchema.parse(request.body);
    const workspaceRoot = body.workspaceRoot ?? process.cwd();
    const rules = body.rules ?? [];
    const contextFiles = body.contextFiles ?? [];
    const stream = body.stream ?? true;

    if (!stream) {
      const chunks: ChatChunk[] = [];
      await runAgentLoop(
        workspaceRoot,
        body.messages,
        rules,
        contextFiles,
        (chunk) => chunks.push(chunk),
        body.model,
      );
      return { chunks };
    }

    reply.raw.writeHead(200, {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache",
      Connection: "keep-alive",
    });

    const send = (chunk: ChatChunk) => {
      reply.raw.write(`data: ${JSON.stringify(chunk)}\n\n`);
    };

    try {
      await runAgentLoop(
        workspaceRoot,
        body.messages,
        rules,
        contextFiles,
        send,
        body.model,
      );
    } catch (error) {
      send({
        type: "error",
        error: error instanceof Error ? error.message : "Unknown error",
      });
    }

    reply.raw.end();
    return reply;
  });
}
