<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Utf8mb3SearchSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GlobalSearchController extends Controller
{
    // A single CJK character is a meaningful student/teacher name query.
    private const MIN_QUERY_LENGTH = 1;
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 5;

    public function index(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        $limit = min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json([
                'query' => $query,
                'min_query_length' => self::MIN_QUERY_LENGTH,
                'groups' => [],
            ]);
        }

        $term = Utf8mb3SearchSanitizer::forLike($query);
        if ($term === '') {
            return response()->json([
                'query' => $query,
                'min_query_length' => self::MIN_QUERY_LENGTH,
                'groups' => [],
            ]);
        }

        return response()->json([
            'query' => $query,
            'min_query_length' => self::MIN_QUERY_LENGTH,
            'groups' => [
                [
                    'key' => 'students',
                    'title' => '學生',
                    'items' => $this->searchStudents($term, $limit, $request),
                ],
                [
                    'key' => 'teachers',
                    'title' => '老師',
                    'items' => $this->searchTeachers($term, $limit, $request),
                ],
                [
                    'key' => 'courses',
                    'title' => '課程／堂次',
                    'items' => $this->searchCourses($term, $limit, $request),
                ],
            ],
        ]);
    }

    private function searchStudents(string $term, int $limit, Request $request): array
    {
        $role = (string) $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = Student::query()
            ->select(['id', 'name', 'ClassID', 'CampusID'])
            ->when(!empty($campusIds), fn ($builder) => $builder->whereIn('CampusID', $campusIds))
            ->where('name', 'like', '%' . $term . '%')
            ->orderByRaw('CASE WHEN name = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$term, $term . '%'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $campuses = Campus::query()
            ->whereIn('id', $query->pluck('CampusID')->map(fn ($id) => (int) $id)->unique()->values())
            ->pluck('name', 'id');

        return $query->map(fn (Student $student) => [
            'id' => (int) $student->getAttribute('id'),
            'type' => 'student',
            'title' => (string) $student->getAttribute('name'),
            'subtitle' => $this->gradeLabel((int) $student->getAttribute('ClassID')),
            'meta' => $this->campusLabel($campuses[(int) $student->getAttribute('CampusID')] ?? null),
            'student_id' => (int) $student->getAttribute('id'),
            'campus_id' => (int) $student->getAttribute('CampusID'),
        ])->values()->all();
    }

    private function searchTeachers(string $term, int $limit, Request $request): array
    {
        $role = (string) $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = User::query()
            ->select(['id', 'Name'])
            ->where('type', 'T')
            ->when(!empty($campusIds), function ($builder) use ($campusIds) {
                $builder->where(function ($scope) use ($campusIds) {
                    $scope->whereIn('id', function ($sub) use ($campusIds) {
                        $sub->select('UserID')
                            ->from('UserCampus')
                            ->whereIn('CampusID', $campusIds)
                            ->where(function ($approved) {
                                $approved->where('Approved', true)->orWhereNull('Approved');
                            });
                    });
                    if (Schema::hasTable('teacher_branches')) {
                        $scope->orWhereIn('id', function ($sub) use ($campusIds) {
                            $sub->select('teacher_id')
                                ->from('teacher_branches')
                                ->whereIn('branch_id', $campusIds);
                        });
                    }
                });
            })
            ->where('Name', 'like', '%' . $term . '%')
            ->orderByRaw('CASE WHEN Name = ? THEN 0 WHEN Name LIKE ? THEN 1 ELSE 2 END', [$term, $term . '%'])
            ->orderBy('Name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $teacherIds = $query->pluck('id')->map(fn ($id) => (int) $id)->values();
        $campusRows = DB::table('UserCampus')
            ->whereIn('UserID', $teacherIds)
            ->where(function ($approved) {
                $approved->where('Approved', true)->orWhereNull('Approved');
            })
            ->get(['UserID', 'CampusID'])
            ->groupBy('UserID');
        $campuses = Campus::query()
            ->whereIn('id', $campusRows->flatten(1)->pluck('CampusID')->map(fn ($id) => (int) $id)->unique()->values())
            ->pluck('name', 'id');

        return $query->map(fn (User $teacher) => [
            'id' => (int) $teacher->getAttribute('id'),
            'type' => 'teacher',
            'title' => (string) $teacher->getAttribute('Name'),
            'subtitle' => '老師',
            'meta' => $this->campusLabel($this->joinLabels(
                ($campusRows->get($teacher->getAttribute('id')) ?? collect())->map(fn ($row) => $campuses[(int) $row->CampusID] ?? null)->filter()->all()
            )),
            'teacher_id' => (int) $teacher->getAttribute('id'),
        ])->values()->all();
    }

    private function searchCourses(string $term, int $limit, Request $request): array
    {
        $role = (string) $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $teacherId = (int) $request->attributes->get('auth_teacher_id', 0);
        $dateTerm = preg_match('/^\d{4}-\d{2}-\d{2}$/', $term) === 1 ? $term : null;

        $query = StudentClass::query()
            ->join('Student as search_student', 'search_student.id', '=', 'StudentClass.StudentID')
            ->leftJoin('User as search_teacher', 'search_teacher.id', '=', 'StudentClass.TeacherID')
            ->leftJoin('Subject as search_subject', 'search_subject.id', '=', 'StudentClass.SubjectID')
            ->leftJoin('Campus as search_campus', 'search_campus.id', '=', 'search_student.CampusID')
            ->select([
                'StudentClass.ID',
                'StudentClass.StudentID',
                'StudentClass.TeacherID',
                'StudentClass.GradeID',
                'search_student.ClassID as StudentGradeID',
                'search_student.name as student_name',
                'search_student.CampusID',
                'search_teacher.Name as teacher_name',
                'search_subject.Subject_Name as subject_name',
                'search_campus.name as campus_name',
            ])
            ->when($role === 'teacher', function ($builder) use ($teacherId) {
                $builder->where(function ($scope) use ($teacherId) {
                    $scope->where('StudentClass.TeacherID', $teacherId)
                        ->orWhereExists(function ($sub) use ($teacherId) {
                            $sub->select(DB::raw(1))
                                ->from('schedules')
                                ->whereColumn('schedules.student_course_id', 'StudentClass.ID')
                                ->where('schedules.teacher_id', $teacherId)
                                ->where('schedules.status', 'scheduled')
                                ->whereNotNull('schedules.original_schedule_id');
                        });
                });
            })
            ->when($role !== 'teacher' && !empty($campusIds), fn ($builder) => $builder->whereIn('search_student.CampusID', $campusIds))
            ->where(function ($search) use ($term, $dateTerm) {
                $search->where('search_student.name', 'like', '%' . $term . '%')
                    ->orWhere('search_teacher.Name', 'like', '%' . $term . '%')
                    ->orWhere('search_subject.Subject_Name', 'like', '%' . $term . '%')
                    ->orWhere('StudentClass.ID', ctype_digit($term) ? (int) $term : -1);
                if ($dateTerm !== null) {
                    $search->orWhereExists(function ($sub) use ($dateTerm) {
                        $sub->select(DB::raw(1))
                            ->from('ClassSession as search_session')
                            ->whereColumn('search_session.StudentClassID', 'StudentClass.ID')
                            ->whereDate('search_session.SessionDate', $dateTerm);
                    });
                }
            })
            ->orderByRaw(
                'CASE WHEN search_student.name = ? OR search_teacher.Name = ? OR search_subject.Subject_Name = ? OR StudentClass.ID = ? THEN 0 ' .
                'WHEN search_student.name LIKE ? OR search_teacher.Name LIKE ? OR search_subject.Subject_Name LIKE ? THEN 1 ELSE 2 END',
                [$term, $term, $term, ctype_digit($term) ? (int) $term : -1, $term . '%', $term . '%', $term . '%']
            )
            ->orderBy('search_student.name')
            ->orderBy('StudentClass.ID')
            ->limit($limit)
            ->get();

        $courseIds = $query->pluck('ID')->map(fn ($id) => (int) $id)->values();
        $nextSessions = ClassSession::query()
            ->whereIn('StudentClassID', $courseIds)
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', Carbon::today()->toDateString())
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime'])
            ->groupBy('StudentClassID')
            ->map(fn ($rows) => $rows->first());

        return $query->map(function ($course) use ($nextSessions) {
            $session = $nextSessions->get($course->ID);
            $subject = trim((string) ($course->subject_name ?? ''));
            $teacher = trim((string) ($course->teacher_name ?? ''));
            $subtitle = implode(' · ', array_filter([$subject, $teacher]));
            $metaParts = array_filter([
                $this->campusLabel($course->campus_name),
                $this->gradeLabel((int) ($course->GradeID ?: $course->StudentGradeID)),
                $session ? trim((string) $session->SessionDate) . ' ' . substr((string) $session->StartTime, 0, 5) : null,
            ]);

            return [
                'id' => (int) $course->ID,
                'type' => 'course',
                'title' => (string) $course->student_name,
                'subtitle' => $subtitle !== '' ? $subtitle : '課程',
                'meta' => implode(' · ', $metaParts),
                'course_id' => (int) $course->ID,
                'student_id' => (int) $course->StudentID,
                'student_name' => (string) $course->student_name,
                'teacher_id' => (int) ($course->TeacherID ?? 0),
                'teacher_name' => $teacher,
                'session_id' => $session ? (int) $session->id : null,
                'session_date' => $session?->SessionDate,
                'session_start_time' => $session?->StartTime,
                'campus_id' => (int) $course->CampusID,
            ];
        })->values()->all();
    }

    private function gradeLabel(int $classId): string
    {
        return array_flip([
            'P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4, 'P5' => 5, 'P6' => 6,
            'J1' => 7, 'J2' => 8, 'J3' => 9, 'H1' => 10, 'H2' => 11, 'H3' => 12,
        ])[$classId] ?? '';
    }

    private function campusLabel(?string $name): string
    {
        return trim((string) $name) !== '' ? '分校：' . trim((string) $name) : '';
    }

    private function joinLabels(array $labels): string
    {
        return implode('、', array_values(array_unique(array_filter(array_map('trim', $labels)))));
    }
}
