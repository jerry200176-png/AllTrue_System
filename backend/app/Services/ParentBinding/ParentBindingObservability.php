<?php

namespace App\Services\ParentBinding;

use App\Support\ParentBinding\ParentBindingCodes;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ParentBindingObservability
{
    public function __construct(
        private readonly ParentBindingClassifier $classifier,
        private readonly ParentBindingAttemptRecorder $recorder,
    ) {
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
        try {
            $this->recorder->record($correlationId, $channel, $method, $c, $normalizedPhone);
        } catch (Throwable $e) {
            Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId, 'channel' => $channel, 'method' => $method, 'error_class' => $e::class,
            ]);
        }
    }
}
