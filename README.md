# Dj AI — Cursor jaisa coding assistant (apna API)

Dj AI ek **Cursor-style AI coding assistant** hai jisme:

- **Tumhara alag API** (LLM provider, keys, billing — sab tumhare control mein)
- **VS Code extension** client (chat panel, workspace context, indexing)
- **Agent mode** with tools: `read_file`, `write_file`, `grep`, `run_terminal`
- **Codebase semantic search** via embeddings
- **Custom rules** (`.cursorrules` jaisa)

## Architecture

```
┌─────────────────────┐         ┌──────────────────────────────┐
│  VS Code Extension  │  HTTP   │  Dj AI API (tumhara server)  │
│  - Chat UI          │ ──────► │  - /v1/chat (SSE streaming)  │
│  - @file context    │         │  - /v1/index, /v1/search     │
│  - Index workspace  │         │  - Tool executor (agent loop)│
└─────────────────────┘         └──────────────┬───────────────┘
                                               │
                                               ▼
                                    ┌──────────────────────┐
                                    │  LLM Provider        │
                                    │  (OpenAI-compatible) │
                                    │  OpenAI / Groq /     │
                                    │  Ollama / custom     │
                                    └──────────────────────┘
```

## Quick start

### 1. Install

```bash
npm install
```

### 2. API configure karo

```bash
cp packages/api/.env.example packages/api/.env
# Edit: LLM_API_KEY, LLM_BASE_URL, LLM_MODEL
```

Koi bhi **OpenAI-compatible** endpoint use kar sakte ho:

| Provider | `LLM_BASE_URL` |
|----------|----------------|
| OpenAI | `https://api.openai.com/v1` |
| Groq | `https://api.groq.com/openai/v1` |
| Ollama (local) | `http://localhost:11434/v1` |

### 3. API start karo

```bash
npm run dev:api
```

### 4. Extension build & run

```bash
npm run build
```

VS Code mein `packages/extension` folder open karo aur **F5** press karo (Extension Development Host).

Settings → `Dj AI` → `Api Url` = `http://localhost:8787`

## API endpoints

| Endpoint | Description |
|----------|-------------|
| `POST /v1/chat` | Streaming chat + agent tool loop |
| `POST /v1/index` | Workspace embed & index |
| `POST /v1/search` | Semantic codebase search |
| `GET /health` | Health check |

## Cursor feature parity roadmap

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
| Full IDE fork | 🔲 Phase 3 |

## Project structure

```
packages/
  shared/     # Shared TypeScript types
  api/        # Your separate backend API
  extension/  # VS Code extension client
```

## License

MIT
