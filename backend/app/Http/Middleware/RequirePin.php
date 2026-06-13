<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

/**
 * #769 敏感頁 PIN 二次驗證 — 後端強制邊界。
 *
 * Soft 模式（漸進採用）：使用者尚未設定 PIN（pin_hash 為 null）→ 放行，
 * 不鎖死任何人。一旦設定 PIN，受保護端點即要求「當前 Bearer session
 * 已通過 PIN 驗證且未過期」（auth_tokens.pin_verified_until > now）。
 *
 * 未通過 → 423 Locked + code=pin_required（前端據此彈出 PinLockModal）。
 *
 * D3：super_admin 不納管（避免最高權限自鎖）。
 * Phase C 起掛於薪資／當月學收／帳務中心的專屬敏感端點（見 routes/api.php）。
 */
class RequirePin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->attributes->get('auth_user');

        // 未認證交由其他 middleware/controller 處理；此處不負責 auth。
        if (!$user) {
            return $next($request);
        }

        // D3：super_admin 不納管 PIN 強制（避免最高權限自鎖）。
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return $next($request);
        }

        // Soft 模式：未設定 PIN 不強制。
        if (empty($user->pin_hash)) {
            return $next($request);
        }

        $bearer = $request->bearerToken();
        $authToken = $bearer ? AuthToken::where('token', $bearer)->first() : null;

        $verifiedUntil = $authToken?->pin_verified_until;
        if ($verifiedUntil && Carbon::parse($verifiedUntil)->isFuture()) {
            return $next($request);
        }

        return response()->json([
            'message' => '請輸入 PIN 碼以存取此頁',
            'code' => 'pin_required',
        ], 423);
    }
}
