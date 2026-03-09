<?php

use App\Models\KnowledgeChunk;
use Database\Seeders\KnowledgeDemoSeeder;
use Illuminate\Support\Facades\DB;

test('knowledge demo seeder uses storage vector dimensions when config dimensions drift', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL required.');
    }

    $row = DB::selectOne(
        <<<'SQL'
            select format_type(a.atttypid, a.atttypmod) as type_name
            from pg_attribute a
            join pg_class c on c.oid = a.attrelid
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = current_schema()
              and c.relname = ?
              and a.attname = ?
              and a.attnum > 0
              and not a.attisdropped
            limit 1
        SQL,
        ['knowledge_chunks', 'embedding']
    );

    expect($row)->not->toBeNull();
    expect(isset($row->type_name))->toBeTrue();

    $matched = preg_match('/^vector\((\d+)\)$/', (string) $row->type_name, $matches);

    expect($matched)->toBe(1);

    $storageDimensions = (int) ($matches[1] ?? 0);
    config()->set('knowledge.defaults.embedding_dimensions', $storageDimensions + 512);

    $this->seed(KnowledgeDemoSeeder::class);

    $firstChunk = KnowledgeChunk::query()->first();

    expect($firstChunk)->not->toBeNull();
    expect((int) $firstChunk->embedding_dimensions)->toBe($storageDimensions);
});
