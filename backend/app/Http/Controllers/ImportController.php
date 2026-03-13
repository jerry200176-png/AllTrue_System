<?php

namespace App\Http\Controllers;

use App\Imports\StudentClassesImport;
use App\Imports\StudentsImport;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function students(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $file = $request->file('file');
        $job = ImportJob::create([
            'Type' => 'students',
            'Status' => 'processing',
            'FileName' => $file->getClientOriginalName(),
            'Summary' => '',
            'ErrorLog' => '',
        ]);

        try {
            Excel::import(new StudentsImport(), $file);
            $job->Status = 'done';
            $job->Summary = 'students imported';
        } catch (\Throwable $e) {
            $job->Status = 'failed';
            $job->ErrorLog = $e->getMessage();
        }

        $job->save();

        return response()->json($job);
    }

    public function studentClasses(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $file = $request->file('file');
        $job = ImportJob::create([
            'Type' => 'student_classes',
            'Status' => 'processing',
            'FileName' => $file->getClientOriginalName(),
            'Summary' => '',
            'ErrorLog' => '',
        ]);

        try {
            Excel::import(new StudentClassesImport(), $file);
            $job->Status = 'done';
            $job->Summary = 'student classes imported';
        } catch (\Throwable $e) {
            $job->Status = 'failed';
            $job->ErrorLog = $e->getMessage();
        }

        $job->save();

        return response()->json($job);
    }
}
