<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\QuestionBank;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionBankApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_authors_and_director_reviews_immutable_versions(): void
    {
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'question-teacher@example.com', $campus);
        $director = $this->makeStaff('A', 'question-director@example.com', $campus);

        $bank = $this->withAuth($teacher['token'])->postJson('/api/v1/question-banks', [
            'campus_id' => $campus, 'name' => '英文文法題庫', 'description' => '基礎句型',
        ])->assertCreated()->json('data');
        $item = $this->withAuth($teacher['token'])->postJson('/api/v1/question-banks/' . $bank['id'] . '/items', [
            'question_type' => 'single_choice', 'prompt' => '選出正確答案', 'choices' => ['A', 'B'], 'answer' => ['A'],
            'knowledge_tag' => '英文／時態', 'difficulty' => 2, 'source_type' => 'internal',
        ])->assertCreated()->json('data');

        $this->withAuth($teacher['token'])->postJson('/api/v1/question-bank-items/' . $item['id'] . '/submit-review')->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->withAuth($teacher['token'])->postJson('/api/v1/question-bank-items/' . $item['id'] . '/approve')->assertForbidden();
        $this->withAuth($director['token'])->postJson('/api/v1/question-bank-items/' . $item['id'] . '/approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $new = $this->withAuth($teacher['token'])->patchJson('/api/v1/question-bank-items/' . $item['id'], [
            'prompt' => '選出正確答案（更新版）', 'difficulty' => 3,
        ])->assertCreated()->assertJsonPath('data.version_no', 2)->json('data');
        $this->assertDatabaseHas('question_bank_items', ['id' => $item['id'], 'status' => 'approved', 'version_no' => 1]);
        $this->withAuth($teacher['token'])->getJson('/api/v1/question-bank-items/' . $new['id'] . '/versions')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('question_bank_audit_logs', ['action' => 'version_created', 'question_bank_item_id' => $new['id']]);
    }

    public function test_csv_import_is_strict_atomic_and_campus_scoped(): void
    {
        $campusA = $this->makeCampus(); $campusB = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'question-importer@example.com', $campusA);
        $bank = QuestionBank::create(['campus_id' => $campusA, 'name' => '匯入題庫', 'status' => 'draft', 'created_by_user_id' => $teacher['user']->id]);
        $bad = "question_type,prompt,knowledge_tag,difficulty\nsingle_choice,有效題,標籤,2\nsingle_choice,錯誤題,標籤,9\n";
        $this->withAuth($teacher['token'])->post('/api/v1/question-banks/' . $bank->id . '/items/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad)])->assertStatus(422);
        $this->assertDatabaseCount('question_bank_items', 0);

        $good = implode("\n", [
            'question_type,prompt,knowledge_tag,difficulty,choices,answer,source_type',
            'single_choice,有效題,標籤,2,"[""A"",""B""]","[""A""]",internal',
            '',
        ]);
        $this->withAuth($teacher['token'])->post('/api/v1/question-banks/' . $bank->id . '/items/import', ['file' => UploadedFile::fake()->createWithContent('good.csv', $good)])->assertCreated()->assertJsonPath('count', 1)->assertJsonPath('data.0.status', 'pending_review');
        $this->withAuth($teacher['token'])->getJson('/api/v1/question-banks?campus_id=' . $campusB)->assertForbidden();
        $this->assertDatabaseHas('question_bank_audit_logs', ['action' => 'item_imported', 'campus_id' => $campusA]);
    }

    private function makeCampus(): int { return (int) CampusFactory::new()->create(['name' => '題庫分校 ' . Str::random(5)])->id; }

    private function makeStaff(string $type, string $login, int $campus): array
    {
        $user = User::create(['LoginName' => $login, 'Name' => '題庫測試人員', 'PSW' => 'secret', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user' => $user];
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
    }
}
