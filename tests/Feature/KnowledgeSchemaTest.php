<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('knowledge_chunks has generated tsvector column', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL required.');
    }

    $row = DB::selectOne("
        select data_type, is_generated
        from information_schema.columns
        where table_schema = 'public'
          and table_name = 'knowledge_chunks'
          and column_name = 'chunk_tsv'
        limit 1
    ");

    expect($row)->not->toBeNull();
});

test('rejects invalid knowledge_items chunking settings', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL required.');
    }

    expect(fn () => KnowledgeItem::query()->create([
        'slug' => 'bad-chunking',
        'title' => 'Bad',
        'content_markdown' => 'x',
        'chunk_size' => 100,
        'chunk_overlap' => 100,
    ]))->toThrow(QueryException::class);
});

test('rejects invalid chunk source mapping', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL required.');
    }

    $item = KnowledgeItem::withoutEvents(fn () => KnowledgeItem::query()->create([
        'slug' => 'src-map',
        'title' => 'Src Map',
        'content_markdown' => 'x',
        'status' => 'published',
        'created_by' => User::factory()->create()->id,
    ]));

    expect(fn () => KnowledgeChunk::query()->create([
        'knowledge_item_id' => $item->id,
        'source_type' => 'code',
        'source_id' => null,
        'chunk_index' => 0,
        'chunk_kind' => 'code',
        'chunk_text' => 'echo 1;',
        'meta' => [],
        'chunk_hash' => hash('sha256', 'bad-1'),
        'embedding_dimensions' => 1536,
    ]))->toThrow(QueryException::class);

    expect(fn () => KnowledgeChunk::query()->create([
        'knowledge_item_id' => $item->id,
        'source_type' => 'article',
        'source_id' => 123,
        'chunk_index' => 0,
        'chunk_kind' => 'markdown',
        'chunk_text' => 'x',
        'meta' => [],
        'chunk_hash' => hash('sha256', 'bad-2'),
        'embedding_dimensions' => 1536,
    ]))->toThrow(QueryException::class);
});

test('rejects invalid resource payload', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL required.');
    }

    $item = KnowledgeItem::withoutEvents(fn () => KnowledgeItem::query()->create([
        'slug' => 'res',
        'title' => 'Res',
        'content_markdown' => 'x',
        'status' => 'published',
        'created_by' => User::factory()->create()->id,
    ]));

    expect(fn () => KnowledgeResource::query()->create([
        'knowledge_item_id' => $item->id,
        'type' => 'link',
        'storage_path' => 'files/a.pdf',
    ]))->toThrow(QueryException::class);

    expect(fn () => KnowledgeResource::query()->create([
        'knowledge_item_id' => $item->id,
        'type' => 'file',
        'url' => 'https://example.com',
    ]))->toThrow(QueryException::class);
});
