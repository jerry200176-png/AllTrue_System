<?php

namespace App\Http\Controllers;

use App\Operations\PopOperationService;
use Illuminate\Http\Request;
use RuntimeException;

/** Control-plane request/approval API; it deliberately has no execute endpoint. */
final class PopOperationController extends Controller
{
    public function storeDraft(Request $request, string $operationId, PopOperationService $service)
    {
        $validated = $request->validate([
            'parameters' => ['required', 'array'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]{1,128}$/'],
        ]);
        $user = $request->attributes->get('auth_user');
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        try {
            $draft = $service->createDraft(
                $operationId,
                $validated['parameters'],
                $validated['idempotency_key'],
                'user:' . (int) $user->id,
                (string) $request->attributes->get('auth_role'),
                (array) $request->attributes->get('auth_campus_ids', []),
                (int) $user->id
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'POP draft rejected', 'reason_code' => $e->getMessage()], $this->errorStatus($e));
        }

        return response()->json(['data' => $draft], 201);
    }

    public function approve(Request $request, string $requestId, PopOperationService $service)
    {
        $validated = $request->validate([
            'approval_reference' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:#\/-]{3,128}$/'],
            'commit_sha' => ['required', 'string', 'size:40', 'regex:/^[0-9a-fA-F]{40}$/'],
            'ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);
        $user = $request->attributes->get('auth_user');
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        try {
            $approval = $service->approve(
                $requestId,
                $validated['approval_reference'],
                'user:' . (int) $user->id,
                (string) $request->attributes->get('auth_role'),
                $validated['commit_sha'],
                (int) $user->id,
                (array) $request->attributes->get('auth_campus_ids', []),
                (int) ($validated['ttl_minutes'] ?? 15)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'POP approval rejected', 'reason_code' => $e->getMessage()], $this->errorStatus($e));
        }

        return response()->json(['data' => $approval], $approval['ready'] ? 200 : 202);
    }

    public function dryRun(Request $request, string $requestId, PopOperationService $service)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        try {
            $result = $service->runDryRun(
                $requestId,
                'user:' . (int) $user->id,
                (int) $user->id,
                (string) $request->attributes->get('auth_role'),
                (array) $request->attributes->get('auth_campus_ids', [])
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'POP dry-run rejected', 'reason_code' => $e->getMessage()], $this->errorStatus($e));
        }

        return response()->json(['data' => $result], ($result['result'] ?? null) === 'succeeded' ? 200 : 422);
    }

    private function errorStatus(RuntimeException $error): int
    {
        $reason = $error->getMessage();
        if (str_contains($reason, 'outside the actor scope')) return 403;
        if (str_contains($reason, 'not active') || str_contains($reason, 'already') || str_contains($reason, 'requires')) return 409;

        return 422;
    }
}
