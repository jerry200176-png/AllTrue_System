<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Exports\StudentClassesExport;
use App\Exports\StudentsExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function students(Request $request)
    {
        // #987: scope the export to the caller's campuses (PII). Empty for
        // super_admin → no restriction. Directors are limited to auth_campus_ids.
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return Excel::download(new StudentsExport($campusIds), 'students.xlsx');
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
