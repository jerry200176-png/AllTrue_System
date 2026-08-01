<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Support\StudentContactPhone;
use Illuminate\Console\Command;

class StudentIdentityCandidates extends Command
{
    protected $signature = 'parent:identity-candidates {--json : Emit machine-readable output}';
    protected $description = 'Read-only dry-run report for possible cross-campus student identities';

    public function handle(): int
    {
        $byNamePhone = [];
        $byPhone = [];

        foreach (Student::query()->orderBy('id')->cursor() as $student) {
            $name = trim((string) $student->name);
            $phone = StudentContactPhone::normalizedDigits($student);
            if ($name === '' || $phone === '') {
                continue;
            }
            $row = [
                'student_id' => (int) $student->id,
                'name' => $name,
                'phone_hash' => substr(hash('sha256', $phone), 0, 16),
                'campus_id' => (int) $student->CampusID,
            ];
            $byNamePhone[mb_strtolower($name) . '|' . $phone][] = $row;
            $byPhone[$phone][] = $row;
        }

        $candidateGroups = collect($byNamePhone)
            ->filter(fn (array $rows) => collect($rows)->pluck('campus_id')->unique()->count() > 1)
            ->values();
        $conflictGroups = collect($byPhone)
            ->filter(fn (array $rows) => collect($rows)->pluck('name')->unique()->count() > 1
                && collect($rows)->pluck('campus_id')->unique()->count() > 1)
            ->values();

        $report = [
            'read_only' => true,
            'candidate_groups' => $candidateGroups->count(),
            'candidate_students' => $candidateGroups->flatten(1)->count(),
            'unresolvable_groups' => $conflictGroups->count(),
            'candidate_rows' => $candidateGroups->all(),
            'conflict_rows' => $conflictGroups->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('Read-only only: no identity links were created.');
        $this->table(
            ['metric', 'count'],
            [
                ['candidate_groups', $report['candidate_groups']],
                ['candidate_students', $report['candidate_students']],
                ['unresolvable_groups', $report['unresolvable_groups']],
            ]
        );
        foreach ($report['candidate_rows'] as $rows) {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '%s | %s | phone#%s | campus:%d',
                    $row['student_id'],
                    $row['name'],
                    $row['phone_hash'],
                    $row['campus_id']
                ));
            }
        }

        return self::SUCCESS;
    }
}
