<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Exports\StudentClassesExport;
use App\Exports\StudentsExport;
use App\Models\SecurityAuditEvent;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function students(Request $request)
    {
        // #987: scope the export to the caller's campuses (PII). Empty for
        // super_admin → no restriction. Directors are limited to auth_campus_ids.
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $export = new StudentsExport($campusIds);
        $rowCount = (int) $export->query()->count();

        // #1812: PII export must leave an append-only, hashed audit trail.
        $actor = $request->attributes->get('auth_user');
        SecurityAuditEvent::append(
            'pii.export.students',
            'success',
            [
                'actor_type' => 'user',
                'actor_id' => $actor?->id,
                'campus_id' => count($campusIds) === 1 ? $campusIds[0] : null,
            ],
            [
                'export_format' => 'xlsx',
                'row_count' => $rowCount,
                'campus_scope' => empty($campusIds) ? 'unrestricted' : 'restricted',
                'campus_count' => count($campusIds),
                'column_count' => count($export->headings()),
                'reason_code' => 'students_xlsx',
            ]
        );

        return Excel::download($export, 'students.xlsx');
    }

    public function studentClasses()
    {
        return Excel::download(new StudentClassesExport(), 'student_classes.xlsx');
    }

    public function invoices()
    {
        return Excel::download(new InvoicesExport(), 'invoices.xlsx');
    }
}
