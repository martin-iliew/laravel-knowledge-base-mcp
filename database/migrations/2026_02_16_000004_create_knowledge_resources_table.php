<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_resources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->index('knowledge_item_id');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('type', ['link', 'file']);
            $table->string('label')->nullable();

            $table->text('url')->nullable();

            $table->string('storage_path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->longText('extracted_text')->nullable();
            $table->char('extracted_hash', 64)->nullable()->index();
            $table->timestamp('extracted_at')->nullable();
            $table->unsignedSmallInteger('extract_attempts')->default(0);
            $table->text('extract_error')->nullable();

            $table->timestamps();

            $table->index(['knowledge_item_id', 'sort_order']);
        });

        DB::statement("
            ALTER TABLE knowledge_resources
            ADD CONSTRAINT knowledge_resources_type_requires_payload
            CHECK (
                (type = 'link' AND url IS NOT NULL AND storage_path IS NULL)
                OR
                (type = 'file' AND storage_path IS NOT NULL AND url IS NULL)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_resources');
    }
};
