<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Superadmin-only CRUD for Campus (branches).
 * All routes are protected by the 'super_admin' middleware.
 */
class AdminCampusController extends Controller
{
    public function index(Request $request)
    {
        $campuses = Campus::query()
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($c) => $this->format($c));

        return response()->json($campuses);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:64',
            'code'               => 'required|string|max:32|unique:Campus,code',
            'active'             => 'boolean',
            'SwipeWindowMinutes' => 'nullable|integer|min:1|max:120',
            'Token'              => 'nullable|string|max:128',
            'LineNotifyID'       => 'nullable|string|max:255',
            'TelegramToken'      => 'nullable|string|max:255',
            'TelegramChatID'     => 'nullable|string|max:64',
        ]);

        $campus = Campus::create([
            'name'               => $data['name'],
            'code'               => $data['code'],
            'active'             => $data['active'] ?? true,
            'Current'            => 0,
            'Token'              => $data['Token'] ?? Str::random(64),
            'SwipeWindowMinutes' => $data['SwipeWindowMinutes'] ?? 30,
            'LineNotifyID'       => $data['LineNotifyID'] ?? '',
            'Client_ID'          => '',
            'Client_Secret'      => '',
            'LIFFID'             => '',
            'LIFF_URL'           => '',
            'URL'                => '',
            'TelegramToken'      => $data['TelegramToken'] ?? null,
            'TelegramChatID'     => $data['TelegramChatID'] ?? null,
            'TelegramURL'        => '',
            'TeachLIFFID'        => '',
            'TeachLIFF_URL'      => '',
        ]);

        return response()->json($this->format($campus), 201);
    }

    public function update(Request $request, int $id)
    {
        $campus = Campus::findOrFail($id);

        $data = $request->validate([
            'name'               => 'sometimes|string|max:64',
            'code'               => "sometimes|string|max:32|unique:Campus,code,{$id}",
            'active'             => 'sometimes|boolean',
            'SwipeWindowMinutes' => 'nullable|integer|min:1|max:120',
            'Token'              => 'nullable|string|max:128',
            'LineNotifyID'       => 'nullable|string|max:255',
            'TelegramToken'      => 'nullable|string|max:255',
            'TelegramChatID'     => 'nullable|string|max:64',
        ]);

        $campus->fill($data);
        $campus->save();

        return response()->json($this->format($campus));
    }

    public function destroy(int $id)
    {
        $campus = Campus::findOrFail($id);

        $userCount = UserCampus::where('CampusID', $id)->count();
        if ($userCount > 0) {
            return response()->json([
                'message' => "此分校仍有 {$userCount} 位使用者，請先移除使用者或改為停用。",
            ], 422);
        }

        $campus->delete();
        return response()->json(['ok' => true]);
    }

    private function format(Campus $c): array
    {
        return [
            'id'                 => $c->id,
            'name'               => $c->name,
            'code'               => $c->code ?? '',
            'active'             => (bool) ($c->active ?? true),
            'Token'              => $c->Token ?? '',
            'SwipeWindowMinutes' => (int) ($c->SwipeWindowMinutes ?? 30),
            'LineNotifyID'       => $c->LineNotifyID ?? '',
            'TelegramToken'      => $c->TelegramToken ?? '',
            'TelegramChatID'     => $c->TelegramChatID ?? '',
        ];
    }
}
