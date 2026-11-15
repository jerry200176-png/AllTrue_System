<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Founder GO 2026-11-16: a read-only triage token can read rows of the business tables, so an agent can check a
 * contract, bill or lesson by its number without a browser session, a screenshot or database access.
 * What leaves: number and date/time columns, plus text columns that are an enum or a known code column and whose value
 * is a short ASCII code. Text is fail-closed: names, notes and any other text column never leave and cannot be filtered or
 * sorted on; a code column takes only an exact match against a code. Numbers are guarded by column name (phone, pin,
 * birthday, account ...) and by size: a number of nine digits or more is withheld and cannot be filtered on.
 * Tables that hold logins, tokens or bindings are not reachable.
 * SELECT only. Switch: triage_tokens.db_read_enabled.
 */
class TriageDbReadController extends Controller
{
    /** Tables that are about signing in or linking an account, and framework tables. */
    private const DENIED_TABLES = '/token|password|secret|credential|apiclient|api_client|parentsession|^sessions$|binding|rfid|system_settings|login_activit|^cache|^jobs$|job_batches|failed_jobs|^migrations$/';

    /** Column names that identify a person or unlock something, whatever their type. */
    private const DENIED_COLUMNS = '/pass|psw|token|secret|hash|salt|otp|pin|birth|dob|phone|mobile|tel|fax|mail|address|zip|rfid|card|account(?!ing)|bank|national|idno|id_number|notify|line_/i';

    /** A number this large is not an id, a count or an amount here; it may be a phone or an identity number in an old column. */
    private const LONG_NUMBER = 100000000;

    /**
     * The only shapes a filter value for a number or date column may take: a plain number below LONG_NUMBER, a date,
     * a date and time, or a time. The database would otherwise read "912345678abc", "9.1e8" or "+912345678" as a number.
     */
    private const NUMBER_OR_DATE = '/\A(-?\d{1,8}(\.\d{1,4})?|\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?|\d{2}:\d{2}(:\d{2})?)\z/';

    /** Text columns that hold a code, not something a person typed. An enum column is always one. */
    private const CODE_COLUMNS = ['status', 'method', 'schedulemode', 'schedulemodeatissue', 'rate_unit', 'classtype', 'class_type',
        'closed_reason', 'billing_period', 'type', 'kind', 'source', 'state', 'role_key', 'deduction_basis', 'payment_type',
        'outcome', 'event_type', 'operation', 'action', 'reason_code', 'zero_amount_reason', 'page_key', 'disposition', 'period', 'month'];

    private const OPERATORS = ['eq' => '=', 'gte' => '>=', 'lte' => '<='];

    /**
     * What a code looks like here: a lowercase word such as `unpaid` or `one_on_one`, one to three capital letters (a role),
     * or a YYYY-MM period. A capitalised word, anything with a space, and any run of digits (a phone) is typed text.
     */
    private const CODE_VALUE = '/\A([a-z][a-z0-9_.:\-]{0,39}|[A-Z]{1,3}|\d{4}-\d{2})?\z/';

    /** Code-named columns that the application lets a person type into; never returned. */
    private const TYPED_COLUMNS = [
        'course_packages' => ['closed_reason'],
        'schedules' => ['status', 'type', 'class_type'],
        'bug_reports' => ['page_key'],
    ];

    /** A column a person can type into but whose codes are needed: only these values are returned or matched. */
    private const KNOWN_VALUES = [
        'Payment' => ['Method' => ['cash', 'transfer', 'bank_transfer', 'card', 'line_pay', 'other', 'void', 'refund',
            'transfer_out', 'transfer_in', 'transfer_reversal_out', 'transfer_reversal_in']],
    ];

