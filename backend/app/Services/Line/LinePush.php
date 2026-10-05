<?php

namespace App\Services\Line;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only place that POSTs to the LINE push endpoint. Real HTTP in prod, Http::fake in tests.
 * Transport errors propagate: each caller keeps its own failure policy (retry / log / swallow).
 */
class LinePush
{
    public const ENDPOINT = 'https://api.line.me/v2/bot/message/push';

    /** @param list<array<string,mixed>> $messages */
    public function send(string $token, string $to, array $messages, ?int $timeoutSeconds = null): Response
    {
        $http = Http::withToken($token);
        if ($timeoutSeconds !== null) {
            $http = $http->timeout($timeoutSeconds);
        }

        return $http->post(self::ENDPOINT, ['to' => $to, 'messages' => $messages]);
    }
}
