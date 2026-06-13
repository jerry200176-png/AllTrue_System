<?php

namespace App\Support;

use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * #769 敏感頁 PIN 二次驗證 — 共享判定謂詞。
 *
 * 「本 request 是否視為已通過 PIN（或豁免）」的單一真相，供：
 * - `RequirePin` middleware（未通過 → 423 擋下）
 * - `ProfileController`（未通過 → 遮罩老師 PII，TD-066）
 * 共用，避免兩處邏輯漂移。
 *
 * 視為通過（unlocked）的情況：
 * - 無認證使用者（auth 由其他層負責，此處不擋）。
 * - super_admin（D3：最高權限不納管，避免自鎖）。
 * - 使用者未設 PIN（soft 模式：漸進採用、零鎖死）。
 * - 當前 Bearer session 的 `pin_verified_until` 仍未過期。
 */
class PinGate
{
    public static function isUnlocked(Request $request): bool
    {
        $user = $request->attributes->get('auth_user');
        if (!$user instanceof User) {
            return true;
        }

        if ($request->attributes->get('auth_role') === 'super_admin') {
            return true;
        }

        if (empty($user->pin_hash)) {
            return true;
        }

        $bearer = $request->bearerToken();
        $authToken = $bearer ? AuthToken::where('token', $bearer)->first() : null;
        $verifiedUntil = $authToken?->pin_verified_until;

        return (bool) ($verifiedUntil && Carbon::parse($verifiedUntil)->isFuture());
    }
}
