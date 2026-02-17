<?php

namespace App\Services\Chunking;

class MarkdownChunker
{
    public function chunk(string $markdown, int $maxChars, int $overlap): array
    {
        $text = preg_replace('/\r\n?/', "\n", $markdown) ?? $markdown;
        $lines = explode("\n", $text);

        $sections = [];
        $stack = [];
        $buf = '';

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                if (trim($buf) !== '') {
                    $sections[] = ['text' => trim($buf), 'path' => $stack];
                    $buf = '';
                }

                $level = strlen($m[1]);
                $title = trim($m[2]);

                $stack = array_slice($stack, 0, $level - 1);
                $stack[] = $title;
                continue;
            }

            $buf .= $line . "\n";
        }

        if (trim($buf) !== '') {
            $sections[] = ['text' => trim($buf), 'path' => $stack];
        }

        $out = [];
        foreach ($sections as $s) {
            $out = array_merge($out, $this->split($s['text'], $maxChars, $overlap, [
                'heading_path' => $s['path'],
            ]));
        }

        return $out;
    }

    private function split(string $text, int $maxChars, int $overlap, array $meta): array
    {
        $blocks = preg_split("/\n{2,}/", trim($text)) ?: [trim($text)];

        $chunks = [];
        $buffer = '';

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if (strlen($buffer) + 2 + strlen($block) <= $maxChars) {
                $buffer = $buffer === '' ? $block : ($buffer . "\n\n" . $block);
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = ['text' => $buffer, 'meta' => $meta];
                $buffer = '';
            }

            if (strlen($block) <= $maxChars) {
                $buffer = $block;
                continue;
            }

            foreach ($this->splitLong($block, $maxChars, $overlap) as $piece) {
                $chunks[] = ['text' => $piece, 'meta' => $meta];
            }
        }

        if ($buffer !== '') {
            $chunks[] = ['text' => $buffer, 'meta' => $meta];
        }

        return $chunks;
    }

    private function splitLong(string $text, int $maxChars, int $overlap): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($text);

        while ($pos < $len) {
            $slice = substr($text, $pos, $maxChars);

            if ($pos + $maxChars < $len) {
                $cut = strrpos($slice, "\n");
                if ($cut !== false && $cut > (int) ($maxChars * 0.6)) {
                    $slice = substr($slice, 0, $cut);
                }
            }

            $out[] = $slice;

            if ($pos + strlen($slice) >= $len) {
                break;
            }

            $pos += max(1, strlen($slice) - $overlap);
        }

        return $out;
    }
}
