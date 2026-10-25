<?php
/** Run-private synthetic fixture artifacts; never accepts a real database target. */
declare(strict_types=1);
require_once __DIR__.'/agreed-fee-isolated-db-guard.php';

function agreed_fee_isolated_fixture_directory(): string
{
    agreed_fee_isolated_assert_db();
    $root = (string) getenv('AGREED_FEE_ISOLATED_ARTIFACT_DIR');
    if ($root === '' || !is_dir($root) || realpath($root) !== $root || is_link($root)
        || (fileperms($root) & 0777) !== 0700 || fileowner($root) !== posix_geteuid()) {
        agreed_fee_isolated_refuse('fixture directory must be canonical, owned and mode0700');
    }
    return $root;
}

function agreed_fee_isolated_fixture_write(string $path, string $contents): void
{
    $previous = umask(0077);
    try {
        $file = fopen($path, 'x');
        if ($file === false) agreed_fee_isolated_refuse('fixture artifact already exists or cannot be created');
        try {
            if (fwrite($file, $contents) !== strlen($contents)) agreed_fee_isolated_refuse('incomplete fixture artifact');
        } finally { fclose($file); }
    } finally { umask($previous); }
}

/** Fake outbound transports, never the inbound API or MySQL; any attempted send fails the harness. */
function agreed_fee_isolated_block_side_effects(): void
{
    \Illuminate\Support\Facades\Http::fake(static fn () => throw new \RuntimeException('isolated outbound HTTP forbidden'));
    \Illuminate\Support\Facades\Notification::fake();
    // Laravel 12 resolves the manager before faking; registration stays inside this guarded harness.
    app()->register(\Illuminate\Mail\MailServiceProvider::class);
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Queue::fake();
}

function agreed_fee_isolated_assert_no_side_effects(): void
{
    \Illuminate\Support\Facades\Notification::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertNothingOutgoing();
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
}
