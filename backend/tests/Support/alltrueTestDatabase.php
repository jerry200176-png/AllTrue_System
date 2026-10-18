<?php

/**
 * Allowed synthetic MySQL schemas for multi-process billing race workers.
 * Matches scripts/phpunit-isolated.sh validate_schema_name for namespaced DBs,
 * plus the exact CI default AllTrue_test. Rejects production / empty / illegal.
 */
function alltrue_is_allowed_test_database(?string $name): bool
{
    if ($name === 'AllTrue_test') {
        return true;
    }
    if ($name === null || $name === '' || $name === 'AllTrue') {
        return false;
    }
    if (strlen($name) > 64) {
        return false;
    }

    return (bool) preg_match('/^AllTrue_test_[A-Za-z0-9_]+$/', $name);
}

function alltrue_is_allowed_test_db_host(?string $host): bool
{
    return in_array($host ?? '', ['127.0.0.1', 'localhost'], true);
}
