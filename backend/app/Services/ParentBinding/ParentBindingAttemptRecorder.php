<?php

namespace App\Services\ParentBinding;

use App\Models\ParentBindingAttempt;
use App\Support\ParentBinding\ParentBindingPhonePrivacy;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Fail-open observation writer — never alters HTTP/LINE responses. */
final class ParentBindingAttemptRecorder
{
    public function enabled(): bool
    {
        return (bool) config('parent_binding.observability_enabled', false);
    }

    public function record(string $correlationId, string $channel, string $method, ParentBindingClassification $c, ?string $normalizedPhone = null): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $fp = ((bool) config('parent_binding.store_phone_fingerprint', false) && $normalizedPhone !== null)
                ? ParentBindingPhonePrivacy::fingerprint($normalizedPhone) : null;
            ParentBindingAttempt::query()->create([
                'correlation_id' => $correlationId, 'occurred_at' => now(),
                'channel' => $channel, 'method' => $method, 'outcome' => $c->outcome,
                'reason_code' => $c->reasonCode, 'campus_id' => $c->campusId, 'student_id' => $c->studentId,
                'phone_fingerprint' => $fp, 'candidate_count' => $c->candidateCount, 'phone_match_count' => $c->phoneMatchCount,
            ]);
            Log::info('parent_binding.attempt', [
                'correlation_id' => $correlationId, 'channel' => $channel, 'method' => $method,
                'outcome' => $c->outcome, 'reason_code' => $c->reasonCode,
                'campus_id' => $c->campusId, 'student_id' => $c->studentId,
                'candidate_count' => $c->candidateCount, 'phone_match_count' => $c->phoneMatchCount,
            ]);
        } catch (Throwable $e) {
            Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId, 'channel' => $channel, 'method' => $method, 'error_class' => $e::class,
            ]);
        }
    }
}
