<?php

namespace Tests\Unit;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionMethod;

final class PopOperationCatalogTest extends TestCase
{
    public function test_versions_follow_the_configured_catalog_and_policy_files(): void
    {
        $dir = sys_get_temp_dir() . '/pop-versions-' . bin2hex(random_bytes(6));
        mkdir($dir); mkdir($dir . '/policies');
        $catalog = new PopOperationCatalog($dir . '/catalog.yaml');
        try {
            foreach ([[7, 11], [8, 12]] as [$catalogVersion, $policyVersion]) {
                file_put_contents($dir . '/catalog.yaml', "version: $catalogVersion\n");
                file_put_contents($dir . '/policies/default.yaml', "version: $policyVersion\n");
                self::assertSame($catalogVersion, $catalog->version());
                self::assertSame($policyVersion, $catalog->policyVersion());
            }
        } finally {
            unlink($dir . '/catalog.yaml'); unlink($dir . '/policies/default.yaml');
            rmdir($dir . '/policies'); rmdir($dir);
        }
    }

    public function test_course_contract_repair_is_active_and_binds_to_pi_local_execution(): void
    {
        $entry = (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->operation('course-contract-repair');

        self::assertSame('active', $entry['lifecycle']);
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame('founder-explicit-single-repair', $entry['approval_policy']);
        self::assertSame(['super_admin'], $entry['approver_roles']);
        self::assertTrue($entry['founder_approval_required']);
        self::assertContains('verify', $entry['capabilities']);
        self::assertContains('rollback', $entry['capabilities']);
    }

    public function test_muzha_schedule_repair_requires_exact_pop_policy_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $entry = $catalog->operation('muzha-fixed-schedule-20261001');
        self::assertSame('active', $entry['lifecycle']);
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame(['super_admin'], $method->invoke($service, $entry));
        self::assertSame(['campus_id', 'decision_reference'], $entry['parameter_keys']);
        $entry['blast_radius'] = 'branch_wide';
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry);
    }

    public function test_unpaid_hidden_closures_requires_exact_pop_policy_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $entry = $catalog->operation('unpaid-hidden-closures-20261005');
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame(['decision_reference'], $entry['parameter_keys']);
        self::assertSame(['super_admin'], $method->invoke($service, $entry));
        $entry['blast_radius'] = 'branch_wide';
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry);
    }

    public function test_td076_repairs_require_exact_pop_policy_shape_and_digest_parameter(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        self::assertSame(['super_admin'], $method->invoke($service, $catalog->operation('td076-r2-history-pins-20261006')));
        $entry = $catalog->operation('td076-r1-collision-keepers-20261006');
        self::assertSame(['campus_id', 'decision_reference', 'expected_digest'], $entry['parameter_keys']);
        self::assertSame(['super_admin'], $method->invoke($service, $entry));
        $entry['parameter_keys'] = ['campus_id', 'decision_reference'];
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry);
    }

    public function test_muzha_chen_billing_catchup_requires_exact_pop_policy_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $entry = $catalog->operation('muzha-chen-billing-catchup-20261005');
        self::assertSame('pop-pi-local', $entry['execution_authority']);
        self::assertSame(['decision_reference'], $entry['parameter_keys']);
        self::assertSame(['super_admin'], $method->invoke($service, $entry));
        $entry['blast_radius'] = 'branch_wide';
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry);
    }

    public function test_founder_scoped_policy_is_supported_only_for_the_safe_course_repair_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $method->setAccessible(true);

        self::assertSame(['super_admin'], $method->invoke($service, $catalog->operation('course-contract-repair')));
        self::assertSame(['director', 'super_admin'], $method->invoke($service, [
            'id' => 'other-operation',
            'approval_policy' => 'critical-dual-approval',
            'approver_roles' => ['director', 'super_admin'],
        ]));

        $invalid = $catalog->operation('course-contract-repair');
        $invalid['blast_radius'] = 'multi_row';
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $invalid);
    }

    public function test_reviewed_monthly_shape_never_claims_cash_is_reversible(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'approvalRoles');
        $entry = $catalog->operation('reviewed-monthly-accounting-correction');
        self::assertSame(['super_admin'], $method->invoke($service, $entry));
        self::assertSame('planned', $catalog->operation('monthly-accounting-correction')['lifecycle']);
        $policy = json_decode(file_get_contents(dirname(__DIR__, 3) . '/' . $entry['eligibility_policy']), true);
        self::assertIsArray($policy['eligible_cases']);
        self::assertLessThanOrEqual(1, count($policy['eligible_cases']));
        $entry['reversible'] = true;
        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry);
    }

    public function test_founder_scoped_approval_reference_requires_explicit_founder_go_marker(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $method = new ReflectionMethod($service, 'assertFounderApprovalReference');
        $method->setAccessible(true);
        $entry = $catalog->operation('course-contract-repair');

        $this->expectException(RuntimeException::class);
        $method->invoke($service, $entry, 'director-approval-123');
    }
}
