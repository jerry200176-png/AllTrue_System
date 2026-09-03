<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Models\Campus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** One-time, host-local bootstrap for the narrowly scoped POP machine identity. */
final class PopBootstrapMachine extends Command
{
    protected $signature = 'pop:bootstrap-machine
        {--campus-id= : Campus the machine may operate on}
        {--name=POP Pi executor : Human-readable machine identity name}
        {--key-file=storage/app/private/pop-machine.key : Host-local key file}
        {--confirm= : Must be POP_BOOTSTRAP_MACHINE}';

    protected $description = 'Create one scoped POP machine identity without displaying its secret';

    public function handle(): int
    {
        if ($this->option('confirm') !== 'POP_BOOTSTRAP_MACHINE') {
            $this->error('Bootstrap requires --confirm=POP_BOOTSTRAP_MACHINE.');
            return self::FAILURE;
        }

        $campusId = (int) $this->option('campus-id');
        $name = trim((string) $this->option('name'));
        $relativePath = trim((string) $this->option('key-file'));
        if ($campusId <= 0 || $name === '' || strlen($name) > 64 || $relativePath === '' || str_contains($relativePath, '..')) {
            $this->error('Campus, name, and a safe relative key-file path are required.');
            return self::FAILURE;
        }

        if (!Campus::query()->whereKey($campusId)->exists()) {
            $this->error('Campus does not exist.');
            return self::FAILURE;
        }
        if (ApiClient::query()->where('Name', $name)->where('CampusID', $campusId)->where('Purpose', 'pop_control_plane')->where('Active', 1)->exists()) {
            $this->error('An active POP machine identity with this name already exists.');
            return self::FAILURE;
        }

        $path = base_path($relativePath);
        if (File::exists($path)) {
            $this->error('Key file already exists; refusing to overwrite it.');
            return self::FAILURE;
        }

        $rawKey = 'pop_machine_' . Str::random(48);
        File::ensureDirectoryExists(dirname($path), 0700);
        if (file_put_contents($path, $rawKey . PHP_EOL, LOCK_EX) === false) {
            $this->error('Could not create the host-local key file.');
            return self::FAILURE;
        }
        chmod($path, 0600);

        try {
            $client = new ApiClient([
                'Name' => $name,
                'ApiKeyHash' => hash('sha256', $rawKey),
                'CampusID' => $campusId,
                'Active' => 1,
                'Purpose' => 'pop_control_plane',
                'Scopes' => ['pop:draft', 'pop:dry-run'],
            ]);
            $client->save();
        } catch (\Throwable $exception) {
            File::delete($path);
            report($exception);
            $this->error('Machine identity was not persisted; bootstrap failed closed.');
            return self::FAILURE;
        }

        // Never print the key. The only delivery channel is the restricted
        // file on the production host where the executor already runs.
        $this->info('POP machine identity created: client_id=' . (int) $client->getKey() . ', campus_id=' . $campusId . '.');
        $this->info('Secret retained only in the host-local restricted key file.');
        return self::SUCCESS;
    }
}
