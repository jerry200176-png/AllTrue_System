<?php

// Discover names from the standard Laravel environment adapters; read each
// matching value through env() to preserve Laravel precedence/normalization.
// Cache only bool/null values, never raw environment contents.
$names = array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv())));
$values = [];
foreach ($names as $name) {
    if (!is_string($name) || !str_starts_with($name, 'FEATURE_')) {
        continue;
    }
    $value = env($name);
    $values[$name] = $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

return ['values' => $values];
