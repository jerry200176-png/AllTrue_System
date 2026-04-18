<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reset PHP-FPM opcache without requiring sudo.
 *
 * Background:
 *   PHP-FPM opcache is a shared-memory cache populated on first request per
 *   script. CLI (artisan) and FPM have *separate* opcache pools, so running
 *   this command from artisan would only clear the CLI pool — useless for the
 *   actual web requests. Therefore this command spawns a short-lived PHP file
 *   inside public/, calls it via loopback HTTPS (which goes through FPM),
 *   triggers opcache_reset() there, then deletes the file.
 *
 * Usage:
 *   php artisan opcache:reset
 *   php artisan opcache:reset --host=daan.lifenet.com.tw
 *
 * See: docs/AI_REGRESSION_LESSONS.md (opcache gotcha) and deploy.sh.
 */
class OpcacheReset extends Command
{
    protected $signature = 'opcache:reset {--host=daan.lifenet.com.tw : HTTPS host served by Apache/FPM} {--scheme=https}';

    protected $description = 'Reset PHP-FPM opcache via a short-lived public endpoint (no sudo required).';

    public function handle(): int
    {
        $host   = (string) $this->option('host');
        $scheme = (string) $this->option('scheme');
        $token  = bin2hex(random_bytes(16));
        $name   = '_opcache_reset_' . bin2hex(random_bytes(4)) . '.php';
        $path   = public_path($name);

        $payload = <<<'PHP'
<?php
if (!hash_equals(__TOKEN__, $_GET['t'] ?? '')) { http_response_code(403); exit('forbidden'); }
header('Content-Type: application/json');
$ok = function_exists('opcache_reset') ? opcache_reset() : false;
echo json_encode(['ok' => $ok, 'ts' => time()]);
PHP;
        $payload = str_replace('__TOKEN__', var_export($token, true), $payload);

        if (file_put_contents($path, $payload) === false) {
            $this->error("Failed to write temp file: {$path}");
            return self::FAILURE;
        }

        try {
            $url = sprintf('%s://%s/%s?t=%s', $scheme, $host, $name, $token);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RESOLVE        => [$host . ':443:127.0.0.1', $host . ':80:127.0.0.1'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $code !== 200) {
                $this->error("Reset request failed (HTTP {$code}): {$err}");
                return self::FAILURE;
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || empty($decoded['ok'])) {
                $this->error("Reset endpoint returned unexpected payload: {$body}");
                return self::FAILURE;
            }
            $this->info('PHP-FPM opcache reset successfully.');
            return self::SUCCESS;
        } finally {
            @unlink($path);
        }
    }
}
