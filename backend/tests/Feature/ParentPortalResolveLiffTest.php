<?php

namespace Tests\Feature;

use App\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: 多分校共用同一網域時，resolve-liff 必須能用 campus_id 定位正確的 LIFF。
 *
 * BUG（家長 LINE 自動登入失敗）：13 新莊中平 與 15 大安 共用 daan.lifenet.com.tw，
 * 純 host 比對只回第一個分校的 LIFF → 其他分校家長拿到別人的 LINE Login channel →
 * getProfile().userId（屬不同 provider）與綁定對不上 → 自動登入 404。
 * 入口連結一律帶 campus_id，故 campus_id 必須優先。
 */
class ParentPortalResolveLiffTest extends TestCase
{
    use RefreshDatabase;

    public function test_campus_id_resolves_that_campus_liff_on_shared_domain(): void
    {
        $a = Campus::factory()->create(['LIFFID' => 'LIFF-A-111', 'URL' => 'https://daan-test.example.com']);
        $b = Campus::factory()->create(['LIFFID' => 'LIFF-B-222', 'URL' => 'https://daan-test.example.com']);

        // 即使共用 host，帶 campus_id 就回該分校自己的 LIFF。
        $resA = $this->getJson('/api/v1/parent/resolve-liff?campus_id=' . $a->id);
        $resA->assertOk()->assertJson(['liff_id' => 'LIFF-A-111', 'campus_id' => $a->id]);

        $resB = $this->getJson('/api/v1/parent/resolve-liff?campus_id=' . $b->id);
        $resB->assertOk()->assertJson(['liff_id' => 'LIFF-B-222', 'campus_id' => $b->id]);
    }

    public function test_campus_id_without_liff_falls_through_without_breaking(): void
    {
        // campus_id 指向沒有 LIFF 的分校 → 不可硬回空字串，需退回原本 host 比對流程。
        // 測試環境 host 不會命中任何分校 URL，故結果為 null（重點：campus_id 是「附加」而非破壞既有行為）。
        $noLiff = Campus::factory()->create(['LIFFID' => '', 'URL' => 'https://onlyone.example.com']);

        $res = $this->getJson('/api/v1/parent/resolve-liff?campus_id=' . $noLiff->id);
        $res->assertOk();
        $this->assertNull($res->json('liff_id'), '無 LIFF 的分校不應被回傳，應退回 host 流程');
    }
}
