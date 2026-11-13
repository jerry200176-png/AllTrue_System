<?php

namespace Tests\Unit;

use App\Operations\Inapp\InappProgressReplyTemplates as Templates;
use PHPUnit\Framework\TestCase;

/** Pins each canned wording so a text edit cannot silently orphan the replies already posted with the old text. */
final class PopInappProgressReplyTemplatesTest extends TestCase
{
    private const HASHES = [
        'received' => '0b5e246028eacac81ee5b65e61d5a2f47907e99611a4737942229635f011b92c',
        'in_progress' => '0e8e3749753de95b29b488b54e912ee928a60c662dbdb4fc7d84cb7965a46d6d',
        'deployed_please_confirm' => 'a83a3582e76c192798cac01fa6ae56d4121929dfa11aa98e94aa700c4ebadc3b',
    ];

    public function test_every_template_text_matches_its_pinned_hash(): void
    {
        self::assertSame(array_keys(self::HASHES), Templates::keys());
        foreach (self::HASHES as $key => $hash) {
            self::assertSame($hash, hash('sha256', Templates::text($key)), "wording changed: move the old text to RETIRED_TEXTS, then update this hash ($key)");
        }
    }
}
