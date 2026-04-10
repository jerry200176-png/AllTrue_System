<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentController extends Controller
{
    private const GRADE_TO_CLASS = [
        'P1'=>1,'P2'=>2,'P3'=>3,'P4'=>4,'P5'=>5,'P6'=>6,
        'J1'=>7,'J2'=>8,'J3'=>9,'H1'=>10,'H2'=>11,'H3'=>12,
    ];

    private function classIdToGrade(?int $classId): string
    {
        static $map = null;
        if ($map === null) $map = array_flip(self::GRADE_TO_CLASS);
        return $map[$classId] ?? '';
    }

    private function transformStudent($s): array
    {
        return [
            'id'            => $s->id,
            'name'          => $s->name,
            'grade'         => $this->classIdToGrade($s->ClassID),
            'school'        => $s->SchoolName ?? '',
            'phone'         => $s->Phone ?? '',
            'parent_name'   => $s->parent_name ?? '',
            'parent_phone'  => $s->parent_phone ?? '',
            'notes'         => $s->notes ?? '',
            'status'        => $s->status ?? 'active',
            'rfid'          => $s->RFID ?? '',
            'branch_id'     => (int) $s->CampusID,
            'RFID'          => $s->RFID ?? '',
            '_laravelId'    => $s->id,
        ];
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = Student::query();

        if ($request->filled('campus_id') || $request->filled('branch_id')) {
            $cid = (int) ($request->input('campus_id') ?? $request->input('branch_id'));
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($cid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('CampusID', $cid);
        } elseif (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('name__ilike')) {
            $query->where('name', 'like', '%' . $request->input('name__ilike') . '%');
        }

        if ($request->filled('class_id')) {
            $query->where('ClassID', (int) $request->input('class_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('rfid')) {
            $query->where('RFID', $request->input('rfid'));
        }

        $perPage = $request->input('per_page');
        if ($perPage === 'all' || (int) $perPage >= 1000) {
            $students = $query->orderBy('name')->get();
            return response()->json($students->map(fn($s) => $this->transformStudent($s))->values());
        }

        $paginated = $query->orderBy('name')->paginate(min((int) ($perPage ?? 50), 500));
        $paginated->getCollection()->transform(fn($s) => $this->transformStudent($s));
        return response()->json($paginated);
    }

    public function show(Student $student)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->transformStudent($student));
    }

    public function store(Request $request)
    {
        $input = $request->all();

        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $campusId = $input['branch_id'] ?? $input['campus_id'] ?? ($campusIds[0] ?? 0);

        $gradeCode = $input['grade'] ?? 'J1';
        $classId = self::GRADE_TO_CLASS[$gradeCode] ?? 7;

        $student = Student::create([
            'name'         => $input['name'],
            'CampusID'     => (int) $campusId,
            'ClassID'      => $classId,
            'SchoolName'   => $input['school'] ?? $input['SchoolName'] ?? null,
            'Phone'        => $input['phone'] ?? $input['Phone'] ?? null,
            'parent_name'  => $input['parent_name'] ?? null,
            'parent_phone' => $input['parent_phone'] ?? null,
            'notes'        => $input['notes'] ?? null,
            'status'       => $input['status'] ?? 'active',
            'RFID'         => $input['rfid'] ?? null,
            'enable'       => 1,
            'MDT'          => now(),
            'TelegramID'   => '',
        ]);

        return response()->json($this->transformStudent($student), 201);
    }

    public function update(Request $request, Student $student)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $input = $request->all();

        if (isset($input['name']))         $student->name = $input['name'];
        if (isset($input['school']))       $student->SchoolName = $input['school'];
        if (isset($input['SchoolName']))   $student->SchoolName = $input['SchoolName'];
        if (isset($input['phone']))        $student->Phone = $input['phone'];
        if (isset($input['Phone']))        $student->Phone = $input['Phone'];
        if (isset($input['parent_name']))  $student->parent_name = $input['parent_name'];
        if (isset($input['parent_phone'])) $student->parent_phone = $input['parent_phone'];
        if (isset($input['notes']))        $student->notes = $input['notes'];
        if (isset($input['status']))       $student->status = $input['status'];
        if (isset($input['rfid']))         $student->RFID = $input['rfid'];

        if (isset($input['grade'])) {
            $student->ClassID = self::GRADE_TO_CLASS[$input['grade']] ?? $student->ClassID;
        }
        if (isset($input['GradeID'])) {
            $student->ClassID = (int) $input['GradeID'];
        }

        if (isset($input['branch_id'])) {
            $student->CampusID = (int) $input['branch_id'];
        }

        $student->save();

        return response()->json($this->transformStudent($student));
    }

    private function purgeStudentRecords(int $studentId): array
    {
        $deleted = [
            'StudentClass' => 0,
            'ClassSession' => 0,
            'LearningRecord' => 0,
            'StudentSingIn' => 0,
            'schedules' => 0,
            'Invoice' => 0,
            'InvoiceItem' => 0,
            'Payment' => 0,
            'ParentSession' => 0,
            'Student' => 0,
        ];

        static $tableExists = null;
        if ($tableExists === null) {
            $tableExists = [];
            foreach (['StudentClass', 'ClassSession', 'LearningRecord', 'StudentSingIn', 'schedules', 'Invoice', 'InvoiceItem', 'Payment', 'ParentSession', 'Student'] as $table) {
                $tableExists[$table] = Schema::hasTable($table);
            }
        }

        DB::transaction(function () use ($studentId, &$deleted, $tableExists) {
            $studentClassIds = [];
            if ($tableExists['StudentClass']) {
                $studentClassIds = DB::table('StudentClass')
                    ->where('StudentID', $studentId)
                    ->pluck('ID')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if ($tableExists['LearningRecord'] && !empty($studentClassIds)) {
                $deleted['LearningRecord'] = DB::table('LearningRecord')
                    ->whereIn('StudentClassID', $studentClassIds)
                    ->delete();
            }

            if ($tableExists['ClassSession'] && !empty($studentClassIds)) {
                $deleted['ClassSession'] = DB::table('ClassSession')
                    ->whereIn('StudentClassID', $studentClassIds)
                    ->delete();
            }

            if ($tableExists['StudentSingIn']) {
                $deleted['StudentSingIn'] = DB::table('StudentSingIn')
                    ->where(function ($q) use ($studentId, $studentClassIds) {
                        $q->where('StudentID', $studentId);
                        if (!empty($studentClassIds)) {
                            $q->orWhereIn('StudentClassID', $studentClassIds);
                        }
                    })
                    ->delete();
            }

            if ($tableExists['schedules']) {
                $deleted['schedules'] = DB::table('schedules')
                    ->where(function ($q) use ($studentId, $studentClassIds) {
                        $q->where('student_id', $studentId);
                        if (!empty($studentClassIds)) {
                            $q->orWhereIn('student_course_id', $studentClassIds);
                        }
                    })
                    ->delete();
            }

            $invoiceIds = [];
            if ($tableExists['Invoice']) {
                $invoiceQuery = DB::table('Invoice')->where('StudentID', $studentId);
                if (!empty($studentClassIds)) {
                    $invoiceQuery->orWhereIn('StudentClassID', $studentClassIds);
                }
                $invoiceIds = $invoiceQuery->pluck('id')->map(fn ($id) => (int) $id)->all();

                if (!empty($invoiceIds) && $tableExists['Payment']) {
                    $deleted['Payment'] = DB::table('Payment')
                        ->whereIn('InvoiceID', $invoiceIds)
                        ->delete();
                }

                if (!empty($invoiceIds) && $tableExists['InvoiceItem']) {
                    $deleted['InvoiceItem'] = DB::table('InvoiceItem')
                        ->whereIn('InvoiceID', $invoiceIds)
                        ->delete();
                }

                if (!empty($invoiceIds)) {
                    $deleted['Invoice'] = DB::table('Invoice')
                        ->whereIn('id', $invoiceIds)
                        ->delete();
                }
            }

            if ($tableExists['StudentClass'] && !empty($studentClassIds)) {
                $deleted['StudentClass'] = DB::table('StudentClass')
                    ->whereIn('ID', $studentClassIds)
                    ->delete();
            }

            if ($tableExists['ParentSession']) {
                $deleted['ParentSession'] = DB::table('ParentSession')
                    ->where('StudentID', $studentId)
                    ->delete();
            }

            if ($tableExists['Student']) {
                $deleted['Student'] = DB::table('Student')
                    ->where('id', $studentId)
                    ->delete();
            }
        });

        return $deleted;
    }

    public function destroy(Request $request, Student $student)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $deleted = $this->purgeStudentRecords((int) $student->id);

        return response()->json([
            'message' => '學生已刪除',
            'deleted' => $deleted,
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        $studentIds = collect($data['student_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return response()->json(['message' => '沒有可刪除的學生 ID'], 422);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = Student::query()->whereIn('id', $studentIds->all());
        if ($role !== 'super_admin') {
            if (empty($campusIds)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->whereIn('CampusID', $campusIds);
        }

        $students = $query->get(['id']);
        $foundIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invalidIds = array_values(array_diff($studentIds->all(), $foundIds));
        if (!empty($invalidIds)) {
            return response()->json([
                'message' => '部分學生不存在或無權限刪除',
                'invalid_ids' => $invalidIds,
            ], 403);
        }

        $deletedTotals = [
            'StudentClass' => 0,
            'ClassSession' => 0,
            'LearningRecord' => 0,
            'StudentSingIn' => 0,
            'schedules' => 0,
            'Invoice' => 0,
            'InvoiceItem' => 0,
            'Payment' => 0,
            'ParentSession' => 0,
            'Student' => 0,
        ];

        foreach ($foundIds as $studentId) {
            $deleted = $this->purgeStudentRecords((int) $studentId);
            foreach ($deleted as $table => $count) {
                $deletedTotals[$table] += (int) $count;
            }
        }

        return response()->json([
            'message' => '已批量刪除學生',
            'deleted_students' => count($foundIds),
            'deleted' => $deletedTotals,
        ]);
    }

    public function bindCard(Request $request, Student $student)
    {
        $data = $request->validate([
            'rfid' => 'required|string|max:64',
        ]);

        $student->RFID = $data['rfid'];
        $student->save();

        return response()->json(['message' => '已綁定卡號', 'student_id' => $student->id]);
    }
}
