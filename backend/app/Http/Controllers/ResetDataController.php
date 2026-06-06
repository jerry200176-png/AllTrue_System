<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 僅限 super_admin 呼叫：清空所有課程、學生、老師，保留 super_admin 帳號。
 */
class ResetDataController extends Controller
{
    public function __invoke(Request $request)
    {
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            return response()->json(['message' => '僅限 Super Admin 執行'], 403);
        }
        try {
            DB::transaction(function () {
                // 1. 依賴學生/課程的資料先刪（表名與 migration / Model 一致）
                if (Schema::hasTable('schedules')) {
                    DB::table('schedules')->delete();
                }
                if (Schema::hasTable('ClassSession')) {
                    DB::table('ClassSession')->delete();
                }
                if (Schema::hasTable('LearningRecord')) {
                    DB::table('LearningRecord')->delete();
                }

                // 2. 課程
                if (Schema::hasTable('StudentClass')) {
                    DB::table('StudentClass')->delete();
                }

                // 3. 學生
                if (Schema::hasTable('Student')) {
                    DB::table('Student')->delete();
                }

                // 4. 老師（Teacher 表）
                // 5. User / UserCampus / auth_tokens：
                //    - 只刪除 type = 'T','U','D'（老師、待審、主任）
                //    - 保留 type = 'S' 的 super_admin 帳號
                if (Schema::hasTable('User')) {
                    $userIds = DB::table('User')
                        ->whereIn('type', ['T', 'U', 'D'])
                        ->pluck('id')
                        ->all();

                    if (!empty($userIds)) {
                        if (Schema::hasTable('UserCampus')) {
                            DB::table('UserCampus')
                                ->whereIn('UserID', $userIds)
                                ->delete();
                        }

                        if (Schema::hasTable('auth_tokens')) {
                            DB::table('auth_tokens')
                                ->whereIn('user_id', $userIds)
                                ->delete();
                        }

                        DB::table('User')
                            ->whereIn('id', $userIds)
                            ->delete();
                    }
                }
            });

            return response()->json([
                'message' => '已清空所有課程、學生與老師（含主任帳號），Super Admin 已保留。',
                'cleared' => [
                    'schedules',
                    'ClassSession',
                    'LearningRecord',
                    'StudentClass',
                    'Student',
                    'Teacher',
                    'User(T,U,D)',
                    'UserCampus',
                    'auth_tokens',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => '清空失敗',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
