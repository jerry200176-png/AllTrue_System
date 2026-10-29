<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordAuthorSnapshot;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\TeacherProfileDirectory;
use Illuminate\Http\Request;

/** #3824: read-only, director/super_admin (route middleware). Never exposed to parents or the new teacher. */
class LearningRecordAuthorSnapshotController extends Controller
{
    public function index(Request $request, LearningRecord $learningRecord)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $sc = StudentClass::find($learningRecord->StudentClassID);
        $campusId = (int) (($sc ? Student::find($sc->StudentID) : null)->CampusID ?? 0);
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(LearningRecordAuthorSnapshot::where('learning_record_id', $learningRecord->id)
            ->orderBy('id')->get()->map(fn ($s) => [
                'id' => $s->id,
                'author_teacher_id' => $s->author_teacher_id,
                'author_teacher_name' => TeacherProfileDirectory::nameFor((int) $s->author_teacher_id, '—'),
                'status_at_snapshot' => $s->status_at_snapshot,
                'payload' => $s->payload,
                'created_at' => $s->created_at,
            ])->values());
    }
}
