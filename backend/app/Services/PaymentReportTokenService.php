<?php

namespace App\Services;

use Carbon\Carbon;

class PaymentReportTokenService
{
    /**
     * Generate a signed token for a payment report link.
     *
     * Token format: base64(JSON payload) . "." . HMAC-SHA256 signature
     * Payload: { scid, bid, exp }  (StudentClassID, branch_id, expiration unix ts)
     */
    public function generate(int $studentClassId, int $branchId, int $ttlHours = 72): array
    {
        $expiresAt = Carbon::now()->addHours($ttlHours);

        $payload = [
            'scid' => $studentClassId,
            'bid'  => $branchId,
            'exp'  => $expiresAt->timestamp,
            'iat'  => Carbon::now()->timestamp,
            'jti'  => bin2hex(random_bytes(8)),
        ];

        $payloadB64 = rtrim(base64_encode(json_encode($payload)), '=');
        $signature  = hash_hmac('sha256', $payloadB64, $this->secret());

        $token = $payloadB64 . '.' . $signature;

        return [
            'token'      => $token,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify and decode a token. Returns payload array or null if invalid/expired.
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadB64, $signature] = $parts;

        $expectedSig = hash_hmac('sha256', $payloadB64, $this->secret());
        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        $payload = json_decode(base64_decode($payloadB64), true);
        if (!$payload || !isset($payload['exp'], $payload['scid'])) {
            return null;
        }

        if (Carbon::now()->timestamp > $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /**
     * Get hash of a token for DB lookup.
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function secret(): string
    {
        return config('app.key', env('APP_KEY', 'alltrue-payment-report-secret'));
    }
}
