import OpenAI from "openai";
import type { ChatCompletionMessageParam, ChatCompletionTool } from "openai/resources/chat/completions";
import type { ChatMessage, ToolCall, ToolDefinition } from "@dj-ai/shared";
import { config } from "../config.js";

const client = new OpenAI({
  apiKey: config.llmApiKey,
  baseURL: config.llmBaseUrl,
});

function toOpenAiTools(tools: ToolDefinition[]): ChatCompletionTool[] {
  return tools.map((tool) => ({
    type: "function" as const,
    function: {
      name: tool.name,
      description: tool.description,
      parameters: tool.parameters,
    },
  }));
}

function toOpenAiMessages(messages: ChatMessage[]): ChatCompletionMessageParam[] {
  return messages.map((message) => {
    if (message.role === "tool") {
      return {
        role: "tool",
        content: message.content,
        tool_call_id: message.toolCallId ?? "tool",
      };
    }
    return {
      role: message.role,
      content: message.content,
      ...(message.name ? { name: message.name } : {}),
    };
  });
}

export function buildSystemPrompt(rules: string[], contextBlock: string): string {
  const rulesBlock = rules.length
    ? `\n\nUser rules:\n${rules.map((r) => `- ${r}`).join("\n")}`
    : "";
  const context = contextBlock
    ? `\n\nRelevant workspace context:\n${contextBlock}`
    : "";
  return [
    "You are Dj AI, a coding assistant similar to Cursor.",
    "You help users write, debug, and understand code in their workspace.",
    "Prefer minimal, focused changes. Use tools when you need file or terminal access.",
    rulesBlock,
    context,
  ].join("");
}

export async function* streamChat({
  messages,
  tools,
  model = config.llmModel,
}: {
  messages: ChatMessage[];
  tools: ToolDefinition[];
  model?: string;
}): AsyncGenerator<
  | { type: "text"; content: string }
  | { type: "tool_call"; toolCall: ToolCall }
> {
  const stream = await client.chat.completions.create({
    model,
    messages: toOpenAiMessages(messages),
    tools: toOpenAiTools(tools),
    stream: true,
  });

  const pendingCalls = new Map<number, { id: string; name: string; args: string }>();

  for await (const chunk of stream) {
    const choice = chunk.choices[0];
    if (!choice) continue;

    const delta = choice.delta;
    if (delta.content) {
      yield { type: "text", content: delta.content };
    }

    if (delta.tool_calls) {
      for (const toolDelta of delta.tool_calls) {
        const index = toolDelta.index;
        const existing = pendingCalls.get(index) ?? {
          id: toolDelta.id ?? crypto.randomUUID(),
          name: toolDelta.function?.name ?? "",
          args: "",
        };
        if (toolDelta.id) existing.id = toolDelta.id;
        if (toolDelta.function?.name) existing.name = toolDelta.function.name;
        if (toolDelta.function?.arguments) existing.args += toolDelta.function.arguments;
        pendingCalls.set(index, existing);
      }
    }

    if (choice.finish_reason === "tool_calls") {
      for (const call of pendingCalls.values()) {
        yield {
          type: "tool_call",
          toolCall: {
            id: call.id,
            name: call.name,
            arguments: JSON.parse(call.args || "{}"),
          },
        };
      }
    }
  }
}

export async function embedText(text: string): Promise<number[]> {
  const response = await client.embeddings.create({
    model: config.embeddingModel,
    input: text,
  });
  return response.data[0]?.embedding ?? [];
}
