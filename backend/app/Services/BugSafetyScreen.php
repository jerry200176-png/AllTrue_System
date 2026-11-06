<?php

namespace App\Services;

/**
 * In-app safety screen (delivery lifecycle L4, #4957 / #4953 section B). Report text is untrusted data, never
 * instructions. This deterministic check only decides "hold for a human": a flagged report is labelled
 * `safety:review`, gets a neutral reply and no automatic action. It never rejects a report.
 *
 * Precision rule: a code fires on a REQUEST (lead-in such as 請/幫我/please, plus an action verb, plus the sensitive
 * noun in the same clause), not on a noun alone. 「扣堂不對」 / 「金額顯示錯」 describe a wrong display and stay normal.
 * `unusual_reporter` (reporter_history) is intentionally not implemented: the history only exists in the
 * bug-detail-dump workflow, not on the candidate query path.
 *
 * Output carries codes only; the text is never echoed (public repo).
 */
class BugSafetyScreen
{
    public const CODES = ['money_change', 'access_change', 'other_person_data', 'disable_control', 'ai_instruction'];

    // Shared pieces. CL = "same clause" gap: no sentence punctuation or newline.
    private const CL = '[^。.!?！？\n;；]{0,24}?';
    private const LEAD = '(?:請|麻煩|幫我|幫忙|幫|拜託|能不能|可不可以|可以|我要|我想要|想要|需要你|你們要|給我|直接|please|pls|kindly|can you|could you|would you|need you to|i want you to|help me|just)';

    /** code => [verb regex, noun regex]; matched as LEAD..verb..noun or LEAD..noun..verb in one clause. */
    private const REQUESTS = [
        // Change price, fee, refund, discount, lesson count, payroll amounts.
        'money_change' => [
            '(?:改成|改為|改到|調成|調整|調高|調低|修改|更改|設為|設定為|增加|減少|減免|免除|免收|退給|退還|退款|補發|補扣|加扣|少扣|多扣|change|set|update|increase|decrease|reduce|waive|refund|adjust|lower|raise|add|remove)',
            '(?:價格|價錢|費用|學費|收費|金額|折扣|優惠|堂數|總堂數|剩餘堂數|薪水|薪資|時薪|鐘點費|獎金|退費|扣款|price|fee|tuition|discount|payroll|salary|wage|bonus|lesson count|balance|refund)',
        ],
        // Grant or raise permissions, accounts, passwords, roles.
        'access_change' => [
            '(?:開通|開啟|授權|提升|升級|加入|新增|建立|重設|重置|修改|更改|改成|設為|解鎖|grant|give|raise|elevate|promote|reset|change|add|create|unlock|make me)',
            '(?:權限|帳號|帳戶|密碼|角色|管理員|超級使用者|permission|password|account|role|admin|access|privilege|superuser)',
        ],
        // Turn off a check, approval or limit.
        'disable_control' => [
            '(?:關閉|關掉|停用|取消|跳過|略過|繞過|移除|拿掉|解除|disable|turn off|switch off|bypass|skip|remove|override|ignore)',
            '(?:檢查|驗證|審核|核准流程|簽核|限制|上限|鎖定|保護|防呆|check|validation|approval|limit|cap|lock|restriction|safeguard|2fa|verification)',
        ],
    ];

    /** Another person's data + a verb that reads or moves it. Matched with LEAD as above. */
    private const OTHER_PERSON = '(?:別人|他人|其他(?:學生|家長|老師)|別的(?:學生|家長|老師)|某位?(?:學生|家長)|someone else|other (?:students?|parents?|teachers?)|another (?:student|parent|teacher))';
    private const OTHER_VERB = '(?:給我|提供|告訴|看|查|匯出|導出|顯示|列出|刪除|清除|show|send|give|reveal|list|tell|export|delete|dump)';
    private const OTHER_DATA = '(?:資料|電話|成績|地址|個資|紀錄|名單|data|record|phone|grade|address|info)';
    /** Bulk export / reveal / delete: verb + (all|every) + data noun, with a lead-in. */
    private const BULK = '(?:匯出|導出|下載|刪除|清除|清空|export|dump|delete|wipe|purge|reveal)' . '.{0,6}?' . '(?:全部|所有|整個|全校|all|every|entire)' . '.{0,10}?' . '(?:資料|學生|家長|名單|紀錄|data|students|parents|records|database|list)';

