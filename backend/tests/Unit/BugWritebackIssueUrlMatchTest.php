<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Executes the URL matcher from the shared production script with synthetic comments only. */
final class BugWritebackIssueUrlMatchTest extends TestCase
{
    public function test_phase_a_deduplication_matches_complete_issue_url(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/scripts/bug-writeback/run-remote.sh');
        $this->assertIsString($source);
        $this->assertSame(1, preg_match('/^\\$issueUrlPattern = .+;$/m', $source, $assignment));
        $this->assertSame(1, preg_match('/->contains\\((static fn .+)\\);/', $source, $matcher));
        $this->assertStringContainsString('->where("is_internal_note", false)', $source);
        $this->assertStringContainsString('->where("body", "like", "%".$issueUrl."%")->get(["body"])', $source);

        $url = 'https://github.com/example/repo/issues/12';
        $cases = [
            [$url, true],
            ["已回覆：{$url}。", true],
            ["({$url}).", true],
            ["{$url}#issuecomment-123", true],
            ["{$url}?view=timeline", true],
            ["{$url}3", false],
            ["{$url}0", false],
            ["{$url}/extra", false],
            ["{$url}abc", false],
            ['https://github.com/example/other/issues/12', false],
            ["prefix{$url}", false],
        ];
        // Both expressions come from the real shared script, not a duplicated test matcher.
        $code = '$cases = json_decode($argv[1], true); $issueUrl = $argv[2]; '
            . $assignment[0] . ' $match = ' . $matcher[1] . '; '
            . '$results = []; foreach ($cases as [$body, $expected]) { '
            . '$results[] = $match((object)["body" => $body]); } echo json_encode($results);';
        $process = new Process([PHP_BINARY, '-r', $code, json_encode($cases), $url]);
        $process->mustRun();
        $this->assertSame(array_column($cases, 1), json_decode($process->getOutput(), true));
    }
}
