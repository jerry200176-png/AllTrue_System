<?php

namespace App\Console\Commands;

use App\Models\CoursePackage;
use App\Models\StudentClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPackageBinding extends Command
{
    protected $signature = 'packages:backfill-bind
        {package_id : The package ID to bind courses to}
        {student_class_ids* : Space-separated StudentClass IDs to bind}
        {--dry-run : Show what would happen without actually binding}';

    protected $description = 'Bind existing StudentClass rows to a course package (migration tool)';

    public function handle(): int
    {
        $packageId = (int) $this->argument('package_id');
        $scIds = array_map('intval', $this->argument('student_class_ids'));

        $pkg = CoursePackage::find($packageId);
        if (!$pkg) {
            $this->error("Package {$packageId} not found.");
            return 1;
        }

        $courses = StudentClass::whereIn('ID', $scIds)->get();
        if ($courses->isEmpty()) {
            $this->error('No matching StudentClass rows found.');
            return 1;
        }

        $this->info("Package: {$pkg->name} (ID {$pkg->id})");
        $this->info("Total sessions: {$pkg->total_sessions}, Remaining: {$pkg->remaining_sessions}");
        $this->newLine();

        $report = [];
        foreach ($courses as $sc) {
            $alreadyBound = !empty($sc->PackageID) && (int) $sc->PackageID > 0;
            $samePackage = (int) ($sc->PackageID ?? 0) === $pkg->id;
            $report[] = [
                $sc->ID,
                $sc->displaySubjectName(),
                DB::table('User')->where('id', (int) $sc->TeacherID)->value('Name') ?? '-',
                (int) ($sc->RemainingSessions ?? 0),
                $alreadyBound ? ($samePackage ? 'Already bound (same)' : "Bound to #{$sc->PackageID}") : 'Will bind',
            ];
        }

        $this->table(['SC ID', 'Subject', 'Teacher', 'Remaining', 'Action'], $report);

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: no changes made.');
            return 0;
        }

        if (!$this->confirm('Proceed with binding?')) {
            $this->warn('Aborted.');
            return 0;
        }

        $bound = 0;
        DB::transaction(function () use ($courses, $pkg, &$bound) {
            foreach ($courses as $sc) {
                if (!empty($sc->PackageID) && (int) $sc->PackageID > 0 && (int) $sc->PackageID !== $pkg->id) {
                    continue;
                }
                $sc->PackageID = $pkg->id;
                $sc->PackageTotalSessions = $pkg->total_sessions;
                $sc->PackageName = $pkg->name;
                $sc->RemainingSessions = $pkg->remaining_sessions;
                $sc->save();
                $bound++;
            }
        });

        $this->info("Bound {$bound} courses to package #{$pkg->id}.");
        return 0;
    }
}
