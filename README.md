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
- Node.js (optional locally; you can also run npm through Sail)

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
sail composer install
sail npm install
```
### 3) Configure `.env` for Sail Postgres

Sail runs Postgres in a separate Docker container. From the Laravel container, the database host is the Docker service name `pgsql` (not `localhost`):

```ini
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel_kb
DB_USERNAME=sail
DB_PASSWORD=secret
```

If your repo already ships these values, keep them. The critical part is `DB_HOST=pgsql` when using Sail.

### 4) Start the containers
#### Optional: Sail alias (recommended)

Run this so you dont need to add `./vendor/bin/sail` to your commands and use `sail` instead:

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```
Start the containers:
```bash
sail up -d
```

### 5) App key + migrations + seed
```bash
sail artisan key:generate
sail artisan migrate --seed

```

### 6) Build assets
```bash
sail npm run build
```

Open:
- http://localhost

---

## Testing

Run tests inside Sail (uses the Docker Postgres service):
```bash
sail test
```



