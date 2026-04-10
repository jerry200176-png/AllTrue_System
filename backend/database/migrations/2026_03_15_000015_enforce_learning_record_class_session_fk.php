<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('LearningRecord') || !Schema::hasTable('ClassSession')) {
            return;
        }

        if (!Schema::hasColumn('LearningRecord', 'ClassSessionID')) {
            return;
        }

        $this->repairLearningRecordSessionFields();
        $this->deleteOrphanLearningRecords();

        // SQLite ALTER TABLE support is limited in CI/local tests; keep runtime-safe.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->ensureUnsignedClassSessionIdColumn();

        if (!$this->hasForeignKey('LearningRecord', 'ClassSessionID')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->foreign('ClassSessionID', 'learningrecord_classsession_fk')
                    ->references('id')
                    ->on('ClassSession')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if ($this->hasForeignKey('LearningRecord', 'ClassSessionID')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropForeign('learningrecord_classsession_fk');
            });
        }
    }

    private function repairLearningRecordSessionFields(): void
    {
        $hasSessionDate = Schema::hasColumn('LearningRecord', 'SessionDate');
        $hasStartTime = Schema::hasColumn('LearningRecord', 'StartTime');
        $hasEndTime = Schema::hasColumn('LearningRecord', 'EndTime');

        $setClauses = ['lr.StudentClassID = cs.StudentClassID'];
        $whereClauses = ['lr.StudentClassID <> cs.StudentClassID'];
        if ($hasSessionDate) {
            $setClauses[] = 'lr.SessionDate = cs.SessionDate';
            $whereClauses[] = 'lr.SessionDate <> cs.SessionDate';
            $whereClauses[] = 'lr.SessionDate IS NULL';
        }
        if ($hasStartTime) {
            $setClauses[] = 'lr.StartTime = cs.StartTime';
            $whereClauses[] = 'lr.StartTime <> cs.StartTime';
            $whereClauses[] = 'lr.StartTime IS NULL';
        }
        if ($hasEndTime) {
            $setClauses[] = 'lr.EndTime = cs.EndTime';
            $whereClauses[] = 'lr.EndTime <> cs.EndTime';
            $whereClauses[] = 'lr.EndTime IS NULL';
        }

        $sql = sprintf(
            "UPDATE LearningRecord lr JOIN ClassSession cs ON cs.id = lr.ClassSessionID SET %s WHERE %s",
            implode(', ', $setClauses),
            implode(' OR ', $whereClauses)
        );

        if (DB::getDriverName() === 'sqlite') {
            $selectColumns = [
                'lr.id as lr_id',
                'cs.StudentClassID',
            ];
            if ($hasSessionDate) {
                $selectColumns[] = 'cs.SessionDate';
            }
            if ($hasStartTime) {
                $selectColumns[] = 'cs.StartTime';
            }
            if ($hasEndTime) {
                $selectColumns[] = 'cs.EndTime';
            }

            $rows = DB::table('LearningRecord as lr')
                ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
                ->select($selectColumns)
                ->get();

            foreach ($rows as $row) {
                $payload = [
                    'StudentClassID' => $row->StudentClassID,
                ];
                if ($hasSessionDate) {
                    $payload['SessionDate'] = $row->SessionDate;
                }
                if ($hasStartTime) {
                    $payload['StartTime'] = $row->StartTime;
                }
                if ($hasEndTime) {
                    $payload['EndTime'] = $row->EndTime;
                }
                DB::table('LearningRecord')
                    ->where('id', $row->lr_id)
                    ->update($payload);
            }
            return;
        }

        DB::statement($sql);
    }

    private function deleteOrphanLearningRecords(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $validIds = DB::table('ClassSession')->pluck('id');
            if ($validIds->isEmpty()) {
                DB::table('LearningRecord')->delete();
                return;
            }
            DB::table('LearningRecord')
                ->whereNotIn('ClassSessionID', $validIds)
                ->delete();
            return;
        }

        DB::statement(<<<'SQL'
DELETE lr
FROM LearningRecord lr
LEFT JOIN ClassSession cs ON cs.id = lr.ClassSessionID
WHERE cs.id IS NULL
SQL);
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        $database = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column]
        );

        return $row !== null;
    }

    private function ensureUnsignedClassSessionIdColumn(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // ClassSession.id is BIGINT UNSIGNED (bigIncrements); LearningRecord.ClassSessionID
        // must match exactly before FK can be created.
        DB::statement('ALTER TABLE `LearningRecord` MODIFY `ClassSessionID` BIGINT UNSIGNED NOT NULL');
    }
};

