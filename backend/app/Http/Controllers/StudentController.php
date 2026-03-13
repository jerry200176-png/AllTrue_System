<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
