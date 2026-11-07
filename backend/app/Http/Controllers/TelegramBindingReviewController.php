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

        $chats = [];
        foreach ($rows as $s) {
            foreach (self::SLOTS as $col) {
                $chats[] = trim((string) $s->{$col});
            }
        }
        $names = $this->chatNames($campus->id, $token, array_values(array_filter($chats, fn ($c) => $c !== '')));

        $data = [];
        foreach ($rows as $s) {
            $bindings = [];
            foreach (self::SLOTS as $slot => $col) {
                $chat = trim((string) $s->{$col});
                if ($chat !== '') {
                    $bindings[] = ['slot' => $slot, 'telegram_name' => $names[$chat] ?? null];
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
        $slot = (int) $in['slot'];
        $col = self::SLOTS[$slot];
        $student->{$col} = $slot === 0 ? '' : null; // same empty values the webhook reads
        $student->MDT = now();
        $student->save();
        StudentIdentityAuditLog::query()->create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'actor_user_id' => $request->attributes->get('auth_user')?->id,
            'action' => 'telegram_unbind',
            'metadata' => ['slot' => $slot],
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

    /** Best effort, null on any failure. Uncached chats are fetched concurrently (one 3 s window, not 3 s each). */
    private function chatNames(int $campusId, string $token, array $chats): array
    {
        if ($token === '' || !$chats) {
            return [];
        }
        $out = [];
        $miss = [];
        foreach (array_unique($chats) as $chat) {
            $hit = Cache::get("tg-chat-name:{$campusId}:{$chat}");
            $hit === null ? $miss[] = $chat : $out[$chat] = $hit;
        }
        if ($miss) {
            try {
                $res = Http::pool(fn ($pool) => array_map(
                    fn ($chat) => $pool->as((string) $chat)->timeout(3)->get("https://api.telegram.org/bot{$token}/getChat", ['chat_id' => $chat]),
                    $miss,
                ));
            } catch (\Throwable) {
                $res = []; // token-bearing exception text is never logged
            }
            foreach ($miss as $chat) {
                $r = $res[$chat] ?? null;
                $c = $r instanceof \Illuminate\Http\Client\Response && $r->successful() ? ($r->json('result') ?? []) : [];
                $name = trim((string) ($c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))));
                $name = $name !== '' ? $name : (isset($c['username']) ? '@' . $c['username'] : '');
                Cache::put("tg-chat-name:{$campusId}:{$chat}", $name, $name === '' ? now()->addMinutes(10) : now()->addDay());
                $out[$chat] = $name;
            }
        }

        return array_map(fn ($n) => $n === '' ? null : $n, $out);
    }
}
