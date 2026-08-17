<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append-only, PII-minimized security evidence.
 *
 * Callers must pass identifiers through ref(); raw names, phones, LINE IDs,
 * tokens, and message bodies are intentionally not accepted as metadata.
 */
final class SecurityAuditEvent
{
    private const RETENTION_DAYS = 180;

    private const SAFE_METADATA_KEYS = [
        'allowed', 'attempt_count', 'binding_verified', 'campus_count',
        'campus_scope', 'column_count', 'delivery_status', 'export_format',
        'http_status', 'method', 'new_remaining_sessions', 'new_session_count',
        'new_type', 'notification_type', 'old_remaining_sessions',
        'old_session_count', 'old_type', 'outcome', 'provider_status',
        'reason_code', 'row_count', 'source', 'student_count',
        'verification_method',
    ];

    public static function ref(string $kind, int|string|null $value): ?string
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        $key = (string) (config('app.key') ?: 'alltrue-audit-key-not-configured');
        return hash_hmac('sha256', "alltrue-security-audit-v1|{$kind}|{$value}", $key);
    }

    /** @return array<string, bool|int|string> */
    public static function metadata(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, self::SAFE_METADATA_KEYS, true) || !is_scalar($value)) {
                continue;
            }
            if (is_string($value)) {
                $value = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?: '', 0, 120);
            }
            $safe[$key] = $value;
        }
        return $safe;
    }

    public static function append(
        string $eventType,
        string $outcome,
        array $context = [],
        array $metadata = []
    ): void {
        try {
            $correlationId = (string) ($context['correlation_id'] ?? '');
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $correlationId)) {
                $correlationId = (string) \Illuminate\Support\Str::uuid();
            }

            DB::table('security_audit_events')->insert([
                'event_type' => substr($eventType, 0, 96),
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
                'campus_id' => isset($context['campus_id']) ? (int) $context['campus_id'] : null,
                'actor_ref' => self::ref((string) ($context['actor_type'] ?? 'actor'), $context['actor_id'] ?? null),
                'subject_ref' => self::ref((string) ($context['subject_type'] ?? 'subject'), $context['subject_id'] ?? null),
                'binding_ref' => self::ref('binding', $context['binding_id'] ?? null),
                'outcome' => substr($outcome, 0, 32),
                'metadata' => json_encode(self::metadata($metadata), JSON_THROW_ON_ERROR),
                'retention_until' => now()->addDays((int) config('audit.retention_days', self::RETENTION_DAYS)),
            ]);
        } catch (Throwable $e) {
            // Evidence must never turn a safe auth/notification action into a 500.
            Log::warning('security_audit.append_failed', ['error_class' => $e::class]);
        }
    }
}
