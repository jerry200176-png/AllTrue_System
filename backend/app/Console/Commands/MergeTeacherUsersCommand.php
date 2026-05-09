<?php

namespace App\Console\Commands;

use App\Services\TeacherUserMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeTeacherUsersCommand extends Command
{
    protected $signature = 'teachers:merge-users
                            {--keep-login= : LoginName of the account to retain (e.g. Jw0103)}
                            {--merge-login= : LoginName of the duplicate account to merge away}
                            {--merge-user-id= : Merge away by User.id (alternative to merge-login)}
                            {--apply : Execute merge in a DB transaction (without this flag: preview only)}';

    protected $description = 'Merge duplicate teacher User rows into one canonical login (FK remap + deactivate duplicate).';

    public function handle(TeacherUserMergeService $mergeService): int
    {
        $keepLogin = (string) $this->option('keep-login');
        $mergeLogin = (string) $this->option('merge-login');
        $mergeUserIdOpt = $this->option('merge-user-id');

        if ($keepLogin === '') {
            $this->error('Missing --keep-login=');

            return self::FAILURE;
        }

        if ($mergeLogin === '' && ($mergeUserIdOpt === null || $mergeUserIdOpt === '')) {
            $this->error('Provide --merge-login= or --merge-user-id=');

            return self::FAILURE;
        }

        if ($mergeLogin !== '' && $mergeUserIdOpt !== null && $mergeUserIdOpt !== '') {
            $this->error('Use only one of --merge-login or --merge-user-id.');

            return self::FAILURE;
        }

        $keepId = $mergeService->resolveUserIdByLogin($keepLogin);
        if ($keepId === null) {
            $this->error('keep-login not found: ' . $keepLogin);

            return self::FAILURE;
        }

        if ($mergeLogin !== '') {
            $mergeId = $mergeService->resolveUserIdByLogin($mergeLogin);
            if ($mergeId === null) {
                $this->error('merge-login not found: ' . $mergeLogin);

                return self::FAILURE;
            }
        } else {
            $mergeId = (int) $mergeUserIdOpt;
            $mergeRow = DB::table('User')->where('id', $mergeId)->first(['id']);
            if (!$mergeRow) {
                $this->error('merge-user-id not found: ' . $mergeId);

                return self::FAILURE;
            }
        }

        try {
            $mergeService->assertMergeable($keepId, $mergeId);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $preview = $mergeService->preview($keepId, $mergeId);

        $this->line('Keep user id: ' . $keepId . ' (' . $keepLogin . ')');
        $this->line('Merge away user id: ' . $mergeId);
        $this->line('Row counts referencing merge user (preview):');
        $this->output->writeln(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (!$this->option('apply')) {
            $this->warn('Dry-run only (no DB writes). Re-run with --apply after backup.');

            return self::SUCCESS;
        }

        $mergeService->merge($keepId, $mergeId);
        $this->info('Merge completed: keep=' . $keepId . ', merged away=' . $mergeId);

        return self::SUCCESS;
    }
}
