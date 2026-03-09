<?php

namespace App\Console\Commands;

use App\Services\Embeddings\EmbeddingDimensionResolver;
use App\Services\Embeddings\EmbeddingManager;
use Illuminate\Console\Command;

class EmbeddingTestCommand extends Command
{
    protected $signature = 'kb:embedding-test
        {text? : Text to embed (defaults to a sample sentence)}
        {--dimensions= : Override embedding dimensions}';

    protected $description = 'Smoke-test the active embedding driver with a sample passage and query';

    public function handle(EmbeddingManager $manager): int
    {
        $dims = (int) ($this->option('dimensions') ?: app(EmbeddingDimensionResolver::class)->resolve());
        $text = $this->argument('text') ?: 'Laravel is a PHP framework for building web applications.';
        $driver = config('knowledge.embedding.driver', 'laravel-ai');

        $this->info("Driver:     {$driver}");
        $this->info("Dimensions: {$dims}");
        $this->newLine();

        // 1. Test indexing (passage) embedding
        $this->info('Testing passage embedding...');

        try {
            $result = $manager->embedForIndexing([$text], $dims);
            $vector = $result->embeddings[0] ?? null;

            if (! is_array($vector) || $vector === []) {
                $this->error('Passage embedding returned empty vector.');

                return self::FAILURE;
            }

            $this->line('  Model:      '.($result->model ?? '(none)'));
            $this->line('  Vector dim: '.count($vector));
            $this->line('  First 5:    ['.implode(', ', array_map(fn ($v) => round($v, 6), array_slice($vector, 0, 5))).', ...]');
        } catch (\Throwable $e) {
            $this->error("Passage embedding failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();

        // 2. Test query embedding
        $this->info('Testing query embedding...');

        try {
            $queryVector = $manager->embedQuery($text, $dims);

            $this->line('  Vector dim: '.count($queryVector));
            $this->line('  First 5:    ['.implode(', ', array_map(fn ($v) => round($v, 6), array_slice($queryVector, 0, 5))).', ...]');
        } catch (\Throwable $e) {
            $this->error("Query embedding failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();

        // 3. Cosine similarity sanity check (same text should be high but not identical due to different prefixes)
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < min(count($vector), count($queryVector)); $i++) {
            $dot += $vector[$i] * $queryVector[$i];
            $normA += $vector[$i] ** 2;
            $normB += $queryVector[$i] ** 2;
        }

        $similarity = ($normA > 0 && $normB > 0) ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;

        $this->info('Cosine similarity (passage vs query, same text): '.round($similarity, 4));

        if ($similarity > 0.5) {
            $this->info('Looks good — embeddings are semantically consistent.');
        } else {
            $this->warn('Similarity is low — verify the server is running and model is loaded.');
        }

        return self::SUCCESS;
    }
}
