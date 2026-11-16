<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #889 資料保存期限 (Founder 2026-11-17): each log keeps only its window; deleted chat keeps its placeholder. CI only. */
final class PruneSystemLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_only_logs_past_their_window(): void
    {
        Carbon::setTestNow('2026-11-17 03:05:00');
        Storage::fake('public');
        $old = fn (int $days) => now()->subDays($days);

        foreach ([400, 300] as $days) {
            DB::table('user_login_activities')->insert(['user_id' => 1, 'created_at' => $old($days), 'updated_at' => $old($days)]);
        }
        foreach ([31, 29] as $days) {
            DB::table('password_reset_requests')->insert(['account_input' => 'x', 'created_at' => $old($days), 'updated_at' => $old($days)]);
        }
        foreach ([91, 89] as $days) {
            DB::table('parent_binding_attempts')->insert(['correlation_id' => (string) Str::uuid(), 'channel' => 'line', 'method' => 'code',
                'outcome' => 'failed', 'occurred_at' => $old($days)]);
        }
        $thread = DB::table('chat_threads')->insertGetId(['CampusID' => 1, 'created_by' => 1]);
        Storage::disk('public')->put('chat-attachments/1/a.jpg', 'x');
        $message = fn (array $row) => DB::table('chat_messages')->insertGetId(array_merge(
            ['thread_id' => $thread, 'sender_user_id' => 1, 'sender_name_snapshot' => 'A', 'body' => 'hello', 'created_at' => $old(60)], $row));
        $gone = $message(['deleted_at' => $old(31), 'media_url' => 'chat-attachments/1/a.jpg', 'media_name' => 'a.jpg']);
        $recent = $message(['deleted_at' => $old(10)]);
        $live = $message([]);

        $this->artisan('privacy:prune-logs')->expectsOutput('Pruned 4 rows (login=1, reset=1, binding=1, chat=1).')->assertSuccessful();

        $this->assertSame(1, DB::table('user_login_activities')->count());
        $this->assertSame(1, DB::table('password_reset_requests')->count());
        $this->assertSame(1, DB::table('parent_binding_attempts')->count());
        $this->assertSame(['', null, null], array_values((array) DB::table('chat_messages')->where('id', $gone)->first(['body', 'media_url', 'media_name'])));
        Storage::disk('public')->assertMissing('chat-attachments/1/a.jpg');
        $this->assertSame(['hello', 'hello'], DB::table('chat_messages')->whereIn('id', [$recent, $live])->orderBy('id')->pluck('body')->all());
        $this->assertSame(3, DB::table('chat_messages')->count(), 'the 已刪除 placeholder row stays');

        // Second run: nothing left to do.
        $this->artisan('privacy:prune-logs')->expectsOutput('Pruned 0 rows (login=0, reset=0, binding=0, chat=0).')->assertSuccessful();
        Carbon::setTestNow();
    }
}
