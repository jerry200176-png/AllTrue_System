<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $levels = [];

    protected $dontReport = [
        // Expected domain outcome (slot already taken) — a clean 422, not an error.
        SlotOccupiedException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        // Single source of truth for the slot-occupied response shape.
        $this->renderable(function (SlotOccupiedException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->toResponseArray(), 422);
            }
            return null;
        });
    }
}
