<?php

namespace App\Helpers;

class FeatureFlag
{
    /** Values are snapshotted during configuration load/cache rebuild. */
    public static function enabled(string $key, ?int $campusId = null): bool
    {
        $envKey = 'FEATURE_' . strtoupper(str_replace(['-', '.'], '_', $key));
        $values = config('feature_flags.values', []);

        if ($campusId !== null) {
            $override = $values[$envKey . '_CAMPUS_' . $campusId] ?? null;
            if ($override !== null) {
                return (bool) $override;
            }
        }

        return (bool) ($values[$envKey] ?? false);
    }

    public static function disabled(string $key, ?int $campusId = null): bool
    {
        return !self::enabled($key, $campusId);
    }
}
