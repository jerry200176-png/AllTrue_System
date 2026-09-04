<?php

namespace App\Services;

use App\Models\AdmissionInquiry;
use Illuminate\Support\Facades\DB;

final class AdmissionInquiryService
{
    public static function enabled(): bool
    {
        return (bool) config('perfflags.admissions_funnel_v1', false);
    }

    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone)) ?: '';
    }

    public static function identityHash(int $campusId, string $value, string $kind): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('Application key is required for inquiry identity hashing.');
        }
        return hash_hmac('sha256', "admission-v1|{$kind}|{$campusId}|{$value}", $key);
    }

    /** @return array{inquiry: AdmissionInquiry, duplicate: bool} */
    public function submit(array $data): array
    {
        $campusId = (int) $data['campus_id'];
        $phone = self::normalizePhone((string) $data['parent_phone']);
        $name = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $data['student_name'])) ?: '');
        $phoneHash = self::identityHash($campusId, $phone, 'phone');
        $nameHash = self::identityHash($campusId, $name, 'student');

        try {
            return DB::transaction(function () use ($data, $campusId, $phone, $phoneHash, $nameHash) {
                $inquiry = AdmissionInquiry::query()
                    ->where('campus_id', $campusId)
                    ->where('parent_phone_hash', $phoneHash)
                    ->where('student_name_hash', $nameHash)
                    ->lockForUpdate()
                    ->first();

                if ($inquiry) {
                    return ['inquiry' => $inquiry, 'duplicate' => true];
                }

                $inquiry = AdmissionInquiry::create([
                    'campus_id' => $campusId,
                    'status' => AdmissionInquiry::STATUS_NEW,
                    'parent_name' => trim((string) $data['parent_name']),
                    'parent_phone' => $phone,
                    'parent_phone_hash' => $phoneHash,
                    'student_name' => trim((string) $data['student_name']),
                    'student_name_hash' => $nameHash,
                    'grade' => $data['grade'],
                    'school_name' => $data['school_name'] ?? null,
                    'subject' => trim((string) $data['subject']),
                    'preferred_slots' => $data['preferred_slots'] ?? [],
                    'public_notes' => $data['public_notes'] ?? null,
                    'consent_at' => now(),
                ]);

                return ['inquiry' => $inquiry, 'duplicate' => false];
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            // A unique-key race can happen when two public submissions arrive together.
            // Treat only that race as an idempotent duplicate; preserve all other failures.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $identityKey = AdmissionInquiry::query()
                ->where('campus_id', $campusId)
                ->where('parent_phone_hash', $phoneHash)
                ->where('student_name_hash', $nameHash)
                ->value('id');
            $inquiry = AdmissionInquiry::findOrFail($identityKey);

            return ['inquiry' => $inquiry, 'duplicate' => true];
        }
    }
}
