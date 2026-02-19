<?php

namespace App\Services\Chunking;

use App\Services\Chunking\Concerns\ChunkPacking;

class MarkdownChunker
{
    use ChunkPacking; 

    /**
     * Chunk Markdown by heading hierarchy, then split each section into size-bounded chunks.
     *
     * @param string $markdown
     * @param int    $maxChars
     * @param int    $overlap
     * @return array<int, array{text: string, meta: array}>
     */
    public function chunk(string $markdown, int $maxChars, int $overlap): array
    {
        $normalized = $this->normalizeNewlines($markdown); 
        $lines = explode("\n", $normalized);

        $sections = $this->extractSections($lines);

        $chunks = [];
        foreach ($sections as $section) {
            $blocks = $this->splitIntoBlocks($section['text']); 

            $chunks = array_merge(
                $chunks,
                $this->packBlocksIntoChunks( 
                    $blocks,
                    $maxChars,
                    $overlap,
                    ['heading_path' => $section['heading_path']]
                )
            );
        }

        return $chunks;
    }

    /**
     * Extract Markdown sections keyed by heading_path (breadcrumb).
     *
     * @param array<int, string> $lines
     * @return array<int, array{text: string, heading_path: array<int, string>}>
     */
    private function extractSections(array $lines): array
    {
        $sections = [];
        $headingPath = [];
        $buffer = '';

        foreach ($lines as $line) {
            if ($this->isHeadingLine($line, $level, $title)) {
                if (trim($buffer) !== '') {
                    $sections[] = [
                        'text' => trim($buffer),
                        'heading_path' => $headingPath,
                    ];
                    $buffer = '';
                }

                $headingPath = $this->updateHeadingPath($headingPath, $level, $title);
                continue;
            }

            $buffer .= $line . "\n";
        }

        if (trim($buffer) !== '') {
            $sections[] = [
                'text' => trim($buffer),
                'heading_path' => $headingPath,
            ];
        }

        return $sections;
    }

    /**
     * Detect an ATX heading line ("# Title") and extract its level and title.
     *
     * @param string      $line
     * @param int|null    $level
     * @param string|null $title
     * @return bool
     */
    private function isHeadingLine(string $line, ?int &$level, ?string &$title): bool
    {
        if (! preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            return false;
        }

        $level = strlen($m[1]);
        $title = trim($m[2]);

        return true;
    }

    /**
     * Update the current heading breadcrumb when encountering a new heading.
     *
     * @param array<int, string> $current
     * @param int                $level
     * @param string             $title
     * @return array<int, string>
     */
    private function updateHeadingPath(array $current, int $level, string $title): array
    {
        $current = array_slice($current, 0, $level - 1);
        $current[] = $title;

        return $current;
    }
}
