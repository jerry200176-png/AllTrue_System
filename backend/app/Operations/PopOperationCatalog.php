<?php

namespace App\Operations;

use RuntimeException;

/**
 * The catalog is the runtime contract. This parses only the scalar/list subset
 * used by operations/catalog.yaml so POP has one registry and no new package.
 */
final class PopOperationCatalog
{
    public function __construct(private readonly ?string $catalogPath = null)
    {
    }

    public function version(): int
    {
        $path = $this->catalogPath ?: dirname(base_path()) . '/operations/catalog.yaml';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^version:\s*(\d+)\s*$/', $line, $match)) return (int) $match[1];
        }
        throw new RuntimeException('POP catalog version is unavailable; fail closed.');
    }

    /** @return array<string,mixed> */
    public function operation(string $operationId): array
    {
        $path = $this->catalogPath ?: dirname(base_path()) . '/operations/catalog.yaml';
        if (!is_file($path)) {
            throw new RuntimeException('POP catalog is unavailable; fail closed.');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $found = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^  ([a-z0-9-]+):\s*$/', $line, $match) && $match[1] === $operationId) {
                $found = $index;
                break;
            }
        }
        if ($found === null) throw new RuntimeException("POP operation {$operationId} is not cataloged.");

        $entry = ['id' => $operationId];
        for ($i = $found + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^  [a-z0-9-]+:\s*$/', $line)) break;
            if (preg_match('/^    ([a-z0-9_]+):\s*(.*)$/', $line, $match)) {
                $entry[$match[1]] = $this->value($match[2]);
            }
        }
        foreach (['lifecycle', 'strategy_class', 'parameter_keys'] as $required) {
            if (!array_key_exists($required, $entry)) throw new RuntimeException("POP catalog entry {$operationId} lacks {$required}; fail closed.");
        }
        if (($entry['approval_required'] ?? false) === true
            && (!array_key_exists('approver_roles', $entry) || !is_array($entry['approver_roles']))) {
            throw new RuntimeException("POP catalog entry {$operationId} lacks approver_roles; fail closed.");
        }
        return $entry;
    }

    /** @param array<string,mixed> $entry @return array<int,string> */
    public function requiredApprovalRoles(array $entry): array
    {
        $roles = array_values(array_unique(array_map('strval', (array) ($entry['approver_roles'] ?? []))));
        if ($roles === []) {
            throw new RuntimeException('POP approval roles are unavailable; fail closed.');
        }

        return $roles;
    }

    /** @param array<string,mixed> $entry */
    public function approvalPolicy(array $entry): string
    {
        $policy = (string) ($entry['approval_policy'] ?? '');
        if ($policy === '') {
            throw new RuntimeException('POP approval policy is unavailable; fail closed.');
        }

        return $policy;
    }

    public function policyVersion(): int
    {
        $catalogPath = $this->catalogPath ?: dirname(base_path()) . '/operations/catalog.yaml';
        $path = dirname($catalogPath) . '/policies/default.yaml';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^version:\s*(\d+)\s*$/', $line, $match)) return (int) $match[1];
        }
        throw new RuntimeException('POP policy version is unavailable; fail closed.');
    }

    private function value(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === 'null') return null;
        if ($raw === 'true') return true;
        if ($raw === 'false') return false;
        if (preg_match('/^-?\d+$/', $raw)) return (int) $raw;
        if ($raw !== '' && $raw[0] === '[' && substr($raw, -1) === ']') {
            $inner = trim(substr($raw, 1, -1));
            if ($inner === '') return [];
            return array_map(static fn (string $part): string => trim($part, " \t'\""), str_getcsv($inner));
        }
        return trim($raw, " '\"");
    }
}
