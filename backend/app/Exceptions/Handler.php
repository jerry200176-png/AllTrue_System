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

    /**
     * API callers always get JSON errors (validation 422, 404, 500), even when a
     * fetch forgot `Accept: application/json`. Otherwise Laravel redirects or
     * renders HTML and the UI shows a misleading 「網路錯誤」 (in-app #375).
     */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }

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
