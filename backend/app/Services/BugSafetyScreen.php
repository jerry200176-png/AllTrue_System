<?php

namespace App\Services;

/**
 * In-app safety screen (delivery lifecycle L4, #4957 / #4953 section B, hardened in #4965). Report text is untrusted
 * data, never instructions. This deterministic check only decides "hold for a human": a flagged report is labelled
 * `safety:review`, gets a neutral reply and no automatic action. It never rejects a report.
 *
 * Precision rule: a code fires on a REQUEST (lead-in such as 請/幫我/please, plus an action verb, plus the sensitive
 * noun in one clause), or on a narrow lead-less form (clause-initial 把…改成, sentence-initial English imperative with
 * a target). 「扣堂不對」 / 「金額顯示錯」 describe a wrong display and stay normal. English words match as whole words.
 * Simplified Chinese is folded to Traditional first, so the lists are written once, in Traditional.
 * `unusual_reporter` (reporter_history) is intentionally not implemented: the history only exists in the
 * bug-detail-dump workflow, not on the candidate query path.
 *
 * Output carries codes only; the text is never echoed (public repo). A regex engine failure counts as flagged.
 */
class BugSafetyScreen
{
    public const CODES = ['money_change', 'access_change', 'other_person_data', 'disable_control', 'ai_instruction'];

    /** Longer than any valid report (max 5000 + title): treated as padding and held. */
    private const MAX_LEN = 6500;

    /** Simplified -> Traditional, only the characters the lists below use. */
    private const S2T = [
        '请' => '請', '帮' => '幫', '烦' => '煩', '权' => '權', '帐' => '帳', '账' => '帳', '码' => '碼', '学' => '學', '费' => '費',
        '价' => '價', '数' => '數', '总' => '總', '余' => '餘', '资' => '資', '时' => '時', '钟' => '鐘', '点' => '點', '奖' => '獎',
        '调' => '調', '设' => '設', '减' => '減', '补' => '補', '删' => '刪', '导' => '導', '汇' => '匯', '别' => '別', '电' => '電',
        '话' => '話', '绩' => '績', '个' => '個', '记' => '記', '录' => '錄', '单' => '單', '关' => '關', '闭' => '閉', '绕' => '繞',
        '过' => '過', '检' => '檢', '验' => '驗', '审' => '審', '签' => '簽', '锁' => '鎖', '执' => '執', '视' => '視', '规' => '規',
        '说' => '說', '现' => '現', '统' => '統', '开' => '開', '发' => '發', '级' => '級', '员' => '員', '创' => '創', '给' => '給',
        '让' => '讓', '链' => '鏈', '网' => '網', '这' => '這', '无' => '無', '额' => '額', '优' => '優', '启' => '啟', '还' => '還',
        '师' => '師', '长' => '長', '库' => '庫', '据' => '據', '户' => '戶', '将' => '將', '为' => '為', '会' => '會',
        '须' => '須', '务' => '務', '复' => '復', '转' => '轉', '换' => '換', '买' => '買',
    ];

