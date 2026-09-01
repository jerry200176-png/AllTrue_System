<?php

namespace Tests\Unit;

use App\Operations\PopOperationService;
use App\Operations\PopOperationCatalog;
use PHPUnit\Framework\TestCase;

class PopOperationServiceTest extends TestCase
{
    public function test_catalog_is_the_source_of_the_prepared_execution_contract(): void
    {
        $operation = (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->operation('course-contract-repair');

        self::assertSame(1, (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->version());
        self::assertSame('prepared', $operation['lifecycle']);
        self::assertSame('critical-dual-approval', $operation['approval_policy']);
        self::assertSame(['director', 'super_admin'], (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->requiredApprovalRoles($operation));
        self::assertSame(1, (new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml'))->policyVersion());
        self::assertContains('target_charge', $operation['parameter_keys']);
        self::assertContains('source_charge', $operation['parameter_keys']);
        self::assertContains('session_expectations', $operation['parameter_keys']);
        self::assertSame('App\\Operations\\Strategies\\CourseContractRepairStrategy', $operation['strategy_class']);
        self::assertSame(count($operation['parameter_keys']), count(array_unique($operation['parameter_keys'])));
    }

    public function test_canonical_parameters_sort_nested_contract_without_losing_exact_values(): void
    {
        $value = PopOperationService::canonicalParameters([
            'target_student_class_id' => 3379,
            'session_expectations' => [
                ['date' => '2026-08-29', 'id' => 29478],
                ['id' => 26552, 'date' => '2026-08-01'],
            ],
            'source_student_class_id' => 2531,
        ]);

        self::assertSame(['session_expectations', 'source_student_class_id', 'target_student_class_id'], array_keys($value));
        self::assertSame(['date', 'id'], array_keys($value['session_expectations'][0]));
        self::assertSame(29478, $value['session_expectations'][0]['id']);
    }

    public function test_executor_is_thin_and_execution_record_contract_is_present(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/pop-execute.yml');
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/pop/EXECUTION_RECORD_SCHEMA.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('backend/bin/pop execute', $workflow);
        self::assertStringNotContainsString('ssh ', $workflow);
        self::assertStringNotContainsString('artisan', $workflow);
        foreach (['schema_version', 'operation_id', 'execution_id', 'strategy', 'result', 'correlation_id', 'version_pins'] as $required) {
            self::assertArrayHasKey($required, $schema['properties']);
        }
    }

    public function test_execution_service_serializes_phases_and_commits_record_with_strategy(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Operations/PopOperationService.php');

        self::assertGreaterThanOrEqual(2, substr_count($service, 'lockForUpdate()'));
        self::assertStringContainsString('Serialise all phases per request.', $service);
        self::assertStringContainsString("The strategy's nested transaction and this outer transaction", $service);
        self::assertStringContainsString('POP request is no longer awaiting approval.', $service);
    }
}
