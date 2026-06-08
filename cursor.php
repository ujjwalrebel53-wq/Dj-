<?php
/**
 * cursor.php — Cursor jaisi AI coding website (single file)
 * Upload: ~/www/cursor.php
 * Open:  https://rebelai.alwaysdata.net/cursor.php
 */

declare(strict_types=1);

const WORM_API = 'https://wormgpt.freeapihub.workers.dev/chat';

// ─── API proxy ────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'chat') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $message = trim((string) ($input['message'] ?? ''));
    $context = trim((string) ($input['context'] ?? ''));

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message required']);
        exit;
    }

    $prompt = $message;
    if ($context !== '') {
        $prompt = "User code context:\n```\n{$context}\n```\n\nUser question:\n{$message}";
    }

    $url = WORM_API . '?q=' . rawurlencode($prompt);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        http_response_code(500);
        echo json_encode(['error' => 'API failed']);
        exit;
    }

    $data = json_decode($body, true);
    echo json_encode([
        'reply' => (string) ($data['reply'] ?? 'No response'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cursor AI</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0b0d10;
      --sidebar: #111318;
      --panel: #16181d;
      --border: #2a2d35;
      --text: #e4e4e7;
      --muted: #8b8f98;
      --accent: #6c9eff;
      --accent-hover: #8ab4ff;
      --user-bg: #1c2333;
      --ai-bg: #14161a;
      --code-bg: #0d0f14;
      --green: #4ade80;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      background: var(--bg);
      color: var(--text);
      height: 100vh;
      overflow: hidden;
    }
    .app { display: flex; height: 100vh; }

    /* Sidebar */
    .sidebar {
      width: 260px;
      background: var(--sidebar);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
    }
    .logo {
      padding: 16px 18px;
      font-size: 15px;
      font-weight: 600;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .logo-icon {
      width: 28px; height: 28px;
      background: linear-gradient(135deg, #6c9eff, #a78bfa);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px;
    }
    .new-chat {
      margin: 12px;
      padding: 10px 14px;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--text);
      cursor: pointer;
      font-size: 13px;
      text-align: left;
      transition: background .15s;
    }
    .new-chat:hover { background: #1e2128; }
    .sidebar-label {
      padding: 8px 18px 4px;
      font-size: 11px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .history { flex: 1; overflow-y: auto; padding: 4px 8px; }
    .history-item {
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 13px;
      color: var(--muted);
      cursor: pointer;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .history-item:hover { background: var(--panel); color: var(--text); }

    /* Main */
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }

    .topbar {
      height: 44px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      padding: 0 16px;
      gap: 12px;
      font-size: 13px;
      color: var(--muted);
      background: var(--sidebar);
    }
    .topbar .file-tab {
      padding: 4px 12px;
      background: var(--panel);
      border-radius: 6px 6px 0 0;
      color: var(--text);
      border: 1px solid var(--border);
      border-bottom: none;
    }
    .status-dot {
      width: 7px; height: 7px;
      background: var(--green);
      border-radius: 50%;
      margin-left: auto;
    }

    .workspace { flex: 1; display: flex; min-height: 0; }

    /* Editor */
    .editor-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      border-right: 1px solid var(--border);
      min-width: 0;
    }
    .editor-header {
      padding: 6px 14px;
      font-size: 12px;
      color: var(--muted);
      border-bottom: 1px solid var(--border);
      background: var(--panel);
    }
    #codeEditor {
      flex: 1;
      width: 100%;
      background: var(--code-bg);
      color: #abb2bf;
      border: none;
      padding: 16px;
      font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
      font-size: 13px;
      line-height: 1.6;
      resize: none;
      outline: none;
    }

    /* Chat */
    .chat-panel {
      width: 420px;
      display: flex;
      flex-direction: column;
      background: var(--bg);
      flex-shrink: 0;
    }
    .chat-header {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
      font-weight: 500;
    }
    .chat-messages {
      flex: 1;
      overflow-y: auto;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .msg { display: flex; flex-direction: column; gap: 6px; }
    .msg-label { font-size: 11px; color: var(--muted); font-weight: 500; }
    .msg-body {
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 13.5px;
      line-height: 1.65;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .msg.user .msg-body { background: var(--user-bg); border: 1px solid var(--border); }
    .msg.ai .msg-body { background: var(--ai-bg); border: 1px solid var(--border); }
    .msg-body code {
      background: var(--code-bg);
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 12px;
    }
    .msg-body pre {
      background: var(--code-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px;
      margin: 8px 0;
      overflow-x: auto;
      font-family: monospace;
      font-size: 12px;
      line-height: 1.5;
    }
    .typing { color: var(--muted); font-size: 13px; padding: 0 16px 8px; }
    .typing.hidden { display: none; }

    .chat-input-area {
      padding: 12px 16px 16px;
      border-top: 1px solid var(--border);
    }
    .input-wrap {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 4px;
      display: flex;
      flex-direction: column;
    }
    .input-wrap:focus-within { border-color: var(--accent); }
    #chatInput {
      background: transparent;
      border: none;
      color: var(--text);
      padding: 10px 12px;
      font-size: 13.5px;
      resize: none;
      outline: none;
      font-family: inherit;
      min-height: 44px;
      max-height: 120px;
    }
    .input-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 4px 8px 4px 12px;
    }
    .input-hint { font-size: 11px; color: var(--muted); }
    #sendBtn {
      background: var(--accent);
      color: #0b0d10;
      border: none;
      border-radius: 8px;
      padding: 6px 14px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: background .15s;
    }
    #sendBtn:hover { background: var(--accent-hover); }
    #sendBtn:disabled { opacity: .4; cursor: not-allowed; }

    @media (max-width: 900px) {
      .sidebar { display: none; }
      .editor-panel { display: none; }
      .chat-panel { width: 100%; }
    }
  </style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-icon">⌘</div>
      Cursor AI
    </div>
    <button class="new-chat" onclick="newChat()">+ New Chat</button>
    <div class="sidebar-label">Recent</div>
    <div class="history" id="history"></div>
  </aside>

  <div class="main">
    <div class="topbar">
      <span class="file-tab">main.php</span>
      <span>PHP</span>
      <span class="status-dot" title="Online"></span>
    </div>

    <div class="workspace">
      <div class="editor-panel">
        <div class="editor-header">EDITOR — code yahan likho, AI context mein use karega</div>
        <textarea id="codeEditor" spellcheck="false" placeholder="<?php echo htmlspecialchars('<?php
// Apna code yahan likho...
echo "Hello World";
'); ?>"></textarea>
      </div>

      <div class="chat-panel">
        <div class="chat-header">Chat</div>
        <div class="chat-messages" id="messages">
          <div class="msg ai">
            <span class="msg-label">AI</span>
            <div class="msg-body">Namaste! Main tumhara coding assistant hoon. Code editor mein code likho aur mujhse kuch bhi poocho — explain, fix, refactor, likhna — sab kar sakta hoon.</div>
          </div>
        </div>
        <div class="typing hidden" id="typing">AI soch raha hai...</div>
        <div class="chat-input-area">
          <div class="input-wrap">
            <textarea id="chatInput" rows="1" placeholder="Ask anything... (Ctrl+Enter to send)"></textarea>
            <div class="input-actions">
              <span class="input-hint">Ctrl + Enter</span>
              <button id="sendBtn" onclick="sendMessage()">Send ↑</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const messagesEl = document.getElementById('messages');
const chatInput = document.getElementById('chatInput');
const codeEditor = document.getElementById('codeEditor');
const sendBtn = document.getElementById('sendBtn');
const typingEl = document.getElementById('typing');
const historyEl = document.getElementById('history');
let chats = JSON.parse(localStorage.getItem('cursor_chats') || '[]');

function saveHistory(title) {
  chats.unshift({ title, time: Date.now() });
  chats = chats.slice(0, 20);
  localStorage.setItem('cursor_chats', JSON.stringify(chats));
  renderHistory();
}

function renderHistory() {
  historyEl.innerHTML = chats.map(c =>
    `<div class="history-item">${esc(c.title)}</div>`
  ).join('');
}
renderHistory();

function newChat() {
  messagesEl.innerHTML = `<div class="msg ai">
    <span class="msg-label">AI</span>
    <div class="msg-body">Nayi chat shuru! Kya karna hai?</div>
  </div>`;
  chatInput.value = '';
  chatInput.focus();
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function formatReply(text) {
  return esc(text)
    .replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) =>
      `<pre><code>${code.trim()}</code></pre>`)
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

function addMessage(role, html) {
  const div = document.createElement('div');
  div.className = 'msg ' + role;
  div.innerHTML = `<span class="msg-label">${role === 'user' ? 'You' : 'AI'}</span>
    <div class="msg-body">${html}</div>`;
  messagesEl.appendChild(div);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

async function sendMessage() {
  const message = chatInput.value.trim();
  if (!message) return;

  const context = codeEditor.value.trim();
  addMessage('user', esc(message));
  chatInput.value = '';
  sendBtn.disabled = true;
  typingEl.classList.remove('hidden');
  saveHistory(message.slice(0, 40));

  try {
    const res = await fetch('?action=chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, context }),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    addMessage('ai', formatReply(data.reply || ''));
  } catch (e) {
    addMessage('ai', `<span style="color:#f87171">Error: ${esc(e.message)}</span>`);
  } finally {
    sendBtn.disabled = false;
    typingEl.classList.add('hidden');
    chatInput.focus();
  }
}

chatInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault();
    sendMessage();
  }
});

chatInput.addEventListener('input', () => {
  chatInput.style.height = 'auto';
  chatInput.style.height = Math.min(chatInput.scrollHeight, 120) + 'px';
});
</script>
</body>
</html>
