<?php

namespace App\Http\Controllers;

use App\Services\ParentPortalTestFixtureService;

final class ParentPortalTestFixtureController extends Controller
{
    public function ensure(ParentPortalTestFixtureService $fixtures)
    {
        try {
            return response()->json(['fixture' => $fixtures->ensureFixture()]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function session(ParentPortalTestFixtureService $fixtures)
    {
        try {
            $fixture = $fixtures->ensureFixture();
            $session = $fixtures->createReusableSession($fixture);

            return response()->json([
                'fixture' => $fixture,
                'session' => $session,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
