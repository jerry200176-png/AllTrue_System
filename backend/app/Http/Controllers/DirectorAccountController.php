<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Director self-registration (public) and super_admin approval (pending list, approve, reject).
 */
class DirectorAccountController extends Controller
{
    /**
     * POST /api/v1/directors/register (public)
     * Body: name, account/email, password, campus_id
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:32',
            'account'   => 'nullable|string|max:64|required_without:email',
            'email'     => 'nullable|string|max:64|required_without:account',
            'password'  => 'required|string|min:4',
            'campus_id' => 'required|integer',
        ]);
        $loginName = trim((string) ($data['account'] ?? $data['email'] ?? ''));
        if ($loginName === '') {
            return response()->json(['message' => '帳號不可空白'], 422);
        }

        $accountInUseByDirectorSide = User::where('LoginName', $loginName)
            ->whereIn('type', ['D', 'U', 'S', 'A'])
            ->exists();
        if ($accountInUseByDirectorSide) {
            return response()->json(['message' => '此帳號已被使用'], 422);
        }

        $campus = Campus::find($data['campus_id']);
        if (!$campus) {
            return response()->json(['message' => '分校不存在'], 422);
        }

        $user = new User();
        $user->LoginName = $loginName;
        $user->Name = $data['name'];
        $user->PSW = Hash::make($data['password']);
        $user->type = 'U'; // pending until approved
        $user->phone = null;
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
                'account'    => $user->LoginName,
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

    /**
     * GET /api/v1/directors — super_admin only: list active directors.
     */
    public function index(Request $request)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $users = User::where('type', 'D')->get();
        $campuses = Campus::all()->keyBy('id');

        $list = $users->map(function (User $user) use ($campuses) {
            $campusIds = UserCampus::where('UserID', $user->id)
                ->where('Approved', true)
                ->pluck('CampusID')
                ->map(fn ($id) => (int) $id)
                ->all();

            $campusNames = collect($campusIds)
                ->map(fn ($id) => $campuses->get($id)?->name)
                ->filter()
                ->values()
                ->all();

            return [
                'id'           => $user->id,
                'name'         => $user->Name,
                'account'      => $user->LoginName,
                'campus_ids'   => $campusIds,
                'campus_names' => $campusNames,
            ];
        })->values();

        return response()->json($list);
    }

    /**
     * POST /api/v1/directors/{id}/reset-password — super_admin only.
     */
    public function resetPassword(Request $request, int $id)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::find($id);
        if (!$user || (string) $user->type !== 'D') {
            return response()->json(['message' => '找不到此主任帳號'], 404);
        }

        $temporaryPassword = $this->generatePassword();
        $user->PSW = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        if (Schema::hasColumn('User', 'MustChangePassword')) {
            $user->MustChangePassword = true;
        }
        if (Schema::hasColumn('User', 'PasswordChangedAt')) {
            $user->PasswordChangedAt = null;
        }
        if (Schema::hasColumn('User', 'PasswordSetByUserID')) {
            $operator = $request->attributes->get('auth_user');
            $user->PasswordSetByUserID = $operator?->id;
        }
        $user->save();

        return response()->json([
            'message'            => '密碼已重設',
            'id'                 => (int) $user->id,
            'name'               => $user->Name,
            'account'            => $user->LoginName,
            'temporary_password' => $temporaryPassword,
            'must_change_password' => true,
        ]);
    }

    /**
     * DELETE /api/v1/directors/{id} — super_admin only: remove an approved director account.
     */
    public function destroy(Request $request, int $id)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $operator = $request->attributes->get('auth_user');
        if ($operator && (int) $operator->id === $id) {
            return response()->json(['message' => '不可刪除目前登入帳號'], 422);
        }

        $user = User::find($id);
        if (!$user || (string) $user->type !== 'D') {
            return response()->json(['message' => '找不到此主任帳號'], 404);
        }

        DB::transaction(function () use ($user) {
            $uid = (int) $user->id;

            if (Schema::hasTable('LearningRecord')) {
                if (Schema::hasColumn('LearningRecord', 'ApprovedBy')) {
                    DB::table('LearningRecord')->where('ApprovedBy', $uid)->update(['ApprovedBy' => null]);
                }
                if (Schema::hasColumn('LearningRecord', 'CreatedByUserID')) {
                    DB::table('LearningRecord')->where('CreatedByUserID', $uid)->update(['CreatedByUserID' => null]);
                }
            }

            if (Schema::hasTable('User') && Schema::hasColumn('User', 'PasswordSetByUserID')) {
                DB::table('User')->where('PasswordSetByUserID', $uid)->update(['PasswordSetByUserID' => null]);
            }

            UserCampus::where('UserID', $uid)->delete();

            if (Schema::hasTable('NotificationReads')) {
                DB::table('NotificationReads')->where('UserID', $uid)->delete();
            }
            if (Schema::hasTable('auth_tokens')) {
                DB::table('auth_tokens')->where('user_id', $uid)->delete();
            }
            if (Schema::hasTable('user_login_activities')) {
                DB::table('user_login_activities')->where('user_id', $uid)->delete();
            }
            if (Schema::hasTable('user_notification_preferences')) {
                DB::table('user_notification_preferences')->where('user_id', $uid)->delete();
            }
            if (Schema::hasTable('password_reset_requests')) {
                DB::table('password_reset_requests')->where('user_id', $uid)->delete();
            }

            $user->delete();
        });

        return response()->json(['message' => '主任帳號已刪除', 'id' => $id]);
    }

    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
}
