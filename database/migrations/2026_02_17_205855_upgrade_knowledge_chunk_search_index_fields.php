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
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->text('title_text')->nullable()->after('chunk_text');
            $table->text('heading_path_text')->nullable()->after('title_text');
            $table->text('tags_text')->nullable()->after('heading_path_text');
            $table->text('category_text')->nullable()->after('tags_text');
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement("
            UPDATE knowledge_chunks AS kc
            SET
                title_text = ki.title,
                category_text = COALESCE(ki.category, ''),
                tags_text = COALESCE((
                    SELECT string_agg(tag_value, ' ')
                    FROM jsonb_array_elements_text(COALESCE(ki.tags, '[]'::jsonb)) AS tag_value
                ), ''),
                heading_path_text = COALESCE((
                    CASE
                        WHEN jsonb_typeof(kc.meta->'heading_path') = 'array' THEN
                            (
                                SELECT string_agg(heading_value, ' > ')
                                FROM jsonb_array_elements_text(kc.meta->'heading_path') AS heading_value
                            )
                        ELSE ''
                    END
                ), '')
            FROM knowledge_items AS ki
            WHERE ki.id = kc.knowledge_item_id
        ");

        DB::statement("
            ALTER TABLE knowledge_chunks
            ADD COLUMN search_tsv tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', COALESCE(title_text, '')), 'A')
                ||
                setweight(to_tsvector('simple', COALESCE(heading_path_text, '')), 'A')
                ||
                setweight(to_tsvector('simple', COALESCE(tags_text, '')), 'B')
                ||
                setweight(to_tsvector('simple', COALESCE(category_text, '')), 'B')
                ||
                setweight(to_tsvector('simple', COALESCE(chunk_text, '')), 'C')
            ) STORED
        ");

        DB::statement('
            CREATE INDEX knowledge_chunks_search_tsv_gin
            ON knowledge_chunks
            USING GIN (search_tsv)
        ');

        DB::statement('
            CREATE INDEX knowledge_chunks_title_text_trgm_gin
            ON knowledge_chunks
            USING GIN (title_text gin_trgm_ops)
        ');

        DB::statement('
            CREATE INDEX knowledge_chunks_heading_path_text_trgm_gin
            ON knowledge_chunks
            USING GIN (heading_path_text gin_trgm_ops)
        ');

        DB::statement('
            CREATE INDEX knowledge_chunks_tags_text_trgm_gin
            ON knowledge_chunks
            USING GIN (tags_text gin_trgm_ops)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_tags_text_trgm_gin');
        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_heading_path_text_trgm_gin');
        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_title_text_trgm_gin');
        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_tsv_gin');
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search_tsv');

        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'title_text',
                'heading_path_text',
                'tags_text',
                'category_text',
            ]);
        });
    }
};
