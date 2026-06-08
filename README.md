# Dj AI — Cursor jaisa coding assistant (PHP API)

Dj AI ek **Cursor-style AI coding assistant** hai jisme:

- **Tumhara alag PHP API** (LLM provider, keys, billing — sab tumhare control mein)
- **VS Code extension** client (chat panel, workspace context, indexing)
- **Agent mode** with tools: `read_file`, `write_file`, `grep`, `run_terminal`
- **Codebase semantic search** via embeddings
- **Custom rules** (`.cursorrules` jaisa)

## Architecture

```
┌─────────────────────┐         ┌──────────────────────────────┐
│  VS Code Extension  │  HTTP   │  Dj AI PHP API               │
│  - Chat UI          │ ──────► │  - /v1/chat (SSE streaming)  │
│  - @file context    │         │  - /v1/index, /v1/search     │
│  - Index workspace  │         │  - Tool executor (agent loop)│
└─────────────────────┘         └──────────────┬───────────────┘
                                               │
                                               ▼
                                    ┌──────────────────────┐
                                    │  LLM Provider        │
                                    │  (OpenAI-compatible) │
                                    └──────────────────────┘
```

## Quick start

### 1. PHP API setup

```bash
cd packages/api-php
composer install
cp .env.example .env
# Edit: LLM_API_KEY, LLM_BASE_URL, LLM_MODEL
composer start
```

API chalega: `http://localhost:8787`

### 2. VS Code extension

```bash
npm install
npm run build
```

VS Code mein `packages/extension` kholo → **F5** dabao.

Settings → `Dj AI` → `Api Url` = `http://localhost:8787`

### LLM providers

| Provider | `LLM_BASE_URL` |
|----------|----------------|
| OpenAI | `https://api.openai.com/v1` |
| Groq | `https://api.groq.com/openai/v1` |
| Ollama (local) | `http://localhost:11434/v1` |

## API endpoints

| Endpoint | Description |
|----------|-------------|
| `POST /v1/chat` | Streaming chat + agent tool loop |
| `POST /v1/index` | Workspace embed & index |
| `POST /v1/search` | Semantic codebase search |
| `GET /health` | Health check |

## PHP project structure

```
packages/api-php/
  public/index.php      # Entry point + routes
  src/
    Config.php          # .env config
    Http/Router.php     # Simple router
    Services/
      LlmService.php    # OpenAI-compatible LLM calls
      AgentService.php  # Agent tool loop
      IndexerService.php
    Tools/ToolExecutor.php
  composer.json
```

## Cursor feature roadmap

| Feature | Status |
|---------|--------|
| Chat panel | ✅ MVP |
| Agent (tools) | ✅ MVP |
| Codebase indexing | ✅ MVP |
| Custom rules | ✅ MVP |
| Active file context | ✅ MVP |
| Tab autocomplete | 🔲 Phase 2 |
| Inline edit (Cmd+K) | 🔲 Phase 2 |
| Composer (multi-file) | 🔲 Phase 2 |
| MCP tools | 🔲 Phase 3 |

## License

MIT
