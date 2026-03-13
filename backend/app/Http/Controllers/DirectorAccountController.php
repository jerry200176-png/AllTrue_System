<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Director self-registration (public) and super_admin approval (pending list, approve, reject).
 */
class DirectorAccountController extends Controller
{
    /**
     * POST /api/v1/directors/register (public)
     * Body: name, email, password, campus_id
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:32',
            'email'     => 'required|string|email|max:64',
            'password'  => 'required|string|min:4',
            'campus_id' => 'required|integer',
        ]);

        if (User::where('LoginName', $data['email'])->exists()) {
            return response()->json(['message' => '此 Email 已被使用'], 422);
        }

        $campus = Campus::find($data['campus_id']);
        if (!$campus) {
            return response()->json(['message' => '分校不存在'], 422);
        }

        $user = new User();
        $user->LoginName = $data['email'];
        $user->Name = $data['name'];
        $user->PSW = Hash::make($data['password']);
        $user->type = 'U'; // pending until approved
        $user->phone = 0;
        $user->save();

        UserCampus::create([
            'UserID'   => $user->id,
            'CampusID' => $data['campus_id'],
            'Admin'    => 0,
            'Approved' => false,
        ]);

        return response()->json([
            'message' => '已送出申請，請等候超級管理員審核 (Application submitted, pending approval)',
        ], 201);
    }

    /**
     * GET /api/v1/directors/pending — super_admin only: list pending director applications.
     */
    public function pending(Request $request)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userIds = UserCampus::where('Approved', false)->pluck('UserID')->unique()->all();
        if (empty($userIds)) {
            return response()->json([]);
        }

        $users = User::whereIn('id', $userIds)->get();
        $campuses = Campus::all()->keyBy('id');

        $list = [];
        foreach ($users as $user) {
            $uc = UserCampus::where('UserID', $user->id)->where('Approved', false)->first();
            if (!$uc) continue;
            $campus = $campuses->get($uc->CampusID);
            $list[] = [
                'id'         => $user->id,
                'name'       => $user->Name,
                'email'      => $user->LoginName,
                'campus_id'  => (int) $uc->CampusID,
                'campus_name' => $campus ? $campus->name : '',
            ];
        }

        return response()->json($list);
    }

    /**
     * POST /api/v1/directors/{id}/approve — super_admin only.
     */
    public function approve(Request $request, int $id)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        UserCampus::where('UserID', $id)->where('Approved', false)->update(['Approved' => true]);
        $user->type = 'D';
        $user->save();

        return response()->json(['message' => '已通過審核']);
    }

    /**
     * POST /api/v1/directors/{id}/reject — super_admin only.
     */
    public function reject(Request $request, int $id)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        UserCampus::where('UserID', $id)->where('Approved', false)->delete();
        // Leave user as type U with no campus; they can re-register if needed. Optionally delete user.
        $user->delete();

        return response()->json(['message' => '已拒絕']);
    }
}
