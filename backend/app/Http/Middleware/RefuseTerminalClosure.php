<?php

namespace App\Http\Middleware;

use App\Models\StudentClass;
use Closure;
use Illuminate\Http\Request;

/**
 * A refunded (退費) contract takes no write through a student-classes/{studentClass}/... POST.
 * One check for the whole route family, so a new endpoint cannot forget it. Runs after auth in the route group.
 */
class RefuseTerminalClosure
{
    public function handle(Request $request, Closure $next)
    {
        $course = $request->route('studentClass');
        $id = $course instanceof StudentClass ? (int) $course->getKey() : (int) $course;
        if ($request->isMethod('POST') && $id > 0 && !str_ends_with($request->path(), '/refund')
            && StudentClass::terminalClosureInDb($id) === 'refunded') { // waived keeps its own endpoint guards and codes
            return response()->json(['message' => StudentClass::REFUNDED_MESSAGE, 'code' => 'course_refunded'], 422);
        }

        return $next($request);
    }
}
