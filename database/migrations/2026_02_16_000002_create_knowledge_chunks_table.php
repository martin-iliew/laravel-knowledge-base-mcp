<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->index('knowledge_item_id');

            $table->enum('source_type', ['article', 'code', 'resource'])->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(['source_type', 'source_id']);

            $table->unsignedInteger('chunk_index');
            $table->enum('chunk_kind', ['markdown', 'code', 'text'])->default('text')->index();

            $table->text('chunk_text');
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));

            $table->char('chunk_hash', 64)->index();
            $table->unsignedInteger('token_count')->nullable();

            $table->vector('embedding', dimensions: 1536)->index();
            $table->string('embedding_model', 100)->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->default(1536);
            $table->timestamp('embedded_at')->nullable();

            $table->unsignedSmallInteger('embedding_attempts')->default(0);
            $table->text('embedding_error')->nullable();

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE knowledge_chunks
            ADD CONSTRAINT knowledge_chunks_source_requires_id
            CHECK (
                (source_type = 'article' AND source_id IS NULL)
                OR
                (source_type IN ('code', 'resource') AND source_id IS NOT NULL)
            )
        ");

        DB::statement("
            CREATE UNIQUE INDEX knowledge_chunks_article_unique
            ON knowledge_chunks (knowledge_item_id, chunk_index)
            WHERE source_type = 'article'
        ");

        DB::statement("
            CREATE UNIQUE INDEX knowledge_chunks_source_unique
            ON knowledge_chunks (source_type, source_id, chunk_index)
            WHERE source_type IN ('code', 'resource') AND source_id IS NOT NULL
        ");

        DB::statement("
            ALTER TABLE knowledge_chunks
            ADD COLUMN chunk_tsv tsvector
            GENERATED ALWAYS AS (to_tsvector('simple', chunk_text)) STORED
        ");

        DB::statement("
            CREATE INDEX knowledge_chunks_chunk_tsv_gin
            ON knowledge_chunks
            USING GIN (chunk_tsv)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS knowledge_chunks_chunk_tsv_gin");
        DB::statement("ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS chunk_tsv");
        DB::statement("DROP INDEX IF EXISTS knowledge_chunks_source_unique");
        DB::statement("DROP INDEX IF EXISTS knowledge_chunks_article_unique");
        Schema::dropIfExists('knowledge_chunks');
    }
};
