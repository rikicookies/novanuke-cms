<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use RuntimeException;

final class WikiRevisionComparator
{
    private const MAX_CHANGED_LINE_PAIRS = 250000;
    private const MAX_TOTAL_LINES = 20000;

    /** @return list<array{kind:string,old:?string,new:?string}> */
    public function compare(string $oldContent, string $newContent): array
    {
        $oldLines = $this->lines($oldContent);
        $newLines = $this->lines($newContent);
        if (count($oldLines) + count($newLines) > self::MAX_TOTAL_LINES) {
            throw new RuntimeException('These revisions contain too many lines to compare safely online.');
        }
        $rows = [];
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        $prefixLength = 0;
        while ($prefixLength < $oldCount && $prefixLength < $newCount
            && $oldLines[$prefixLength] === $newLines[$prefixLength]) {
            $line = $oldLines[$prefixLength++];
            $rows[] = ['kind' => 'unchanged', 'old' => $line, 'new' => $line];
        }

        $suffix = [];
        $oldEnd = $oldCount - 1;
        $newEnd = $newCount - 1;
        while ($oldEnd >= $prefixLength && $newEnd >= $prefixLength
            && $oldLines[$oldEnd] === $newLines[$newEnd]) {
            $line = $oldLines[$oldEnd--];
            $newEnd--;
            $suffix[] = ['kind' => 'unchanged', 'old' => $line, 'new' => $line];
        }
        $suffix = array_reverse($suffix);

        $old = array_slice($oldLines, $prefixLength, $oldEnd - $prefixLength + 1);
        $new = array_slice($newLines, $prefixLength, $newEnd - $prefixLength + 1);

        if (count($old) * count($new) > self::MAX_CHANGED_LINE_PAIRS) {
            throw new RuntimeException('These revisions contain too many changed lines to compare safely online.');
        }

        array_push($rows, ...$this->changedRows($old, $new), ...$suffix);
        return $rows;
    }

    /** @return list<string> */
    private function lines(string $content): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{kind:string,old:?string,new:?string}>
     */
    private function changedRows(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $matrix = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($oldIndex = $oldCount - 1; $oldIndex >= 0; $oldIndex--) {
            for ($newIndex = $newCount - 1; $newIndex >= 0; $newIndex--) {
                $matrix[$oldIndex][$newIndex] = $old[$oldIndex] === $new[$newIndex]
                    ? $matrix[$oldIndex + 1][$newIndex + 1] + 1
                    : max($matrix[$oldIndex + 1][$newIndex], $matrix[$oldIndex][$newIndex + 1]);
            }
        }

        $rows = [];
        $oldIndex = 0;
        $newIndex = 0;
        while ($oldIndex < $oldCount || $newIndex < $newCount) {
            if ($oldIndex < $oldCount && $newIndex < $newCount && $old[$oldIndex] === $new[$newIndex]) {
                $rows[] = ['kind' => 'unchanged', 'old' => $old[$oldIndex++], 'new' => $new[$newIndex++]];
            } elseif ($oldIndex < $oldCount && ($newIndex >= $newCount || $matrix[$oldIndex + 1][$newIndex] >= $matrix[$oldIndex][$newIndex + 1])) {
                $rows[] = ['kind' => 'removed', 'old' => $old[$oldIndex++], 'new' => null];
            } else {
                $rows[] = ['kind' => 'added', 'old' => null, 'new' => $new[$newIndex++]];
            }
        }
        return $rows;
    }
}
