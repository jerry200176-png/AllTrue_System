<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Founder GO 2026-11-16: a read-only triage token can read rows of the business tables, so an agent can check a
 * contract, bill or lesson by its number without a browser session, a screenshot or database access.
 * Fail-closed on what leaves: number and date/time columns, plus text columns that are an enum or a known code column
 * and whose value is a short ASCII code. Names, phones, notes, credentials and any other text never leave, and such
 * columns cannot be filtered or sorted on either. Tables that hold logins, tokens or bindings are not reachable.
 * SELECT only. Switch: triage_tokens.db_read_enabled.
 */
class TriageDbReadController extends Controller
{
    /** Tables that are about signing in or linking an account, and framework tables. */
    private const DENIED_TABLES = '/token|password|secret|credential|apiclient|api_client|parentsession|^sessions$|binding|rfid|system_settings|login_activit|^cache|^jobs$|job_batches|failed_jobs|^migrations$/';

    /** Column names that identify a person or unlock something, whatever their type. */
    private const DENIED_COLUMNS = '/pass|psw|token|secret|hash|salt|otp|birth|phone|mobile|(^|_)tel|mail|address|rfid|card|account|bank|national|idno|id_number|notify|line_/i';

    /** Text columns that hold a code, not something a person typed. An enum column is always one. */
    private const CODE_COLUMNS = ['status', 'method', 'schedulemode', 'schedulemodeatissue', 'rate_unit', 'classtype', 'class_type',
        'closed_reason', 'billing_period', 'type', 'kind', 'source', 'state', 'role_key', 'deduction_basis', 'payment_type',
        'outcome', 'event_type', 'operation', 'action', 'reason_code', 'zero_reason', 'page_key', 'disposition', 'period', 'month'];

    private const OPERATORS = ['eq' => '=', 'gte' => '>=', 'lte' => '<='];

    public function tables()
    {
        if (!(bool) config('triage_tokens.db_read_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['tables' => array_values(array_filter(
            array_map(fn (array $t): string => (string) $t['name'], Schema::getTables()),
            fn (string $name): bool => $this->tableAllowed($name),
        ))]);
    }

    public function rows(Request $request, string $table)
    {
        if (!(bool) config('triage_tokens.db_read_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // The name must be one the database itself lists: nothing the caller typed is used as an identifier.
        $table = collect(Schema::getTables())->pluck('name')->first(fn ($name) => (string) $name === $table);
        if ($table === null || !$this->tableAllowed((string) $table)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:100000'],
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
        foreach (self::OPERATORS as $key => $operator) {
            foreach ((array) ($data[$key] ?? []) as $column => $value) {
                if (!in_array((string) $column, $readable, true) || !is_scalar($value)) {
                    return $refuse((string) $column);
                }
                $query->where((string) $column, $operator, $value);
            }
        }
        foreach ((array) ($data['in'] ?? []) as $column => $values) {
            if (!in_array((string) $column, $readable, true) || !is_string($values)) {
                return $refuse((string) $column);
            }
            $query->whereIn((string) $column, array_slice(explode(',', $values), 0, 200));
        }
        $order = $data['order'] ?? collect($readable)->first(fn (string $name) => strtolower($name) === 'id');
        if ($order !== null) {
            if (!in_array($order, $readable, true)) {
                return $refuse((string) $order);
            }
            $query->orderBy($order, $data['dir'] ?? 'asc');
        }
        // ponytail: no statement timeout; the page size, the offset cap and the route's own rate limit bound the cost.
        $rows = $query->offset((int) ($data['offset'] ?? 0))->limit((int) ($data['limit'] ?? 50))->get()
            ->map(function (object $row) use ($codes): array {
                $out = (array) $row;
                foreach ($codes as $name) {
                    // A code column can still hold typed text in old rows: anything that is not a short ASCII code is withheld.
                    if (is_string($out[$name]) && preg_match('/\A[A-Za-z0-9_.:\-]{0,40}\z/', $out[$name]) !== 1) {
                        $out[$name] = null;
                    }
                }

                return $out;
            });

        return response()->json(['table' => $table, 'columns' => $readable, 'withheld_columns' => count($columns) - count($readable),
            'count' => $rows->count(), 'rows' => $rows->all()]);
    }

    private function tableAllowed(string $table): bool
    {
        return preg_match(self::DENIED_TABLES, strtolower($table)) !== 1;
    }
}
