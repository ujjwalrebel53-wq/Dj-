export type ChatRole = "system" | "user" | "assistant" | "tool";

export interface ChatMessage {
  role: ChatRole;
  content: string;
  name?: string;
  toolCallId?: string;
}

export interface ToolDefinition {
  name: string;
  description: string;
  parameters: Record<string, unknown>;
}

export interface ToolCall {
  id: string;
  name: string;
  arguments: Record<string, unknown>;
}

export interface ContextFile {
  path: string;
  content: string;
  language?: string;
}

export interface ChatRequest {
  messages: ChatMessage[];
  model?: string;
  mode?: "chat" | "agent" | "composer";
  workspaceRoot?: string;
  contextFiles?: ContextFile[];
  rules?: string[];
  stream?: boolean;
}

export interface ChatChunk {
  type: "text" | "tool_call" | "tool_result" | "done" | "error";
  content?: string;
  toolCall?: ToolCall;
  toolResult?: { id: string; output: string };
  error?: string;
}

export interface IndexFileRequest {
  workspaceRoot: string;
  paths: string[];
}

export interface SearchRequest {
  workspaceRoot: string;
  query: string;
  limit?: number;
}

export interface SearchResult {
  path: string;
  score: number;
  snippet: string;
}

export const DEFAULT_TOOLS: ToolDefinition[] = [
  {
    name: "read_file",
    description: "Read the contents of a file in the workspace",
    parameters: {
      type: "object",
      properties: {
        path: { type: "string", description: "Relative path from workspace root" },
      },
      required: ["path"],
    },
  },
  {
    name: "write_file",
    description: "Write or overwrite a file in the workspace",
    parameters: {
      type: "object",
      properties: {
        path: { type: "string" },
        content: { type: "string" },
      },
      required: ["path", "content"],
    },
  },
  {
    name: "grep",
    description: "Search for a regex pattern across workspace files",
    parameters: {
      type: "object",
      properties: {
        pattern: { type: "string" },
        glob: { type: "string", description: "Optional glob filter, e.g. **/*.ts" },
      },
      required: ["pattern"],
    },
  },
  {
    name: "run_terminal",
    description: "Run a shell command in the workspace",
    parameters: {
      type: "object",
      properties: {
        command: { type: "string" },
        cwd: { type: "string" },
      },
      required: ["command"],
    },
  },
];
