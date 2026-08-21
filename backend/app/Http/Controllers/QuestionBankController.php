<?php

namespace App\Http\Controllers;

use App\Models\QuestionBank;
use App\Models\QuestionBankAuditLog;
use App\Models\QuestionBankItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuestionBankController extends Controller
{
    private const TYPES = ['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'short_answer'];
    private const SOURCES = ['internal', 'licensed', 'ai_draft', 'other'];

    public function index(Request $request)
    {
        $query = $this->accessibleBanks($request)->withCount('items')->latest('id');
        if ($request->filled('campus_id')) {
            $campus = (int) $request->input('campus_id');
            if (!$this->campusAllowed($request, $campus)) return response()->json(['message' => 'Forbidden'], 403);
            $query->where('campus_id', $campus);
        }
        return response()->json(['data' => $query->limit(200)->get()->map(fn (QuestionBank $b) => $this->bankPayload($b))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'campus_id' => ['required', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        if (!$this->campusAllowed($request, (int) $data['campus_id'])) return response()->json(['message' => 'Forbidden'], 403);
        $bank = DB::transaction(function () use ($data, $request) {
            $bank = QuestionBank::create(array_merge($data, ['status' => 'draft', 'created_by_user_id' => $this->userId($request)]));
            $this->audit($request, $bank, null, 'bank_created', null, $bank->fresh()->toArray());
            return $bank;
        });
        return response()->json(['data' => $this->bankPayload($bank)], 201);
    }

    public function show(Request $request, QuestionBank $questionBank)
    {
        if (!$this->canAccessBank($request, $questionBank)) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['data' => $this->bankPayload($questionBank->loadCount('items'))]);
    }

    public function items(Request $request, QuestionBank $questionBank)
    {
        if (!$this->canAccessBank($request, $questionBank)) return response()->json(['message' => 'Not found'], 404);
        $query = $questionBank->items()->latest('question_key')->latest('version_no');
        foreach (['status', 'question_type', 'difficulty', 'knowledge_tag'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        return response()->json(['data' => $query->limit(500)->get()->map(fn (QuestionBankItem $i) => $this->itemPayload($i))]);
    }

    public function storeItem(Request $request, QuestionBank $questionBank)
    {
        if (!$this->canManageBank($request, $questionBank)) return response()->json(['message' => 'Not found'], 404);
        $data = $this->validatedItem($request);
        $item = DB::transaction(function () use ($data, $questionBank, $request) {
            $item = $questionBank->items()->create(array_merge($data, [
                'question_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => 'draft',
                'created_by_user_id' => $this->userId($request),
            ]));
            $this->audit($request, $questionBank, $item, 'item_created', null, $item->fresh()->toArray());
            return $item;
        });
        return response()->json(['data' => $this->itemPayload($item)], 201);
    }

    public function updateItem(Request $request, QuestionBankItem $questionBankItem)
    {
        $bank = $questionBankItem->bank;
        if (!$bank || !$this->canManageBank($request, $bank)) return response()->json(['message' => 'Not found'], 404);
        if ($questionBankItem->status === 'retired') return response()->json(['message' => '已退休題目不可修改'], 409);
        $data = $this->validatedItem($request, true);
        $next = DB::transaction(function () use ($data, $questionBankItem, $bank, $request) {
            $version = ((int) $bank->items()->where('question_key', $questionBankItem->question_key)->max('version_no')) + 1;
            $next = $bank->items()->create(array_merge($questionBankItem->only([
                'question_key', 'question_type', 'prompt', 'choices', 'answer', 'explanation',
                'knowledge_tag', 'difficulty', 'source_type', 'source_ref',
            ]), $data, [
                'version_no' => $version, 'status' => 'draft', 'created_by_user_id' => $this->userId($request),
            ]));
            $this->audit($request, $bank, $next, 'version_created', $questionBankItem->toArray(), $next->fresh()->toArray());
            return $next;
        });
        return response()->json(['data' => $this->itemPayload($next)], 201);
    }

    public function versions(Request $request, QuestionBankItem $questionBankItem)
    {
        $bank = $questionBankItem->bank;
        if (!$bank || !$this->canAccessBank($request, $bank)) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['data' => $bank->items()->where('question_key', $questionBankItem->question_key)
            ->orderByDesc('version_no')->get()->map(fn (QuestionBankItem $i) => $this->itemPayload($i))]);
    }

    public function submitReview(Request $request, QuestionBankItem $questionBankItem)
    {
        return $this->transition($request, $questionBankItem, 'draft', 'pending_review', 'submitted_for_review');
    }

    public function approve(Request $request, QuestionBankItem $questionBankItem)
    {
        if (!$this->canReview($request)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->transition($request, $questionBankItem, 'pending_review', 'approved', 'approved');
    }

    public function retire(Request $request, QuestionBankItem $questionBankItem)
    {
        if (!$this->canReview($request)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->transition($request, $questionBankItem, ['draft', 'pending_review', 'approved'], 'retired', 'retired');
    }

    public function importItems(Request $request, QuestionBank $questionBank)
    {
        if (!$this->canManageBank($request, $questionBank)) return response()->json(['message' => 'Not found'], 404);
        $request->validate(['file' => ['required', 'file', 'max:1024']]);
        $handle = @fopen($request->file('file')->getRealPath(), 'rb');
        if (!$handle) throw ValidationException::withMessages(['file' => 'CSV 檔案無法讀取。']);
        $headers = fgetcsv($handle);
        if ($headers === false) throw ValidationException::withMessages(['file' => 'CSV 缺少標題列。']);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $required = ['question_type', 'prompt', 'knowledge_tag', 'difficulty'];
        $missing = array_values(array_diff($required, $headers));
        if ($missing) throw ValidationException::withMessages(['file' => 'CSV 缺少欄位：' . implode(', ', $missing)]);
        $rows = []; $errors = []; $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            if (count($rows) >= 500) { $errors[] = ['row' => $line, 'message' => '單次最多匯入 500 題。']; break; }
            $raw = [];
            foreach ($headers as $index => $header) $raw[$header] = trim((string) ($values[$index] ?? ''));
            try { $rows[] = $this->csvItem($raw); }
            catch (ValidationException $e) { $errors[] = ['row' => $line, 'message' => implode(' ', $e->validator->errors()->all())]; }
            if (count($errors) >= 20) break;
        }
        fclose($handle);
        if ($errors) return response()->json(['message' => 'CSV 有資料錯誤，未匯入任何題目。', 'errors' => $errors], 422);
        if (!$rows) return response()->json(['message' => 'CSV 沒有可匯入的資料。'], 422);
        $items = DB::transaction(function () use ($rows, $questionBank, $request) {
            $created = [];
            foreach ($rows as $data) {
                $key = $data['question_key'] ?? (string) Str::uuid();
                $version = ((int) $questionBank->items()->where('question_key', $key)->max('version_no')) + 1;
                $item = $questionBank->items()->create(array_merge($data, [
                    'question_key' => $key, 'version_no' => $version, 'status' => 'pending_review',
                    'created_by_user_id' => $this->userId($request),
                ]));
                $this->audit($request, $questionBank, $item, 'item_imported', null, $item->fresh()->toArray());
                $created[] = $item;
            }
            return $created;
        });
        return response()->json(['data' => collect($items)->map(fn (QuestionBankItem $i) => $this->itemPayload($i))->values(), 'count' => count($items)], 201);
    }

    private function transition(Request $request, QuestionBankItem $item, $from, string $to, string $action)
    {
        $bank = $item->bank;
        if (!$bank || !$this->canAccessBank($request, $bank)) return response()->json(['message' => 'Not found'], 404);
        $allowed = (array) $from;
        if ($item->status === $to) return response()->json(['data' => $this->itemPayload($item)]);
        if (!in_array($item->status, $allowed, true)) return response()->json(['message' => '目前狀態不可轉換'], 409);
        $before = $item->fresh()->toArray();
        $item->update(['status' => $to, 'reviewed_by_user_id' => $to === 'approved' ? $this->userId($request) : $item->reviewed_by_user_id, 'reviewed_at' => $to === 'approved' ? now() : $item->reviewed_at]);
        $this->audit($request, $bank, $item, $action, $before, $item->fresh()->toArray());
        return response()->json(['data' => $this->itemPayload($item->fresh())]);
    }

    private function csvItem(array $raw): array
    {
        $data = [
            'question_type' => $raw['question_type'] ?? '', 'prompt' => $raw['prompt'] ?? '',
            'knowledge_tag' => $raw['knowledge_tag'] ?? '', 'difficulty' => $raw['difficulty'] ?? '',
            'explanation' => ($raw['explanation'] ?? '') ?: null, 'source_type' => ($raw['source_type'] ?? '') ?: 'internal',
            'source_ref' => ($raw['source_ref'] ?? '') ?: null,
        ];
        if (!in_array($data['question_type'], self::TYPES, true)) throw ValidationException::withMessages(['question_type' => '題型無效。']);
        if ($data['prompt'] === '' || mb_strlen($data['prompt']) > 20000) throw ValidationException::withMessages(['prompt' => '題目內容不可空白且不可超過 20000 字。']);
        if ($data['knowledge_tag'] === '' || mb_strlen($data['knowledge_tag']) > 120) throw ValidationException::withMessages(['knowledge_tag' => '知識標籤不可空白且不可超過 120 字。']);
        if (!ctype_digit((string) $data['difficulty']) || (int) $data['difficulty'] < 1 || (int) $data['difficulty'] > 5) throw ValidationException::withMessages(['difficulty' => '難度必須是 1 到 5。']);
        if (!in_array($data['source_type'], self::SOURCES, true)) throw ValidationException::withMessages(['source_type' => '來源類型無效。']);
        foreach (['choices', 'answer'] as $jsonField) {
            if (($raw[$jsonField] ?? '') === '') { $data[$jsonField] = null; continue; }
            $decoded = json_decode($raw[$jsonField], true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw ValidationException::withMessages([$jsonField => '必須是 JSON 陣列。']);
            $data[$jsonField] = $decoded;
        }
        if (!empty($raw['question_key']) && !Str::isUuid($raw['question_key'])) throw ValidationException::withMessages(['question_key' => 'question_key 必須是 UUID。']);
        if (!empty($raw['question_key'])) $data['question_key'] = $raw['question_key'];
        return $data;
    }

    private function validatedItem(Request $request, bool $partial = false): array
    {
        $rules = [
            'question_type' => ['required', 'in:' . implode(',', self::TYPES)], 'prompt' => ['required', 'string', 'max:20000'],
            'choices' => ['nullable', 'array'], 'answer' => ['nullable', 'array'], 'explanation' => ['nullable', 'string', 'max:20000'],
            'knowledge_tag' => ['required', 'string', 'max:120'], 'difficulty' => ['required', 'integer', 'between:1,5'],
            'source_type' => ['nullable', 'in:' . implode(',', self::SOURCES)], 'source_ref' => ['nullable', 'string', 'max:255'],
        ];
        if ($partial) foreach ($rules as $field => $rule) $rules[$field] = array_merge(['sometimes'], $rule);
        return $request->validate($rules);
    }

    private function accessibleBanks(Request $request): Builder
    {
        $query = QuestionBank::query();
        return $this->isSuperAdmin($request) ? $query : $query->whereIn('campus_id', $this->campusIds($request));
    }

    private function canAccessBank(Request $request, QuestionBank $bank): bool
    {
        return $this->isSuperAdmin($request) || in_array((int) $bank->campus_id, $this->campusIds($request), true);
    }

    private function canManageBank(Request $request, QuestionBank $bank): bool
    {
        return in_array($this->role($request), ['director', 'teacher', 'super_admin'], true) && $this->canAccessBank($request, $bank);
    }

    private function canReview(Request $request): bool
    {
        return in_array($this->role($request), ['director', 'super_admin'], true);
    }

    private function audit(Request $request, QuestionBank $bank, ?QuestionBankItem $item, string $action, $before, $after): void
    {
        QuestionBankAuditLog::create([
            'question_bank_id' => $bank->id, 'question_bank_item_id' => $item?->id,
            'campus_id' => $bank->campus_id, 'actor_user_id' => $this->userId($request),
            'action' => $action, 'before' => $before, 'after' => $after,
        ]);
    }

    private function bankPayload(QuestionBank $bank): array
    {
        return ['id' => (int) $bank->id, 'campus_id' => (int) $bank->campus_id, 'subject_id' => $bank->subject_id ? (int) $bank->subject_id : null, 'name' => $bank->name, 'description' => $bank->description, 'status' => $bank->status, 'items_count' => (int) ($bank->items_count ?? 0)];
    }

    private function itemPayload(QuestionBankItem $item): array
    {
        return ['id' => (int) $item->id, 'question_bank_id' => (int) $item->question_bank_id, 'question_key' => $item->question_key, 'version_no' => (int) $item->version_no, 'question_type' => $item->question_type, 'prompt' => $item->prompt, 'choices' => $item->choices, 'answer' => $item->answer, 'explanation' => $item->explanation, 'knowledge_tag' => $item->knowledge_tag, 'difficulty' => (int) $item->difficulty, 'source_type' => $item->source_type, 'source_ref' => $item->source_ref, 'status' => $item->status, 'review_note' => $item->review_note, 'created_at' => optional($item->created_at)->toISOString(), 'reviewed_at' => optional($item->reviewed_at)->toISOString()];
    }

    private function role(Request $request): string { return (string) $request->attributes->get('auth_role', ''); }
    private function userId(Request $request): ?int { return optional($request->attributes->get('auth_user'))->id ? (int) $request->attributes->get('auth_user')->id : null; }
    private function isSuperAdmin(Request $request): bool { return $this->role($request) === 'super_admin'; }
    private function campusIds(Request $request): array { return array_values(array_filter(array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])))); }
    private function campusAllowed(Request $request, int $campus): bool { return $this->isSuperAdmin($request) || in_array($campus, $this->campusIds($request), true); }
}
