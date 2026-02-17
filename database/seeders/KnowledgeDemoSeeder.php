<?php

namespace Database\Seeders;

use App\Models\CodeExample;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;
use Illuminate\Database\Seeder;

class KnowledgeDemoSeeder extends Seeder
{
    /**
     * Seed the knowledge base database.
     */
    public function run(): void
    {
        $user = User::query()->where('email', 'test@example.com')->first()
            ?? User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]); 

        $item1 = KnowledgeItem::query()->create([
            'slug' => 'pgvector-hnsw-basics',
            'title' => 'pgvector HNSW basics',
            'content_markdown' => <<<MD
# HNSW in pgvector

HNSW indexes make vector search fast.

## Cosine similarity

Use cosine similarity for semantic retrieval.
MD,
            'category' => 'db',
            'tags' => ['pgvector', 'hnsw', 'cosine'],
            'status' => 'published',
            'published_at' => now(),
            'source' => 'human',
            'created_by' => $user->id, 
        ]);

        $item2 = KnowledgeItem::query()->create([
            'slug' => 'laravel-mcp-bearer-auth',
            'title' => 'Laravel MCP bearer auth (Sanctum)',
            'content_markdown' => <<<MD
# MCP auth

Protect MCP HTTP route with `auth:sanctum`.

Tools must scope data by `created_by`.
MD,
            'category' => 'laravel',
            'tags' => ['mcp', 'sanctum', 'bearer'],
            'status' => 'published',
            'published_at' => now(),
            'source' => 'human',
            'created_by' => $user->id, 
        ]);

        CodeExample::query()->create([
            'knowledge_item_id' => $item2->id,
            'sort_order' => 0,
            'title' => 'routes/ai.php MCP route',
            'language' => 'php',
            'filename' => 'routes/ai.php',
            'description' => 'Register MCP server and protect with Sanctum.',
            'code' => "<?php\n\nuse App\\Mcp\\Servers\\KnowledgeBaseServer;\nuse Laravel\\Mcp\\Facades\\Mcp;\n\nMcp::web('/mcp/knowledge', KnowledgeBaseServer::class)\n    ->middleware('auth:sanctum');\n",
        ]);

        KnowledgeResource::query()->create([
            'knowledge_item_id' => $item1->id,
            'sort_order' => 0,
            'type' => 'link',
            'label' => 'pgvector (overview)',
            'url' => 'https://github.com/pgvector/pgvector',
            'extracted_text' => "pgvector adds vector similarity search to Postgres. HNSW is a fast ANN index.",
            'extracted_at' => now(),
        ]);
    }
}
