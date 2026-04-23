<?php

namespace App\Helpers;

class FeatureFlag
{
    /**
     * 用法：FeatureFlag::enabled('new-billing')
     * .env：FEATURE_NEW_BILLING=true
     * 分校覆寫：FEATURE_NEW_BILLING_CAMPUS_2=false  ← 只蓋該分校，其他不影響
     */
    public static function enabled(string $key, ?int $campusId = null): bool
    {
        $envKey = 'FEATURE_' . strtoupper(str_replace(['-', '.'], '_', $key));

        if ($campusId !== null) {
            $override = env($envKey . '_CAMPUS_' . $campusId);
            if ($override !== null) {
                return filter_var($override, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return filter_var(env($envKey, false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function disabled(string $key, ?int $campusId = null): bool
    {
        return !self::enabled($key, $campusId);
    }
}
