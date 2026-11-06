<?php

namespace App\Http\Controllers;

use App\Services\ParentBinding\ParentGuardianAccessService;
use App\Services\ParentBinding\TelegramBindCodeService;
use Illuminate\Http\Request;

/** #1139: parent portal issues a one-time Telegram bind code (the bot accepts `/start <code>` only). */
class TelegramBindCodeController extends Controller
{
    public function issue(Request $request, ParentGuardianAccessService $access, TelegramBindCodeService $codes)
    {
        $session = $access->liveSession(preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization')));
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $issued = $codes->issueForSession($session);

        return $issued
            ? response()->json($issued)
            : response()->json(['message' => '請先以 LINE 登入並完成監護人驗證'], 403);
    }
}