    public function tables()
    {
        if (!(bool) config('triage_tokens.db_read_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['tables' => array_values(array_filter($this->tableNames(), fn (string $name): bool => $this->tableAllowed($name)))]);
    }

    public function rows(Request $request, string $table)
    {
        if (!(bool) config('triage_tokens.db_read_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // The name must be one the database itself lists: nothing the caller typed is used as an identifier.
        $table = collect($this->tableNames())->first(fn (string $name) => $name === $table);
        if ($table === null || !$this->tableAllowed((string) $table)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'order' => ['nullable', 'string', 'max:64'],
            'dir' => ['nullable', 'in:asc,desc'],
            'eq' => ['nullable', 'array', 'max:6'], 'gte' => ['nullable', 'array', 'max:6'], 'lte' => ['nullable', 'array', 'max:6'],
            'in' => ['nullable', 'array', 'max:3'],
        ]);

        $columns = Schema::getColumns($table);
        $numeric = [];
        $codes = [];
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if (preg_match(self::DENIED_COLUMNS, $name) === 1) {
                continue;
            }
            $type = strtolower((string) $column['type_name']);
            if (preg_match('/int|decimal|numeric|float|double|real|bool|year|date|time/', $type) === 1) {
                $numeric[] = $name;
            } elseif (in_array($name, self::TYPED_COLUMNS[$table] ?? [], true)) {
                continue;
            } elseif ($type === 'enum' || in_array(strtolower($name), self::CODE_COLUMNS, true)) {
                $codes[] = $name;
            }
        }
        $readable = [...$numeric, ...$codes];
        if ($readable === []) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $query = DB::table($table)->select($readable);
        $refuse = fn (string $column) => response()->json(['message' => "Column not readable: {$column}"], 422);
        // A code column may hold typed text in old rows, which is withheld on the way out. So it takes no range filter and no
        // ordering (either would let the text be found one comparison at a time), and an exact match only against a value
        // that is itself a short ASCII code: a row holding typed text can never be singled out.
        $known = self::KNOWN_VALUES[$table] ?? [];
        $isCodeOf = fn (string $column, mixed $value): bool => is_string($value) && preg_match(self::CODE_VALUE, $value) === 1
            && (!isset($known[$column]) || $value === '' || in_array($value, $known[$column], true));
        // The database compares text by its collation (case, accents, trailing spaces), so a code can "equal" typed text
        // there. Rows are kept only when the stored value is byte-for-byte one of the codes asked for.
        $exact = [];
        // A withheld long number cannot be searched for either: a number or date column takes only a plain small number or a date.
        $isPlain = fn (mixed $value): bool => is_scalar($value) && preg_match(self::NUMBER_OR_DATE, (string) $value) === 1;
        foreach (self::OPERATORS as $key => $operator) {
            foreach ((array) ($data[$key] ?? []) as $column => $value) {
                $allowed = (in_array((string) $column, $numeric, true) && $isPlain($value))
                    || ($key === 'eq' && in_array((string) $column, $codes, true) && $isCodeOf((string) $column, $value));
                if (!$allowed || !is_scalar($value)) {
                    return $refuse((string) $column);
                }
                $query->where((string) $column, $operator, $value);
                if (in_array((string) $column, $codes, true)) {
                    $exact[(string) $column][] = [(string) $value];
                }
            }
        }
        foreach ((array) ($data['in'] ?? []) as $column => $values) {
            $list = is_string($values) ? array_slice(explode(',', $values), 0, 200) : null;
            $allowed = $list !== null && ((in_array((string) $column, $numeric, true) && collect($list)->every($isPlain))
                || (in_array((string) $column, $codes, true) && collect($list)->every(fn ($value) => $isCodeOf((string) $column, $value))));
            if (!$allowed) {
                return $refuse((string) $column);
            }
            $query->whereIn((string) $column, $list);
            if (in_array((string) $column, $codes, true)) {
                $exact[(string) $column][] = $list;
            }
        }
        $order = $data['order'] ?? collect($numeric)->first(fn (string $name) => strtolower($name) === 'id');
        if ($order !== null) {
            if (!in_array($order, $numeric, true)) {
                return $refuse((string) $order);
            }
            $query->orderBy($order, $data['dir'] ?? 'asc');
        }
        // ponytail: no statement timeout; the page size, the offset cap and the route's own rate limit bound the cost.
        // ponytail: ordering by a number column still shows the relative order of withheld long numbers (never the values);
        // cap the column in SQL if an old table turns out to keep phone-like numbers under an unlisted name.
        $rows = $query->offset((int) ($data['offset'] ?? 0))->limit((int) ($data['limit'] ?? 50))->get()
            ->filter(function (object $row) use ($exact): bool {
                foreach ($exact as $name => $lists) {
                    foreach ($lists as $list) {
                        if (!in_array($row->{$name}, $list, true)) {
                            return false;
                        }
                    }
                }

                return true;
            })->values()
            ->map(function (object $row) use ($codes, $numeric, $isCodeOf): array {
                $out = (array) $row;
                foreach ($numeric as $name) {
                    if (is_numeric($out[$name]) && abs((float) $out[$name]) >= self::LONG_NUMBER) {
                        $out[$name] = null;
                    }
                }
                foreach ($codes as $name) {
                    // A code column can still hold typed text in old rows: anything that is not a short ASCII code is withheld.
                    if (is_string($out[$name]) && !$isCodeOf($name, $out[$name])) {
                        $out[$name] = null;
                    }
                }

                return $out;
            });

        return response()->json(['table' => $table, 'columns' => $readable, 'withheld_columns' => count($columns) - count($readable),
            'count' => $rows->count(), 'rows' => $rows->all()]);
    }

    /** @return list<string> tables of this application's own database only */
    private function tableNames(): array
    {
        $own = DB::connection()->getDatabaseName();

        return collect(Schema::getTables())
            ->filter(fn (array $t): bool => !isset($t['schema']) || $t['schema'] === $own)
            ->map(fn (array $t): string => (string) $t['name'])->values()->all();
    }

    private function tableAllowed(string $table): bool
    {
        return preg_match(self::DENIED_TABLES, strtolower($table)) !== 1;
    }
}
