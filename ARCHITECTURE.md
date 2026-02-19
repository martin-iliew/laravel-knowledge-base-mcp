# Architecture

This document explains how the project is built, how data flows through it, and how to operate it safely.

## 1) System Purpose

The system is a multi-tenant knowledge base for integration patterns (articles, code samples, resources) with:

- web UI for humans (Inertia + React)
- hybrid retrieval (vector + lexical + fuzzy)
- MCP tools so external AI clients can retrieve or create knowledge entries

Important boundary:

- The app is a retrieval and evidence-serving system.
- Final answer generation is performed by the external LLM client consuming MCP tool output.

## 2) Runtime Components

- `laravel.test` container: Laravel app, PHP runtime, queue worker commands, frontend tooling via Sail.
- `pgsql` container: PostgreSQL 16 with `pgvector` and `pg_trgm` extensions.
- Browser client: Inertia React pages under `resources/js/pages`.
- MCP clients: any client calling `/mcp/knowledge` tools.

```mermaid
flowchart LR
    User[Web User] --> UI[Inertia React UI]
    UI --> Web[Laravel Controllers]
    Web --> Search[KnowledgeSearchService]
    Search --> DB[(PostgreSQL + pgvector)]

    Editor[Item/Code/Resource writes] --> Obs[Observers]
    Obs --> Queue[SyncKnowledgeItemIndex Job]
    Queue --> Emb[Laravel AI Embeddings]
    Queue --> DB

    MCPClient[MCP Client] --> MCPRoute[/mcp/knowledge]
    MCPRoute --> MCPTools[Search/Create MCP Tools]
    MCPTools --> Search
    MCPTools --> Web
```

## 3) Core Subsystems

### 3.1 Knowledge Domain

Main models:

- `KnowledgeItem`: primary article object (`title`, `content_markdown`, `category`, `tags`, publishing and indexing metadata).
- `CodeExample`: code snippets attached to a knowledge item.
- `KnowledgeResource`: links/files and optional extracted text attached to a knowledge item.
- `KnowledgeChunk`: normalized retrievable unit used by vector and lexical retrieval.
- `KnowledgeAccountAccess`: owner-to-grantee sharing permissions (`viewer` / `editor`).

### 3.2 MCP Server

Server: `App\Mcp\Servers\KnowledgeBaseServer`

Tools:

- `search_knowledge_base` (read-only, idempotent)
- `create_knowledge_entry`

Route:

- `routes/ai.php` mounts MCP web endpoint at `/mcp/knowledge`.
- Optional Sanctum middleware when `KB_MCP_REQUIRE_AUTH=true`.

### 3.3 Search Engine

Service: `App\Services\KnowledgeSearchService`

Retrieval pipeline:

1. Dense retrieval (pgvector / semantic)
2. Sparse strict retrieval (FTS websearch query)
3. Sparse relaxed fallback (prefix tsquery)
4. Sparse fuzzy fallback (trigram word similarity)
5. Weighted fusion (RRF)
6. Optional AI reranking
7. Assembly into item-level result payload

## 4) Data Model and Constraints

### 4.1 `knowledge_items`

Key fields:

- content: `title`, `content_markdown`, `category`, `tags`
- publishing: `status`, `published_at`
- ownership: `created_by`, `reviewed_by`
- indexing metadata: `content_hash`, `chunked_at`, `chunk_size`, `chunk_overlap`, `embedding_model`, `embedding_dimensions`, `index_version`

Important constraints:

- `chunk_size > 0`
- `chunk_overlap >= 0`
- `chunk_overlap < chunk_size`

### 4.2 `knowledge_chunks`

Each row is a retrievable chunk with source metadata:

- source identity: `source_type` (`article`|`code`|`resource`), `source_id`
- chunk identity: `chunk_index`, `chunk_kind`
- retrieval content: `chunk_text`, `meta`
- vector fields: `embedding` (`vector(1536)` default), `embedding_model`, `embedding_dimensions`
- lexical/fuzzy fields: `title_text`, `heading_path_text`, `tags_text`, `category_text`, generated `search_tsv`

Important constraints/indexes:

- source mapping constraint:
  - article chunks must have `source_id = NULL`
  - code/resource chunks must have `source_id != NULL`
- uniqueness:
  - article: unique by (`knowledge_item_id`, `chunk_index`)
  - code/resource: unique by (`source_type`, `source_id`, `chunk_index`)
- indexes:
  - GIN on `search_tsv`
  - GIN trigram indexes on `title_text`, `heading_path_text`, `tags_text`
  - vector index on `embedding`

### 4.3 Sharing (`knowledge_account_accesses`)

Defines account-level sharing:

- `(owner_user_id, grantee_user_id)` unique
- `permission`: `viewer` or `editor`
- owner cannot grant to self (check constraint)

### 4.4 Attachments

- `code_examples` stores language, file metadata, and `code`.
- `knowledge_resources` stores links/files and optional `extracted_text` for retrieval.
- resource payload constraint enforces either link OR file semantics.

## 5) Ingestion and Indexing Pipeline

Indexing is asynchronous and event-driven.

### 5.1 Triggers

Observers bump index versions and dispatch jobs after commit:

- `KnowledgeItemObserver`
- `CodeExampleObserver`
- `KnowledgeResourceObserver`

### 5.2 Version-Safe Queue Job

Job: `SyncKnowledgeItemIndex` (`ShouldQueue`, `ShouldBeUnique`)

Safety techniques:

- unique key: `knowledge_item_id:index_version`
- pre-check for stale version
- final transaction lock + version re-check before write
- atomic replace of all chunks for the item

### 5.3 Chunk Construction

Sources chunked:

- synthetic header chunk (title/category/tags)
- markdown article sections
- code examples
- extracted resource text

Chunkers:

- `MarkdownChunker`: heading-aware sectioning
- `CodeChunker`: chunking with reduced overlap for oversized code
- `TextChunker`: generic text chunking
- `ChunkPacking` trait: block packing + overlap + newline-aware splits

### 5.4 Embeddings

- Embeddings generated with Laravel AI `Embeddings::for(...)->dimensions(...)`.
- Dimensions default from `KB_EMBED_DIMS`.
- Reuse optimization: existing chunk embeddings reused when `chunk_hash` and dimensions match.
- Embedding provider model recorded per chunk/item for observability.

## 6) Retrieval Pipeline (RAG Retrieval Core)

### 6.1 Query Processing

Inputs:

- query text
- optional `category`
- optional `tags` (AND semantics)
- `includeDrafts`
- scoped `userId`

Adaptive mode (`search_v2`) selects profile by token count:

- single-token query: lexical-heavy
- short query: balanced
- long query: denser/broader

### 6.2 Dense Retrieval

- `whereVectorSimilarTo('embedding', query)` with optional `minSimilarity`.
- Candidate count controlled by `dense_k`.

### 6.3 Sparse Retrieval

Three lexical channels:

1. strict FTS: `websearch_to_tsquery`
2. relaxed FTS: prefix OR tsquery from tokenized query
3. fuzzy trigram similarity across title/heading/tags/category texts

Sparse channels are internally fused with weighted RRF:

- strict: 0.6
- relaxed: 0.25
- fuzzy: 0.15

### 6.4 Hybrid Fusion

Dense and sparse candidate IDs are fused with weighted RRF.

RRF score form:

- `score += weight / (k + rank + 1)`

Weights come from active profile, defaulting to 0.5 / 0.5 (dense/sparse).

### 6.5 Optional AI Reranking

Reranking is enabled only when both are true:

- `KB_ENABLE_AI_RERANK=true`
- API key exists for `ai.default_for_reranking` provider (default `cohere`)

On reranker failures, service falls back to fused order.

### 6.6 Result Assembly

Final response is grouped by knowledge item and includes:

- `item` metadata
- `snippets`
- `code_examples` (related to snippet evidence)
- `resources` (related to snippet evidence)

Ordering behavior:

- if rerank score exists: sort by rerank score desc
- else: sort by fused rank

Caps (latency/cost control):

- max items
- max snippets per item
- max code examples per item
- max resources per item

## 7) Access Control Model

Authorization is enforced in both web controllers and retrieval scope:

- owner has full access
- shared `viewer` has read-only access
- shared `editor` has update access (but not delete owner item)

Retrieval scope resolves owner IDs visible to the requesting user:

- own user ID
- plus owner IDs granted via `knowledge_account_accesses`

This prevents leakage across unrelated owners.

## 8) Web UX Flow

Page: `resources/js/pages/knowledge/base.tsx`

Behavior:

- filters are stateful and auto-submit (debounced) via Inertia router
- no query: show latest accessible items
- with query: call hybrid retrieval and display evidence-backed previews

Filter semantics:

- category: optional exact match
- tags: AND semantics
- include drafts: boolean gate on status/published_at

## 9) MCP Contract and Auth Modes

`search_knowledge_base`:

- strict input schema (`query`, `limit`, optional filters)
- strict output schema with stable field sets (`withoutAdditionalProperties`)

Auth modes:

- `KB_MCP_REQUIRE_AUTH=true`: Sanctum token required
- `KB_MCP_REQUIRE_AUTH=false`: tool can run with default/fallback user scope

Default user resolution for unauthenticated calls:

