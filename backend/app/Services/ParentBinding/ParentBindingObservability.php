<?php

namespace App\Services\ParentBinding;

use App\Enums\ParentBindingChannel;
use App\Enums\ParentBindingMethod;
use App\Support\ParentBinding\ParentBindingCorrelationId;

/**
 * Thin orchestrator: classify + record. Controllers stay free of duplicated reason logic.
 */
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

    public function recorder(): ParentBindingAttemptRecorder
    {
        return $this->recorder;
    }

    public function newCorrelationId(?string $inbound = null): string
    {
        return ParentBindingCorrelationId::fromRequest($inbound);
    }

    public function observe(
        string $correlationId,
        ParentBindingChannel $channel,
        ParentBindingMethod $method,
        ParentBindingClassification $classification,
        ?string $normalizedPhone = null,
    ): void {
        try {
            $this->recorder->record($correlationId, $channel, $method, $classification, $normalizedPhone);
        } catch (\Throwable $e) {
            // Defense-in-depth: recorder is already fail-open; never alter bind/login.
            \Illuminate\Support\Facades\Log::warning('parent_binding.observation_write_failed', [
                'correlation_id' => $correlationId,
                'channel' => $channel->value,
                'method' => $method->value,
                'error_class' => $e::class,
            ]);
        }
    }
}
