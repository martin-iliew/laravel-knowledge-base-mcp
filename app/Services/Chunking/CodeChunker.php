<?php

namespace App\Services\Chunking;

class CodeChunker
{
    public function chunk(string $code, int $maxChars, int $overlap): array
    {
        $t = preg_replace('/\r\n?/', "\n", $code) ?? $code;
        $blocks = preg_split("/\n{2,}/", trim($t)) ?: [trim($t)];

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
                $chunks[] = ['text' => $buffer, 'meta' => []];
                $buffer = '';
            }

            if (strlen($block) <= $maxChars) {
                $buffer = $block;
                continue;
            }

            foreach ($this->splitLong($block, $maxChars, (int) floor($overlap / 2)) as $piece) {
                $chunks[] = ['text' => $piece, 'meta' => []];
            }
        }

        if ($buffer !== '') {
            $chunks[] = ['text' => $buffer, 'meta' => []];
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
