<?php

namespace App\Services\Chunking;

use App\Services\Chunking\Concerns\ChunkPacking;

class TextChunker
{
    use ChunkPacking; 

    /**
     * Chunk plain text into size-bounded segments for embedding / retrieval.
     *
     * @param string $text
     * @param int    $maxChars
     * @param int    $overlap
     * @return array<int, array{text: string, meta: array}>
     */
    public function chunk(string $text, int $maxChars, int $overlap): array
    {
        $normalized = $this->normalizeNewlines($text); 
        $blocks = $this->splitIntoBlocks($normalized); 

        return $this->packBlocksIntoChunks($blocks, $maxChars, $overlap, []); 
    }
}
