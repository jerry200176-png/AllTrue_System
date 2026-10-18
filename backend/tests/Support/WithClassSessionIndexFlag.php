<?php

namespace Tests\Support;

/** Restore all environment channels, including native putenv (not backed up by PHPUnit globals). */
trait WithClassSessionIndexFlag
{
    private bool $classSessionIndexFlagCaptured = false;

    protected function enableClassSessionIndexFlag(): void
    {
        $key = 'APPLY_CLASS_SESSION_UNIQUE_INDEX';
        if (!$this->classSessionIndexFlagCaptured) {
            $this->classSessionIndexFlagCaptured = true;
            $process = getenv($key);
            $env = $_ENV[$key] ?? null;
            $server = $_SERVER[$key] ?? null;
            $this->beforeApplicationDestroyed(static function () use ($key, $process, $env, $server): void {
                putenv($process === false ? $key : $key.'='.$process);
                if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
                if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
            });
        }
        putenv($key.'=1');
        $_ENV[$key] = $_SERVER[$key] = '1';
    }
}
