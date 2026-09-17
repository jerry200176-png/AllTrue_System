<?php

namespace App\Services;

/**
 * Curated, read-only company-wide school directory (in-app #296 V1).
 * Does not read or rewrite Student.SchoolName rows.
 */
class SchoolDirectory
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $catalog = null;

    /**
     * @return list<array{
     *   id: string,
     *   canonical_name: string,
     *   municipality: string,
     *   district: ?string,
     *   school_code: ?string,
     *   label: string,
     *   matched_alias: ?string
     * }>
     */
    public function search(string $query, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $q = $this->normalize($query);
        if ($q === '') {
            return [];
        }

        $hits = [];
        foreach ($this->catalog() as $row) {
            $matchedAlias = null;
            $score = $this->scoreRow($row, $q, $matchedAlias);
            if ($score <= 0) {
                continue;
            }
            $hits[] = [
                'score' => $score,
                'item' => $this->present($row, $matchedAlias),
            ];
        }

        usort($hits, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['item']['canonical_name'], $b['item']['canonical_name']);
        });

        return array_values(array_map(
            static fn (array $hit) => $hit['item'],
            array_slice($hits, 0, $limit)
        ));
    }

    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }
        $value = str_replace(['台', ' '], ['臺', ''], $value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(
            ['國民中學', '國民小學', '高級中學', '女子高級中學'],
            ['國中', '國小', '高中', '女高'],
            $value
        );

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $path = base_path('resources/data/schools.v1.json');
        if (!is_readable($path)) {
            self::$catalog = [];

            return self::$catalog;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$catalog = is_array($decoded) ? array_values($decoded) : [];

        return self::$catalog;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function scoreRow(array $row, string $q, ?string &$matchedAlias): int
    {
        $aliases = $row['aliases'] ?? [];
        if (!is_array($aliases)) {
            $aliases = [];
        }

        // Prefer exact alias matches so typeahead can show which short name hit.
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            if ($this->normalize($alias) === $q) {
                $matchedAlias = $alias;

                return 110;
            }
        }

        $canonical = (string) ($row['canonical_name'] ?? '');
        $canonicalKey = $this->normalize($canonical);
        if ($canonicalKey !== '' && str_contains($canonicalKey, $q)) {
            $matchedAlias = null;

            return str_starts_with($canonicalKey, $q) ? 100 : 80;
        }

        $best = 0;
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }
            $aliasKey = $this->normalize($alias);
            if ($aliasKey === '' || !str_contains($aliasKey, $q)) {
                continue;
            }
            $score = str_starts_with($aliasKey, $q) ? 90 : 70;
            if ($score > $best) {
                $best = $score;
                $matchedAlias = $alias;
            }
        }

        $muni = $this->normalize((string) ($row['municipality'] ?? ''));
        $district = $this->normalize((string) ($row['district'] ?? ''));
        $code = $this->normalize((string) ($row['school_code'] ?? ''));
        if ($best === 0 && ($muni === $q || $district === $q || ($code !== '' && str_contains($code, $q)))) {
            $matchedAlias = null;

            return 40;
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *   id: string,
     *   canonical_name: string,
     *   municipality: string,
     *   district: ?string,
     *   school_code: ?string,
     *   label: string,
     *   matched_alias: ?string
     * }
     */
    private function present(array $row, ?string $matchedAlias): array
    {
        $name = (string) ($row['canonical_name'] ?? '');
        $municipality = (string) ($row['municipality'] ?? '');
        $district = isset($row['district']) && is_string($row['district']) && $row['district'] !== ''
            ? $row['district']
            : null;
        $location = trim($municipality.($district ? ' '.$district : ''));
        $label = $location !== '' ? "{$name}（{$location}）" : $name;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'canonical_name' => $name,
            'municipality' => $municipality,
            'district' => $district,
            'school_code' => isset($row['school_code']) && is_string($row['school_code']) ? $row['school_code'] : null,
            'label' => $label,
            'matched_alias' => $matchedAlias,
        ];
    }
}
