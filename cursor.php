<?php
/**
 * cursor.php — Cursor jaisi AI website + GitHub commit
 * https://rebelai.alwaysdata.net/cursor.php
 */

declare(strict_types=1);

const WORM_API = 'https://wormgpt.freeapihub.workers.dev/chat';

function wormChat(string $prompt, int $maxParts = 8): string
{
    $full = '';
    $partPrompt = $prompt;

    for ($i = 0; $i < $maxParts; $i++) {
        $url = WORM_API . '?q=' . rawurlencode($partPrompt);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (CursorPHP/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if ($body === false) {
            break;
        }

        $data = json_decode($body, true);
        $chunk = (string) ($data['reply'] ?? '');
        if ($chunk === '') {
            break;
        }

        $full .= ($full !== '' ? "\n" : '') . $chunk;

        $fences = substr_count($full, '```');
        $chunkLen = strlen($chunk);
        $closedBlocks = $fences % 2 === 0;
        $shortReply = $chunkLen < 550;

        if ($shortReply && $closedBlocks) {
            break;
        }
        if ($i > 0 && $chunkLen < 400 && $closedBlocks) {
            break;
        }

        $tail = substr($chunk, -400);
        $partPrompt = <<<CONT
Continue EXACTLY where you stopped. DO NOT repeat. Hinglish mein.
POORA code complete karo — koi placeholder mat chhod.
Pehle wala last part:
---
{$tail}
---
Ab continue karo (sirf naya hissa):
CONT;
    }

    return $full;
}

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function githubRequest(string $token, string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: CursorPHP',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['ok' => false, 'code' => 500, 'error' => $error];
    }
    $decoded = json_decode($response, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $decoded, 'raw' => $response];
}

$action = $_GET['action'] ?? '';

if ($action === 'chat') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $message = trim((string) ($input['message'] ?? ''));
    $context = trim((string) ($input['context'] ?? ''));
    $filename = trim((string) ($input['filename'] ?? 'main.php'));
    $history = is_array($input['history'] ?? null) ? $input['history'] : [];

    if ($message === '') {
        jsonOut(['error' => 'message required'], 400);
    }

    $prompt = <<<PROMPT
STRICT RULES — MUST FOLLOW:
1. ALWAYS reply in Hinglish (Hindi + English mix). Example: "Bhai ye code sahi hai, bas line 5 fix karo."
2. Kabhi bhi sirf English mein mat likho — har response Hinglish mein ho.
3. Tone friendly aur helpful rakho — jaise ek bada bhai explain kare.
4. Code blocks markdown mein do (```language).
5. Pehle Hinglish mein explain karo, phir code do.
6. CRITICAL: POORA COMPLETE code likho — kabhi "..." ya placeholder mat chhod.
7. Agar code lamba hai tab bhi ek continuous block mein poora file code do.
8. Functions, imports, main — sab kuch include karo. Incomplete code FORBIDDEN.

Tu Cursor jaisa coding AI hai. File: {$filename}

PROMPT;

    if ($context !== '') {
        $prompt .= "\nCurrent code:\n```\n{$context}\n```\n";
    }

    if ($history !== []) {
        $prompt .= "\n--- Pichli chat (yaad rakh) ---\n";
        foreach (array_slice($history, -12) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $role = strtoupper((string) ($h['role'] ?? 'user'));
            $content = trim((string) ($h['content'] ?? ''));
            if ($content !== '') {
                $prompt .= "{$role}: {$content}\n";
            }
        }
        $prompt .= "--- Chat khatam ---\n";
    }

    $prompt .= "\nUSER (ab jawab Hinglish mein do, POORA code): {$message}";

    $wantsFullCode = (bool) preg_match('/\b(poora|pura|full|complete|advanced|pura code|poora code|osint|bot|script|project)\b/i', $message);
    $reply = wormChat($prompt, $wantsFullCode ? 8 : 3);

    if ($reply === '') {
        jsonOut(['error' => 'API failed'], 500);
    }

    if ($wantsFullCode && preg_match('/\bosint\b/i', $message)) {
        $template = __DIR__ . '/osint_bot.py';
        if (is_file($template)) {
            $code = (string) file_get_contents($template);
            $reply .= "\n\n---\n**Bhai ye POORA ready-made Advanced OSINT Bot hai** (API ne kaata ho to ye use kar):\n\n```python\n"
                . $code . "\n```\n\nRun: `pip install python-telegram-bot httpx dnspython phonenumbers` phir `python osint_bot.py`";
        }
    }

    jsonOut(['reply' => $reply]);
}

