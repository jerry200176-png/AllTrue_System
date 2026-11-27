<?php

namespace App\Http\Controllers;

use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Services\SchoolNameGrouper;
use Illuminate\Http\Request;

/**
 * Founder GO 2026-11-27: a read-only triage token can read which schools the active students of ONE campus attend
 * (school name + head count) and the 段考 periods already entered there, so an agent can prepare school exam dates.
 * No student data. SchoolName is text a person typed, so a name leaves only when it is a catalogued school, or reads
 * like one (a few Chinese characters ending in a school word) and at least two students share it; anything else is
 * counted, never returned.
 * Switch: triage_tokens.school_names_enabled.
 */
class TriageSchoolNamesController extends Controller
{
    /** What a typed school name looks like: a short run of Chinese characters and a school word. No digits, Latin, spaces or punctuation. */
    private const SCHOOL_NAME = '/\A\p{Han}{2,8}(國小|國中|高中|中學|小學|附中|附小|女中|高工|高商|高職|學校|實小|實中)\z/u';

    /** A name that is not in the catalogue leaves only when at least this many students share it (one person's typing never does). */
    private const MIN_SHARED = 2;

    public function show(Request $request, SchoolNameGrouper $grouper)
    {
        if (!(bool) config('triage_tokens.school_names_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $campusId = (int) $request->validate(['campus_id' => ['required', 'integer', 'min:1']])['campus_id'];

        $schools = [];
        $withheld = 0;
        $rows = Student::query()->where('CampusID', $campusId)->where('enable', 1)
            ->whereNotNull('SchoolName')->where('SchoolName', '!=', '')
            ->selectRaw('SchoolName as n, COUNT(*) as c')->groupBy('SchoolName')->toBase()->get();
        $typed = [];
        foreach ($rows as $row) {
            $raw = (string) $row->n;
            if ($grouper->isCatalogued($raw)) {
                $name = $grouper->display($raw);
                $schools[$name] = ($schools[$name] ?? 0) + (int) $row->c;
            } else {
                $name = trim($raw);
                $typed[$name] = ($typed[$name] ?? 0) + (int) $row->c;
            }
        }
        foreach ($typed as $name => $count) {
            if ($count >= self::MIN_SHARED && preg_match(self::SCHOOL_NAME, (string) $name) === 1) {
                $schools[(string) $name] = ($schools[(string) $name] ?? 0) + $count;
            } else {
                $withheld += $count;
            }
        }
        arsort($schools);

        $periods = SchoolExamPeriod::query()->where('campus_id', $campusId)
            ->orderBy('academic_year')->orderBy('semester')->orderBy('seq')
            ->get(['id', 'school_name', 'academic_year', 'semester', 'seq', 'start_date', 'end_date', 'grade_ids'])
            ->map(fn (SchoolExamPeriod $p) => [
                'id' => (int) $p->id,
                'school_name' => $this->safeName($grouper, (string) $p->school_name),
                'academic_year' => (int) $p->academic_year,
                'semester' => (int) $p->semester,
                'seq' => (int) $p->seq,
                'start_date' => substr((string) $p->getRawOriginal('start_date'), 0, 10),
                'end_date' => substr((string) $p->getRawOriginal('end_date'), 0, 10),
                'grade_ids' => array_values(array_map('intval', (array) ($p->grade_ids ?? []))),
            ])->all();

        return response()->json([
            'campus_id' => $campusId,
            'schools' => array_map(fn ($name, $count) => ['school_name' => (string) $name, 'student_count' => $count], array_keys($schools), $schools),
            'withheld_student_count' => $withheld,
            'periods' => $periods,
        ]);
    }

    /** A staff-entered period's school: the catalogue's own name, or the text when it reads like a school, else null. */
    private function safeName(SchoolNameGrouper $grouper, string $raw): ?string
    {
        if ($grouper->isCatalogued($raw)) {
            return $grouper->display($raw);
        }

        return preg_match(self::SCHOOL_NAME, trim($raw)) === 1 ? trim($raw) : null;
    }
}
