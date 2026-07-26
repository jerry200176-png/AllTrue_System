<?php

namespace App\Services\ParentBinding;

use App\Enums\ParentBindingChannel;
use App\Enums\ParentBindingMethod;
use App\Models\ParentBindingAttempt;
use App\Support\ParentBinding\ParentBindingPhonePrivacy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-open observation writer. Telemetry DB failures never change HTTP/LINE responses.
 */
final class ParentBindingAttemptRecorder
{
    public function enabled(): bool
    {
        return (bool) config('parent_binding.observability_enabled', false);
    }

    public function record(
        string $correlationId,
        ParentBindingChannel $channel,
        ParentBindingMethod $method,
        ParentBindingClassification $classification,
        ?string $normalizedPhone = null,
    ): void {
        if (!$this->enabled()) {
            return;
        }

        try {
            $fingerprint = null;
            if ((bool) config('parent_binding.store_phone_fingerprint', false) && $normalizedPhone !== null) {
                $fingerprint = ParentBindingPhonePrivacy::fingerprint($normalizedPhone);
            }

            ParentBindingAttempt::query()->create([
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
                'channel' => $channel->value,
                'method' => $method->value,
                'outcome' => $classification->outcome->value,
                'reason_code' => $classification->reasonCode?->value,
                'campus_id' => $classification->campusId,
                'student_id' => $classification->studentId,
                'phone_fingerprint' => $fingerprint,
                'candidate_count' => $classification->candidateCount,
                'phone_match_count' => $classification->phoneMatchCount,
            ]);

            Log::info('parent_binding.attempt', [
                'correlation_id' => $correlationId,
                'channel' => $channel->value,
                'method' => $method->value,
                'outcome' => $classification->outcome->value,
                'reason_code' => $classification->reasonCode?->value,
                'campus_id' => $classification->campusId,
                'student_id' => $classification->studentId,
                'candidate_count' => $classification->candidateCount,
                'phone_match_count' => $classification->phoneMatchCount,
            ]);
        } catch (Throwable $e) {
            // PII-safe technical warning only — no exception message (may contain SQL/bindings).
            Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId,
                'channel' => $channel->value,
                'method' => $method->value,
                'error_class' => $e::class,
            ]);
        }
    }
}
