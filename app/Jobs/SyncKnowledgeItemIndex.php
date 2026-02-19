<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Chunking\CodeChunker;
use App\Services\Chunking\MarkdownChunker;
use App\Services\Chunking\TextChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Embeddings;

/**
 * Rebuild the vector/FTS index for a single KnowledgeItem.
 *
 * Key invariants:
 * - Jobs are unique per (knowledge_item_id, expected_index_version) to avoid duplicate work.
 * - The final write is guarded by a pessimistic lock + version re-check to prevent stale jobs
 *   from overwriting newer indexing results.
 *
 * Pipeline:
 * 1) Load item + related code/resources
 * 2) Build chunk specs (article markdown + code + resource text + synthetic header)
 * 3) Reuse existing embeddings by chunk_hash when dimensions match
 * 4) Generate missing embeddings via Laravel AI
 * 5) Atomically replace KnowledgeChunk rows inside a transaction (version-checked)
 */
class SyncKnowledgeItemIndex implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry settings for transient provider/network failures.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /**
     * Exponential-ish backoff for retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * @param  int  $knowledgeItemId  Target item to (re)index
     * @param  int  $expectedIndexVersion  Version gate; only write if item matches this version
     */
    public function __construct(
        public int $knowledgeItemId,
        public int $expectedIndexVersion
    ) {}

    /**
     * Unique key so duplicates of the same item+version don't pile up.
     */
    public function uniqueId(): string
    {
        return $this->knowledgeItemId.':'.$this->expectedIndexVersion;
    }

    /**
     * Execute indexing. External embedding calls happen outside the DB transaction.
     * DB writes are atomic and guarded by lockForUpdate + version check.
     */
    public function handle(
        DatabaseManager $db,
        MarkdownChunker $markdownChunker,
        CodeChunker $codeChunker,
        TextChunker $textChunker
    ): void {
        $now = now();

        // Embedding dimensions must match what's stored in Postgres vector columns.
        $embeddingDims = (int) config('knowledge.defaults.embedding_dimensions');

        $item = KnowledgeItem::query()
            ->with(['codeExamples', 'resources'])
            ->find($this->knowledgeItemId);

        if (! $item) {
            return;
        }

        // Fast pre-check: skip obviously stale jobs. (Correctness is enforced again under lock.)
        if ((int) $item->index_version !== (int) $this->expectedIndexVersion) {
            return;
        }

        // Per-item overrides fallback to defaults.
        $chunkSize = (int) ($item->chunk_size ?: config('knowledge.defaults.chunk_size'));
        $chunkOverlap = (int) ($item->chunk_overlap ?: config('knowledge.defaults.chunk_overlap'));

        // Chunk specs are normalized rows ready to insert into knowledge_chunks.
        $specs = [];

        // Synthetic header improves retrieval by injecting item-level metadata into the embedding text.
        $headerText = $this->syntheticHeader($item);

        $specs[] = $this->makeSpec(
            knowledgeItemId: $item->id,
            sourceType: 'article',
            sourceId: null,
            chunkIndex: 0,
            chunkKind: 'text',
            chunkText: $headerText,
            meta: ['kind' => 'synthetic_header'],
            embedText: $this->contextualEmbedText($item, ['kind' => 'synthetic_header'], $headerText),
            now: $now,
            embeddingDims: $embeddingDims
        );

        // Article markdown chunks (chunk_index starts at 1 because 0 is reserved for synthetic header).
        foreach ($markdownChunker->chunk((string) $item->content_markdown, $chunkSize, $chunkOverlap) as $i => $chunk) {
            $specs[] = $this->makeSpec(
                $item->id,
                'article',
                null,
                $i + 1,
                'markdown',
                $chunk['text'],
                $chunk['meta'],
                $this->contextualEmbedText($item, $chunk['meta'], $chunk['text']),
                $now,
                $embeddingDims
            );
        }

        // Code example chunks (meta enriches embeddings and later UI payloads).
        foreach ($item->codeExamples as $codeExample) {
            foreach ($codeChunker->chunk((string) $codeExample->code, $chunkSize, $chunkOverlap) as $i => $chunk) {
                $meta = array_merge($chunk['meta'], [
                    'language' => $codeExample->language,
                    'title' => $codeExample->title,
                    'filename' => $codeExample->filename,
                ]);

                $specs[] = $this->makeSpec(
                    $item->id,
                    'code',
                    (int) $codeExample->id,
                    $i,
                    'code',
                    $chunk['text'],
                    $meta,
                    $this->contextualEmbedText($item, $meta, $chunk['text']),
                    $now,
                    $embeddingDims
                );
            }
        }

        // Resource chunks (only extracted_text is embedded).
        foreach ($item->resources as $resource) {
            if (! $resource->extracted_text) {
                continue;
            }

            foreach ($textChunker->chunk((string) $resource->extracted_text, $chunkSize, $chunkOverlap) as $i => $chunk) {
                $meta = array_merge($chunk['meta'], [
                    'label' => $resource->label,
                    'url' => $resource->url,
                    'mime' => $resource->mime,
                ]);

                $specs[] = $this->makeSpec(
                    $item->id,
                    'resource',
                    (int) $resource->id,
                    $i,
                    'text',
                    $chunk['text'],
                    $meta,
                    $this->contextualEmbedText($item, $meta, $chunk['text']),
                    $now,
                    $embeddingDims
                );
            }
        }

        if ($specs === []) {
            return;
        }

        $titleText = (string) $item->title;
        $categoryText = is_string($item->category) ? $item->category : '';
        $tagsText = collect(is_array($item->tags) ? $item->tags : [])
            ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => trim($tag))
            ->implode(' ');

        foreach ($specs as &$spec) {
            $metaPayload = json_decode((string) ($spec['meta'] ?? ''), true);

            if (! is_array($metaPayload)) {
                $metaPayload = [];
            }

            $spec['title_text'] = $titleText;
            $spec['category_text'] = $categoryText;
            $spec['tags_text'] = $tagsText;
            $spec['heading_path_text'] = $this->headingPathTextFromMeta($metaPayload);
        }
        unset($spec);

        // Used for embedding reuse. If chunk_hash matches and dims match, we keep the previous embedding.
        $chunkHashes = array_values(array_unique(array_map(fn ($s) => $s['chunk_hash'], $specs)));

        $existingByHash = KnowledgeChunk::query()
            ->where('knowledge_item_id', $item->id)
            ->whereIn('chunk_hash', $chunkHashes)
            ->get(['chunk_hash', 'embedding', 'embedded_at', 'embedding_model', 'embedding_dimensions'])
            ->keyBy('chunk_hash');

        $textsToEmbed = [];
        $specIndexesToEmbed = [];

        foreach ($specs as $specIndex => $spec) {
            $previous = $existingByHash->get($spec['chunk_hash']);

            // Reuse only when embedding exists AND dimensions match our current config.
            if (
                $previous &&
                $previous->embedding &&
                (int) $previous->embedding_dimensions === $embeddingDims
            ) {
                $specs[$specIndex]['embedding'] = $previous->embedding;
                $specs[$specIndex]['embedded_at'] = $previous->embedded_at;
                $specs[$specIndex]['embedding_dimensions'] = (int) $previous->embedding_dimensions;
                $specs[$specIndex]['embedding_model'] = $previous->embedding_model ? (string) $previous->embedding_model : null;

                continue;
            }

            $specIndexesToEmbed[] = $specIndex;
            $textsToEmbed[] = $spec['embed_text'];
        }

        $embeddingModel = null;

        // Embedding call is intentionally outside the DB transaction.
        if ($textsToEmbed !== []) {
            $response = Embeddings::for($textsToEmbed)->dimensions($embeddingDims)->generate();
            $vectors = $response->embeddings;

            // Provider/model may or may not exist on the response; keep it nullable.
            $embeddingModel = isset($response->model) ? (string) $response->model : null;

            foreach ($specIndexesToEmbed as $j => $specIndex) {
                $vector = $vectors[$j] ?? null;

                if (! is_array($vector) || $vector === []) {
                    throw new \RuntimeException("Embedding response missing vector at index {$j}.");
                }

                // Convert to pgvector literal so insert is stable across drivers.
                $specs[$specIndex]['embedding'] = $this->vectorLiteral($vector);
                $specs[$specIndex]['embedded_at'] = $now;
                $specs[$specIndex]['embedding_dimensions'] = $embeddingDims;
                $specs[$specIndex]['embedding_model'] = $embeddingModel;
            }
        }

        // Remove temporary embed_text field and normalize embedding payload for insert().
        $insertRows = array_map(function (array $spec) {
            unset($spec['embed_text']);

            if (is_array($spec['embedding'])) {
                $spec['embedding'] = $this->vectorLiteral($spec['embedding']);
            }

            return $spec;
        }, $specs);

        // Atomic replace guarded by lockForUpdate + version re-check.
        // This prevents older jobs from overwriting a newer index_version.
        $db->transaction(function () use ($item, $insertRows, $embeddingDims, $now) {
            $currentVersion = (int) KnowledgeItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->value('index_version');

            if ($currentVersion !== (int) $this->expectedIndexVersion) {
                return;
            }

            KnowledgeChunk::query()
                ->where('knowledge_item_id', $item->id)
                ->delete();

            KnowledgeChunk::query()->insert($insertRows);

            KnowledgeItem::query()
                ->whereKey($item->id)
                ->update([
                    'chunked_at' => $now,
                    'embedding_model' => $this->firstNonEmptyEmbeddingModel($insertRows),
                    'embedding_dimensions' => $embeddingDims,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * Convert a numeric array into a pgvector literal like: [0.1,0.2,0.3]
     *
     * @param  array<int, int|float|string|null>  $v
     */
    private function vectorLiteral(array $v): string
    {
        $nums = array_map(function ($x) {
            if ($x === null) {
                return '0';
            }
            if (is_int($x) || is_float($x)) {
                return (string) $x;
            }

            return (string) (float) $x;
        }, $v);

        return '['.implode(',', $nums).']';
    }

    /**
     * Extract the first non-empty embedding_model seen in inserted rows.
     * Used to persist an item-level model marker for debugging/visibility.
     *
     * @param  array<int, array<string, mixed>>  $specs
     */
    private function firstNonEmptyEmbeddingModel(array $specs): ?string
    {
        foreach ($specs as $s) {
            if (! empty($s['embedding_model'])) {
                return (string) $s['embedding_model'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function headingPathTextFromMeta(array $meta): string
    {
        if (! isset($meta['heading_path']) || ! is_array($meta['heading_path'])) {
            return '';
        }

        return collect($meta['heading_path'])
            ->filter(fn ($heading): bool => is_string($heading) && trim($heading) !== '')
            ->map(fn (string $heading): string => trim($heading))
            ->implode(' > ');
    }

    /**
     * Build a compact item-level header injected as a dedicated chunk and prefix for embeddings.
     */
    private function syntheticHeader(KnowledgeItem $item): string
    {
        $tags = is_array($item->tags) ? implode(', ', $item->tags) : '';

        return trim("Title: {$item->title}\nCategory: {$item->category}\nTags: {$tags}");
    }

    /**
     * Build the embedding text used for vector generation.
     * Adds stable item metadata + optional section/code/resource signals, then the body.
     *
     * @param  array<string, mixed>  $meta
     */
    private function contextualEmbedText(KnowledgeItem $item, array $meta, string $body): string
    {
        $tags = is_array($item->tags) ? implode(', ', $item->tags) : '';
        $heading = '';

        if (! empty($meta['heading_path']) && is_array($meta['heading_path'])) {
            $heading = implode(' > ', $meta['heading_path']);
        }

        $prefix = "Title: {$item->title}\nCategory: {$item->category}\nTags: {$tags}";

        if ($heading !== '') {
            $prefix .= "\nSection: {$heading}";
        }

        if (! empty($meta['language'])) {
            $prefix .= "\nLanguage: {$meta['language']}";
        }

        if (! empty($meta['filename'])) {
            $prefix .= "\nFile: {$meta['filename']}";
        }

        if (! empty($meta['label'])) {
            $prefix .= "\nResource: {$meta['label']}";
        }

        return trim($prefix."\n\n".trim($body));
    }

    /**
     * Create an insert-ready chunk spec row plus a temporary embed_text field used only for embeddings.
     *
     * @param  array<string, mixed>  $meta
     * @param  mixed  $now
     * @return array<string, mixed>
     */
    private function makeSpec(
        int $knowledgeItemId,
        string $sourceType,
        ?int $sourceId,
        int $chunkIndex,
        string $chunkKind,
        string $chunkText,
        array $meta,
        string $embedText,
        $now,
        int $embeddingDims
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
            'embedding_dimensions' => $embeddingDims,
            'embedded_at' => null,
            'embedding_attempts' => 0,
            'embedding_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'embed_text' => $embedText,
        ];
    }
}
