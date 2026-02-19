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
        Schema::create('knowledge_account_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('permission', ['viewer', 'editor'])->default('viewer');
            $table->timestamps();

            $table->unique(['owner_user_id', 'grantee_user_id']);
            $table->index(['grantee_user_id', 'permission']);
        });

        DB::statement('
            ALTER TABLE knowledge_account_accesses
            ADD CONSTRAINT knowledge_account_accesses_owner_not_grantee
            CHECK (owner_user_id <> grantee_user_id)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_account_accesses');
    }
};
