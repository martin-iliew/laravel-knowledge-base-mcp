<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('code_examples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_item_id')->constrained('knowledge_items')->cascadeOnDelete();
            $table->index('knowledge_item_id');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title')->nullable();
            $table->string('language', 50);

            $table->string('filename')->nullable();
            $table->text('description')->nullable();

            $table->longText('code');
            $table->char('code_hash', 64)->nullable()->index();
            $table->timestamp('chunked_at')->nullable();

            $table->timestamps();

            $table->index(['knowledge_item_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_examples');
    }
};
