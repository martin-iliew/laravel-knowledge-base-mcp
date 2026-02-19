<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE knowledge_chunks AS kc
            SET
                title_text = ki.title,
                category_text = COALESCE(ki.category, ''),
                tags_text = COALESCE((
                    SELECT string_agg(trim(tag_value), ' ')
                    FROM jsonb_array_elements_text(COALESCE(ki.tags, '[]'::jsonb)) AS tag_value
                ), ''),
                heading_path_text = COALESCE((
                    CASE
                        WHEN jsonb_typeof(kc.meta->'heading_path') = 'array' THEN
                            (
                                SELECT string_agg(trim(heading_value), ' > ')
                                FROM jsonb_array_elements_text(kc.meta->'heading_path') AS heading_value
                            )
                        ELSE ''
                    END
                ), '')
            FROM knowledge_items AS ki
            WHERE ki.id = kc.knowledge_item_id
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfill migration intentionally has no destructive rollback.
    }
};
