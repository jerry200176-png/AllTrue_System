<?php

namespace App\Services;

/** Groups free-text Student.SchoolName spellings into one display name (names only, no student data). */
class SchoolNameGrouper
{
    public function __construct(private SchoolDirectory $directory)
    {
    }

    /** Stable key: catalogue canonical (when exact name/alias hit) else normalized raw text. */
    public function key(string $raw): string
    {
        return $this->directory->normalize($this->canonical($raw) ?? $raw);
    }

    public function display(string $raw): string
    {
        return mb_substr($this->canonical($raw) ?? trim($raw), 0, 64);
    }

    private function canonical(string $raw): ?string
    {
        $q = $this->directory->normalize($raw);
        if ($q === '') {
            return null;
        }
        foreach ($this->directory->search($raw, 5) as $hit) {
            $alias = $hit['matched_alias'];
            if ($this->directory->normalize($hit['canonical_name']) === $q
                || ($alias !== null && $this->directory->normalize($alias) === $q)) {
                return $hit['canonical_name'];
            }
        }

        return null;
    }
}
