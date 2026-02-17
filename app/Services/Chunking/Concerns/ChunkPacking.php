<?php

namespace App\Services\Chunking\Concerns;

trait ChunkPacking
{
    /**
     * Normalize Windows/Mac newlines into "\n" for consistent splitting and slicing.
     *
     * @param string $text
     * @return string
     */
    protected function normalizeNewlines(string $text): string
    {
        return preg_replace('/\r\n?/', "\n", $text) ?? $text;
    }

    /**
     * Split text into "blocks" separated by one or more blank lines.
     *
     * @param string $text
     * @return array<int, string>
     */
    protected function splitIntoBlocks(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        $blocks = preg_split("/\n{2,}/", $trimmed) ?: [$trimmed];

        return array_values(array_filter(array_map('trim', $blocks), fn ($b) => $b !== ''));
    }

    /**
     * Pack blocks into chunks up to $maxChars. Oversized blocks are split into overlapping slices.
     *
     * @param array<int, string> $blocks
     * @param int                $maxChars
     * @param int                $overlap
     * @param array              $meta
     * @param int|null           $oversizedOverlap If provided, used only for oversized block splitting.
     * @return array<int, array{text: string, meta: array}>
     */
    protected function packBlocksIntoChunks(
        array $blocks,
        int $maxChars,
        int $overlap,
        array $meta = [],
        ?int $oversizedOverlap = null
    ): array {
        $chunks = [];
        $currentChunk = '';
        $splitOverlap = $oversizedOverlap ?? $overlap;

        foreach ($blocks as $block) {
            if ($this->canAppend($currentChunk, $block, $maxChars)) {
                $currentChunk = $currentChunk === '' ? $block : ($currentChunk . "\n\n" . $block);
                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = ['text' => $currentChunk, 'meta' => $meta];
                $currentChunk = '';
            }

            if (strlen($block) <= $maxChars) {
                $currentChunk = $block;
                continue;
            }

            foreach ($this->splitOversizedBlock($block, $maxChars, $splitOverlap) as $part) {
                $chunks[] = ['text' => $part, 'meta' => $meta];
            }
        }

        if ($currentChunk !== '') {
            $chunks[] = ['text' => $currentChunk, 'meta' => $meta];
        }

        return $chunks;
    }

    /**
     * Check if a block can be appended to the current chunk without exceeding max size.
     * Accounts for the "\n\n" separator.
     *
     * @param string $currentChunk
     * @param string $block
     * @param int    $maxChars
     * @return bool
     */
    protected function canAppend(string $currentChunk, string $block, int $maxChars): bool
    {
        $separatorLen = $currentChunk === '' ? 0 : 2;

        return (strlen($currentChunk) + $separatorLen + strlen($block)) <= $maxChars;
    }

    /**
     * Split an oversized block into overlapping slices.
     * Prefer cutting at a newline near the end of the slice to avoid mid-line breaks.
     *
     * @param string $text
     * @param int    $maxChars
     * @param int    $overlap
     * @return array<int, string>
     */
    protected function splitOversizedBlock(string $text, int $maxChars, int $overlap): array
    {
        $parts = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $slice = substr($text, $offset, $maxChars);

            if ($offset + $maxChars < $length) {
                $cut = strrpos($slice, "\n");
                if ($cut !== false && $cut > (int) ($maxChars * 0.6)) {
                    $slice = substr($slice, 0, $cut);
                }
            }

            $parts[] = $slice;

            $sliceLen = strlen($slice);
            if ($offset + $sliceLen >= $length) {
                break;
            }

            $offset += max(1, $sliceLen - $overlap);
        }

        return $parts;
    }
}
