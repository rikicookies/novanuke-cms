<?php

declare(strict_types=1);

namespace Modules\Search\src;

use Twig\Markup;

final class SafeHighlighter
{
    public function highlight(string $text, string $term): Markup
    {
        $pattern = '/(' . preg_quote($term, '/') . ')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return new Markup($this->escape($text), 'UTF-8');

        $normalized = mb_strtolower($term, 'UTF-8');
        $html = '';
        foreach ($parts as $part) {
            $escaped = $this->escape($part);
            $html .= mb_strtolower($part, 'UTF-8') === $normalized ? '<mark>' . $escaped . '</mark>' : $escaped;
        }
        return new Markup($html, 'UTF-8');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
