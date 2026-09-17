<?php

namespace App\Http\Controllers;

use App\Services\SchoolDirectory;
use Illuminate\Http\Request;

class SchoolDirectoryController extends Controller
{
    public function index(Request $request, SchoolDirectory $directory)
    {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', '20');

        return response()->json([
            'data' => $directory->search($q, $limit),
        ]);
    }
}
