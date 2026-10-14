<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** Bounded line diff; values remain plain text and are escaped by Twig. */
final class TextDiff
{
    /** @return list<array{kind:string,text:string}> */
    public static function lines(string $before, string $after): array
    {
        $a = explode("\n", $before);
        $b = explode("\n", $after);
        if (count($a) * count($b) > 250000) {
            return [['kind' => 'removed', 'text' => $before], ['kind' => 'added', 'text' => $after]];
        }
        $dp = array_fill(0, count($a) + 1, array_fill(0, count($b) + 1, 0));
        for ($i = count($a) - 1; $i >= 0; --$i) {
            for ($j = count($b) - 1; $j >= 0; --$j) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < count($a) || $j < count($b)) {
            if ($i < count($a) && $j < count($b) && $a[$i] === $b[$j]) {
                $out[] = ['kind' => 'equal', 'text' => $a[$i++]];
                ++$j;
            } elseif ($j < count($b) && ($i === count($a) || $dp[$i][$j + 1] >= $dp[$i + 1][$j])) {
                $out[] = ['kind' => 'added', 'text' => $b[$j++]];
            } else {
                $out[] = ['kind' => 'removed', 'text' => $a[$i++]];
            }
        }
        return $out;
    }
}
