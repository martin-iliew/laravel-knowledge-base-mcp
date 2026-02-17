# AI Knowledge Base with MCP

AI-searchable knowledge base for storing and retrieving integration patterns, exposed to Claude Code via the Model Context Protocol (MCP).

This project uses **Laravel Sail (Docker)** for the development environment. PostgreSQL + pgvector run inside Docker, so no local PostgreSQL installation is required.

---

## What this does

- Store “integration patterns” as knowledge entries (Markdown + tags + metadata).
- Generate vector embeddings for entries and run semantic search via `pgvector`.
- Expose tools over MCP so AI agents (Claude Code) can query the knowledge base.

---

## Tech stack

- **Backend**: Laravel 12
- **MCP**: Laravel MCP
- **Frontend**: Inertia.js + React + shadcn/ui
- **Database**: PostgreSQL 16  + `pgvector`

---

## Features

### Knowledge Base
- CRUD articles (markdown, tags, categories)
- Categories + tags
- Optional code examples / resources (project-defined)
- Semantic vector search with `pgvector` (`vector(1536)`)

### MCP tools
- `search_knowledge_base`: vector search returning relevant knowledge entries (and related code/resources if implemented)
- `create_knowledge_entry`: optional tool to create new entries (if implemented)

---

## Requirements 

- Docker + Docker Compose
- PHP + Composer (only to install dependencies and run Sail)

> You do **not** need local PostgreSQL. Sail runs Postgres + pgvector in Docker.

---
## Important (Windows + WSL)

If you're on Windows, **clone and run the project inside WSL** (Linux filesystem), not inside `C:\...`.

Running Sail/Laravel from `/mnt/c` is often **extremely slow** due to filesystem bind-mount performance. Cloning inside WSL avoids that.

Example (inside WSL):
```bash
mkdir -p ~/Projects
cd ~/Projects
git clone https://github.com/martin-iliew/laravel-knowledge-base-mcp
cd laravel-knowledge-base-mcp
```
## Setup

### 1) Install dependencies
```bash
composer install
```
### 2) Configure `.env`

Sail runs Postgres in a separate Docker container. From the Laravel container, the database host is the Docker service name `pgsql`:

```ini
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel_kb
DB_USERNAME=sail
DB_PASSWORD=secret
``` 

Create your local environment file with command:
```bash
cp .env.example .env
```

### 3) Start the containers
#### Optional: Sail alias (recommended)

Run this so you dont need to add `./vendor/bin/sail` to your commands and use `sail` instead:

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```
### Fast setup (recommended)
```bash
npm run setup
```
### Manual setup (step-by-step)
### 1) Start the containers:
```bash
sail up -d
```

### 2) App key + migrations + seed
```bash
sail artisan key:generate
sail artisan migrate --seed
```

### 3) Install frontend deps + build assets
```bash
sail npm ci
sail npm run build
```

Open:
- http://localhost

---

## Claude Code MCP auth

If Claude Code returns `Unauthorized`, you have two options:

### Local development (recommended)
Use MCP without Sanctum auth:

```ini
KB_MCP_REQUIRE_AUTH=false
KB_MCP_DEFAULT_USER_ID=1
```

Then clear cached config:

```bash
sail artisan config:clear
```

### Secure mode (Sanctum)
Keep auth enabled:

```ini
KB_MCP_REQUIRE_AUTH=true
```

Create a personal access token and use it as `Authorization: Bearer <token>` in your MCP client:

```bash
sail artisan tinker --execute="echo \App\Models\User::findOrFail(1)->createToken('claude-code')->plainTextToken;"
```

---

## Free reranking mode (no Cohere key)

This project now defaults to a free fallback that does not call paid reranking APIs.

```ini
KB_ENABLE_AI_RERANK=false
```

With that setting, search uses hybrid retrieval + RRF ordering and skips Cohere reranking.

---

## Search ranking + response contract

`search_knowledge_base` uses a hybrid pipeline:

1. Dense retrieval (`pgvector` similarity)
2. Sparse retrieval (Postgres FTS on `chunk_tsv`)
3. Reciprocal Rank Fusion (RRF)
4. Optional AI reranking of fused candidates

AI reranking runs only when both are true:
- `KB_ENABLE_AI_RERANK=true`
- A valid API key exists for `ai.default_for_reranking` (default provider is `cohere`)

If reranking is disabled or errors, the tool falls back to fused (RRF) ordering.

### Snippet `score` behavior

- `score` is always present in each snippet.
- `score` is a float when AI reranking ran successfully.
- `score` is `null` when reranking did not run (or fallback was used).

### Snippet ordering

- When reranked: snippets are ordered by rerank `score` descending.
- When not reranked: snippets are ordered by fused (RRF) rank.

### Timestamp format

- `item.updated_at` is returned in ISO8601 format with UTC offset (date-time), e.g.:
  - `2026-02-17T07:53:05+00:00`

### Output schema stability

The MCP tool declares a strict output schema (`withoutAdditionalProperties`) for:
- `item` (id/slug/title/category/tags/updated_at)
- `snippets[]` (source metadata, `score`, `text`)
- `code_examples[]`
- `resources[]`

This is enforced in `app/Mcp/Tools/SearchKnowledgeBaseTool.php`.

---

## Testing

Run tests inside Sail:
```bash
sail test
```

Contract-focused tests for the search response are in:
- `tests/Feature/KnowledgeSearchServiceFormattingTest.php`

