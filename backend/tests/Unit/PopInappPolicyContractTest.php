<?php

namespace Tests\Unit;

use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use PHPUnit\Framework\TestCase;

/** ADR-POP-015 CI contract: the standing policy and its operations reference only each other. */
final class PopInappPolicyContractTest extends TestCase
{
    public function test_only_the_governed_operations_reference_the_standing_policy_and_nothing_else_auto_approves_them(): void
    {
        $root = dirname(__DIR__, 3) . '/operations';
        $catalog = new PopOperationCatalog($root . '/catalog.yaml');
        // Raw text, not operation(): some planned entries are deliberately not executable yet.
        preg_match_all('/^  ([a-z0-9-]+):\s*$((?:\n    .*)*)/m', (string) file_get_contents($root . '/catalog.yaml'), $m, PREG_SET_ORDER);
        $referencing = array_values(array_map(fn (array $e): string => $e[1], array_filter($m, fn (array $e): bool => str_contains($e[2], InappStandingPolicy::ID))));
        self::assertSame(array_keys(InappStandingPolicy::OPERATIONS), $referencing);

        foreach (array_keys(InappStandingPolicy::OPERATIONS) as $id) {
            $entry = $catalog->operation($id);
            self::assertTrue(InappStandingPolicy::governs($entry), $id);
            self::assertSame(['super_admin'], $entry['approver_roles'], 'human fallback quorum');
        }
        // No other policy file may match these operations (e.g. an auto-approving rule) or name this policy.
        foreach (array_diff(glob($root . '/policies/*') ?: [], [$catalog->policyFile(InappStandingPolicy::FILE)]) as $file) {
            foreach ([InappStandingPolicy::ID, ...array_keys(InappStandingPolicy::OPERATIONS)] as $needle) {
                self::assertStringNotContainsString($needle, (string) file_get_contents($file), basename($file));
            }
        }

        $policy = (string) file_get_contents($catalog->policyFile(InappStandingPolicy::FILE));
        self::assertMatchesRegularExpression('/^operations: \[' . preg_quote(implode(', ', array_keys(InappStandingPolicy::OPERATIONS)), '/') . '\]$/m', $policy);
        self::assertStringContainsString('FOUNDER_STANDING_DECISIONS.md 2026-10-14 (II)', $policy);
    }
}
