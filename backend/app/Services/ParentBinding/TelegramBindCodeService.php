<?php

namespace App\Services\ParentBinding;

use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Support\Facades\Cache;

/**
 * #1139: one-time Telegram bind codes. A code is issued only to a LINE-verified parent session whose guardian link
 * to the anchor student is active, and redeems only for that (link, student, campus). Stored hashed in the cache
 * (no PII, no migration), single-use, TTL <= 15 min.
 */
final class TelegramBindCodeService
{
    public const TTL_MINUTES = 10;

    private static function key(string $code): string
    {
        return 'tg_bind_code:' . hash('sha256', strtolower(trim($code)));
    }

    /** @return array{code: string, expires_in: int}|null null when the session is not a verified guardian of its student */
    public function issueForSession(ParentSession $session): ?array
    {
        $line = trim((string) ($session->getAttribute('line_user_id') ?? ''));
        $student = Student::find((int) $session->StudentID);
        $guardian = $line === '' ? null : Guardian::where('line_user_id', $line)->first();
        $link = ($student && $guardian)
            ? StudentGuardian::activeAccess()->where('student_id', $student->id)->where('guardian_id', $guardian->id)->first()
            : null;
        if (!$link || !$this->linkMatches($link, $student)) {
            return null;
        }

        $code = bin2hex(random_bytes(5));
        Cache::put(self::key($code), ['link' => $link->id, 'guardian' => $guardian->id, 'student' => $student->id, 'campus' => (int) $student->CampusID], now()->addMinutes(self::TTL_MINUTES));

        return ['code' => $code, 'expires_in' => self::TTL_MINUTES * 60];
    }

    /** Consumes the code (single use, even on a failed re-check) and returns the student only if the link is still valid for this campus. */
    public function redeem(string $code, int $campusId): ?Student
    {
        // Atomic consume: the lock serialises concurrent redeems so only one sees the payload.
        $p = Cache::lock(self::key($code) . ':lock', 5)->block(5, fn () => Cache::pull(self::key($code)));
        if (!is_array($p) || (int) $p['campus'] !== $campusId) {
            return null;
        }
        $student = Student::where('id', $p['student'])->where('enable', 1)->where('CampusID', $campusId)->first();
        $link = StudentGuardian::activeAccess()->find($p['link']);

        return ($student && $link && (int) $link->student_id === $student->id && (int) $link->guardian_id === (int) $p['guardian'] && $this->linkMatches($link, $student)) ? $student : null;
    }

    private function linkMatches(StudentGuardian $link, Student $student): bool
    {
        return $link->campus_id === null || (int) $link->campus_id === (int) $student->CampusID;
    }
}
