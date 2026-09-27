<?php

namespace App\Services\TrueFit;

/**
 * Synthetic material/unit catalog for TrueFit Slice 1.
 * No real curriculum copyright ingestion; keys are stable fixture IDs.
 */
final class TrueFitMaterialCatalog
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return list<array{key:string,subject_hint:string,title:string,unit_label:string,summary:string}>
     */
    public function listUnits(?string $subjectHint = null): array
    {
        $units = $this->allUnits();
        if ($subjectHint === null || $subjectHint === '') {
            return $units;
        }

        $needle = mb_strtolower($subjectHint);
        $matched = array_values(array_filter(
            $units,
            static fn (array $unit) => str_contains(mb_strtolower($unit['subject_hint']), $needle)
                || str_contains(mb_strtolower($unit['title']), $needle)
        ));

        return $matched !== [] ? $matched : $units;
    }

    /**
     * @return array{key:string,subject_hint:string,title:string,unit_label:string,summary:string}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->allUnits() as $unit) {
            if ($unit['key'] === $key) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @return list<array{key:string,subject_hint:string,title:string,unit_label:string,summary:string}>
     */
    private function allUnits(): array
    {
        return [
            [
                'key' => 'syn.math.fractions.v1',
                'subject_hint' => '數學',
                'title' => '分數加減（同分母）',
                'unit_label' => '單元 A',
                'summary' => '建立同分母分數加減的意義與程序，銜接數線表徵。',
            ],
            [
                'key' => 'syn.math.word-problems.v1',
                'subject_hint' => '數學',
                'title' => '兩步驟應用題',
                'unit_label' => '單元 B',
                'summary' => '辨識題意、選擇運算、檢查合理性。',
            ],
            [
                'key' => 'syn.eng.phonics.short-vowels.v1',
                'subject_hint' => '英文',
                'title' => '短母音拼讀',
                'unit_label' => 'Unit 1',
                'summary' => 'CVC 拼讀與聽辨；連結口語到書面。',
            ],
            [
                'key' => 'syn.eng.reading.main-idea.v1',
                'subject_hint' => '英文',
                'title' => '段落大意',
                'unit_label' => 'Unit 2',
                'summary' => '找出主題句與支持細節，避免逐字翻譯依賴。',
            ],
            [
                'key' => 'syn.zh.reading.inference.v1',
                'subject_hint' => '國語',
                'title' => '閱讀推論',
                'unit_label' => '第 3 課',
                'summary' => '依文本線索推論人物動機與因果。',
            ],
            [
                'key' => 'syn.science.states-of-matter.v1',
                'subject_hint' => '自然',
                'title' => '物質三態',
                'unit_label' => '單元 2',
                'summary' => '觀察固液氣特徵，連結溫度與狀態變化。',
            ],
        ];
    }
}
