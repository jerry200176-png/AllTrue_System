<?php

namespace Tests\Feature;

use App\Operations\Inapp\InappReportResolveEvidence;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The resolve evidence reads GitHub with the deploy-written read-only token; without it, anonymously (60 calls an hour). */
final class PopInappGithubReadTokenTest extends TestCase
{
    private string $file;
    private ?string $envToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path(InappReportResolveEvidence::TOKEN_FILE);
        $this->envToken = getenv('GITHUB_TOKEN') === false ? null : getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');
        unset($_ENV['GITHUB_TOKEN'], $_SERVER['GITHUB_TOKEN']);
        config(['pop_inapp.github_repo' => 'jerry200176-png/AllTrue_System']);
        Http::fake(['api.github.com/*' => Http::response(['id' => 1], 200)]);
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        if ($this->envToken !== null) putenv('GITHUB_TOKEN=' . $this->envToken);
        parent::tearDown();
    }

    private function authorizationSent(): string
    {
        app(InappReportResolveEvidence::class)->successfulDeploy('1');
        $sent = '';
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request->header('Authorization')[0] ?? '';
            return true;
        });

        return $sent;
    }

    public function test_the_deploy_written_token_file_authenticates_the_github_reads(): void
    {
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, "github_pat_ABCdef0123456789_xyz\n");

        $this->assertSame('Bearer github_pat_ABCdef0123456789_xyz', $this->authorizationSent());
    }

    public function test_no_or_malformed_token_file_reads_anonymously(): void
    {
        $this->assertSame('', $this->authorizationSent());

        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, "not a token; rm -rf /\n");
        $this->assertSame('', $this->authorizationSent());
    }
}