1. `KB_MCP_DEFAULT_USER_ID` if valid
2. otherwise owner with most knowledge items

## 10) Configuration Surface

Primary config files:

- `config/knowledge.php` (retrieval/indexing knobs)
- `config/ai.php` (provider defaults and keys)
- `.env` (runtime values)

Most important env vars:

- Indexing: `KB_CHUNK_SIZE`, `KB_CHUNK_OVERLAP`, `KB_EMBED_DIMS`
- Hybrid search: `KB_DENSE_K`, `KB_SPARSE_K`, `KB_FUSED_K`, `KB_RRF_K`, `KB_RERANK_K`, `KB_ENABLE_AI_RERANK`, `KB_FTS_CONFIG`
- Adaptive search: `KB_SEARCH_V2_ENABLED` + profile vars (`KB_V2_*`)
- Response caps: `KB_ITEMS_LIMIT`, `KB_MAX_CHUNKS_PER_ITEM`, `KB_MAX_CODE_PER_ITEM`, `KB_MAX_RES_PER_ITEM`
- MCP auth: `KB_MCP_REQUIRE_AUTH`, `KB_MCP_DEFAULT_USER_ID`

## 11) Operations Runbook

### 11.1 Normal Development Runtime

Use Sail:

1. `vendor/bin/sail up -d`
2. `vendor/bin/sail artisan queue:listen --tries=1`
3. `vendor/bin/sail npm run dev`

### 11.2 Why Queue Worker Matters

Without a running worker:

- content writes still succeed
- indexing jobs remain pending
- search may return stale results for changed items

### 11.3 Search Mode Without Paid Reranker

Set:

- `KB_ENABLE_AI_RERANK=false`

Behavior:

- still uses dense+sparse+RRF
- skips external reranking API

### 11.4 Lexical-Only Operation

If you must run without embedding provider access, disable dense retrieval across active profiles.

Recommended minimal approach:

- `KB_SEARCH_V2_ENABLED=false`
- `KB_DENSE_K=0`

Or, if `KB_SEARCH_V2_ENABLED=true`, set each profile dense K to `0`:

- `KB_V2_SINGLE_DENSE_K=0`
- `KB_V2_SHORT_DENSE_K=0`
- `KB_V2_LONG_DENSE_K=0`

### 11.5 Rebuilding Index After Config/Model Changes

Practical approach:

- modify/save items (or attachments) to trigger observer-driven reindex
- ensure queue worker is running

(There is currently no dedicated “reindex all” Artisan command in this repo.)

## 12) Quality and Verification

Key test coverage areas:

- retrieval behavior and shared-scope search
- typo fallback and long-query fallback
- response assembly ordering and contract shape
- schema constraints and invalid-state protection
- web/controller integration with scoped search calls

Useful commands:

- `vendor/bin/sail artisan test --compact`
- `vendor/bin/sail artisan test --compact tests/Feature/KnowledgeSearchServiceRetrievalTest.php`
- `vendor/bin/sail artisan knowledge:search-eval --user-id=1 --limit=10`

## 13) Extension Points

### 13.1 Add New Evidence Source Type

High-level steps:

1. Add source model/table and relation to `KnowledgeItem`.
2. Update ingestion in `SyncKnowledgeItemIndex` to emit chunk specs.
3. Extend `source_type` enum/constraints and uniqueness/index rules in migrations.
4. Update assembler to attach source payload to search result.
5. Add tests for ingestion + retrieval + authorization scope.

### 13.2 Tune Ranking Strategy

- adjust `KB_*` knobs in `config/knowledge.php`
- tune query profile weights/k values
- run `knowledge:search-eval` before/after changes

### 13.3 Add MCP Tools

- register tool class in `KnowledgeBaseServer`
- define strict schema and stable output contract
- enforce auth and tenant scoping consistently

## 14) Known Tradeoffs

- Indexing is asynchronous: write latency is low, but freshness depends on queue health.
- Dense retrieval quality depends on embedding model/key availability.
- No first-class full reindex command yet; operationally handled via re-save events.
- Result assembly is item-centric (good for UI and MCP evidence bundles), not raw chunk list output.

## 15) File Map (Primary)

- Search service: `app/Services/KnowledgeSearchService.php`
- Index job: `app/Jobs/SyncKnowledgeItemIndex.php`
- Chunkers: `app/Services/Chunking/*`
- Observers: `app/Observers/*`
- MCP server/tools: `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*`
- Web search controller: `app/Http/Controllers/Knowledge/KnowledgeBaseController.php`
- Search page: `resources/js/pages/knowledge/base.tsx`
- Retrieval config: `config/knowledge.php`
- AI provider config: `config/ai.php`
- MCP route: `routes/ai.php`