    /** Text aimed at an AI or the system, or code to run. No lead-in needed: these have no ordinary-bug reading. */
    private const AI_PATTERNS = [
        '(?:忽略|無視|忘記|ignore|disregard|forget)\s*(?:(?:以上|上述|之前|先前|前面|所有|previous|prior|above|all|your|的)\s*)+(?:指令|指示|規則|說明|instructions?|prompts?|rules?)',
        '(?:你現在是|你扮演|現在起你|你是(?:一個|一位|個)?\s*(?:ai|助理|助手|機器人|系統|管理員|工程師|模型|chatgpt|claude|gpt))',
        '\byou are (?:now )?(?:a|an|the|my)\s+(?:ai|assistant|bot|system|admin|developer|engineer|model|claude|gpt)|\byou are now\b|\bact as\b|\bpretend (?:to be|you)\b|\bfrom now on\b|\bnew instructions?\b|\bjailbreak\b|\bdeveloper mode\b',
        '系統提示|系統指令|開發者模式|system prompt|system message|developer message',
        '(?:執行|run|execute|eval)\s*(?:以下|下列|這段|這個指令|this|the following|command|shell|bash|script|sql|指令|命令|腳本)',
        '\b(?:approve|merge|deploy)\s+(?:this|the|that)?\s*(?:pr|pull request|change|commit|branch|code|issue|#\d+)',
        '(?:合併|部署|上線)\s*(?:這個|此|以上)?\s*(?:pr|分支|程式|變更|修改|commit|issue)|(?:核准|批准)\s*(?:這個|此)?\s*(?:pr|pull|程式碼|合併|部署|issue)',
        // Code blocks, markup injection, shell and SQL snippets.
        '```|<\s*script|<\s*iframe|javascript:|onerror\s*=|\$\{|\{\{|\$\(',
        '(?:^|\s)(?:sudo|rm\s+-rf|chmod|bash\s+-c|php\s+artisan|git\s+push|gh\s+(?:pr|issue|workflow)|npm\s+run|kubectl)\b',
        '\b(?:curl|wget)\b[^\n]*(?:\||https?://)',
        '\b(?:drop|truncate)\s+table\b|\bdelete\s+from\b|\bupdate\s+[\w.`]+\s+set\b|\binsert\s+into\b|\bselect\s+(?:\*|[\w.,`\s]{1,40}?)\s+from\s+[\w.`]+\s*(?:where\b|limit\b|;|$)',
        // A URL the reader is told to open / download / run (a bare URL, e.g. a screenshot link, is fine).
        '(?:點|開啟|前往|下載|執行|visit|open|go to|download|run)\s*(?:這個|此|this|the)?\s*(?:連結|網址|link|url)?\s*[:：]?\s*https?://',
    ];

    /** @return array{flagged: bool, reasons: list<string>} reasons are codes from self::CODES only */
    public static function screen(?string $title, ?string $description): array
    {
        $t = self::normalize(trim((string) $title . "\n" . (string) $description));
        $reasons = [];
        foreach (self::REQUESTS as $code => [$verb, $noun]) {
            if (self::request($t, $verb, $noun)) {
                $reasons[] = $code;
            }
        }
        if (self::request($t, self::OTHER_VERB, self::OTHER_PERSON . self::CL . self::OTHER_DATA)
            || self::match(self::LEAD . self::CL . self::BULK, $t)) {
            $reasons[] = 'other_person_data';
        }
        foreach (self::AI_PATTERNS as $p) {
            if (self::match($p, $t)) {
                $reasons[] = 'ai_instruction';
                break;
            }
        }
        $reasons = array_values(array_intersect(self::CODES, $reasons));

        return ['flagged' => $reasons !== [], 'reasons' => $reasons];
    }

    /** Lowercase, NFKC (full-width -> ASCII) and drop zero-width characters so padding cannot dodge the lists. */
    private static function normalize(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KC) ?: $s;
        }
        $s = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $s) ?? $s;

        return mb_strtolower($s);
    }

    private static function request(string $t, string $verb, string $noun): bool
    {
        return self::match(self::LEAD . self::CL . '(?:' . $verb . self::CL . $noun . '|' . $noun . self::CL . $verb . ')', $t);
    }

    private static function match(string $pattern, string $t): bool
    {
        return preg_match('/' . str_replace('/', '\/', $pattern) . '/iu', $t) === 1;
    }
}
