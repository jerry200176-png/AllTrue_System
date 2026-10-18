<?php

/**
 * Allowed synthetic MySQL schemas for multi-process billing race workers.
 * Matches scripts/phpunit-isolated.sh validate_schema_name for namespaced DBs,
 * plus the exact CI default AllTrue_test. Rejects production / empty / illegal / trailing LF.
 *
 * Use \z (not $) so a trailing newline cannot sneak past the end anchor.
 */
function alltrue_is_allowed_test_database(?string $name): bool
{
    if ($name === 'AllTrue_test') {
        return true;
    }
    if ($name === null || $name === '' || $name === 'AllTrue') {
        return false;
    }
    // Reject CR/LF before length/regex — `$` would still match before a final LF.
    if (strpbrk($name, "\r\n") !== false) {
        return false;
    }
    if (strlen($name) > 64) {
        return false;
    }

    return (bool) preg_match('/^AllTrue_test_[A-Za-z0-9_]+\z/', $name);
}

function alltrue_is_allowed_test_db_host(?string $host): bool
{
    return in_array($host ?? '', ['127.0.0.1', 'localhost'], true);
}

/**
 * After a matching SQL runs (listen), touch ready and block until release.
 * Used so a worker can hold a lock while the parent coordinates the peer.
 *
 * @param  array{match:string,ready:string,release:string,locked?:bool}|null  $pause
 */
function alltrue_install_sql_pause(?array $pause, string $timeoutMessage): void
{
    if ($pause === null) {
        return;
    }
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$pause, $timeoutMessage) {
        if ($pause && str_contains(strtolower($query->sql), $pause['match'])
            && (!($pause['locked'] ?? true) || str_contains(strtolower($query->sql), 'for update'))) {
            $p = $pause;
            $pause = null;
            touch($p['ready']);
            $deadline = microtime(true) + 15;
            while (!file_exists($p['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException($timeoutMessage);
                }
                usleep(5000);
            }
        }
    });
}

/**
 * beforeExecuting: fire once when the next StudentClass FOR UPDATE is about to run.
 * Does not wait — parent awaits the marker, then releases the peer that holds the course.
 * Course-first void: marker before report lock. Report-first void: marker after report lock.
 */
function alltrue_install_before_studentclass_for_update_signal(?string $markerPath): void
{
    if ($markerPath === null || $markerPath === '') {
        return;
    }
    $pending = $markerPath;
    Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) use (&$pending) {
        if ($pending === null) {
            return;
        }
        $s = strtolower((string) $sql);
        if (str_contains($s, 'for update') && str_contains($s, 'studentclass')) {
            $path = $pending;
            $pending = null;
            touch($path);
        }
    });
}
