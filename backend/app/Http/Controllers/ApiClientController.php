<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\Campus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiClientController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = ApiClient::query()
            ->join('Campus', 'ApiClient.CampusID', '=', 'Campus.id')
            ->select([
                'ApiClient.id',
                'ApiClient.Name',
                'ApiClient.CampusID',
                'ApiClient.Active',
                'ApiClient.created_at',
                'Campus.name as campus_name',
            ])
            ->where('ApiClient.Active', 1);

        if (!empty($campusIds)) {
            $query->whereIn('ApiClient.CampusID', $campusIds);
        }

        $clients = $query->orderBy('ApiClient.id', 'desc')->get();

        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'Name' => 'required|string|max:64',
            'CampusID' => 'required|integer',
        ]);

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array((int) $data['CampusID'], $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $campus = Campus::findOrFail($data['CampusID']);

        $rawKey = 'sk_live_' . Str::random(32);
        $hash = hash('sha256', $rawKey);

        $client = ApiClient::create([
            'Name' => $data['Name'],
            'ApiKeyHash' => $hash,
            'CampusID' => $campus->id,
            'Active' => 1,
        ]);

        return response()->json([
            'api_key' => $rawKey,
            'client' => [
                'id' => $client->id,
                'name' => $client->Name,
                'campus_id' => $client->CampusID,
                'campus_name' => $campus->name,
                'active' => (bool) $client->Active,
                'created_at' => $client->created_at,
            ],
        ]);
    }

    public function revoke(ApiClient $apiClient)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array((int) $apiClient->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $apiClient->Active = 0;
        $apiClient->save();

        return response()->json(['status' => 'revoked']);
    }
}
