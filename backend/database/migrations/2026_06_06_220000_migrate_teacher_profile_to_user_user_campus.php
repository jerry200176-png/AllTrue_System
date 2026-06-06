<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('User') && !Schema::hasColumn('User', 'LineID')) {
            Schema::table('User', function (Blueprint $table) {
                $table->string('LineID', 128)->nullable()->after('phone');
            });
        }

        if (!Schema::hasTable('Teacher') || !Schema::hasTable('User')) {
            return;
        }

        $hasUserPhone = Schema::hasColumn('User', 'phone');
        $hasUserLine = Schema::hasColumn('User', 'LineID');
        $hasUserCampus = Schema::hasTable('UserCampus');
        $hasUserCampusRfid = $hasUserCampus && Schema::hasColumn('UserCampus', 'RFID');
        $hasUserCampusApproved = $hasUserCampus && Schema::hasColumn('UserCampus', 'Approved');

        DB::table('Teacher')->orderBy('id')->chunk(200, function ($teachers) use (
            $hasUserPhone,
            $hasUserLine,
            $hasUserCampus,
            $hasUserCampusRfid,
            $hasUserCampusApproved
        ) {
            foreach ($teachers as $teacher) {
                $user = DB::table('User')->where('id', (int) $teacher->id)->first();
                if (!$user) {
                    continue;
                }

                $userPatch = [];
                if ($hasUserPhone && trim((string) ($user->phone ?? '')) === '' && trim((string) ($teacher->Phone ?? '')) !== '') {
                    $userPatch['phone'] = preg_replace('/\D+/', '', (string) $teacher->Phone) ?: $teacher->Phone;
                }
                if ($hasUserLine && trim((string) ($user->LineID ?? '')) === '' && trim((string) ($teacher->LineID ?? '')) !== '') {
                    $userPatch['LineID'] = trim((string) $teacher->LineID);
                }
                if (!empty($userPatch)) {
                    DB::table('User')->where('id', (int) $teacher->id)->update($userPatch);
                }

                if (!$hasUserCampus) {
                    continue;
                }

                $campusId = (int) ($teacher->CampusID ?? 0);
                if ($campusId <= 0) {
                    continue;
                }

                $exists = DB::table('UserCampus')
                    ->where('UserID', (int) $teacher->id)
                    ->where('CampusID', $campusId)
                    ->exists();
                if (!$exists) {
                    $insert = [
                        'UserID' => (int) $teacher->id,
                        'CampusID' => $campusId,
                        'Admin' => 0,
                    ];
                    if ($hasUserCampusApproved) {
                        $insert['Approved'] = true;
                    }
                    if ($hasUserCampusRfid && trim((string) ($teacher->RFID ?? '')) !== '') {
                        $insert['RFID'] = trim((string) $teacher->RFID);
                    }
                    DB::table('UserCampus')->insert($insert);
                } elseif ($hasUserCampusRfid && trim((string) ($teacher->RFID ?? '')) !== '') {
                    DB::table('UserCampus')
                        ->where('UserID', (int) $teacher->id)
                        ->where('CampusID', $campusId)
                        ->where(function ($query) {
                            $query->whereNull('RFID')->orWhere('RFID', '');
                        })
                        ->update(['RFID' => trim((string) $teacher->RFID)]);
                }
            }
        });
    }

    public function down()
    {
        // Intentionally keep migrated User/UserCampus data. Dropping it would lose live profile state.
    }
};
