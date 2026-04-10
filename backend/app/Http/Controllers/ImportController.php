<?php

namespace App\Http\Controllers;

use App\Imports\StudentClassesImport;
use App\Imports\StudentsImport;
use App\Models\ImportJob;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function students(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt',
            'branch_id' => 'required|integer|min:1',
        ]);

        $branchId = (int) $request->input('branch_id');
        $role = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $file = $request->file('file');
        $job = ImportJob::create([
            'Type' => 'students',
            'Status' => 'processing',
            'FileName' => $file->getClientOriginalName(),
            'Summary' => '',
            'ErrorLog' => '',
        ]);

        $summary = null;

        try {
            $isCsv = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true);
            if ($isCsv) {
                $summary = $this->importStudentsFromCsv($file, $branchId);
            } else {
                $summary = $this->importStudentsFromXlsx($file, $branchId);
            }

            $job->Status = 'done';
            $job->Summary = sprintf(
                'created:%d updated:%d skipped:%d errors:%d',
                (int) ($summary['created'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                count($summary['errors'] ?? [])
            );
            $job->ErrorLog = !empty($summary['errors'] ?? [])
                ? json_encode(array_slice($summary['errors'], 0, 20), JSON_UNESCAPED_UNICODE)
                : '';
        } catch (\Throwable $e) {
            $job->Status = 'failed';
            $job->ErrorLog = $e->getMessage();
        }

        $job->save();

        return response()->json([
            'job' => $job,
            'result' => $summary,
        ]);
    }

    public function studentClasses(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $file = $request->file('file');
        $job = ImportJob::create([
            'Type' => 'student_classes',
            'Status' => 'processing',
            'FileName' => $file->getClientOriginalName(),
            'Summary' => '',
            'ErrorLog' => '',
        ]);

        try {
            Excel::import(new StudentClassesImport(), $file);
            $job->Status = 'done';
            $job->Summary = 'student classes imported';
        } catch (\Throwable $e) {
            $job->Status = 'failed';
            $job->ErrorLog = $e->getMessage();
        }

        $job->save();

        return response()->json($job);
    }

    private function importStudentsFromXlsx(UploadedFile $file, int $branchId): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new \RuntimeException('Excel 檔案內容為空');
        }

        // Build a pseudo-CSV and reuse the same parsing logic
        $tmpFile = tempnam(sys_get_temp_dir(), 'import_xlsx_');
        $handle = fopen($tmpFile, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn($v) => $v === null ? '' : (string) $v, $row));
        }
        fclose($handle);

        // Now parse using the shared CSV logic
        $handle = fopen($tmpFile, 'r');
        $result = $this->importStudentsFromHandle($handle, $branchId);
        fclose($handle);
        unlink($tmpFile);
        return $result;
    }

    private function importStudentsFromCsv(UploadedFile $file, int $branchId): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('無法開啟 CSV 檔案');
        }
        $result = $this->importStudentsFromHandle($handle, $branchId);
        fclose($handle);
        return $result;
    }

    private function importStudentsFromHandle($handle, int $branchId): array
    {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new \RuntimeException('檔案內容為空');
        }

        $normalizedHeaders = array_map([$this, 'normalizeHeader'], $headers);
        $studentIdx = $this->findHeaderIndex($normalizedHeaders, ['學生', '姓名', 'name', 'student']);
        $gradeSchoolIdx = $this->findHeaderIndex($normalizedHeaders, ['年級學校', '年級', 'grade']);
        $schoolIdx = $this->findHeaderIndex($normalizedHeaders, ['學校', 'school']);
        $phoneIdx = $this->findHeaderIndex($normalizedHeaders, ['手機', '電話', 'phone', '家長手機', '家長電話', 'parent_phone']);

        if ($studentIdx === null) {
            throw new \RuntimeException('找不到「學生/姓名」欄位，請確認第一列為標題（如：學生、姓名）');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];
        $errors = [];
        $lowConfidenceMatches = 0;
        $rowNo = 1;
        $seen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNo++;
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rawName = trim((string) ($row[$studentIdx] ?? ''));
            if ($rawName === '' || in_array($rawName, ['學生', '姓名', 'Name', 'name'], true)) {
                $skipped++;
                continue;
            }

            $name = $this->normalizeStudentName($rawName);
            if ($name === '') {
                $errors[] = "第{$rowNo}列：學生姓名無效";
                continue;
            }

            if (mb_strlen($name) > 32) {
                $name = mb_substr($name, 0, 32);
                $warnings[] = "第{$rowNo}列：姓名過長，已截斷為 {$name}";
            }

            $rawPhone = trim((string) ($phoneIdx !== null ? ($row[$phoneIdx] ?? '') : ''));
            $phone = $this->normalizePhone($rawPhone);
            if ($phone === '') {
                $lowConfidenceMatches++;
            }
            if (mb_strlen($phone) > 20) {
                $phone = mb_substr($phone, 0, 20);
                $warnings[] = "第{$rowNo}列：手機過長，已截斷";
            }

            $gradeSchoolRaw = trim((string) ($gradeSchoolIdx !== null ? ($row[$gradeSchoolIdx] ?? '') : ''));
            [$grade, $schoolFromGrade] = $this->parseGradeAndSchool($gradeSchoolRaw);
            $schoolRaw = trim((string) ($schoolIdx !== null ? ($row[$schoolIdx] ?? '') : ''));
            $school = $schoolRaw !== '' ? $schoolRaw : $schoolFromGrade;

            if (mb_strlen($school) > 32) {
                $school = mb_substr($school, 0, 32);
                $warnings[] = "第{$rowNo}列：學校名稱過長，已截斷";
            }

            $key = mb_strtolower($name) . '|' . $phone;
            if (isset($seen[$key])) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            $query = Student::query()
                ->where('CampusID', $branchId)
                ->where('name', $name);

            if ($phone !== '') {
                $query->where('Phone', $phone);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('Phone')->orWhere('Phone', '');
                });
            }

            $existing = $query->first();
            $classId = $this->gradeToClassId($grade);

            if ($existing) {
                $changed = false;
                if ($classId !== null && (int) $existing->ClassID !== $classId) {
                    $existing->ClassID = $classId;
                    $changed = true;
                }
                if ($school !== '' && ($existing->SchoolName ?? '') !== $school) {
                    $existing->SchoolName = $school;
                    $changed = true;
                }
                if ($phone !== '' && ($existing->Phone ?? '') !== $phone) {
                    $existing->Phone = $phone;
                    $changed = true;
                }
                if (($existing->status ?? '') !== 'active') {
                    $existing->status = 'active';
                    $changed = true;
                }
                if ($changed) {
                    $existing->save();
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            Student::create([
                'name' => $name,
                'CampusID' => $branchId,
                'ClassID' => $classId ?? 7,
                'SchoolName' => $school !== '' ? $school : null,
                'Phone' => $phone !== '' ? $phone : null,
                'status' => 'active',
                'enable' => 1,
                'MDT' => now(),
                'TelegramID' => '',
                'Notify_Token' => '',
            ]);
            $created++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
            'warnings' => array_slice($warnings, 0, 50),
            'low_confidence_matches' => $lowConfidenceMatches,
        ];
    }

    private function normalizeHeader(?string $value): string
    {
        $v = trim((string) $value);
        $v = preg_replace('/^\xEF\xBB\xBF/u', '', $v);
        return str_replace([' ', "\t", "\r", "\n"], '', $v);
    }

    private function findHeaderIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $idx => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $this->normalizeHeader($candidate)) {
                    return $idx;
                }
            }
        }
        return null;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function normalizeStudentName(string $raw): string
    {
        $name = preg_replace('/\s+/u', '', trim($raw));
        $name = preg_replace('/(?:[\(（]?\s*1\s*[vV]\s*[123]\s*[\)）]?)$/u', '', $name);
        $name = preg_replace('/(?:[\(（]?\s*1\s*對\s*[123]\s*[\)）]?)$/u', '', $name);
        return trim((string) $name);
    }

    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/u', '', $raw);
        return trim((string) $digits);
    }

    private function parseGradeAndSchool(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            return [null, ''];
        }

        $token = null;
        if (preg_match('/(國[一二三123]|高[一二三123]|小[一二三四五六123456]|P[1-6]|J[1-3]|H[1-3])/u', $text, $m)) {
            $token = $m[1];
        }

        $grade = $this->tokenToGrade($token);
        $school = $text;
        if ($token !== null) {
            $school = trim((string) preg_replace('/' . preg_quote($token, '/') . '/u', '', $text, 1));
        }

        return [$grade, $school];
    }

    private function tokenToGrade(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $normalized = strtoupper(trim($token));
        if (preg_match('/^P([1-6])$/', $normalized, $m)) {
            return 'P' . $m[1];
        }
        if (preg_match('/^J([1-3])$/', $normalized, $m)) {
            return 'J' . $m[1];
        }
        if (preg_match('/^H([1-3])$/', $normalized, $m)) {
            return 'H' . $m[1];
        }

        $map = [
            '國一' => 'J1', '國二' => 'J2', '國三' => 'J3',
            '國1' => 'J1', '國2' => 'J2', '國3' => 'J3',
            '高一' => 'H1', '高二' => 'H2', '高三' => 'H3',
            '高1' => 'H1', '高2' => 'H2', '高3' => 'H3',
            '小一' => 'P1', '小二' => 'P2', '小三' => 'P3',
            '小四' => 'P4', '小五' => 'P5', '小六' => 'P6',
            '小1' => 'P1', '小2' => 'P2', '小3' => 'P3',
            '小4' => 'P4', '小5' => 'P5', '小6' => 'P6',
        ];

        return $map[$token] ?? null;
    }

    private function gradeToClassId(?string $grade): ?int
    {
        $map = [
            'P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5, 'P6' => 6,
            'J1' => 7, 'J2' => 8, 'J3' => 9,
            'H1' => 10, 'H2' => 11, 'H3' => 12,
        ];
        return $grade ? ($map[$grade] ?? null) : null;
    }
}
