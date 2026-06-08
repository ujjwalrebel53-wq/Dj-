import * as vscode from "vscode";
import { ChatPanelProvider } from "./chatPanel.js";
import { DjApiClient } from "./apiClient.js";

export function activate(context: vscode.ExtensionContext): void {
  const chatProvider = new ChatPanelProvider(context);
  context.subscriptions.push(
    vscode.window.registerWebviewViewProvider(ChatPanelProvider.viewType, chatProvider),
  );

  context.subscriptions.push(
    vscode.commands.registerCommand("dj-ai.openChat", async () => {
      await vscode.commands.executeCommand("workbench.view.explorer");
      await vscode.commands.executeCommand("dj-ai.chatView.focus");
    }),
  );

  context.subscriptions.push(
    vscode.commands.registerCommand("dj-ai.indexWorkspace", async () => {
      const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
      if (!workspaceRoot) {
        vscode.window.showErrorMessage("Open a workspace folder first.");
        return;
      }
      const apiUrl = vscode.workspace.getConfiguration("djAi").get<string>("apiUrl", "http://localhost:8787");
      const client = new DjApiClient(apiUrl);
      await vscode.window.withProgress(
        { location: vscode.ProgressLocation.Notification, title: "Indexing workspace..." },
        async () => {
          const count = await client.indexWorkspace(workspaceRoot);
          vscode.window.showInformationMessage(`Indexed ${count} code chunks.`);
        },
      );
    }),
  );

  context.subscriptions.push(
    vscode.commands.registerCommand("dj-ai.askAboutSelection", async () => {
      const editor = vscode.window.activeTextEditor;
      if (!editor || editor.selection.isEmpty) {
        vscode.window.showWarningMessage("Select some code first.");
        return;
      }
      const selected = editor.document.getText(editor.selection);
      const question = await vscode.window.showInputBox({
        prompt: "What do you want to know about this selection?",
        placeHolder: "Explain this code / find bugs / refactor",
      });
      if (!question) return;
      await vscode.commands.executeCommand("dj-ai.openChat");
      // Chat panel will pick up active editor context on next message.
      vscode.window.showInformationMessage(`Ask in Dj AI panel: ${question}\n\nSelection included as context.`);
    }),
  );
}

export function deactivate(): void {}
