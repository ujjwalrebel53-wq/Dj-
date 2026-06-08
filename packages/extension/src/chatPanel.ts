import * as vscode from "vscode";
import { DjApiClient } from "./apiClient.js";
import type { ChatMessage } from "./types.js";

export class ChatPanelProvider implements vscode.WebviewViewProvider {
  public static readonly viewType = "dj-ai.chatView";
  private messages: ChatMessage[] = [];

  constructor(private readonly context: vscode.ExtensionContext) {}

  resolveWebviewView(webviewView: vscode.WebviewView): void {
    webviewView.webview.options = {
      enableScripts: true,
      localResourceRoots: [this.context.extensionUri],
    };

    webviewView.webview.html = this.getHtml();

    webviewView.webview.onDidReceiveMessage(async (message) => {
      if (message.type === "send") {
        await this.handleUserMessage(webviewView, String(message.text ?? ""));
      }
    });
  }

  private getConfig() {
    const config = vscode.workspace.getConfiguration("djAi");
    return {
      apiUrl: config.get<string>("apiUrl", "http://localhost:8787"),
      model: config.get<string>("model", "gpt-4o"),
      rules: config.get<string[]>("rules", []),
    };
  }

  private async handleUserMessage(
    view: vscode.WebviewView,
    text: string,
  ): Promise<void> {
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) {
      view.webview.postMessage({ type: "error", text: "Open a workspace folder first." });
      return;
    }

    this.messages.push({ role: "user", content: text });
    view.webview.postMessage({ type: "user", text });

    const editor = vscode.window.activeTextEditor;
    const contextFiles =
      editor?.document
        ? [
            {
              path: vscode.workspace.asRelativePath(editor.document.uri),
              content: editor.document.getText(),
              language: editor.document.languageId,
            },
          ]
        : [];

    const { apiUrl, model, rules } = this.getConfig();
    const client = new DjApiClient(apiUrl);
    let assistant = "";

    try {
      for await (const chunk of client.chat({
        messages: this.messages,
        workspaceRoot,
        contextFiles,
        rules,
        model,
        mode: "agent",
      })) {
        if (chunk.type === "text" && chunk.content) {
          assistant += chunk.content;
          view.webview.postMessage({ type: "assistant_delta", text: chunk.content });
        }
        if (chunk.type === "tool_call" && chunk.toolCall) {
          view.webview.postMessage({
            type: "tool",
            text: `Tool: ${chunk.toolCall.name}`,
          });
        }
        if (chunk.type === "tool_result" && chunk.toolResult) {
          view.webview.postMessage({
            type: "tool_result",
            text: chunk.toolResult.output.slice(0, 500),
          });
        }
        if (chunk.type === "error") {
          view.webview.postMessage({ type: "error", text: chunk.error ?? "Error" });
        }
      }

      if (assistant) {
        this.messages.push({ role: "assistant", content: assistant });
      }
      view.webview.postMessage({ type: "assistant_done" });
    } catch (error) {
      view.webview.postMessage({
        type: "error",
        text: error instanceof Error ? error.message : "Request failed",
      });
    }
  }

  private getHtml(): string {
    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <style>
    body { font-family: var(--vscode-font-family); color: var(--vscode-foreground); margin: 0; display: flex; flex-direction: column; height: 100vh; }
    #messages { flex: 1; overflow-y: auto; padding: 12px; }
    .msg { margin-bottom: 12px; white-space: pre-wrap; line-height: 1.4; }
    .user { color: var(--vscode-textLink-foreground); }
    .assistant { color: var(--vscode-foreground); }
    .tool { color: var(--vscode-descriptionForeground); font-size: 12px; }
    .error { color: var(--vscode-errorForeground); }
    #input-row { display: flex; gap: 8px; padding: 12px; border-top: 1px solid var(--vscode-panel-border); }
    textarea { flex: 1; min-height: 64px; resize: vertical; background: var(--vscode-input-background); color: var(--vscode-input-foreground); border: 1px solid var(--vscode-input-border); border-radius: 4px; padding: 8px; }
    button { align-self: end; padding: 8px 12px; }
  </style>
</head>
<body>
  <div id="messages"></div>
  <div id="input-row">
    <textarea id="prompt" placeholder="Ask Dj AI anything about your code..."></textarea>
    <button id="send">Send</button>
  </div>
  <script>
    const vscode = acquireVsCodeApi();
    const messages = document.getElementById('messages');
    const prompt = document.getElementById('prompt');
    const send = document.getElementById('send');
    let currentAssistant = null;

    function append(className, text, replace = false) {
      if (replace && currentAssistant) {
        currentAssistant.textContent += text;
        messages.scrollTop = messages.scrollHeight;
        return;
      }
      const el = document.createElement('div');
      el.className = 'msg ' + className;
      el.textContent = text;
      messages.appendChild(el);
      messages.scrollTop = messages.scrollHeight;
      if (className === 'assistant') currentAssistant = el;
    }

    send.addEventListener('click', () => {
      const text = prompt.value.trim();
      if (!text) return;
      prompt.value = '';
      currentAssistant = null;
      vscode.postMessage({ type: 'send', text });
    });

    window.addEventListener('message', (event) => {
      const msg = event.data;
      if (msg.type === 'user') append('user', 'You: ' + msg.text);
      if (msg.type === 'assistant_delta') {
        if (!currentAssistant) append('assistant', msg.text);
        else append('assistant', msg.text, true);
      }
      if (msg.type === 'assistant_done') currentAssistant = null;
      if (msg.type === 'tool') append('tool', msg.text);
      if (msg.type === 'tool_result') append('tool', msg.text);
      if (msg.type === 'error') append('error', msg.text);
    });
  </script>
</body>
</html>`;
  }
}
