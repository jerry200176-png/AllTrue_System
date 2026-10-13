<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\TriageTokenService;
use Illuminate\Http\Request;

/**
 * Super-admin management of read-only AI triage tokens (list / create / revoke).
 * Routes sit behind the `super_admin` middleware; the plaintext is returned once.
 */
class TriageTokenController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => TriageTokenService::list(),
            'ability' => TriageTokenService::ability(),
            'ttl_days' => (int) config('triage_tokens.ttl_days'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $user = $request->attributes->get('auth_user');
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $created = TriageTokenService::create($user, trim($data['name']));
        $this->audit('triage_token.created', $user, (int) $created['record']['id']);

        return response()->json($created, 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $id)
    {
        $pat = TriageTokenService::find($id);
        if ($pat === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $pat->delete();
        $user = $request->attributes->get('auth_user');
        $this->audit('triage_token.revoked', $user instanceof User ? $user : null, $id);

        return response()->json(['revoked' => true, 'id' => $id]);
    }

    private function audit(string $event, ?User $actor, int $tokenId): void
    {
        SecurityAuditEvent::append($event, 'success', [
            'actor_type' => 'user',
            'actor_id' => $actor?->getKey(),
            'subject_type' => 'triage_token',
            'subject_id' => $tokenId,
        ], ['token_id' => $tokenId, 'client_ip' => (string) request()->ip()]);
    }
}
