<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PopMachineAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_route_rejects_missing_key(): void
    {
        $response = $this->postJson('/api/v1/pop/machine/operations/course-contract-repair/draft', []);

        $response->assertUnauthorized();
    }

    public function test_attendance_device_key_cannot_authenticate_pop(): void
    {
        $key = 'attendance-test-key';
        $this->createClient($key, 'attendance_device', ['attendance:swipe']);

        $response = $this->withHeader('X-POP-MACHINE-KEY', $key)
            ->postJson('/api/v1/pop/machine/operations/course-contract-repair/draft', [
                'parameters' => $this->parameters(9),
                'idempotency_key' => 'machine-auth-device-' . Str::lower(Str::random(8)),
            ]);

        $response->assertUnauthorized();
    }

    public function test_scoped_machine_can_create_a_draft_without_a_human_identity(): void
    {
        $key = 'pop-test-key';
        $client = $this->createClient($key, 'pop_control_plane', ['pop:draft', 'pop:dry-run']);
        $idempotencyKey = 'machine-auth-draft-' . Str::lower(Str::random(8));

        $response = $this->withHeader('X-POP-MACHINE-KEY', $key)
            ->postJson('/api/v1/pop/machine/operations/course-contract-repair/draft', [
                'parameters' => $this->parameters(9),
                'idempotency_key' => $idempotencyKey,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.actor', 'machine:api-client:' . $client->id);
        $this->assertDatabaseHas('pop_operation_requests', [
            'id' => $response->json('data.id'),
            'actor' => 'machine:api-client:' . $client->id,
        ]);
    }

    public function test_machine_cannot_use_a_different_campus(): void
    {
        $key = 'pop-campus-key';
        $this->createClient($key, 'pop_control_plane', ['pop:draft']);

        $response = $this->withHeader('X-POP-MACHINE-KEY', $key)
            ->postJson('/api/v1/pop/machine/operations/course-contract-repair/draft', [
                'parameters' => $this->parameters(16),
                'idempotency_key' => 'machine-auth-campus-' . Str::lower(Str::random(8)),
            ]);

        $response->assertForbidden();
    }

    private function createClient(string $key, string $purpose, array $scopes): ApiClient
    {
        $client = new ApiClient([
            'Name' => 'test-' . Str::lower(Str::random(8)),
            'ApiKeyHash' => hash('sha256', $key),
            'CampusID' => 9,
            'Active' => 1,
            'Purpose' => $purpose,
            'Scopes' => $scopes,
        ]);
        $client->save();
        return $client;
    }

    /** @return array<string,mixed> */
    private function parameters(int $campusId): array
    {
        return [
            'student_id' => 30,
            'campus_id' => $campusId,
            'subject_id' => 66,
            'source_student_class_id' => 2531,
            'target_student_class_id' => 3379,
            'preserve_session_ids' => [24712, 21907],
            'transfer_session_ids' => [26552, 21910, 24805, 26006, 29478],
            'session_expectations' => [],
            'expected_source_charge' => 4400,
            'expected_target_charge' => 5200,
            'source_charge' => 2200,
            'target_charge' => 6500,
            'source_invoice_id' => null,
            'target_invoice_id' => 1601,
            'expected_source_invoice_total' => 0,
            'expected_target_invoice_total' => 4400,
            'reason' => 'huang-yikui-contract-repair',
            'decision_reference' => 'issue-2318',
        ];
    }
}
