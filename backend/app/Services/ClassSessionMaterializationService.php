<?php

namespace App\Services;

use App\Models\ClassSession;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single write authority for ClassSession row creation (Phase 1 refactor).
 *
 * Idempotent on (StudentClassID, SessionDate, StartTime HH:MM). Does not encode
 * overlap policy, leave cascade, or LearningRecord side-effects — callers retain those.
 */
class ClassSessionMaterializationService
{
    /**
     * @param  array<string, mixed>  $slot
     * @return array{session: ClassSession, created: bool}
     */
    public function upsertSlot(array $slot): array
    {
        $studentClassId = (int) ($slot['StudentClassID'] ?? 0);
        $sessionDate = $this->normalizeDate($slot['SessionDate'] ?? null);
        $startTime = $this->normalizeTimeForStorage($slot['StartTime'] ?? null);
        $startHm = substr($startTime, 0, 5);

        if ($studentClassId <= 0 || $sessionDate === '' || $startHm === '') {
            throw new InvalidArgumentException('ClassSession slot requires StudentClassID, SessionDate, and StartTime.');
        }

        return DB::transaction(function () use ($slot, $studentClassId, $sessionDate, $startTime, $startHm) {
            $existing = ClassSession::query()
                ->where('StudentClassID', $studentClassId)
                ->whereDate('SessionDate', $sessionDate)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($existing) {
                return ['session' => $existing, 'created' => false];
            }

            $payload = $this->buildCreatePayload($slot, $studentClassId, $sessionDate, $startTime);

            return [
                'session' => ClassSession::create($payload),
                'created' => true,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $slot, int $studentClassId, string $sessionDate, string $startTime): array
    {
        $payload = [
            'StudentClassID' => $studentClassId,
            'SessionDate' => $sessionDate,
            'StartTime' => $startTime,
            'EndTime' => $this->normalizeTimeForStorage($slot['EndTime'] ?? '18:00:00'),
            'Status' => (string) ($slot['Status'] ?? 'scheduled'),
            'Note' => array_key_exists('Note', $slot) ? (string) $slot['Note'] : '',
        ];

        if (array_key_exists('SubjectID', $slot)) {
            $payload['SubjectID'] = $slot['SubjectID'] !== null ? (int) $slot['SubjectID'] : null;
        }

        if (array_key_exists('IsContractException', $slot)) {
            $payload['IsContractException'] = (int) (bool) $slot['IsContractException'];
        }

        if (array_key_exists('session_charge', $slot)) {
            $payload['session_charge'] = $slot['session_charge'] !== null ? (int) $slot['session_charge'] : null;
        }

        return $payload;
    }

    private function normalizeDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return substr((string) $value, 0, 10);
    }

    private function normalizeTimeForStorage(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '00:00:00';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        return substr($raw, 0, 8);
    }
}
