<?php

namespace App\Models\Scopes;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Hide the canonical TEST campus and students from ordinary operational reads.
 * QA/admin code must opt in explicitly with withoutGlobalScope().
 */
final class OperationalTenantScope implements Scope
{
    private static array $hasTestColumn = [];

    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        if ($model instanceof Campus) {
            if ($this->hasTestColumn($table)) {
                $builder->where(function (Builder $query) use ($table): void {
                    $query->whereNull("{$table}.is_test")
                        ->orWhere("{$table}.is_test", false);
                });
            }

            return;
        }

        if ($table !== 'Student' || !$this->hasTestColumn('Campus')) {
            return;
        }

        $builder->whereNotExists(function ($query) use ($table): void {
            $query->selectRaw('1')
                ->from('Campus as operational_campus')
                ->whereColumn('operational_campus.id', "{$table}.CampusID")
                ->where('operational_campus.is_test', true);
        });
    }

    private function hasTestColumn(string $table): bool
    {
        if (!array_key_exists($table, self::$hasTestColumn)) {
            self::$hasTestColumn[$table] = Schema::hasColumn($table, 'is_test');
        }

        return self::$hasTestColumn[$table];
    }
}
