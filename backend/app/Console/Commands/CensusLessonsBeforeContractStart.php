<?php

namespace App\Console\Commands;

use App\Services\Scheduling\LessonBeforeContractStartCensus;
use Illuminate\Console\Command;

/** Read-only: counts and lists (ids only, per campus) lessons dated before their contract's StartDate (in-app #536, #5198). */
class CensusLessonsBeforeContractStart extends Command
{
    protected $signature = 'sessions:census-before-contract-start {--json : Emit JSON}';

    protected $description = 'Observe-only: lessons dated before their contract StartDate, per campus (ids only). Writes nothing.';

    public function handle(LessonBeforeContractStartCensus $census): int
    {
        $result = $census->run();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info("lessons before contract start: {$result['count']}");
        foreach ($result['by_campus'] as $campus => $b) {
            $this->line("campus {$campus}: {$b['count']} ids " . implode(',', $b['ids']) . ($b['ids_truncated'] ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