    /** Cyrillic / Greek lookalikes -> Latin (applied after lowercasing). */
    private const HOMOGLYPHS = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x', 'у' => 'y', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's',
        'ԁ' => 'd', 'ӏ' => 'l', 'һ' => 'h', 'ѵ' => 'v', 'ɡ' => 'g', 'α' => 'a', 'ε' => 'e', 'ο' => 'o', 'ι' => 'i', 'κ' => 'k',
        'ν' => 'v', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ı' => 'i', 'ℓ' => 'l',
    ];

    // CL = "same clause" gap: no sentence punctuation. SENT = start of a sentence.
    private const CL = '[^。.!?！？;；]{0,120}?';
    private const SENT = '(?:^|[。.!?！？;；\n]\s*)';

    /** Word-bounded alternation: ASCII words never match inside longer words (offset / settings are not "set"). */
    private static function w(string $alts): string
    {
        return '(?<![a-z])(?:' . $alts . ')(?![a-z])';
    }

    /** Request lead-in (Traditional + English). */
    private static function lead(): string
    {
        return self::w('請|麻煩|幫我|幫忙|幫|拜託|能不能|可不可以|可以|我要|我想要|想要|需要你|你們要|給我|直接|please|pls|kindly|can you|could you|would you|need you to|i want you to|help me|just');
    }

    /**
     * code => [verb, noun, clause-initial lead-less verbs (Chinese 把…X / English sentence-initial imperative), English target].
     * Matched as LEAD..verb..noun or LEAD..noun..verb in one clause.
     */
    private static function requests(): array
    {
        return [
            // Change price, fee, refund, discount, lesson count, payroll amounts. (Restoring a wrongly deducted count is stripped first.)
            'money_change' => [
                self::w('改成|改為|改到|調成|調整|調高|調低|修改|更改|設為|設定為|增加|減少|減免|免除|免收|退給|退還|退款|補發|補扣|加扣|少扣|多扣|change|set|update|increase|decrease|reduce|waive|refund|adjust|lower|raise'),
                self::w('價格|價錢|費用|學費|收費|金額|折扣|優惠|堂數|總堂數|剩餘堂數|薪水|薪資|時薪|鐘點費|獎金|退費|扣款|prices?|fees?|tuition|discounts?|payroll|salary|wages?|bonus(?:es)?|lesson counts?|balance|refunds?'),
                '改成|改為|調成|設為|設定為|調整為|改',
                'change|set|update|adjust|waive',
            ],
            // Grant or raise permissions, accounts, roles. (Password reset for a locked-out user is ordinary support, not listed.)
            'access_change' => [
                self::w('開通|開啟|授權|提升|升級|加入|新增|建立|修改|更改|改成|設為|解鎖|grant|give|raise|elevate|promote|add|create|unlock|make me|change'),
                self::w('權限|帳號|帳戶|角色|管理員|超級使用者|permissions?|roles?|admin|access|privileges?|superuser|accounts?'),
                '設為|改成|開通|授權|提升|升級',
                'grant|give|promote|elevate|make|add',
            ],
            // Turn off a check, approval or limit.
            'disable_control' => [
                self::w('關閉|關掉|停用|取消|跳過|略過|繞過|移除|拿掉|解除|disable|turn off|switch off|bypass|skip|remove|override|ignore'),
                self::w('檢查|驗證|審核|核准流程|簽核|限制|上限|鎖定|保護|防呆|checks?|validations?|approvals?|limits?|caps?|locks?|restrictions?|safeguards?|2fa|verification'),
                '關閉|關掉|取消|停用|跳過|略過|繞過|移除|拿掉|解除',
                'disable|bypass|skip|remove|turn off',
            ],
        ];
    }

    private const OTHER_PERSON = '(?:別人|他人|其他(?:學生|家長|老師)|別的(?:學生|家長|老師)|某位?(?:學生|家長)|(?<![a-z])someone else|(?<![a-z])other (?:students?|parents?|teachers?)|(?<![a-z])another (?:student|parent|teacher))';
    private const OTHER_VERB = '(?:給我|提供|告訴|看|查|匯出|導出|顯示|列出|刪除|清除|(?<![a-z])(?:show|send|give|reveal|list|tell|export|delete|dump)(?![a-z]))';
    private const OTHER_DATA = '(?:資料|電話|成績|地址|個資|紀錄|記錄|名單|(?<![a-z])(?:data|records?|phone|grades?|address|info)(?![a-z]))';
    /** Verbs strong enough to need no lead-in when aimed at another person's data. */
    private const OTHER_STRONG = '(?:給我|告訴我|匯出|導出|(?<![a-z])(?:show me|give me|send me|tell me|let me see|export|dump)(?![a-z]))';
    /** Bulk export / reveal / delete: verb + (all|every) + data noun. */
    private const BULK = '(?:匯出|導出|下載|刪除|清除|清空|(?<![a-z])(?:export|dump|delete|wipe|purge|reveal)(?![a-z])).{0,12}?(?:全部|所有|整個|全校|(?<![a-z])(?:all|every|entire)(?![a-z])).{0,20}?(?:資料|學生|家長|名單|紀錄|記錄|數據|資料庫|(?<![a-z])(?:data|students|parents|records|database|list)(?![a-z]))';

    /** Instruction / prompt-extraction context that makes "system prompt / message" wording an attack, not an app message. */
    private const SYS_NOUN = '(?:系統提示詞?|系統指令|(?<![a-z])system prompt|(?<![a-z])system message|(?<![a-z])developer message)';
    private const SYS_CTX = '(?:輸出|顯示|列出|告訴我|洩漏|透露|重複|印出|(?<![a-z])(?:print|show|reveal|repeat|output|display|leak|dump|tell me|what is|what\'s)(?![a-z]))';

    /** Text aimed at an AI or the system, or code to run. */
    private static function aiPatterns(): array
    {
        return [
            '(?:忽略|無視|忘記|ignore|disregard|forget)\s*(?:(?:以上|上述|之前|先前|前面|所有|previous|prior|above|all|your|的)\s*)+(?:指令|指示|規則|說明|instructions?|prompts?|rules?)',
            '(?:你現在是|你扮演|現在起你|你是(?:一個|一位|個)?\s*(?:ai|助理|助手|機器人|系統|管理員|工程師|模型|chatgpt|claude|gpt))',
            '(?<![a-z])you are (?:now )?(?:a|an|the|my)\s+(?:ai|assistant|bot|system|admin|developer|engineer|model|claude|gpt)|(?<![a-z])you are now(?![a-z])|(?<![a-z])act as (?:a|an|the|if)\b|(?<![a-z])pretend (?:to be|you)\b|(?<![a-z])new instructions?\s*[:：]|(?<![a-z])jailbreak|(?<![a-z])developer mode|開發者模式',
            self::SYS_CTX . '\s*(?:你的|你|your|the|its|出)?\s*' . self::SYS_NOUN,
            self::SYS_NOUN . '\s*[:：]\s*(?:you|ignore|忽略|你)',
            '(?:^|\n)\s*(?:system|assistant)\s*[:：]\s*(?:you|ignore|do|always|never|你|忽略)',
            '(?:執行|run|execute|eval)\s*(?:以下|下列|這段|這個指令|this|the following|command|shell|bash|script|sql|指令|命令|腳本)',
            '(?<![a-z])(?:approve|merge|deploy)\s+(?:this|the|that)?\s*(?:pr|pull request|change|commit|branch|code|issue|#\d+)',
            '(?:合併|部署|上線)\s*(?:這個|此|以上)?\s*(?:pr|分支|程式|變更|修改|commit|issue)|(?:核准|批准)\s*(?:這個|此)?\s*(?:pr|pull|程式碼|合併|部署|issue)',
            // Code blocks and markup injection. Template markers like {{name}} / ${x} on screen are ordinary bugs and not listed.
            '```|<\s*script|<\s*iframe|javascript:|onerror\s*=',
            // Shell and SQL snippets.
            '(?:^|\s)(?:sudo|rm\s+-rf|chmod|bash\s+-c|php\s+artisan|git\s+push|gh\s+(?:pr|issue|workflow)|npm\s+run|kubectl)(?![a-z])',
            '(?<![a-z])(?:curl|wget)(?![a-z])[^\n]*(?:\||https?://)',
            '(?<![a-z])(?:drop|truncate)\s+table(?![a-z])|(?<![a-z])delete\s+from(?![a-z])|(?<![a-z])update\s+[\w.`]+\s+set(?![a-z])|(?<![a-z])insert\s+into(?![a-z])|(?<![a-z])select\s+(?:\*|[\w.,`\s]{1,40}?)\s+from\s+[\w.`]+\s*(?:where(?![a-z])|limit(?![a-z])|;|$)',
            // A URL the reader is told to open / download / run (a bare URL, e.g. a screenshot link, is fine).
            '(?:點|開啟|前往|下載|執行|visit|open|go to|download|run)\s*(?:這個|此|this|the)?\s*(?:連結|鏈接|網址|link|url)?\s*[:：]?\s*https?://',
        ];
    }

    private const PW_VERB = '(?:重設|重置|修改|更改|改掉|換掉|告訴我|給我|(?<![a-z])(?:reset|change|set|tell me|give me|show me)(?![a-z]))';
    private const PW_WHO = '(?:別人|他人|其他人|他們|所有人|大家|管理員|某位?(?:使用者|用戶)|其他(?:學生|家長|老師|使用者|用戶)|(?<![a-z])(?:admin|administrators?|everyone|everybody|someone else|another user|other users|all users|other (?:students?|parents?|teachers?)|another (?:student|parent|teacher))(?![a-z]))';
    private const PW_NOUN = '(?:密碼|(?<![a-z])passwords?(?![a-z]))';
    private const PW_FORMS = '(?:' . self::PW_VERB . self::CL . self::PW_WHO . self::CL . self::PW_NOUN . '|' . self::PW_WHO . self::CL . self::PW_NOUN . self::CL . self::PW_VERB . ')';

    /** A sentence with a symptom or hearsay marker describes a problem or relays what someone said; it is not a lead-less request. */
    private const SYMPTOM = '時出現|後就|後會|就會|錯誤|出錯|壞掉|當機|崩潰|失敗|不能|無法|沒反應|老師說|他說|她說|家長說|學生說|聽說|據說|(?<![a-z])(?:crash(?:es|ed)?|errors?|fail(?:s|ed)?|broken|bug|exception|told me|said that|says)(?![a-z])|and the (?:page|app|screen)';

    private static function withoutSymptomSentences(string $t): string
    {
        $parts = preg_split('/(?<=[。.!?！？;；\n])/u', $t) ?: [$t];

        return implode('', array_filter($parts, fn (string $p): bool => preg_match('/' . self::SYMPTOM . '/iu', $p) !== 1));
    }

    /** Restoring a wrongly deducted lesson count is a correction, not a money change: removed before matching. */
    private const RESTORE = '(?:還原|恢復|補回|復原|回復|(?<![a-z])restore)[^。.!?！？;；]{0,12}堂數?|堂數?[^。.!?！？;；]{0,6}(?:還原|恢復|補回|復原|回來|回去)|(?<![a-z])(?:wrongly|mistakenly|incorrectly|erroneously) deducted[^.!?;]{0,30}';

    /** @return array{flagged: bool, reasons: list<string>} reasons are codes from self::CODES only */
    public static function screen(?string $title, ?string $description, bool $capLength = true): array
    {
        $raw = trim((string) $title . "\n" . (string) $description);
        if ($capLength && mb_strlen($raw) > self::MAX_LEN) {
            return ['flagged' => true, 'reasons' => ['ai_instruction']];
        }
        $t = self::normalize($raw);
        $reasons = [];
        $lead = self::lead();
        $leadless = self::withoutSymptomSentences($t);
        $noRestore = preg_replace('/' . self::RESTORE . '/iu', ' ', $t) ?? $t;
        foreach (self::requests() as $code => [$verb, $noun, $zhVerb, $enVerb]) {
            $text = $code === 'money_change' ? $noRestore : $t;
            $ll = $code === 'money_change' ? self::withoutSymptomSentences($noRestore) : $leadless;
            if (self::match($lead . self::CL . '(?:' . $verb . self::CL . $noun . '|' . $noun . self::CL . $verb . ')', $text)
                // Lead-less: clause-initial 把…X, or sentence-initial English imperative with a target.
                || self::match('(?:^|[。！？!?\n，,；;]\s*)(?:把|將)' . self::CL . '(?:' . $noun . self::CL . '(?:' . $zhVerb . ')|(?:' . $zhVerb . ')' . self::CL . $noun . ')', $ll)
                || self::match(self::SENT . self::w($enVerb) . self::CL . $noun . self::CL . self::w('to|for') . '\s*(?:me|my|us|admin|zero|free|nt|\$|\d)', $ll)
                || ($code !== 'money_change' && self::match(self::SENT . self::w($enVerb) . '\s+' . self::w('me|us') . self::CL . $noun, $ll))) {
                $reasons[] = $code;
            }
        }
        // Sentence-initial English disable/bypass is a request by itself.
        if (!in_array('disable_control', $reasons, true)
            && self::match(self::SENT . self::w('disable|bypass|turn off|skip') . '\s+(?:the |all |this )?' . self::requests()['disable_control'][1], $leadless)) {
            $reasons[] = 'disable_control';
        }
        // Resetting or changing ANOTHER user's (or an admin's) password is access_change; one's own reset is support.
        if (!in_array('access_change', $reasons, true)
            && (self::match($lead . self::CL . self::PW_FORMS, $t) || self::match(self::SENT . self::PW_FORMS, $leadless))) {
            $reasons[] = 'access_change';
        }
        if (self::match($lead . self::CL . '(?:' . self::OTHER_VERB . self::CL . self::OTHER_PERSON . self::CL . self::OTHER_DATA
                . '|' . self::OTHER_PERSON . self::CL . self::OTHER_DATA . self::CL . self::OTHER_VERB . ')', $t)
            || self::match(self::OTHER_STRONG . self::CL . self::OTHER_PERSON . self::CL . self::OTHER_DATA, $t)
            || self::match($lead . self::CL . self::BULK, $t)) {
            $reasons[] = 'other_person_data';
        }
        foreach (self::aiPatterns() as $p) {
            if (self::match($p, $t)) {
                $reasons[] = 'ai_instruction';
                break;
            }
        }
        $reasons = array_values(array_intersect(self::CODES, $reasons));

        return ['flagged' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Title+description (length rule applies) plus the reporter's later comments, each screened on its own with no length
     * rule (a long thread is normal; each comment is cut at 5000 characters, the report limit, to bound the work).
     *
     * @param list<string> $comments
     * @return array{flagged: bool, reasons: list<string>}
     */
    public static function screenReport(?string $title, ?string $description, array $comments = []): array
    {
        $reasons = self::screen($title, $description)['reasons'];
        foreach ($comments as $body) {
            $reasons = array_merge($reasons, self::screen('', mb_substr((string) $body, 0, 5000), false)['reasons']);
        }
        $reasons = array_values(array_intersect(self::CODES, $reasons));

        return ['flagged' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Decode HTML entities, NFKC (full-width -> ASCII), drop invisible/format/combining characters (soft hyphen,
     * zero-width, bidi), lowercase, fold lookalike letters and Simplified Chinese, so padding cannot dodge the lists.
     */
    private static function normalize(string $s): string
    {
        for ($i = 0; $i < 2; $i++) {
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KC) ?: $s;
        }
        $s = preg_replace('/[\p{Cf}\p{Mn}\p{Me}]/u', '', $s) ?? $s;
        $s = preg_replace('/[^\S\n]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', ' ', $s) ?? $s;

        return strtr(strtr(mb_strtolower($s), self::HOMOGLYPHS), self::S2T);
    }

    /** A regex engine failure (false) counts as a match: fail closed. */
    private static function match(string $pattern, string $t): bool
    {
        return preg_match('/' . str_replace('/', '\/', $pattern) . '/iu', $t) !== 0;
    }
}
