<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordAuthorSnapshot;
use App\Support\TeacherProfileDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** #3824: read-only, director/super_admin (route middleware). Never exposed to parents or the new teacher. */
class LearningRecordAuthorSnapshotController extends Controller
{
    public function index(Request $request, LearningRecord $learningRecord)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        $campusId = (int) DB::table('StudentClass')->join('Student', 'Student.id', '=', 'StudentClass.StudentID')
            ->where('StudentClass.ID', $learningRecord->StudentClassID)->value('Student.CampusID');
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json((new LearningRecordAuthorSnapshot())->newQuery()->where('learning_record_id', $learningRecord->id)
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
