<?php

namespace Tests\Unit;

use App\Operations\Inapp\InappReportReplyGuard;
use PHPUnit\Framework\TestCase;

/** ADR-POP-015 reply guard: plain Traditional Chinese passes; PII, foreign links and look-alike issue URLs fail. */
final class InappReportReplyGuardTest extends TestCase
{
    private const URL = 'https://github.com/jerry200176-png/AllTrue_System/issues/77';
    private const BODY = '已修好並上線，重新整理後再試一次（手機也可以）。沒問題請按「確認已修好」，還有問題請按「問題仍存在」。追蹤：';

    public function test_a_plain_reply_with_full_width_punctuation_passes(): void
    {
        foreach ([self::BODY . self::URL, self::BODY . self::URL . '。', '（' . self::URL . '）' . self::BODY . self::URL] as $reply) {
            self::assertSame([], InappReportReplyGuard::violations($reply, self::URL, true), $reply);
        }
        self::assertSame([], InappReportReplyGuard::violations('已收到，追蹤：' . self::URL, self::URL, false), 'outcome phrases only for resolve');
    }

    public static function refused(): array
    {
        return [
            'no outcome phrases' => ['已修好。追蹤：' . self::URL, 'reply_missing_outcome_phrases'],
            'no issue URL' => [self::BODY, 'reply_missing_issue_url'],
            'longer issue number' => [self::BODY . self::URL . '0', 'reply_missing_issue_url'],
            'path under the issue' => [self::BODY . self::URL . '/x', 'reply_missing_issue_url'],
            'userinfo glued to the URL' => [self::BODY . self::URL . '@evil.com', 'reply_missing_issue_url'],
            'port glued to the URL' => [self::BODY . self::URL . ':8080', 'reply_missing_issue_url'],
            'zero-width space inside the URL' => [self::BODY . str_replace('/77', "/7\u{200B}7", self::URL), 'format_character'],
            'soft hyphen inside the URL' => [self::BODY . str_replace('/77', "/7\u{00AD}7", self::URL), 'format_character'],
            'full-width scheme' => [self::BODY . 'ｈｔｔｐｓ://github.com/jerry200176-png/AllTrue_System/issues/77', 'reply_missing_issue_url'],
            'another link' => [self::BODY . '說明 https://example.com/x ' . self::URL, 'url_not_allowed'],
            'host glued to Chinese text' => [self::BODY . '請到evil.com看' . self::URL, 'host_not_allowed'],
            'mobile number' => [self::BODY . '0912-345-678' . self::URL, 'tw_mobile'],
            'mobile with Unicode dashes' => [self::BODY . "0912\u{2010}345\u{2010}678" . self::URL, 'tw_mobile'],
            'email' => [self::BODY . 'a@b.co ' . self::URL, 'email'],
            'IP address' => [self::BODY . '192.168.1.1 ' . self::URL, 'ip_address'],
            'commit hash' => [self::BODY . str_repeat('ab', 20) . ' ' . self::URL, 'long_hex'],
            'percent encoding' => [self::BODY . '%30%39 ' . self::URL, 'percent_encoded'],
            'too long' => [str_repeat('很', 600) . self::BODY . self::URL, 'reply_length'],
        ];
    }

    /** @dataProvider refused */
    public function test_each_unsafe_reply_names_the_rule_it_breaks(string $reply, string $rule): void
    {
        self::assertContains($rule, InappReportReplyGuard::violations($reply, self::URL, true));
    }

    public function test_full_width_digits_fail_closed_with_or_without_intl(): void
    {
        $rules = InappReportReplyGuard::violations(self::BODY . '０９１２３４５６７８' . self::URL, self::URL, true);
        self::assertContains(class_exists(\Normalizer::class) ? 'tw_mobile' : 'reply_unnormalizable', $rules);
    }
}