if ($action === 'template') {
    $name = (string) ($_GET['name'] ?? '');
    $files = ['osint' => 'osint_bot.py'];
    if (!isset($files[$name]) || !is_file(__DIR__ . '/' . $files[$name])) {
        jsonOut(['error' => 'template not found'], 404);
    }
    jsonOut([
        'filename' => $files[$name],
        'code' => (string) file_get_contents(__DIR__ . '/' . $files[$name]),
    ]);
}

if ($action === 'github_test') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $token = trim((string) ($input['token'] ?? ''));
    if ($token === '') {
        jsonOut(['error' => 'token required'], 400);
    }
    $res = githubRequest($token, 'GET', 'https://api.github.com/user');
    if (!$res['ok']) {
        jsonOut(['error' => 'Invalid token', 'detail' => $res['data']['message'] ?? ''], 401);
    }
    jsonOut(['ok' => true, 'user' => $res['data']['login'] ?? '']);
}

if ($action === 'commit') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $token = trim((string) ($input['token'] ?? ''));
    $owner = trim((string) ($input['owner'] ?? ''));
    $repo = trim((string) ($input['repo'] ?? ''));
    $branch = trim((string) ($input['branch'] ?? 'main'));
    $path = ltrim(trim((string) ($input['path'] ?? 'main.php')), '/');
    $message = trim((string) ($input['message'] ?? 'Update via Cursor AI'));
    $content = (string) ($input['content'] ?? '');

    if ($token === '' || $owner === '' || $repo === '' || $content === '') {
        jsonOut(['error' => 'token, owner, repo, content required'], 400);
    }

    $apiBase = "https://api.github.com/repos/{$owner}/{$repo}/contents/" . rawurlencode($path);
    $sha = null;

    $existing = githubRequest($token, 'GET', $apiBase . '?ref=' . rawurlencode($branch));
    if ($existing['ok'] && isset($existing['data']['sha'])) {
        $sha = $existing['data']['sha'];
    }

    $payload = [
        'message' => $message,
        'content' => base64_encode($content),
        'branch' => $branch,
    ];
    if ($sha) {
        $payload['sha'] = $sha;
    }

    $res = githubRequest($token, 'PUT', $apiBase, $payload);
    if (!$res['ok']) {
        jsonOut([
            'error' => $res['data']['message'] ?? 'Commit failed',
            'detail' => $res['data'],
        ], $res['code'] ?: 500);
    }

    jsonOut([
        'ok' => true,
        'sha' => $res['data']['commit']['sha'] ?? '',
        'url' => $res['data']['content']['html_url'] ?? '',
        'message' => "Committed to {$owner}/{$repo}:{$branch}",
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0b0d10">
<title>Cursor AI</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b0d10;--sidebar:#111318;--panel:#16181d;--border:#2a2d35;
  --text:#e4e4e7;--muted:#8b8f98;--accent:#6c9eff;--accent2:#8ab4ff;
  --user-bg:#1c2333;--ai-bg:#14161a;--code-bg:#0d0f14;--green:#4ade80;
  --danger:#f87171;--safe-b:env(safe-area-inset-bottom,0px);
  --safe-t:env(safe-area-inset-top,0px);
  --tab-h:56px;--top-h:48px;
}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
  -webkit-tap-highlight-color:transparent}
.app{display:flex;height:100dvh;padding-top:var(--safe-t)}

/* Sidebar */
.sidebar{width:240px;background:var(--sidebar);border-right:1px solid var(--border);
  display:flex;flex-direction:column;flex-shrink:0}
