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

```env
PORT=8787
LLM_BASE_URL=https://api.openai.com/v1
LLM_API_KEY=your-key
LLM_MODEL=gpt-4o
EMBEDDING_MODEL=text-embedding-3-small
DATA_DIR=.data
```

| Provider | LLM_BASE_URL |
|----------|--------------|
| OpenAI | `https://api.openai.com/v1` |
| Groq | `https://api.groq.com/openai/v1` |
| Ollama | `http://localhost:11434/v1` |

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
