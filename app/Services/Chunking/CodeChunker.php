<?php

namespace App\Services\Chunking;

use App\Services\Chunking\Concerns\ChunkPacking;

class CodeChunker
{
    use ChunkPacking; 

    /**
     * Chunk code into size-bounded segments for embedding / retrieval.
     *
     * Note: oversized block splitting uses half overlap to reduce repeated code noise.
     *
     * @param string $code
     * @param int    $maxChars
     * @param int    $overlap
     * @return array<int, array{text: string, meta: array}>
     */
    public function chunk(string $code, int $maxChars, int $overlap): array
    {
        $normalized = $this->normalizeNewlines($code); 
        $blocks = $this->splitIntoBlocks($normalized); 

        return $this->packBlocksIntoChunks(
            $blocks,
            $maxChars,
            $overlap,
            [],
            (int) floor($overlap / 2) 
        );
    }
}
