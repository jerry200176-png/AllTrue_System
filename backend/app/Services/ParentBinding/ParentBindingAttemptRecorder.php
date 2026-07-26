<?php

namespace App\Services\ParentBinding;

use App\Enums\ParentBindingChannel;
use App\Enums\ParentBindingMethod;
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
            $fp = null;
            if ((bool) config('parent_binding.store_phone_fingerprint', false) && $normalizedPhone !== null) {
                $fp = ParentBindingPhonePrivacy::fingerprint($normalizedPhone);
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
                'phone_fingerprint' => $fp,
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
            Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId,
                'channel' => $channel->value,
                'method' => $method->value,
                'error_class' => $e::class,
            ]);
        }
    }
}
