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
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();
            $table->text('title');
            $table->longText('content_markdown');

            $table->string('category')->nullable()->index();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();
            $table->enum('source', ['human', 'ai'])->default('human')->index();
            $table->timestamp('published_at')->nullable()->index();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->char('content_hash', 64)->nullable()->index();
            $table->timestamp('chunked_at')->nullable();

            $table->unsignedInteger('chunk_size')->default(1000);
            $table->unsignedInteger('chunk_overlap')->default(200);
            $table->string('embedding_model', 100)->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->default(1536);

            $table->unsignedInteger('index_version')->default(1);

            $table->timestamps();

            $table->fullText(['title', 'content_markdown']);
        });

        DB::statement("CREATE INDEX knowledge_items_tags_gin ON knowledge_items USING GIN (tags)");

        DB::statement("
            ALTER TABLE knowledge_items
            ADD CONSTRAINT knowledge_items_chunking_valid
            CHECK (chunk_size > 0 AND chunk_overlap >= 0 AND chunk_overlap < chunk_size)
        ");
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
