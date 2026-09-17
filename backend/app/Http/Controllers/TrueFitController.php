<?php

namespace App\Http\Controllers;

use App\Services\TrueFit\TrueFitLessonPrepService;
use App\Services\TrueFit\TrueFitObservationService;
use App\Services\TrueFit\TrueFitDiagnosisService;
use App\Services\TrueFit\TrueFitRemediationService;
use App\Services\TrueFit\TrueFitMasteryService;
use App\Services\TrueFit\TrueFitSessionProgressService;
use App\Services\TrueFitService;
use App\Services\TrueFitTodaySessionsReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrueFitController extends Controller
{
    /**
     * Pure-read today view for the authenticated teacher's eligible sessions.
     * Merges materialized ClassSession rows with contract projections and
     * schedule-exception slots without invoking index auto-materialization.
     */
    public function todaySessions(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');
        if ($branchDenied = $this->denyUnlessBranchAllowed($request)) {
            return $branchDenied;
        }

        $payload = app(TrueFitTodaySessionsReadService::class)->fetchTodaySessions($request, $teacherId);

        return response()->json($payload);
    }

    /**
     * Read-only aggregate stage presence for multiple session refs (workspace progress).
     * Body: { "sessions": [ { "class_session_id": N } | { "student_class_id", "session_date", "start_time" }, ... ] }
     * Inaccessible/invalid refs are omitted (meta.contract=omit_inaccessible). Never writes.
     */
    public function sessionProgress(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');
        if ($branchDenied = $this->denyUnlessBranchAllowed($request)) {
            return $branchDenied;
        }

        try {
            $payload = app(TrueFitSessionProgressService::class)->progressForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Synthetic material/unit catalog for Teacher Brief generation.
     */
    public function materialUnits(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $subjectHint = trim((string) $request->input('subject_hint', ''));
        $units = app(TrueFitLessonPrepService::class)->listMaterialUnits(
            $subjectHint !== '' ? $subjectHint : null
        );

        return response()->json([
            'meta' => [
                'catalog' => 'synthetic',
                'count' => count($units),
            ],
            'data' => $units,
        ]);
    }

    /**
     * Read the teacher's LessonPrep + structured Teacher Brief for a session.
     */
    public function showLessonPrep(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitLessonPrepService::class)->getPrepForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Generate (or regenerate) a fixture Teacher Brief for a session + material unit.
     */
    public function generateLessonPrep(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitLessonPrepService::class)->generateForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload, 201);
    }

    /**
     * Read the teacher's structured Teacher Observation for a session.
     */
    public function showObservation(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitObservationService::class)->getForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Upsert a teacher-entered structured Teacher Observation for a session.
     */
    public function upsertObservation(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitObservationService::class)->upsertForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload, 201);
    }

    /**
     * Read the teacher's structured Error Diagnosis for a session.
     */
    public function showDiagnosis(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitDiagnosisService::class)->getForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Upsert a teacher-confirmed Error Diagnosis for a session.
     */
    public function upsertDiagnosis(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitDiagnosisService::class)->upsertForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload, 201);
    }


    public function showRemediation(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitRemediationService::class)->getForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    public function upsertRemediation(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitRemediationService::class)->upsertForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload, 201);
    }

    public function showMasteryEvidence(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitMasteryService::class)->getForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload);
    }

    public function upsertMasteryEvidence(Request $request)
    {
        if ($denied = $this->denyUnlessTeacherTrueFit($request)) {
            return $denied;
        }

        $teacherId = (int) $request->attributes->get('auth_teacher_id');

        try {
            $payload = app(TrueFitMasteryService::class)->upsertForTeacher($request, $teacherId);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Invalid request', 'errors' => $e->errors()], 422);
        }

        return response()->json($payload, 201);
    }

    private function denyUnlessTeacherTrueFit(Request $request)
    {
        if (!TrueFitService::enabled()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $role = (string) $request->attributes->get('auth_role');
        if ($role !== 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
        if ($teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        return null;
    }

    private function denyUnlessBranchAllowed(Request $request)
    {
        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            if (!empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
        }

        return null;
    }
}