.logo{padding:14px 16px;font-weight:600;font-size:15px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px}
.logo-icon{width:26px;height:26px;background:linear-gradient(135deg,#6c9eff,#a78bfa);
  border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px}
.btn{padding:10px 14px;margin:10px;background:var(--panel);border:1px solid var(--border);
  border-radius:8px;color:var(--text);font-size:13px;cursor:pointer;text-align:left}
.btn:active{opacity:.8}
.btn-primary{background:var(--accent);color:#0b0d10;border:none;font-weight:600;text-align:center}
.btn-green{background:#166534;border-color:#22c55e;color:#bbf7d0}
.history{flex:1;overflow-y:auto;padding:4px 8px}
.history-item{padding:10px 12px;border-radius:8px;font-size:13px;color:var(--muted);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Main */
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{height:var(--top-h);border-bottom:1px solid var(--border);display:flex;align-items:center;
  padding:0 12px;gap:8px;font-size:12px;background:var(--sidebar);overflow-x:auto}
.topbar button,.topbar .tab{padding:6px 12px;border-radius:6px;border:1px solid var(--border);
  background:var(--panel);color:var(--text);font-size:12px;cursor:pointer;white-space:nowrap}
.topbar .tab.active{border-color:var(--accent);color:var(--accent)}
.topbar .spacer{flex:1}
.status-dot{width:7px;height:7px;background:var(--green);border-radius:50%}

.workspace{flex:1;display:flex;min-height:0}

/* Panels */
.panel{display:none;flex-direction:column;min-height:0;background:var(--bg)}
.panel.active{display:flex}
.editor-panel{flex:1;border-right:1px solid var(--border)}
.chat-panel{width:400px;flex-shrink:0}
.settings-panel{flex:1;padding:16px;overflow-y:auto}

.panel-header{padding:10px 14px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);
  background:var(--panel);display:flex;align-items:center;justify-content:space-between}
#codeEditor,#filenameInput{flex:1;width:100%;background:var(--code-bg);color:#abb2bf;
  border:none;padding:14px;font-family:'JetBrains Mono',Consolas,monospace;font-size:14px;
  line-height:1.6;resize:none;outline:none;-webkit-overflow-scrolling:touch}
#filenameInput{flex:none;height:40px;padding:8px 14px;border-bottom:1px solid var(--border);font-size:13px}

.chat-messages{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:12px;
  -webkit-overflow-scrolling:touch}
.msg-body{padding:12px;border-radius:10px;font-size:14px;line-height:1.6;white-space:pre-wrap;word-break:break-word}
.msg.user .msg-body{background:var(--user-bg);border:1px solid var(--border)}
.msg.ai .msg-body{background:var(--ai-bg);border:1px solid var(--border)}
.msg-label{font-size:11px;color:var(--muted);margin-bottom:4px;display:block}
.msg-actions{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.msg-actions button{padding:6px 10px;font-size:11px;border-radius:6px;border:1px solid var(--border);
  background:var(--panel);color:var(--text);cursor:pointer}
.code-wrap{position:relative;margin:8px 0}
.code-wrap pre{background:var(--code-bg);border:1px solid var(--border);border-radius:8px;
  padding:10px;padding-top:36px;overflow-x:auto;font-size:12px;margin:0}
.copy-btn{position:absolute;top:6px;right:6px;padding:5px 10px;font-size:11px;
  background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;z-index:2}
.copy-btn:active{background:var(--accent);color:#0b0d10}
.msg-body code{background:var(--code-bg);padding:2px 5px;border-radius:4px;font-size:12px}
.history-item.active{background:var(--panel);color:var(--accent)}

.typing{padding:0 14px 6px;font-size:12px;color:var(--muted)}
.typing.hide{display:none}
.chat-input-area{padding:10px 12px calc(10px + var(--safe-b));border-top:1px solid var(--border)}
.input-wrap{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:4px}
.input-wrap:focus-within{border-color:var(--accent)}
#chatInput{width:100%;background:transparent;border:none;color:var(--text);padding:10px 12px;
  font-size:16px;resize:none;outline:none;min-height:44px;max-height:120px}
.input-actions{display:flex;justify-content:space-between;align-items:center;padding:4px 8px}
#sendBtn{background:var(--accent);color:#0b0d10;border:none;border-radius:8px;
  padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer;min-height:44px;min-width:64px}

/* Settings */
.field{margin-bottom:14px}
.field label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.field input{width:100%;padding:12px;background:var(--panel);border:1px solid var(--border);
  border-radius:8px;color:var(--text);font-size:16px}
.field small{display:block;margin-top:4px;font-size:11px;color:var(--muted)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.toast{position:fixed;bottom:calc(var(--tab-h) + var(--safe-b) + 12px);left:50%;transform:translateX(-50%);
  background:#1e293b;border:1px solid var(--border);padding:12px 18px;border-radius:10px;
  font-size:13px;z-index:999;max-width:90vw;text-align:center;box-shadow:0 8px 32px #0008}
.toast.ok{border-color:var(--green);color:var(--green)}
.toast.err{border-color:var(--danger);color:var(--danger)}

/* Mobile bottom tabs */
.mobile-tabs{display:none;position:fixed;bottom:0;left:0;right:0;height:calc(var(--tab-h) + var(--safe-b));
  padding-bottom:var(--safe-b);background:var(--sidebar);border-top:1px solid var(--border);z-index:100}
.mobile-tabs button{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:2px;background:none;border:none;color:var(--muted);font-size:10px;cursor:pointer;padding:6px}
.mobile-tabs button.active{color:var(--accent)}
.mobile-tabs button span{font-size:20px}

/* Modal */
.modal{position:fixed;inset:0;background:#000a;display:none;align-items:flex-end;justify-content:center;z-index:200}
.modal.show{display:flex}
.modal-box{background:var(--panel);border:1px solid var(--border);border-radius:16px 16px 0 0;
  padding:20px;width:100%;max-width:500px;max-height:85vh;overflow-y:auto}
.modal-box h3{margin-bottom:14px;font-size:16px}
.modal-box input,.modal-box textarea{width:100%;padding:12px;margin-bottom:10px;background:var(--code-bg);
  border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:16px}
.modal-actions{display:flex;gap:8px;margin-top:8px}
.modal-actions button{flex:1;padding:12px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer}
.modal-actions .cancel{background:var(--border);color:var(--text)}
.modal-actions .confirm{background:var(--accent);color:#0b0d10}

@media(max-width:768px){
  .sidebar{display:none}
  .chat-panel,.editor-panel{width:100%;border:none}
  .workspace{padding-bottom:calc(var(--tab-h) + var(--safe-b))}
  .mobile-tabs{display:flex}
  .topbar .desk-only{display:none}
  .chat-input-area{padding-bottom:calc(10px + var(--tab-h) + var(--safe-b))}
}
</style>
</head>
<body>

<div class="app">
  <aside class="sidebar desk-only">
    <div class="logo"><div class="logo-icon">⌘</div> Cursor AI</div>
    <button class="btn" onclick="newChat()">+ New Chat</button>
    <button class="btn btn-green" onclick="openCommitModal()">⬆ Commit GitHub</button>
    <div class="history" id="history"></div>
  </aside>

  <div class="main">
    <div class="topbar">
      <span class="tab active" id="tabFile">📄 <span id="topFilename">main.php</span></span>
      <button class="desk-only" onclick="openCommitModal()">Commit ↑</button>
      <button onclick="applyAiCode()" title="AI code apply">Apply</button>
      <button onclick="loadTemplate('osint')" title="OSINT bot template">OSINT Bot</button>
      <span class="spacer"></span>
      <span class="status-dot"></span>
    </div>

    <div class="workspace">
      <div class="panel editor-panel active" id="panelEditor">
        <input id="filenameInput" value="main.php" placeholder="filename.php" oninput="syncFilename(this.value)">
        <textarea id="codeEditor" spellcheck="false" placeholder="Code likho yahan..."><?php echo htmlspecialchars('<?php
echo "Hello World";
'); ?></textarea>
      </div>

      <div class="panel chat-panel" id="panelChat">
        <div class="panel-header">💬 Chat <button onclick="newChat()" style="background:none;border:none;color:var(--muted);cursor:pointer">Clear</button></div>
        <div class="chat-messages" id="messages"></div>
        <div class="typing hide" id="typing">AI soch raha hai...</div>
        <div class="chat-input-area">
          <div class="input-wrap">
            <textarea id="chatInput" rows="1" placeholder="Ask AI... (commit karo, fix karo, likho)"></textarea>
            <div class="input-actions">
              <span style="font-size:11px;color:var(--muted)">↵ send</span>
              <button id="sendBtn" onclick="sendMessage()">Send</button>
            </div>
          </div>
        </div>
      </div>

      <div class="panel settings-panel" id="panelSettings">
        <h2 style="margin-bottom:16px;font-size:18px">⚙️ GitHub Settings</h2>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Cursor jaisa — code seedha GitHub pe commit hoga. Token mein <code>repo</code> permission do.</p>
        <div class="field"><label>GitHub Token</label><input type="password" id="ghToken" placeholder="ghp_xxxx"><small>github.com → Settings → Developer settings → Tokens</small></div>
        <div class="grid2">
          <div class="field"><label>Owner</label><input id="ghOwner" placeholder="username"></div>
          <div class="field"><label>Repo</label><input id="ghRepo" placeholder="my-project"></div>
        </div>
        <div class="field"><label>Branch</label><input id="ghBranch" value="main"></div>
        <button class="btn btn-primary" style="width:100%;margin:0 0 10px" onclick="saveGhSettings()">Save Settings</button>
        <button class="btn" style="width:100%;margin:0" onclick="testGithub()">Test Connection</button>
      </div>
    </div>
  </div>
</div>

<nav class="mobile-tabs">
  <button class="active" data-panel="editor" onclick="switchTab('editor',this)"><span>{ }</span>Code</button>
  <button data-panel="chat" onclick="switchTab('chat',this)"><span>💬</span>Chat</button>
  <button data-panel="settings" onclick="switchTab('settings',this)"><span>⚙️</span>GitHub</button>
  <button onclick="openCommitModal()"><span>⬆</span>Commit</button>
</nav>

<div class="modal" id="commitModal">
  <div class="modal-box">
    <h3>⬆ Commit to GitHub</h3>
    <input id="commitPath" placeholder="file path e.g. src/index.php">
    <textarea id="commitMsg" rows="2" placeholder="Commit message"></textarea>
    <div class="modal-actions">
      <button class="cancel" onclick="closeCommitModal()">Cancel</button>
      <button class="confirm" onclick="doCommit()">Commit ↑</button>
    </div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const STORAGE = 'cursor_sessions_v2';
let lastAiCode = '';
let lastAiReply = '';
const codeStore = {};
let codeId = 0;
let sessions = {};
let currentId = null;
let chatLog = [];

function saveSessions() {
  localStorage.setItem(STORAGE, JSON.stringify(sessions));
  localStorage.setItem('cursor_editor', $('codeEditor').value);
  localStorage.setItem('cursor_filename', $('filenameInput').value);
}

function loadSessions() {
  try { sessions = JSON.parse(localStorage.getItem(STORAGE) || '{}'); } catch { sessions = {}; }
  const savedEditor = localStorage.getItem('cursor_editor');
  const savedFile = localStorage.getItem('cursor_filename');
  if (savedEditor) $('codeEditor').value = savedEditor;
  if (savedFile) { $('filenameInput').value = savedFile; syncFilename(savedFile); }

  const ids = Object.keys(sessions).sort((a,b) => sessions[b].updated - sessions[a].updated);
  if (ids.length) {
    loadSession(ids[0]);
  } else {
    createSession(true);
  }
  renderHistory();
}

function createSession(showWelcome=true) {
  currentId = 's' + Date.now();
  chatLog = showWelcome ? [{role:'ai', content:'Namaste bhai! Code likho, Hinglish mein poocho, GitHub pe commit bhi kar sakte ho.'}] : [];
  sessions[currentId] = { id: currentId, title: 'Nayi chat', updated: Date.now(), messages: chatLog };
  saveSessions();
  renderMessages();
  renderHistory();
}

function loadSession(id) {
  if (!sessions[id]) return;
  currentId = id;
  chatLog = sessions[id].messages || [];
  renderMessages();
  renderHistory();
}

function saveChat() {
  if (!currentId) return;
  sessions[currentId].messages = chatLog;
  sessions[currentId].updated = Date.now();
  const firstUser = chatLog.find(m => m.role === 'user');
  if (firstUser) sessions[currentId].title = firstUser.content.slice(0, 36);
  saveSessions();
  renderHistory();
}

function renderHistory() {
  const el = $('history');
  if (!el) return;
  const ids = Object.keys(sessions).sort((a,b) => sessions[b].updated - sessions[a].updated);
  el.innerHTML = ids.map(id =>
    `<div class="history-item${id===currentId?' active':''}" onclick="loadSession('${id}')">${esc(sessions[id].title)}</div>`
  ).join('');
}

function renderMessages() {
  $('messages').innerHTML = '';
  chatLog.forEach(m => {
    if (m.role === 'user') addMessageDOM('user', esc(m.content));
    else addMessageDOM('ai', formatReply(m.content), m.code || extractCode(m.content));
  });
}

function attachCopyButtons(container) {
  container.querySelectorAll('pre').forEach(pre => {
    if (pre.parentElement?.classList.contains('code-wrap')) return;
    const wrap = document.createElement('div');
    wrap.className = 'code-wrap';
    const btn = document.createElement('button');
    btn.className = 'copy-btn';
    btn.textContent = '📋 Copy';
    btn.onclick = async () => {
      try {
        await navigator.clipboard.writeText(pre.textContent);
        btn.textContent = '✓ Copied';
        setTimeout(() => btn.textContent = '📋 Copy', 2000);
      } catch { toast('Copy failed', 'err'); }
    };
    pre.parentNode.insertBefore(wrap, pre);
    wrap.append(btn, pre);
  });
}

function ghSettings() {
  return {
    token: localStorage.getItem('gh_token') || '',
    owner: localStorage.getItem('gh_owner') || '',
    repo: localStorage.getItem('gh_repo') || '',
    branch: localStorage.getItem('gh_branch') || 'main',
  };
}

function loadGhSettings() {
  const s = ghSettings();
  $('ghToken').value = s.token;
  $('ghOwner').value = s.owner;
  $('ghRepo').value = s.repo;
  $('ghBranch').value = s.branch;
}
loadGhSettings();

function saveGhSettings() {
  localStorage.setItem('gh_token', $('ghToken').value.trim());
  localStorage.setItem('gh_owner', $('ghOwner').value.trim());
  localStorage.setItem('gh_repo', $('ghRepo').value.trim());
  localStorage.setItem('gh_branch', $('ghBranch').value.trim() || 'main');
  toast('GitHub settings saved', 'ok');
}

function syncFilename(v) {
  $('topFilename').textContent = v || 'main.php';
}

function switchTab(name, btn) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.mobile-tabs button[data-panel]').forEach(b => b.classList.remove('active'));
  const map = {editor:'panelEditor', chat:'panelChat', settings:'panelSettings'};
  $(map[name])?.classList.add('active');
  btn?.classList.add('active');
  if (name === 'chat') setTimeout(() => $('chatInput').focus(), 100);
}

function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

function extractCode(text) {
  const m = text.match(/```[\w]*\n([\s\S]*?)```/);
  return m ? m[1].trim() : '';
}

function formatReply(text) {
  return esc(text)
    .replace(/```(\w*)\n([\s\S]*?)```/g, (_, l, c) => `<pre><code>${c.trim()}</code></pre>`)
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

function toast(msg, type='') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

function addMessageDOM(role, html, code='') {
  const div = document.createElement('div');
  div.className = 'msg ' + role;
  const body = document.createElement('div');
  body.className = 'msg-body';
  body.innerHTML = html;
  div.innerHTML = `<span class="msg-label">${role==='user'?'Tu':'AI'}</span>`;
  div.appendChild(body);
  attachCopyButtons(body);

  if (role === 'ai' && code) {
    const id = 'c' + (++codeId);
    codeStore[id] = code;
    const actions = document.createElement('div');
    actions.className = 'msg-actions';
    const copyBtn = document.createElement('button');
    copyBtn.textContent = '📋 Copy Code';
    copyBtn.onclick = async () => {
      await navigator.clipboard.writeText(code);
      toast('Code copy ho gaya!', 'ok');
    };
    const applyBtn = document.createElement('button');
    applyBtn.textContent = 'Apply';
    applyBtn.onclick = () => applyCode(codeStore[id]);
    const commitBtn = document.createElement('button');
    commitBtn.textContent = 'Commit ↑';
    commitBtn.onclick = () => openCommitModal($('filenameInput').value, codeStore[id]);
    actions.append(copyBtn, applyBtn, commitBtn);
    div.appendChild(actions);
  }

  $('messages').appendChild(div);
  $('messages').scrollTop = $('messages').scrollHeight;
}

function addMessage(role, text, code='') {
  chatLog.push({ role, content: text, code: code || undefined });
  saveChat();
  addMessageDOM(role, role === 'user' ? esc(text) : formatReply(text), code);
}

function newChat() { createSession(true); }

function applyCode(code) {
  if (!code) return;
  $('codeEditor').value = code;
  toast('Code editor mein apply ho gaya', 'ok');
  if (window.innerWidth <= 768) switchTab('editor', document.querySelector('[data-panel=editor]'));
}

function applyAiCode() {
  if (lastAiCode) applyCode(lastAiCode);
  else toast('Pehle AI se code lo', 'err');
}

async function sendMessage() {
  const message = $('chatInput').value.trim();
  if (!message) return;
  const context = $('codeEditor').value;
  const filename = $('filenameInput').value.trim() || 'main.php';

  addMessage('user', message);
  $('chatInput').value = '';
  $('sendBtn').disabled = true;
  $('typing').textContent = 'AI poora code likh raha hai... (thoda wait)';
  $('typing').classList.remove('hide');
  saveSessions();

  const commitWords = /commit|push|github|save karo|upload/i;
  const wantsCommit = commitWords.test(message);
  const history = chatLog.slice(0, -1).map(m => ({ role: m.role, content: m.content }));

  try {
    const res = await fetch('?action=chat', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ message, context, filename, history }),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    lastAiReply = data.reply || '';
    lastAiCode = extractCode(lastAiReply);
    addMessage('ai', lastAiReply, lastAiCode);

    if (wantsCommit && lastAiCode) {
      setTimeout(() => openCommitModal(filename, lastAiCode, 'AI: ' + message.slice(0,60)), 500);
    } else if (wantsCommit) {
      openCommitModal(filename, context, message.slice(0,80));
    }
  } catch(e) {
    addMessage('ai', 'Bhai error aa gaya: ' + e.message);
  } finally {
    $('sendBtn').disabled = false;
    $('typing').textContent = 'AI soch raha hai...';
    $('typing').classList.add('hide');
  }
}

async function loadTemplate(name) {
  try {
    const res = await fetch('?action=template&name=' + name);
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    $('codeEditor').value = data.code;
    $('filenameInput').value = data.filename;
    syncFilename(data.filename);
    toast('Template load ho gaya: ' + data.filename, 'ok');
    if (window.innerWidth <= 768) switchTab('editor', document.querySelector('[data-panel=editor]'));
  } catch(e) { toast(e.message, 'err'); }
}

function openCommitModal(path='', content='', msg='') {
  const s = ghSettings();
  if (!s.token || !s.owner || !s.repo) {
    toast('Pehle GitHub settings save karo', 'err');
    if (window.innerWidth <= 768) switchTab('settings', document.querySelector('[data-panel=settings]'));
    return;
  }
  $('commitPath').value = path || $('filenameInput').value || 'main.php';
  $('commitMsg').value = msg || 'Update via Cursor AI';
  $('commitModal').dataset.content = content ?? $('codeEditor').value;
  $('commitModal').classList.add('show');
}

function closeCommitModal() { $('commitModal').classList.remove('show'); }

async function doCommit() {
  const s = ghSettings();
  const content = $('commitModal').dataset.content || $('codeEditor').value;
  const path = $('commitPath').value.trim();
  const message = $('commitMsg').value.trim() || 'Update via Cursor AI';

  if (!content || !path) { toast('File path aur content chahiye', 'err'); return; }

  closeCommitModal();
  toast('Committing...', '');

  try {
    const res = await fetch('?action=commit', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({...s, path, message, content}),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    toast('✓ ' + (data.message || 'Committed!'), 'ok');
    addMessage('ai', '✓ Bhai GitHub pe commit ho gaya!\n' + (data.url || ''));
  } catch(e) {
    toast('Commit failed: ' + e.message, 'err');
  }
}

async function testGithub() {
  saveGhSettings();
  const s = ghSettings();
  if (!s.token) { toast('Token daalo', 'err'); return; }
  try {
    const res = await fetch('?action=github_test', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({token: s.token}),
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    toast('Connected: @' + data.user, 'ok');
  } catch(e) { toast(e.message, 'err'); }
}

$('chatInput').addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
$('codeEditor').addEventListener('input', () => saveSessions());
$('filenameInput').addEventListener('input', () => saveSessions());
$('commitModal').addEventListener('click', e => { if (e.target === $('commitModal')) closeCommitModal(); });

loadSessions();
</script>
</body>
</html>
