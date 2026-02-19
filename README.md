# Laravel Knowledge Base MCP

AI-searchable knowledge base built with Laravel + Inertia + React, with MCP tools for AI clients.

This repo is designed for two use cases:

- humans managing knowledge via web UI
- AI clients retrieving knowledge via MCP (`search_knowledge_base`)

For full internals, see `ARCHITECTURE.md`.

## Table of Contents

- [What This Project Does](#what-this-project-does)
- [Tech Stack](#tech-stack)
- [Quick Start](#quick-start)
- [OpenAI API Key (Required)](#openai-api-key-required)
- [Manual Setup (Deterministic)](#manual-setup-deterministic)
- [How to Operate Day to Day](#how-to-operate-day-to-day)
- [MCP Usage and Authentication](#mcp-usage-and-authentication)
- [Search and Indexing Behavior](#search-and-indexing-behavior)
- [Configuration Reference](#configuration-reference)
- [Testing, Linting, and Build](#testing-linting-and-build)
- [Troubleshooting](#troubleshooting)
- [Additional Documentation](#additional-documentation)

## What This Project Does

- Stores knowledge entries (Markdown articles + category + tags).
- Supports attached code examples and resources.
- Builds chunked retrieval index in PostgreSQL (`pgvector` + FTS + trigram fuzzy matching).
- Exposes MCP tools:
  - `search_knowledge_base`
  - `create_knowledge_entry`

## Tech Stack

- Backend: Laravel 12, PHP 8.5, Sanctum, Fortify
- Frontend: Inertia v2 + React 19 + Tailwind v4
- AI integrations: Laravel AI (`laravel/ai`), Laravel MCP (`laravel/mcp`)
- Database: PostgreSQL 16 + `pgvector` + `pg_trgm`
- Runtime: Laravel Sail (Docker)

## Quick Start

Prerequisites:

- Docker + Docker Compose
- PHP + Composer (needed to install dependencies and bootstrap Sail)

#### Optional: Sail alias (recommended)

Run this so you dont need to add `./vendor/bin/sail` to your commands and use `sail` instead:

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```

### Fastest Path (Most users)

```bash
composer install
cp .env.example .env
```

Add your OpenAI key to `.env`:

```ini
OPENAI_API_KEY=sk-...
```

You can create the key in your OpenAI dashboard: `https://platform.openai.com/api-keys`.

Then run:

```bash
npm run setup
```

Notes:

- `npm run setup` uses Sail and will:
  - recreate containers
  - wait for Postgres readiness
  - generate app key
  - run migrations + seeders
  - install npm deps
  - start Vite dev server
- `npm run setup` is an alias to `npm run setup:dev`.
- `npm run setup` runs `sail down -v` internally, so it resets local DB volume data.
- Keep that terminal running while developing frontend.
- For a one-time production-style asset build instead of dev server, use `npm run setup:build`.

### First Login (Seeded Users)

The seeders create multiple accounts. Password for demo users is:

- `password`

Examples:

- `owner@acme.test`
- `manager@acme.test`
- `support@acme.test`
- `security@acme.test`
- `finance@acme.test`
- `viewer@acme.test`
- `test@example.com`

## OpenAI API Key (Required)

By default, this app generates embeddings with Laravel AI provider `openai` (`config/ai.php`).

You should set `OPENAI_API_KEY` before creating/editing knowledge content because indexing jobs call the embeddings API.

Without `OPENAI_API_KEY`:

- `SyncKnowledgeItemIndex` jobs will fail/retry when new embeddings are needed.
- semantic vector retrieval quality drops or fails for new/updated content.
- MCP search can still use lexical fallback, but you lose the main dense retrieval path.

## Manual Setup (Deterministic)

Use this when you want explicit control over each step.

```bash
composer install
cp .env.example .env
sail up -d
sail composer install
sail artisan key:generate
sail artisan migrate --seed
sail npm install
```

Then run the two long-lived processes in separate terminals:

```bash
sail npm run dev
```

```bash
sail artisan queue:listen --tries=1
```

Open:

- App: `http://localhost`
- MCP endpoint: `http://localhost/mcp/knowledge`

## How to Operate Day to Day

### Core Container Lifecycle

```bash
sail up -d
sail stop
sail down
sail down -v
```

Use `down -v` only when you intentionally want to reset DB volume data.

### Typical Development Loop

1. Start containers: `sail up -d`
2. Start queue worker: `sail artisan queue:listen --tries=1`
3. Start Vite: `sail npm run dev`
4. Build/test changes as needed (commands below)

### Route Changes (Wayfinder)

If backend routes/actions changed and TypeScript route helpers are stale:

```bash
sail artisan wayfinder:generate --with-form --no-interaction
```

## MCP Usage and Authentication

MCP route is defined in `routes/ai.php` at `/mcp/knowledge`.

### 1) Enable auth mode for Claude Code

In `.env`:

```ini
KB_MCP_REQUIRE_AUTH=true
```

Then reload config:

```bash
sail artisan config:clear
```

### 2) Generate token in the app

Create/revoke tokens from:

- `Settings -> Token settings` (`/settings/mcp-token`)

Token behavior:

- plaintext token is shown once after creation
- copy it immediately and store securely
- if lost, revoke and generate a new token

### 3) Configure Claude Code MCP

Claude Code now recommends HTTP transport for remote MCP servers.
Older examples may say "project" scope; current Claude Code CLI uses `--scope local` for project-local setup.

CLI option:

```bash
export KB_MCP_TOKEN="paste-token-from-settings-page"
claude mcp add --transport http knowledge-base http://localhost/mcp/knowledge --header "Authorization: Bearer $KB_MCP_TOKEN" --scope local
```

Shared project config option (`.mcp.json`):

```json
{
  "mcpServers": {
    "knowledge-base": {
      "type": "http",
      "url": "http://localhost/mcp/knowledge",
      "headers": {
        "Authorization": "Bearer ${KB_MCP_TOKEN}"
      }
    }
  }
}
```

If you use `.mcp.json`, set `KB_MCP_TOKEN` in your shell before launching Claude Code.

## Search and Indexing Behavior

### Retrieval Pipeline

`search_knowledge_base` uses a hybrid pipeline:

1. Dense retrieval (`pgvector`)
2. Sparse strict retrieval (FTS)
3. Sparse relaxed fallback (prefix tsquery)
4. Sparse fuzzy fallback (`pg_trgm` similarity)
5. Weighted RRF fusion
6. Optional AI rerank

### Indexing Pipeline

- Writes to items/code/resources trigger observers.
- Observers bump `index_version` and queue `SyncKnowledgeItemIndex`.
- Job rebuilds chunks and embeddings for the item atomically.

Important operational requirement:

- Keep a queue worker running, or new/updated content will not be searchable immediately.

### Reranking

To disable paid reranking APIs:

```ini
KB_ENABLE_AI_RERANK=false
```

### Lexical-Heavy Retrieval Mode (Optional)

If you want retrieval to rely mostly on lexical matching, disable dense retrieval:

```ini
KB_SEARCH_V2_ENABLED=false
KB_DENSE_K=0
```

If you keep `KB_SEARCH_V2_ENABLED=true`, also set profile dense values to `0`:

```ini
KB_V2_SINGLE_DENSE_K=0
KB_V2_SHORT_DENSE_K=0
KB_V2_LONG_DENSE_K=0
```

Important:

- these flags change retrieval behavior
- indexing still attempts embedding generation for new/updated chunks
- keep an embedding provider key configured (OpenAI by default), or customize the indexing job for your environment

## Configuration Reference

Main knobs live in:

- `config/knowledge.php`
- `config/ai.php`
- `.env`

High-impact env vars:

- Retrieval/indexing:
  - `KB_CHUNK_SIZE`
  - `KB_CHUNK_OVERLAP`
  - `KB_EMBED_DIMS`
  - `KB_MIN_SIMILARITY`
  - `KB_DENSE_K`
  - `KB_SPARSE_K`
  - `KB_FUSED_K`
  - `KB_RRF_K`
  - `KB_RERANK_K`
  - `KB_ENABLE_AI_RERANK`
  - `KB_SEARCH_V2_ENABLED`
- Response limits:
  - `KB_ITEMS_LIMIT`
  - `KB_MAX_CHUNKS_PER_ITEM`
  - `KB_MAX_CODE_PER_ITEM`
  - `KB_MAX_RES_PER_ITEM`
- MCP auth:
  - `KB_MCP_REQUIRE_AUTH`
  - `KB_MCP_DEFAULT_USER_ID`
- AI providers:
  - `OPENAI_API_KEY` (required by default for embeddings)
  - `COHERE_API_KEY`
  - `GEMINI_API_KEY`
  - others in `.env.example`

After env changes:

```bash
sail artisan config:clear
```

## Testing, Linting, and Build

### PHP formatting

```bash
sail bin pint --dirty --format agent
```

### Frontend lint

```bash
sail npm run lint
```

### Build assets

```bash
sail npm run build
```

### Run tests (compact)

```bash
sail artisan test --compact
```

### Run a specific test file

```bash
sail artisan test --compact tests/Feature/KnowledgeSearchServiceRetrievalTest.php
```

### Evaluate search quality (before/after v2)

```bash
sail artisan knowledge:search-eval --user-id=1 --limit=10
```

## Troubleshooting

### MCP returns `Unauthorized`

- Check `KB_MCP_REQUIRE_AUTH`.
- If `true`, ensure valid Sanctum token in `Authorization` header (generate from `Settings -> Token settings`).
- If `false`, ensure `KB_MCP_DEFAULT_USER_ID` is a valid user.

### Frontend changes do not appear

Run one of:

- `sail npm run dev` (recommended during development)
- `sail npm run build`

### Vite manifest error (`Unable to locate file in Vite manifest`)

Run:

```bash
sail npm run build
```

Or keep `sail npm run dev` running.

### DB reset for clean local state

```bash
sail down -v
sail up -d
sail artisan migrate --seed
```

### Windows performance issues

Run inside WSL filesystem, not `/mnt/c/...`.

## Additional Documentation

- Full architecture and internals: `ARCHITECTURE.md`
- Team/agent instructions: `AGENTS.md`
