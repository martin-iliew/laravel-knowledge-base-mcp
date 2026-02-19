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

### Fastest Path

```bash
composer install
cp .env.example .env
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
- Keep that terminal running while developing frontend.

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

## Manual Setup (Deterministic)

Use this when you want explicit control over each step.

```bash
composer install
cp .env.example .env
vendor/bin/sail up -d
vendor/bin/sail composer install
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed
vendor/bin/sail npm install
```

Then run the two long-lived processes in separate terminals:

```bash
vendor/bin/sail npm run dev
```

```bash
vendor/bin/sail artisan queue:listen --tries=1
```

Open:

- App: `http://localhost`
- MCP endpoint: `http://localhost/mcp/knowledge`

## How to Operate Day to Day

### Core Container Lifecycle

```bash
vendor/bin/sail up -d
vendor/bin/sail stop
vendor/bin/sail down
vendor/bin/sail down -v
```

Use `down -v` only when you intentionally want to reset DB volume data.

### Typical Development Loop

1. Start containers: `vendor/bin/sail up -d`
2. Start queue worker: `vendor/bin/sail artisan queue:listen --tries=1`
3. Start Vite: `vendor/bin/sail npm run dev`
4. Build/test changes as needed (commands below)

### Route Changes (Wayfinder)

If backend routes/actions changed and TypeScript route helpers are stale:

```bash
vendor/bin/sail artisan wayfinder:generate --with-form --no-interaction
```

## MCP Usage and Authentication

MCP route is defined in `routes/ai.php` at `/mcp/knowledge`.

### Authenticated MCP 
Create/revoke tokens from the app settings page:

- `Settings -> MCP Token`

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

### Lexical-Only Mode (No Embedding Provider)

If you intentionally run without embedding provider keys, disable dense retrieval:

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
- AI providers (depending on your chosen providers):
  - `OPENAI_API_KEY`
  - `COHERE_API_KEY`
  - `GEMINI_API_KEY`
  - others in `.env.example`

After env changes:

```bash
vendor/bin/sail artisan config:clear
```

## Testing, Linting, and Build

### PHP formatting

```bash
vendor/bin/sail bin pint --dirty --format agent
```

### Frontend lint

```bash
vendor/bin/sail npm run lint
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
- If `true`, ensure valid Sanctum token in `Authorization` header.
- If `false`, ensure `KB_MCP_DEFAULT_USER_ID` is a valid user.

### Frontend changes do not appear

Run one of:

- `vendor/bin/sail npm run dev` (recommended during development)
- `vendor/bin/sail npm run build`

### Vite manifest error (`Unable to locate file in Vite manifest`)

Run:

```bash
vendor/bin/sail npm run build
```

Or keep `vendor/bin/sail npm run dev` running.

### DB reset for clean local state

```bash
vendor/bin/sail down -v
vendor/bin/sail up -d
vendor/bin/sail artisan migrate --seed
```

### Windows performance issues

Run inside WSL filesystem, not `/mnt/c/...`.

## Additional Documentation

- Full architecture and internals: `ARCHITECTURE.md`
- Team/agent instructions: `AGENTS.md`

