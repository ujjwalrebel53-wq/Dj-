# Dj AI — Single PHP file (Cursor jaisa assistant)

Poora backend **ek hi file** mein: `dj-ai.php`

- Chat + Agent mode (tools)
- Codebase indexing & semantic search
- OpenAI-compatible LLM (OpenAI / Groq / Ollama)
- VS Code extension client (optional)

## Chalana (sirf 2 steps)

```bash
cp .env.example .env
# .env mein LLM_API_KEY set karo

php dj-ai.php
```

API: `http://localhost:8787`

```bash
curl http://localhost:8787/health
# {"ok":true}
```

## .env config

**Default: WormGPT API** (free, no API key)

```env
LLM_PROVIDER=wormgpt
WORMGPT_API_URL=https://wormgpt.freeapihub.workers.dev/chat
```

OpenAI use karna ho to:

```env
LLM_PROVIDER=openai
LLM_API_KEY=your-key
LLM_BASE_URL=https://api.openai.com/v1
```

## API endpoints

| Endpoint | Kaam |
|----------|------|
| `GET /health` | Health check |
| `POST /v1/chat` | Streaming chat + agent |
| `POST /v1/index` | Workspace index |
| `POST /v1/search` | Semantic search |

## VS Code extension (optional)

```bash
npm install && npm run build
# packages/extension → F5
# Settings: djAi.apiUrl = http://localhost:8787
```

## Requirements

- PHP 8.2+
- `php-curl` extension

Composer ki zaroorat **nahi**.

---

## AlwaysData VPS deploy

### 1. SSH se install (ek command)

```bash
wget https://raw.githubusercontent.com/ujjwalrebel53-wq/Dj-/main/alwaysdata/install.sh -O install.sh && bash install.sh
```

### 2. AlwaysData panel settings

| Setting | Value |
|---------|-------|
| **Web > Sites > Type** | PHP |
| **PHP version** | 8.2 ya 8.3 |
| **Root directory** | `/home/TUMHARA_USER/dj-ai/alwaysdata/public` |

Domain/subdomain panel mein add karo (e.g. `djai.tumhara-domain.com`).

### 3. Test

```bash
curl https://djai.tumhara-domain.com/health
```

### 4. VS Code extension

Settings → `djAi.apiUrl` = `https://djai.tumhara-domain.com`

### AlwaysData folder structure

```
~/dj-ai/
  dj-ai.php          ← main API code
  .env               ← config
  .data/             ← indexes
  workspace/         ← code files yahan
  alwaysdata/public/ ← site root (panel mein ye path)
    index.php
    .htaccess
```
