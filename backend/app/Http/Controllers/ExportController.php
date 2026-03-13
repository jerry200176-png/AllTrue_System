<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Exports\StudentClassesExport;
use App\Exports\StudentsExport;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function students()
    {
        return Excel::download(new StudentsExport(), 'students.xlsx');
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
