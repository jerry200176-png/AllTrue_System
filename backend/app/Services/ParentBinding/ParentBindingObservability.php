<?php

namespace App\Services\ParentBinding;

use App\Models\ParentBindingAttempt;
use App\Support\ParentBinding\ParentBindingCodes;
use Illuminate\Support\Facades\Log;
use Throwable;

/** PB-00 classify + fail-open observation writer. */
final class ParentBindingObservability
{
    public function __construct(private readonly ParentBindingClassifier $classifier)
    {
    }

    public function classifier(): ParentBindingClassifier
    {
        return $this->classifier;
    }

    public function newCorrelationId(?string $inbound = null): string
    {
        return ParentBindingCodes::correlationId($inbound);
    }

    public function observe(string $correlationId, string $channel, string $method, array $c, ?string $normalizedPhone = null): void
    {
        if (!(bool) config('parent_binding.observability_enabled', false)) {
            return;
        }
        try {
            $fp = ((bool) config('parent_binding.store_phone_fingerprint', false) && $normalizedPhone !== null)
                ? ParentBindingCodes::phoneFingerprint($normalizedPhone) : null;
            ParentBindingAttempt::query()->create([
                'correlation_id' => $correlationId, 'occurred_at' => now(),
                'channel' => $channel, 'method' => $method, 'outcome' => $c['outcome'],
                'reason_code' => $c['reasonCode'], 'campus_id' => $c['campusId'], 'student_id' => $c['studentId'],
                'phone_fingerprint' => $fp, 'candidate_count' => $c['candidateCount'], 'phone_match_count' => $c['phoneMatchCount'],
            ]);
            Log::info('parent_binding.attempt', [
                'correlation_id' => $correlationId, 'channel' => $channel, 'method' => $method,
                'outcome' => $c['outcome'], 'reason_code' => $c['reasonCode'],
                'campus_id' => $c['campusId'], 'student_id' => $c['studentId'],
                'candidate_count' => $c['candidateCount'], 'phone_match_count' => $c['phoneMatchCount'],
            ]);
        } catch (Throwable $e) {
            Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId, 'channel' => $channel, 'method' => $method, 'error_class' => $e::class,
            ]);
        }
    }
}
