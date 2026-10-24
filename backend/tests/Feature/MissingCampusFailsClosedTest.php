<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\PendingSwipe;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A record whose campus cannot be resolved is not writable by a campus-scoped director. Synthetic data only. */
class MissingCampusFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_on_an_invoice_without_a_student_is_refused(): void
    {
        $invoice = Invoice::create(['StudentID' => 987654, 'IssueDate' => '2026-05-01', 'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'unpaid']);

        $this->api($this->directorToken(1))->postJson("/api/v1/invoices/{$invoice->id}/payments", ['Amount' => 1000, 'Method' => 'cash'])
            ->assertNotFound();
        $this->assertSame(0, DB::table('Payment')->where('InvoiceID', $invoice->id)->count());
    }

    public function test_a_campusless_swipe_follows_its_students_campus(): void
    {
        $foreign = Student::create(['name' => 'Swipe other', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $own = Student::create(['name' => 'Swipe own', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $swipe = fn (?int $studentId) => PendingSwipe::create(['RFID' => 'R' . random_int(1000, 9999), 'StudentID' => $studentId, 'CampusID' => null, 'SwipeAt' => now(), 'Reason' => 'no_course']);
        $token = $this->directorToken(1);

        $other = $swipe($foreign->id);
        $this->api($token)->deleteJson("/api/v1/pending-swipes/{$other->id}")->assertForbidden();
        $this->assertNotNull(PendingSwipe::find($other->id));
        $unknown = $swipe(null);
        $this->api($token)->deleteJson("/api/v1/pending-swipes/{$unknown->id}")->assertForbidden();

        $mine = $swipe($own->id);
        $this->api($token)->deleteJson("/api/v1/pending-swipes/{$mine->id}")->assertSuccessful();
        $this->assertNull(PendingSwipe::find($mine->id));
    }

    private function directorToken(int $campusId): string
    {
        $user = User::create(['LoginName' => 'dir-' . uniqid() . '@example.com', 'Name' => 'dir', 'PSW' => 'x', 'type' => 'A',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
