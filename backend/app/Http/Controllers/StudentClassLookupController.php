<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Billing\ContractMoneyState;
use App\Services\Billing\ContractMoneyVerdict;
use App\Services\BillingPayableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** #3868 (Founder A): a super_admin turns engineering contract ids (StudentClass.ID) into readable rows, so ids can
 * travel through GitHub without names. Read-only, at most 50 ids, one audit row per request. Money comes from the
 * existing rules only: StudentClass::currentContractCharge / rateTimesQuantity, ContractMoneyVerdict, and the
 * non-void invoice count of BillingPayableResolver. */
class StudentClassLookupController extends Controller
{
    private const MAX_IDS = 50;

    /** Wording of courseMoneyState.js for the ContractMoneyVerdict statuses. */
    private const STATUS_NOTE = [
        'paid' => '已收', 'free' => '免費／贈送', 'partial' => '繳了一部分', 'unpaid' => '未繳',
        'unbilled' => '尚未開帳單', 'review_required' => '需核對',
    ];

    public function __construct()
    {
        $this->middleware(fn (Request $r, \Closure $next) => in_array($r->attributes->get('auth_user')?->status, ['inactive', 'suspended'], true) ? response()->json(['message' => 'Forbidden'], 403) : $next($r));
    }

    public function index(Request $request)
    {
        $ids = $this->parseIds($request->query('ids'));
        $user = $request->attributes->get('auth_user');
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        // Audit first and fail closed: no row is read when the evidence cannot be written. Ids are not PII, but the
        // audit row keeps them as an HMAC ref like every other batch; the plain ids go to the log (ids only, no names).
        SecurityAuditEvent::append('student_class_lookup.read', 'success', [
            'actor_type' => 'user', 'actor_id' => $user->getKey(),
            'subject_type' => 'student_class_batch', 'subject_id' => implode(',', collect($ids)->sort()->all()),
        ], ['row_count' => count($ids)], true);
        Log::info('student_class_lookup', ['ids' => $ids]);

        $courses = StudentClass::query()->with(['student:id,name,CampusID', 'teacher:id,Name'])->whereIn('ID', $ids)->get()->keyBy(fn (StudentClass $c) => (int) $c->getKey());
        $campusNames = Campus::query()->whereIn('id', $courses->map(fn (StudentClass $c) => (int) $c->student?->CampusID)->filter()->unique()->all())->pluck('name', 'id');
        $verdicts = $courses->isEmpty() ? [] : app(ContractMoneyVerdict::class)->forCourses($courses->values());
        $invoiceCounts = app(BillingPayableResolver::class)->invoiceAggregateByStudentClassIds($courses->keys()->all());

        $rows = array_map(fn (int $id) => $courses->has($id)
            ? $this->row($courses[$id], $campusNames->all(), $verdicts[$id]->status ?? null, (int) ($invoiceCounts[$id]['active_invoice_count'] ?? 0))
            : ['id' => $id, 'found' => false], $ids);

        return response()->json(['data' => $rows])->header('Cache-Control', 'private, no-store');
    }

    /** @return list<int> distinct positive ids in the order given; 422 for anything else. */
    private function parseIds(mixed $raw): array
    {
        $parts = is_string($raw) ? array_map('trim', explode(',', $raw)) : [];
        $valid = $parts !== [] && count($parts) <= self::MAX_IDS
            && collect($parts)->every(fn (string $p) => preg_match('/^[1-9][0-9]{0,9}$/', $p) === 1);
        if (!$valid) {
            throw ValidationException::withMessages(['ids' => '請輸入 1 到 ' . self::MAX_IDS . ' 個合約編號（正整數，用逗號分隔）。']);
        }

        return array_values(array_unique(array_map('intval', $parts)));
    }

    /**
     * @param array<int, string> $campusNames
     * @return array<string, mixed>
     */
    private function row(StudentClass $c, array $campusNames, ?string $status, int $invoiceCount): array
    {
        $count = (string) ($c->ScheduleMode ?? 'count') === 'count';
        $rate = $c->Rate === null ? null : (float) $c->Rate;
        $charge = $c->currentContractCharge();
        $base = $count && $rate > 0 ? $c->rateTimesQuantity() : null;
        $notes = array_filter([
            ContractMoneyState::isWaived($c) ? '不收了' : null,
            self::STATUS_NOTE[$status] ?? null,
            $charge !== null && $base !== null && $charge !== $base ? '總費用與單價×堂數不同' : null,
        ]);

        return [
            'id' => (int) $c->getKey(), 'found' => true,
            'campus_id' => $c->student ? (int) $c->student->CampusID : null,
            'campus_name' => $campusNames[(int) $c->student?->CampusID] ?? null,
            'student_id' => (int) $c->StudentID, 'student_name' => $c->student?->name,
            'subject' => $c->displaySubjectName(), 'teacher_name' => $c->teacher?->Name,
            'schedule_mode' => (string) ($c->ScheduleMode ?? ''),
            'start_date' => $c->StartDate ? substr((string) $c->StartDate, 0, 10) : null,
            'end_date' => $c->EndDate ? substr((string) $c->EndDate, 0, 10) : null,
            // enabled = the contract is running (not stopped).
            'enabled' => (int) ($c->Stop ?? 0) !== 1,
            'charge' => $charge, 'rate' => $rate,
            'sessions' => $c->SessionCount === null ? null : (int) $c->SessionCount,
            // What the rate multiplies: hours for an hourly contract (rate_unit = hour), else the session count.
            'rate_unit' => (string) $c->rate_unit === 'hour' ? 'hour' : 'session',
            'quantity' => $base === null ? null : $c->pricedQuantity(),
            'rate_x_sessions' => $base,
            'has_invoice' => $invoiceCount > 0,
            'money_note' => implode('；', $notes),
        ];
    }
}
