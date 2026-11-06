<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentIdentityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** #5012 item 3: a director reviews the Telegram chats bound to their campus's students and unbinds unknown ones. */
class TelegramBindingReviewController extends Controller
{
    private const SLOTS = [0 => 'TelegramID', 1 => 'TelegramID1', 2 => 'TelegramID2'];

    public function index(Request $request)
    {
        $campus = $this->campus($request);
        if ($campus instanceof \Illuminate\Http\JsonResponse) {
            return $campus;
        }
        $token = (string) $campus->TelegramToken;

        $rows = Student::query()->where('CampusID', $campus->id)
            ->where(fn ($q) => $q->where('TelegramID', '!=', '')->orWhere('TelegramID1', '!=', '')->orWhere('TelegramID2', '!=', ''))
            ->orderBy('id')->get(['id', 'name', 'TelegramID', 'TelegramID1', 'TelegramID2']);

        $data = [];
        foreach ($rows as $s) {
            $bindings = [];
            foreach (self::SLOTS as $slot => $col) {
                $chat = trim((string) $s->{$col});
                if ($chat !== '') {
                    $bindings[] = ['slot' => $slot, 'telegram_name' => $this->chatName($campus->id, $token, $chat)];
                }
            }
            if ($bindings) {
                $data[] = ['student_id' => $s->id, 'student_name' => $s->name, 'bindings' => $bindings];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function unbind(Request $request)
    {
        $campus = $this->campus($request);
        if ($campus instanceof \Illuminate\Http\JsonResponse) {
            return $campus;
        }
        $in = $request->validate(['student_id' => 'required|integer', 'slot' => 'required|integer|in:0,1,2']);
        $student = Student::query()->where('CampusID', $campus->id)->find($in['student_id']);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }
        $col = self::SLOTS[$in['slot']];
        $student->{$col} = $in['slot'] === 0 ? '' : null; // same empty values the webhook reads
        $student->MDT = now();
        $student->save();
        StudentIdentityAuditLog::query()->create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'actor_user_id' => $request->attributes->get('auth_user')?->id,
            'action' => 'telegram_unbind',
            'metadata' => ['slot' => $in['slot']],
        ]);

        return response()->json(['ok' => true]);
    }

    private function campus(Request $request): Campus|\Illuminate\Http\JsonResponse
    {
        $allowed = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $id = (int) ($request->input('branch_id') ?: ($allowed[0] ?? 0));
        if (!$id) {
            return response()->json(['message' => 'Campus not found'], 404);
        }
        if ($request->attributes->get('auth_role') !== 'super_admin' && !in_array($id, $allowed, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return Campus::query()->find($id) ?? response()->json(['message' => 'Campus not found'], 404);
    }

    /** Best effort: null on any failure. ponytail: serial calls, add Http::pool if a campus has many chats. */
    private function chatName(int $campusId, string $token, string $chat): ?string
    {
        if ($token === '') {
            return null;
        }
        $key = "tg-chat-name:{$campusId}:{$chat}";
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === '' ? null : $hit;
        }
        $name = '';
        try {
            $r = Http::timeout(3)->get("https://api.telegram.org/bot{$token}/getChat", ['chat_id' => $chat]);
            $c = $r->successful() ? ($r->json('result') ?? []) : [];
            $name = trim((string) ($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
            $name = $name !== '' ? $name : (isset($c['username']) ? '@' . $c['username'] : '');
        } catch (\Throwable) {
            // token-bearing exception text is never logged
        }
        Cache::put($key, $name, $name === '' ? now()->addMinutes(10) : now()->addDay());

        return $name === '' ? null : $name;
    }
}
