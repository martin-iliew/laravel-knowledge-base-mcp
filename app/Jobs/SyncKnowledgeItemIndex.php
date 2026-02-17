<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Chunking\CodeChunker;
use App\Services\Chunking\MarkdownChunker;
use App\Services\Chunking\TextChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Embeddings;

class SyncKnowledgeItemIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $knowledgeItemId,
        public int $expectedIndexVersion
    ) {}

    public function handle(
        DatabaseManager $db,
        MarkdownChunker $markdown,
        CodeChunker $code,
        TextChunker $text
    ): void {
        $item = KnowledgeItem::query()
            ->with(['codeExamples', 'resources'])
            ->find($this->knowledgeItemId);

        if (!$item) {
            return;
        }

        if ((int) $item->index_version !== (int) $this->expectedIndexVersion) {
            return;
        }

        $chunkSize = (int) ($item->chunk_size ?: config('knowledge.defaults.chunk_size'));
        $overlap = (int) ($item->chunk_overlap ?: config('knowledge.defaults.chunk_overlap'));
        $dims = (int) config('knowledge.defaults.embedding_dimensions');

        $specs = [];

        $specs[] = $this->makeSpec(
            knowledgeItemId: $item->id,
            sourceType: 'article',
            sourceId: null,
            chunkIndex: 0,
            chunkKind: 'text',
            chunkText: $this->syntheticHeader($item),
            meta: ['kind' => 'synthetic_header'],
            embedText: $this->contextualEmbedText($item, ['kind' => 'synthetic_header'], $this->syntheticHeader($item))
        );

        foreach ($markdown->chunk($item->content_markdown, $chunkSize, $overlap) as $i => $c) {
            $specs[] = $this->makeSpec(
                $item->id,
                'article',
                null,
                $i + 1,
                'markdown',
                $c['text'],
                $c['meta'],
                $this->contextualEmbedText($item, $c['meta'], $c['text'])
            );
        }

        foreach ($item->codeExamples as $ex) {
            foreach ($code->chunk($ex->code, $chunkSize, $overlap) as $i => $c) {
                $meta = array_merge($c['meta'], [
                    'language' => $ex->language,
                    'title' => $ex->title,
                    'filename' => $ex->filename,
                ]);

                $specs[] = $this->makeSpec(
                    $item->id,
                    'code',
                    (int) $ex->id,
                    $i,
                    'code',
                    $c['text'],
                    $meta,
                    $this->contextualEmbedText($item, $meta, $c['text'])
                );
            }
        }

        foreach ($item->resources as $r) {
            if (!$r->extracted_text) {
                continue;
            }

            foreach ($text->chunk($r->extracted_text, $chunkSize, $overlap) as $i => $c) {
                $meta = array_merge($c['meta'], [
                    'label' => $r->label,
                    'url' => $r->url,
                    'mime' => $r->mime,
                ]);

                $specs[] = $this->makeSpec(
                    $item->id,
                    'resource',
                    (int) $r->id,
                    $i,
                    'text',
                    $c['text'],
                    $meta,
                    $this->contextualEmbedText($item, $meta, $c['text'])
                );
            }
        }

        if ($specs === []) {
            return;
        }

        $hashes = array_values(array_unique(array_map(fn ($s) => $s['chunk_hash'], $specs)));

        $existing = KnowledgeChunk::query()
            ->where('knowledge_item_id', $item->id)
            ->whereIn('chunk_hash', $hashes)
            ->get(['chunk_hash', 'embedding', 'embedded_at', 'embedding_model', 'embedding_dimensions'])
            ->keyBy('chunk_hash');

        $toEmbedTexts = [];
        $toEmbedIndexes = [];

        foreach ($specs as $idx => $s) {
            $prev = $existing->get($s['chunk_hash']);
            if ($prev && $prev->embedding && (int) $prev->embedding_dimensions === $dims) {
                $specs[$idx]['embedding'] = $prev->embedding;
                $specs[$idx]['embedded_at'] = $prev->embedded_at;
                $specs[$idx]['embedding_dimensions'] = (int) $prev->embedding_dimensions;
                $specs[$idx]['embedding_model'] = $prev->embedding_model ? (string) $prev->embedding_model : null;         

                continue;
            }

            $toEmbedIndexes[] = $idx;
            $toEmbedTexts[] = $s['embed_text'];
        }

        if ($toEmbedTexts !== []) {
            $response = Embeddings::for($toEmbedTexts)->dimensions($dims)->generate();
            $vectors = $response->embeddings;

            foreach ($toEmbedIndexes as $j => $specIndex) {
                $vector = $vectors[$j] ?? null;
                if (!is_array($vector) || $vector === []) {
                    throw new \RuntimeException("Embedding response missing vector at index {$j}.");
                }

                $specs[$specIndex]['embedding'] = $this->vectorLiteral($vector);
                $specs[$specIndex]['embedded_at'] = now();
                $specs[$specIndex]['embedding_dimensions'] = $dims;
                $specs[$specIndex]['embedding_model'] = null;
            }
        }

        $db->transaction(function () use ($item, $specs) {
            KnowledgeChunk::query()->where('knowledge_item_id', $item->id)->delete();

            $insert = array_map(function (array $s) {
                unset($s['embed_text']);
                if (is_array($s['embedding'])) {
                    $s['embedding'] = $this->vectorLiteral($s['embedding']);
                }

                return $s;
            }, $specs);

            KnowledgeChunk::query()->insert($insert);

            $item->forceFill([
                'chunked_at' => now(),
                'embedding_model' => $this->firstNonEmptyEmbeddingModel($insert),
                'embedding_dimensions' => (int) config('knowledge.defaults.embedding_dimensions'),
            ])->saveQuietly();
        });
    }
    private function vectorLiteral(array $v): string
    {
        $nums = array_map(function ($x) {
            if ($x === null) return '0';
            if (is_int($x) || is_float($x)) return (string) $x;
            return (string) (float) $x;
        }, $v);

        return '[' . implode(',', $nums) . ']';
    }
    private function firstNonEmptyEmbeddingModel(array $specs): ?string
    {
        foreach ($specs as $s) {
            if (!empty($s['embedding_model'])) {
                return (string) $s['embedding_model'];
            }
        }

        return null;
    }

    private function syntheticHeader(KnowledgeItem $item): string
    {
        $tags = is_array($item->tags) ? implode(', ', $item->tags) : '';
        return trim("Title: {$item->title}\nCategory: {$item->category}\nTags: {$tags}");
    }

    private function contextualEmbedText(KnowledgeItem $item, array $meta, string $body): string
    {
        $tags = is_array($item->tags) ? implode(', ', $item->tags) : '';
        $heading = '';

        if (!empty($meta['heading_path']) && is_array($meta['heading_path'])) {
            $heading = implode(' > ', $meta['heading_path']);
        }

        $prefix = "Title: {$item->title}\nCategory: {$item->category}\nTags: {$tags}";
        if ($heading !== '') {
            $prefix .= "\nSection: {$heading}";
        }

        if (!empty($meta['language'])) {
            $prefix .= "\nLanguage: {$meta['language']}";
        }

        if (!empty($meta['filename'])) {
            $prefix .= "\nFile: {$meta['filename']}";
        }

        if (!empty($meta['label'])) {
            $prefix .= "\nResource: {$meta['label']}";
        }

        return trim($prefix . "\n\n" . trim($body));
    }

    private function makeSpec(
        int $knowledgeItemId,
        string $sourceType,
        ?int $sourceId,
        int $chunkIndex,
        string $chunkKind,
        string $chunkText,
        array $meta,
        string $embedText
    ): array {
        $text = trim($chunkText);

        return [
            'knowledge_item_id' => $knowledgeItemId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'chunk_index' => $chunkIndex,
            'chunk_kind' => $chunkKind,
            'chunk_text' => $text,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'chunk_hash' => hash('sha256', $sourceType.'|'.(string) $sourceId.'|'.$chunkIndex.'|'.$chunkKind.'|'.$embedText),
            'token_count' => null,
            'embedding' => null,
            'embedding_model' => null,
            'embedding_dimensions' => (int) config('knowledge.defaults.embedding_dimensions'),
            'embedded_at' => null,
            'embedding_attempts' => 0,
            'embedding_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'embed_text' => $embedText,
        ];
    }
}
