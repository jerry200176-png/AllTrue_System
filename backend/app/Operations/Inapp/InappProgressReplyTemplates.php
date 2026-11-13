<?php

namespace App\Operations\Inapp;

/**
 * The only public wording `inapp-report-progress-reply` can post. Canned replies chosen by key (macro / saved-reply practice):
 * the caller never supplies text. Plain language, no field names, no issue numbers, no links (docs/CHAT_BUG_SYSTEM.md §3.8).
 * Review wording here and nowhere else.
 */
final class InappProgressReplyTemplates
{
    public const RECEIVED = 'received';
    public const IN_PROGRESS = 'in_progress';
    public const DEPLOYED_PLEASE_CONFIRM = 'deployed_please_confirm';

    /** Report statuses a template is limited to; a template not listed here only refuses `closed` reports. */
    public const REQUIRED_STATUS = [self::DEPLOYED_PLEASE_CONFIRM => ['resolved', 'triaged']];

    private const TEXT = [
        self::RECEIVED => '已收到你的回報，我們正在確認，有進度會再回覆你。',
        self::IN_PROGRESS => '已找到原因，我們正在處理，完成後會再回覆你。',
        self::DEPLOYED_PLEASE_CONFIRM => '已更新，請你協助確認是否恢復正常；如果還有問題，直接在這裡回覆我們就好。',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEXT);
    }

    public static function has(mixed $key): bool
    {
        return is_string($key) && isset(self::TEXT[$key]);
    }

    public static function text(string $key): string
    {
        return self::TEXT[$key];
    }

    /** @return list<string> every canned text, used to recognise this operation's earlier replies */
    public static function allTexts(): array
    {
        return array_values(self::TEXT);
    }
}
