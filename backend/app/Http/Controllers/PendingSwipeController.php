<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\PendingSwipe;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PendingSwipeController extends Controller
{
    public function index(Request $request)
    {
        $query = PendingSwipe::query()
            ->leftJoin('Student', 'PendingSwipe.StudentID', '=', 'Student.id')
            ->select([
                'PendingSwipe.*',
                'Student.name as student_name',
            ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds)) {
            $query->whereIn('PendingSwipe.CampusID', $campusIds);
        }

        if ($request->filled('campus_id')) {
            $query->where('PendingSwipe.CampusID', $request->input('campus_id'));
        }

        $rows = $query->orderBy('PendingSwipe.id', 'desc')->paginate(50);

        return response()->json($rows);
    }

    public function recentUnknownRfids(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $threshold = Carbon::now()->subMinutes(10);

        $query = PendingSwipe::query()
            ->whereNull('StudentID')
            ->where('created_at', '>=', $threshold)
            ->orderBy('created_at', 'desc')
            ->select(['id', 'RFID', 'CampusID', 'created_at']);

        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        $row = $query->first();

        return response()->json($row);
    }

    public function match(Request $request, PendingSwipe $pendingSwipe)
    {
        if ($response = $this->authorizeSwipeCampus($pendingSwipe)) {
            return $response;
        }

        $data = $request->validate([
            'StudentClassID' => 'required|integer',
            'ClassSessionID' => 'nullable|integer',
        ]);

        if (!$pendingSwipe->StudentID) {
            return response()->json(['message' => 'Student not found for this swipe'], 422);
        }

        return DB::transaction(function () use ($pendingSwipe, $data) {
            $studentClass = StudentClass::findOrFail($data['StudentClassID']);
            if ((int) $studentClass->StudentID !== (int) $pendingSwipe->StudentID) {
                return response()->json(['message' => 'Class does not match student'], 422);
            }

            $swipeAt = Carbon::parse($pendingSwipe->SwipeAt);
            $session = null;

            if (!empty($data['ClassSessionID'])) {
                $session = ClassSession::findOrFail($data['ClassSessionID']);
                if ((int) $session->StudentClassID !== (int) $studentClass->ID) {
                    return response()->json(['message' => 'Session does not match class'], 422);
                }
            } else {
                $sessions = ClassSession::where('StudentClassID', $studentClass->ID)
                    ->whereDate('SessionDate', $swipeAt->toDateString())
                    ->get();
                $session = $this->pickClosestSession($sessions, $swipeAt);

                if (!$session) {
                    $session = $this->createSessionFromSwipe($studentClass, $swipeAt);
                }
            }

            $existing = StudentSignIn::where('ClassSessionID', $session->id)->first();
            if ($existing) {
                return response()->json(['message' => 'Attendance already recorded'], 409);
            }

            $status = $this->resolveSwipeStatus($session, $swipeAt);

            $signIn = StudentSignIn::create([
                'StudentClassID' => $studentClass->ID,
                'StudentID' => $pendingSwipe->StudentID,
                'TeacherID' => $studentClass->TeacherID,
                'GradeID' => $studentClass->GradeID,
                'SubjectID' => $studentClass->SubjectID,
                'Get1byID' => $studentClass->by1,
                'Hours' => null,
                'Memo' => 'manual-match',
                'SignInDT' => $swipeAt,
                'SignOutDT' => null,
                'MDT' => now(),
                'ClassSessionID' => $session->id,
                'Status' => $status,
            ]);

            $session->Status = $status === 'late' ? 'late' : 'attended';
            $session->save();

            $pendingSwipe->delete();

            return response()->json($signIn, 201);
        });
    }

    public function assignStudent(Request $request, PendingSwipe $pendingSwipe)
    {
        if ($response = $this->authorizeSwipeCampus($pendingSwipe)) {
            return $response;
        }

        $data = $request->validate([
            'StudentID' => 'required|integer',
        ]);

        if ($pendingSwipe->StudentID) {
            return response()->json(['message' => 'Swipe already linked'], 409);
        }

        $student = Student::findOrFail($data['StudentID']);
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!empty($student->RFID) && $student->RFID !== $pendingSwipe->RFID) {
            return response()->json(['message' => 'Student already bound to different RFID'], 409);
        }

        $existing = Student::where('RFID', $pendingSwipe->RFID)
            ->where('id', '!=', $student->id)
            ->first();
        if ($existing) {
            return response()->json(['message' => 'RFID already bound'], 409);
        }

        $student->RFID = $pendingSwipe->RFID;
        $student->MDT = now();
        $student->save();

        $pendingSwipe->StudentID = $student->id;
        $pendingSwipe->save();

        return response()->json([
            'pending_swipe' => $pendingSwipe,
            'student' => $student,
        ]);
    }

    public function destroy(PendingSwipe $pendingSwipe)
    {
        if ($response = $this->authorizeSwipeCampus($pendingSwipe)) {
            return $response;
        }
        $pendingSwipe->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function authorizeSwipeCampus(PendingSwipe $pendingSwipe)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if (empty($campusIds)) {
            return null;
        }

        if ($pendingSwipe->CampusID !== null && !in_array((int) $pendingSwipe->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function pickClosestSession($sessions, Carbon $swipeAt): ?ClassSession
    {
        $closest = null;
        $closestDiff = null;

        foreach ($sessions as $session) {
            $startTime = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
            $diff = abs($startTime->diffInMinutes($swipeAt));
            if ($closestDiff === null || $diff < $closestDiff) {
                $closest = $session;
                $closestDiff = $diff;
            }
        }

        return $closest;
    }

    private function createSessionFromSwipe(StudentClass $studentClass, Carbon $swipeAt): ClassSession
    {
        $duration = 120;
        $start = $swipeAt->copy();
        $end = $start->copy()->addMinutes($duration);

        return ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => $start->toDateString(),
            'StartTime' => $start->format('H:i:s'),
            'EndTime' => $end->format('H:i:s'),
            'Status' => 'scheduled',
            'Note' => 'manual-match',
        ]);
    }

    private function resolveSwipeStatus(ClassSession $classSession, Carbon $swipeAt): string
    {
        $startTime = Carbon::parse($classSession->SessionDate . ' ' . $classSession->StartTime);
        $graceMinutes = 15;

        return $swipeAt->greaterThan($startTime->copy()->addMinutes($graceMinutes)) ? 'late' : 'present';
    }
}
