<?php

namespace App\Console\Concerns;

/**
 * Console output is copied into public CI logs, so commands print ids,
 * statuses, dates and counts only. Free text (names, notes, phones) is shown
 * as a length unless an operator passes --with-names (never done by workflows).
 */
trait MasksPersonData
{
    protected function withNames(): bool
    {
        return (bool) $this->option('with-names');
    }

    /** Person data as text only when --with-names; otherwise '-' (empty) or '[len=N]'. */
    protected function personText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($this->withNames()) {
            return $text !== '' ? $text : '-';
        }

        return $text === '' ? '-' : '[len=' . mb_strlen($text) . ']';
    }

    /** Recursively replace every string leaf with '[len=N]' (ints/bools/null kept) unless --with-names. */
    protected function maskTree(mixed $value): mixed
    {
        if ($this->withNames()) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->maskTree($v), $value);
        }

        return is_string($value) && $value !== '' ? '[len=' . mb_strlen($value) . ']' : $value;
    }
}
