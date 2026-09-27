<?php

namespace App\Services\TrueFit;

/**
 * Deterministic Teacher Brief generator — no external LLM, no student PII.
 * Uses synthetic material catalog + subject hint only.
 */
final class FixtureTeacherBriefProvider
{
    public function __construct(
        private TrueFitTeacherBriefContract $contract,
    ) {
    }

    /**
     * @param array{key:string,subject_hint:string,title:string,unit_label:string,summary:string} $material
     * @return array<string, mixed>
     */
    public function generate(array $material, string $subjectName = ''): array
    {
        $title = $material['title'];
        $unit = $material['unit_label'];
        $subject = $subjectName !== '' ? $subjectName : $material['subject_hint'];
        $summary = $material['summary'];

        $brief = [
            'schema_version' => TrueFitTeacherBriefContract::SCHEMA_VERSION,
            'provider' => 'fixture',
            'material_unit_key' => $material['key'],
            'material_title' => $title,
            'learning_objectives' => [
                "學生能說明「{$title}」的核心概念，並用自己的話複述。",
                "學生能在引導下完成與「{$unit}」對應的典型例題。",
                '學生能指出至少一個常見錯誤並說明如何修正。',
            ],
            'prior_knowledge' => "複習與「{$subject}／{$title}」相關的前一單元關鍵詞與基本程序；確認學生能辨識題目條件。",
            'hook' => "用 60 秒真實情境或迷你謎題導入「{$title}」，讓學生先預測答案再打開教材「{$unit}」。",
            'analogy_or_representation' => $this->analogyFor($material),
            'prediction_questions' => [
                "若條件改變一小步，你預期「{$title}」的結果會如何變化？為什麼？",
                '哪個步驟最容易出錯？你會先檢查哪裡？',
            ],
            'expected_misconceptions' => [
                [
                    'label' => '程序記憶取代意義理解',
                    'signal' => '能算出答案但說不出為什麼這樣算',
                    'repair_move' => '要求學生用圖示或口語重述意義，再回到程序',
                ],
                [
                    'label' => '忽略題目條件',
                    'signal' => '套用固定公式卻漏讀關鍵限制',
                    'repair_move' => '先圈出條件，再選策略；對照教材例題結構',
                ],
            ],
            'hint_ladders' => [
                [
                    'level' => 1,
                    'hint' => "回到教材「{$unit}」的例題標題，找出與本題相同的結構詞。",
                ],
                [
                    'level' => 2,
                    'hint' => '只提示下一步操作類型（例如：先畫圖／先列式），不給完整解。',
                ],
                [
                    'level' => 3,
                    'hint' => '提供半完成支架（填空式步驟），讓學生補上關鍵轉換。',
                ],
            ],
            'teaching_moves_tied_to_material' => [
                "打開原教材「{$unit}」例題，師生一起標註已知／未知。",
                "對照教材圖示或表格，完成一次「我做—你說—你做」循環。",
                "用教材練習題中的一題做 think-aloud，明示易錯點。",
                "小結時請學生指回教材頁面中對應「{$summary}」的那一段。",
            ],
            'exit_ticket_plan' => [
                'prompt' => "用一句話說明今天「{$title}」最重要的一步，並寫出一題迷你檢查題的答案。",
                'success_criteria' => [
                    '能說出關鍵步驟或概念詞',
                    '迷你題正確或能解釋錯誤原因',
                ],
                'follow_up_if_miss' => '下一堂先用同結構變式題做 3 分鐘熱身，再進入新內容。',
            ],
        ];

        return $this->contract->assertValid($brief);
    }

    /**
     * @param array{key:string,subject_hint:string,title:string,unit_label:string,summary:string} $material
     */
    private function analogyFor(array $material): string
    {
        return match (true) {
            str_contains($material['key'], 'fractions') => '把同分母分數想成「相同大小的格子」：分子是已塗色格數，加減只動格子數、不改格子大小。',
            str_contains($material['key'], 'phonics') => '把字母當「聲音積木」：先聽聲音再排積木，而不是先背整字。',
            str_contains($material['key'], 'main-idea') => '把段落想成雨傘：主題句是傘柄，細節是傘骨，不能只有骨沒有柄。',
            str_contains($material['key'], 'states-of-matter') => '把分子想成舞者：固體手拉手站定、液體可流動換位、氣體散開滿場。',
            default => "用具體表徵（圖／表／實物）對照教材「{$material['unit_label']}」中的抽象符號。",
        };
    }
}
