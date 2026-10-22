<?php

namespace App\Operations\Inapp;

use App\Services\BugReportService;

/**
 * ADR-POP-015 reply checks: the PII patterns of .github/scripts/inapp-writeback-rows.py, stricter on links (only the report's
 * own issue URL). Returns rule names, never the matched text. A pattern guard, not a PII guarantee (names are not detected).
 */
final class InappReportReplyGuard
{
    public const MAX_CHARS = 600;

    private const SEP = '[-\s.]*';
    private const DOT = '\s*(?:\.|\[\.\]|\(\.\)|\{\.\}|\[dot\]|\(dot\)|\s+dot\s+)\s*';
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** @return list<string> rule names the reply breaks; [] passes */
    public static function violations(string $reply, string $issueUrl, bool $resolve): array
    {
        $out = [];
        if (trim($reply) === '' || mb_strlen($reply) > self::MAX_CHARS) $out[] = 'reply_length';
        if ($resolve) {
            foreach (BugReportService::RESOLVE_REPLY_REQUIRED_PHRASES as $phrase) {
                if (mb_strpos($reply, $phrase) === false) $out[] = 'reply_missing_outcome_phrases';
            }
        }
        $text = self::normalize($reply);
        if ($text === null) return [...array_values(array_unique($out)), 'reply_unnormalizable'];
        // The exact issue URL is the one allowed link (not a longer issue number or a path under it);
        // anything URL- or host-like left after removing it fails.
        $rest = preg_replace('/' . preg_quote($issueUrl, '/') . '(?![0-9A-Za-z\/?#_-])/u', ' ', $text, -1, $links);
        if (!is_string($rest)) return [...array_values(array_unique($out)), 'reply_unnormalizable'];
        if ($links < 1) $out[] = 'reply_missing_issue_url';
        foreach (self::rules() as $name => $pattern) {
            if (preg_match($pattern, $rest) !== 0) $out[] = $name; // 1 = match, false = regex error: both fail closed
        }

        return array_values(array_unique($out));
    }

    /** NFKC + drop format characters, as the workflow validator does; without intl, look-alikes fail closed. */
    private static function normalize(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) return null;
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (!is_string($text)) return null;
        } elseif (preg_match('/[\x{FF0E}\x{FF10}-\x{FF19}\x{FF20}-\x{FF3A}\x{FF41}-\x{FF5A}\x{2460}-\x{24FF}\x{1D400}-\x{1D7FF}]/u', $text) !== 0) {
            return null;
        }
        $text = preg_replace('/\p{Cf}/u', '', $text);

        return is_string($text) ? $text : null;
    }

    /** @return array<string,string> */
    private static function rules(): array
    {
        $sep = self::SEP;
        $dot = self::DOT;
        $label = self::LABEL;

        return [
            'tw_mobile' => "/(?<!\d)(?:(?:\+|00)?886{$sep}(?:\(0\){$sep})?|0)9(?:{$sep}\d){8}(?!\d)/u",
            'tw_landline' => "/(?<!\d)(?:(?:\+|00)?886{$sep}(?:\(0\){$sep})?\(?|\(?0)[2-8](?:[-\s.)]*\d){7,8}(?!\d)/u",
            'tw_national_id' => '/(?<![A-Za-z0-9])[A-Za-z][-\s]*[12](?:[-\s]*\d){8}(?!\d)/u',
            'email' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+/u',
            'email_defanged' => "/[A-Za-z0-9._%+-]+\s*(?:\(at\)|\[at\]|\{at\}|\s+at\s+)\s*{$label}{$dot}{$label}/iu",
            'secret_token' => '/(?<![A-Za-z0-9])(?:ghp_|gho_|ghs_|ghu_|github_pat_|sk-|xox[bpas]-|AKIA)[A-Za-z0-9_-]{8,}/u',
            'private_key' => '/BEGIN [A-Z ]*KEY/iu',
            'long_hex' => '/(?<![0-9A-Fa-f])[0-9A-Fa-f]{32,}(?![0-9A-Fa-f])/u',
            'long_base64' => '/(?=[A-Za-z0-9+\/=_-]*\d)(?=[A-Za-z0-9+\/=_-]*[A-Za-z])[A-Za-z0-9+\/=_-]{32,}/u',
            'percent_encoded' => '/%[0-9A-Fa-f]{2}/u',
            'url_not_allowed' => '/(?:[a-z][a-z0-9+.-]{1,15}:\/\/|https?:|www\.|[\\\\\/]{2})/iu',
            'host_not_allowed' => "/(?<![\w@-]){$label}(?:{$dot}{$label})*{$dot}(?:[a-z]{2,63}|xn--[a-z0-9-]+)(?![\w-])/iu",
            'ip_address' => '/(?<![\d.])\d{1,3}(?:\s*(?:\.|\[\.\])\s*\d{1,3}){3}(?![\d.])/u',
        ];
    }
}
