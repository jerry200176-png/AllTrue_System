<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #769 敏感頁 PIN 二次驗證控制器（Phase A primitives）。
 *
 * 設計重點：
 * - 失敗計數 / 鎖定一律走 DB（User 欄位），不用 Cache（避開事故 E）。
 * - per-account 鎖定在此；per-IP 節流由路由 throttle middleware 負責（OWASP 雙桶）。
 * - 解鎖狀態綁定當前 Bearer session（auth_tokens.pin_verified_until）。
 * - 回應 generic，不洩漏剩餘次數 / hash（OWASP / NIST 800-63B）。
 */
class PinVerificationController extends Controller
{
    private const PIN_TTL_MINUTES = 10;   // 解鎖有效時間
    private const MAX_ATTEMPTS = 5;       // 連續錯誤門檻
    private const LOCK_MINUTES = 15;      // 達門檻後鎖定時間

    public function status(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $locked = $this->isLocked($user);
        $token = $this->currentToken($request);
        $verified = $token && $token->pin_verified_until && Carbon::parse($token->pin_verified_until)->isFuture();

        return response()->json([
            'pin_set' => !empty($user->pin_hash),
            'locked' => $locked,
            'locked_until' => $locked ? Carbon::parse($user->pin_locked_until)->toIso8601String() : null,
            'verified' => (bool) $verified,
        ]);
    }

    public function set(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!empty($user->pin_hash)) {
            return response()->json(['message' => 'PIN 已設定，請使用重設', 'code' => 'pin_already_set'], 409);
        }

        $pin = (string) $request->input('pin', '');
        if ($err = $this->validatePin($pin)) {
            return response()->json(['message' => $err, 'code' => 'pin_invalid_format'], 422);
        }

        $user->pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        $user->pin_set_at = Carbon::now();
        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();

        $this->unlockSession($request);

        return response()->json(['message' => 'PIN 已設定', 'pin_set' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (empty($user->pin_hash)) {
            return response()->json(['message' => '尚未設定 PIN', 'code' => 'pin_not_set'], 400);
        }

        if ($this->isLocked($user)) {
            return response()->json(['message' => '嘗試次數過多，請稍後再試', 'code' => 'pin_locked'], 429);
        }

        $pin = (string) $request->input('pin', '');
        if (!password_verify($pin, $user->pin_hash)) {
            $user->pin_failed_attempts = (int) $user->pin_failed_attempts + 1;
            if ($user->pin_failed_attempts >= self::MAX_ATTEMPTS) {
                $user->pin_locked_until = Carbon::now()->addMinutes(self::LOCK_MINUTES);
                $user->pin_failed_attempts = 0;
                $user->save();
                return response()->json(['message' => '嘗試次數過多，請稍後再試', 'code' => 'pin_locked'], 429);
            }
            $user->save();
            return response()->json(['message' => 'PIN 錯誤', 'code' => 'pin_invalid'], 401);
        }

        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();

        $this->unlockSession($request);

        return response()->json(['message' => '已解鎖', 'verified' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $password = (string) $request->input('password', '');
        if (empty($user->PSW) || !password_verify($password, $user->PSW)) {
            return response()->json(['message' => '帳號密碼錯誤', 'code' => 'password_invalid'], 401);
        }

        $pin = (string) $request->input('pin', '');
        if ($err = $this->validatePin($pin)) {
            return response()->json(['message' => $err, 'code' => 'pin_invalid_format'], 422);
        }

        $user->pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        $user->pin_set_at = Carbon::now();
        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $user->save();

        $this->unlockSession($request);

        return response()->json(['message' => 'PIN 已重設', 'pin_set' => true]);
    }

    public function lock(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $token = $this->currentToken($request);
        if ($token) {
            $token->pin_verified_until = null;
            $token->save();
        }

        return response()->json(['message' => '已鎖定', 'verified' => false]);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function authUser(Request $request): ?User
    {
        $user = $request->attributes->get('auth_user');
        return $user instanceof User ? $user : null;
    }

    private function currentToken(Request $request): ?AuthToken
    {
        $bearer = $request->bearerToken();
        return $bearer ? AuthToken::where('token', $bearer)->first() : null;
    }

    private function unlockSession(Request $request): void
    {
        $token = $this->currentToken($request);
        if ($token) {
            $token->pin_verified_until = Carbon::now()->addMinutes(self::PIN_TTL_MINUTES);
            $token->save();
        }
    }

    private function isLocked(User $user): bool
    {
        return $user->pin_locked_until && Carbon::parse($user->pin_locked_until)->isFuture();
    }

    /** @return string|null 錯誤訊息；null = 合法 */
    private function validatePin(string $pin): ?string
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return 'PIN 須為 4 到 6 位數字';
        }
        if ($this->isWeakPin($pin)) {
            return 'PIN 過於簡單，請改用較不易猜測的數字';
        }
        return null;
    }

    private function isWeakPin(string $pin): bool
    {
        // 全部相同數字（0000 / 111111…）
        if (preg_match('/^(\d)\1+$/', $pin)) {
            return true;
        }
        // 連續遞增 / 遞減（1234 / 4321 / 123456 / 654321…）
        $asc = '0123456789';
        $desc = '9876543210';
        if (str_contains($asc, $pin) || str_contains($desc, $pin)) {
            return true;
        }
        // 常見弱碼
        $common = ['1212', '2121', '121212', '112233', '123123', '696969', '000000', '111111'];
        return in_array($pin, $common, true);
    }
}
