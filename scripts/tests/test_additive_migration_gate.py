"""Founder 2026-10-14 (三)④ + 補充 #1: declared additive-only migrations merge as T2; others stay T3."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from unittest import mock  # noqa: E402

import scripts.governance.autonomy_gate as gate  # noqa: E402
from scripts.governance.autonomy_gate import (  # noqa: E402
    additive_migration_paths, classify_activation_scope, classify_scope, declares_additive_migration,
    validate_declaration,
)

MIG = "backend/database/migrations/2026_10_14_000000_x.php"


def new_file(path, body):
    lines = body.strip("\n").split("\n")
    return f"diff --git a/{path} b/{path}\n@@ -0,0 +1,{len(lines)} @@\n" + "".join(f"+{l}\n" for l in lines)


def migration(up, down=""):
    return f"""<?php
use Illuminate\\Database\\Migrations\\Migration;
return new class extends Migration {{
    public function up(): void {{ {up} }}
    public function down(): void {{ {down} }}
}};"""


ADD = migration("Schema::table('lesson_notes', function (Blueprint $t) { $t->string('zero_reason')->nullable(); });")
CREATE = migration("Schema::create('notes', function (Blueprint $t) { $t->id(); $t->text('body'); $t->index('id'); });",
                   "Schema::dropIfExists('notes');")


class AdditiveMigrationGate(unittest.TestCase):
    def tiers(self, patch, paths=(MIG,)):
        return classify_scope(list(paths), patch, True)["machine_minimum_tier"], \
            classify_activation_scope(list(paths), patch, True)["machine_minimum_tier"]

    def test_add_column_and_create_table_are_t2(self):
        for body in (ADD, CREATE):
            patch = new_file(MIG, body)
            self.assertEqual(additive_migration_paths([MIG], patch), {MIG})
            # Founder 2026-10-28 A: merge and activation are both T2; the switch off restores the click.
            self.assertEqual(self.tiers(patch), (2, 2))
            with mock.patch.object(gate, "ADDITIVE_MIGRATION_AUTO_ACTIVATION", False):
                self.assertEqual(self.tiers(patch), (2, 3))

    def test_activation_flag_is_on_after_deploy_rollback_and_watch(self):
        self.assertIs(gate.ADDITIVE_MIGRATION_AUTO_ACTIVATION, True)

    def test_destructive_or_data_up_stays_t3(self):
        for up in (
            "Schema::table('t', fn ($t) => $t->dropColumn('c'));",
            "Schema::dropIfExists('t');",
            "Schema::table('t', fn ($t) => $t->renameColumn('a', 'b'));",
            "Schema::table('t', fn ($t) => $t->string('a', 10)->change());",
            "Schema::table('t', fn ($t) => $t->string('a')); DB::table('t')->update(['a' => 1]);",
            "Schema::table('t', fn ($t) => $t->unique('a'));",
            "Schema::table('t', fn ($t) => $t->foreignId('u')->constrained());",
            "Schema::table('t', fn ($t) => $t->string('a')); Student::query()->update(['a' => 1]);",
            "Schema::table('t', fn ($t) => $t->string('a')); foreach (Student::cursor() as $s) { $s->save(); }",
            "Schema::table('t', fn ($t) => $t->string('a')); $this->backfill();",
            "Schema::table('t', fn ($t) => $t->string('a')); Campus::create(['n' => 1]);",
        ):
            patch = new_file(MIG, migration(up))
            self.assertEqual(additive_migration_paths([MIG], patch), set(), up)
            self.assertEqual(self.tiers(patch), (3, 3), up)

    def test_parser_and_import_bypasses_stay_t3(self):
        add = "Schema::table('t', fn ($t) => $t->string('a'));"
        for src in (
            migration(add + " /* function down( */ X::statement('x');"),
            migration(add + " // function down(\n X::statement('x');"),
            "<?php\nuse Illuminate\\Support\\Facades\\DB as Q;\n" + migration(add + " Q::statement('x');")[6:],
            "<?php\nQ::statement('x');\n" + migration(add)[6:],
            migration(add + " $f = 'statement'; $f('x');"),
            migration(add + " $s = <<<SQL\nx\nSQL;"),
            migration(add + " $x = 'function down() {';"),
            migration(add, "Schema::dropIfExists('t'); } public function boot(): void { Q::statement('x');"),
            migration(add + " app('db')->statement('x');"),
            migration(add + " Schema::getConnection()->statement('x');"),
            migration(add + " $x = 'a // '; Q::statement('x');"),
            migration(add + " $x = 'a /* '; Q::statement('x'); $y = ' */';"),
            migration(add + ' $x = "{$f()}";'),
            migration(add + " #[A] Q::statement('x');"),
            migration(add + " // x ?> <?php Q::statement('x');"),
            migration(add + " // x\rQ::statement('x');"),
            migration(add + " $t->string('a')->charset('x');"),
            migration("Schema::table('t', fn ($t) => $t->string('a')->change());"),
            migration("if (Schema::hasTable('t')) { Q::statement('x'); }"),
        ):
            patch = new_file(MIG, src)
            self.assertEqual(additive_migration_paths([MIG], patch), set(), src)
            self.assertEqual(self.tiers(patch), (3, 3), src)

    def test_line_and_chunk_tricks_stay_t3(self):
        add = "Schema::table('t', fn ($t) => $t->string('a'));"
        drop = "Schema::dropIfExists('students');"
        cases = [
            new_file(MIG, migration(add + "\r" + drop)),
            new_file(MIG, migration(add) .replace("{ " + add, "{ " + add + "\n++$n; " + drop, 1)),
            new_file(MIG, migration(add)) + f"diff --git a/{MIG} b/{MIG}\n@@ -5,1 +5,1 @@\n-x\n+{drop}\n",
            new_file(MIG, migration(add).replace("<?php", "<?php\n#[A] class Z { function __construct() { " + drop + " } }", 1)),
        ]
        for patch in cases:
            self.assertEqual(additive_migration_paths([MIG], patch), set(), patch)
            self.assertEqual(self.tiers(patch), (3, 3), patch)

    def test_existing_table_needs_nullable_or_default_and_no_new_key(self):
        bad = (
            "Schema::table('t', function (Blueprint $t) { $t->id(); });",
            "Schema::table('t', function (Blueprint $t) { $t->bigIncrements('k'); });",
            "Schema::table('t', function (Blueprint $t) { $t->string('code'); });",
            "Schema::table('t', fn (Blueprint $t) => $t->integer('n')->after('id'));",
            "Schema::table('t', fn (Blueprint $t) => $t->bigInteger('k', true)->nullable());",
            "Schema::table('t', fn (Blueprint $t) => $t->unsignedInteger('k', TRUE)->default(1));",
            "Schema::table('t', fn (Blueprint $t) => $t->bigInteger('k', 1)->nullable());",
            "Schema::table('t', fn (Blueprint $t) => $t->integer('k', 'yes')->nullable());",
            "Schema::table('t', fn (Blueprint $t) => $t->smallInteger('k', false, 1)->nullable());",
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->nullable(false));",
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->default(null));",
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->nullable()->nullable(false));",
            # A default fills every historical row (module-migration M2 needs a backfill).
            "Schema::table('t', fn (Blueprint $t) => $t->string('s')->default('pending_review'));",
            "Schema::table('t', fn (Blueprint $t) => $t->boolean('b')->nullable()->default(false));",
            "Schema::table('t', fn (Blueprint $t) => $t->integer('n')->default(0));",
            "Schema::table('t', fn (Blueprint $t) => $t->timestamp('c')->nullable()->useCurrent());",
            "Schema::table('t', fn (Blueprint $t) => $t->timestamp('c')->nullable()->useCurrentOnUpdate());",
            "Schema::table('t', fn (Blueprint $t) => $t->timestamp('c')->nullable()->useCurrent()->default(null));",
        )
        for up in bad:
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set(), up)
        good = (
            "Schema::table('t', function (Blueprint $t) { $t->string('c')->nullable(); $t->integer('n')->nullable(); $t->index('c'); $t->timestamps(); });",
            "Schema::create('n', function (Blueprint $t) { $t->id(); $t->string('code'); });",
            "Schema::table('t', fn (Blueprint $t) => $t->boolean('b')->nullable(true));",
            "Schema::table('t', fn (Blueprint $t) => $t->boolean('b')->nullable()->default(null));",
            "Schema::table('t', fn (Blueprint $t) => $t->unsignedBigInteger('k', false)->nullable());",
            "Schema::table('t', fn (Blueprint $t) => $t->decimal('d', 8, 2)->nullable());",
        )
        for up in good:
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), {MIG}, up)

    def test_truncated_patch_stays_t3(self):
        add = "Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable());"
        full = new_file(MIG, migration(add) + "\nDB::statement('x');")
        truncated = full.rsplit("+DB::statement", 1)[0]
        self.assertEqual(additive_migration_paths([MIG], truncated), set())
        self.assertEqual(self.tiers(truncated), (3, 3))
        padded = new_file(MIG, migration(add)).replace("@@ -0,0 +1,", "@@ -0,0 +1,9", 1)
        self.assertEqual(additive_migration_paths([MIG], padded), set())

    def test_down_may_only_drop_what_up_added(self):
        add = ("Schema::table('t', function (Blueprint $t) { $t->string('a')->nullable()->index('t_a_idx'); "
               "$t->string('b')->nullable(); $t->index(['b']); $t->timestamps(); });")
        create = "Schema::create('n', function (Blueprint $t) { $t->id(); $t->string('x'); });"
        good = (
            (add, "if (Schema::hasColumn('t', 'a')) { Schema::table('t', function (Blueprint $t) { "
                  "$t->dropIndex('t_a_idx'); $t->dropIndex(['b']); $t->dropColumn(['a', 'b']); $t->dropTimestamps(); }); }"),
            (add, "if (!Schema::hasTable('t')) { return; } Schema::table('t', fn (Blueprint $t) => $t->dropColumn('a'));"),
            (create, "Schema::dropIfExists('n');"),
            (create, ""),
            ("Schema::table('t', fn (Blueprint $t) => $t->softDeletes('archived_at'));",
             "Schema::table('t', fn (Blueprint $t) => $t->dropSoftDeletes('archived_at'));"),
            ("Schema::table('t', fn (Blueprint $t) => $t->softDeletes('archived_at'));",
             "Schema::table('t', fn (Blueprint $t) => $t->dropColumn('archived_at'));"),
            ("Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()->index('custom_idx'));",
             "Schema::table('t', fn (Blueprint $t) => $t->dropIndex('custom_idx'));"),
            ("Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()->index());",
             "Schema::table('t', fn (Blueprint $t) => $t->dropIndex('t_a_index'));"),
        )
        for up, down in good:
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up, down))), {MIG}, down)
        bad = (
            (add, "Schema::drop('t');"),
            (add, "Schema::dropIfExists('Student');"),
            (add, "Schema::table('t', fn (Blueprint $t) => $t->dropColumn('legacy'));"),
            (add, "Schema::table('Student', fn (Blueprint $t) => $t->dropColumn('a'));"),
            (add, "Schema::table('t', fn (Blueprint $t) => $t->dropIndex('other_idx'));"),
            (add, "Schema::table('t', fn (Blueprint $t) => $t->dropSoftDeletes());"),
            (add, "Schema::table('t', fn (Blueprint $t) => $t->dropColumn('a')->change());"),
            (add, "DB::table('Student')->delete();"),
            (add, "Schema::table('t', fn (Blueprint $t) => $t->renameColumn('a', 'z'));"),
            (create, "Schema::dropIfExists('n'); Schema::dropIfExists('Student');"),
            # dropSoftDeletes() drops deleted_at, not the archived_at that up() added.
            ("Schema::table('t', fn (Blueprint $t) => $t->softDeletes('archived_at'));",
             "Schema::table('t', fn (Blueprint $t) => $t->dropSoftDeletes());"),
            # The column form derives t_a_index and cannot drop custom_idx.
            ("Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()->index('custom_idx'));",
             "Schema::table('t', fn (Blueprint $t) => $t->dropIndex(['a']));"),
            # Column order matters for the derived name.
            ("Schema::table('t', function (Blueprint $t) { $t->string('a')->nullable(); $t->string('b')->nullable(); $t->index(['a', 'b']); });",
             "Schema::table('t', fn (Blueprint $t) => $t->dropIndex(['b', 'a']));"),
        )
        for up, down in bad:
            patch = new_file(MIG, migration(up, down))
            self.assertEqual(additive_migration_paths([MIG], patch), set(), down)
            self.assertEqual(self.tiers(patch), (3, 3), down)

    def test_helper_modifiers_are_evaluated(self):
        for up in (
            "Schema::table('t', fn (Blueprint $t) => $t->softDeletes('gone')->nullable(false));",
            "Schema::table('t', fn (Blueprint $t) => $t->rememberToken()->default('known'));",
            "Schema::table('t', fn (Blueprint $t) => $t->timestamps()->useCurrent());",
            "Schema::table('t', fn (Blueprint $t) => $t->index('a')->nullable());",
        ):
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set(), up)

    def test_guarded_up_cannot_be_rolled_back_by_a_drop(self):
        drop_a = "Schema::table('t', fn (Blueprint $t) => $t->dropColumn('a'));"
        cases = (
            ("if (Schema::hasTable('n')) { return; } Schema::create('n', function (Blueprint $t) { $t->id(); });",
             "Schema::dropIfExists('n');"),
            ("if (!Schema::hasColumn('t', 'a')) { Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()); }",
             drop_a),
            # The guard names other things but still controls whether a is added.
            ("if (Schema::hasColumn('t', 'legacy')) { Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()); }",
             drop_a),
            ("if (Schema::hasTable('t')) { Schema::table('t', fn (Blueprint $t) => $t->string('a')->nullable()); }",
             drop_a),
        )
        for up, down in cases:
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up, down))), set(), down)
            # Without the rollback drop the guarded up() is still additive.
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), {MIG}, up)

    def test_escaped_literals_fail_closed(self):
        up = 'Schema::table(\'t\', fn (Blueprint $t) => $t->string("\\x61")->nullable());'
        self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set())

    def test_identity_and_security_tables_stay_t3(self):
        for up in (
            "Schema::create('auth_tokens', function (Blueprint $t) { $t->id(); });",
            "Schema::table('permissions', fn (Blueprint $t) => $t->string('scope')->nullable());",
            "Schema::create('roles', function (Blueprint $t) { $t->id(); });",
            "Schema::table('User', fn (Blueprint $t) => $t->boolean('is_super_admin')->nullable());",
            "Schema::create('student_guardians', function (Blueprint $t) { $t->id(); });",
            "if (Schema::hasTable('api_clients')) { return; } Schema::create('notes', function (Blueprint $t) { $t->id(); });",
            "Schema::create('payment_credentials', function (Blueprint $t) { $t->id(); });",
            "Schema::table('invoice_secrets', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('bank_accounts', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('parent_invoice_links', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('pop_approval_events', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('Student', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('TempRfid', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('StudentSingIn', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('system_settings', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('chat_thread_members', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::create('pii_exports', function (Blueprint $t) { $t->id(); });",
            "Schema::create('legal_records', function (Blueprint $t) { $t->id(); });",
        ):
            patch = new_file(MIG, migration(up))
            self.assertEqual(additive_migration_paths([MIG], patch), set(), up)
            self.assertEqual(self.tiers(patch), (3, 3), up)

    def test_billing_tables_take_the_additive_path(self):
        # Founder 2026-10-28 A: additive-only changes on billing tables are no longer excluded.
        for up in (
            "Schema::table('Invoice', fn (Blueprint $t) => $t->string('note')->nullable());",
            "Schema::table('StudentClass', fn (Blueprint $t) => $t->string('memo')->nullable());",
            "Schema::table('payroll_runs', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('session_deduction_ledger', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('course_packages', fn (Blueprint $t) => $t->index('campus_id'));",
            "if (Schema::hasTable('Payment')) { return; } Schema::create('notes', function (Blueprint $t) { $t->id(); });",
            "Schema::create('invoice_adjustments', function (Blueprint $t) { $t->id(); $t->text('reason'); });",
        ):
            patch = new_file(MIG, migration(up))
            self.assertEqual(additive_migration_paths([MIG], patch), {MIG}, up)
            self.assertEqual(self.tiers(patch)[0], 2, up)
            with mock.patch.object(gate, "ADDITIVE_MIGRATION_AUTO_ACTIVATION", True):
                self.assertEqual(self.tiers(patch), (2, 2), up)

    def test_billing_tables_still_refuse_destructive_or_data_changes(self):
        for up in (
            "Schema::table('Invoice', fn ($t) => $t->dropColumn('note'));",
            "Schema::table('StudentClass', fn ($t) => $t->renameColumn('Memo', 'memo'));",
            "Schema::table('Payment', fn ($t) => $t->decimal('Amount', 12, 2)->change());",
            "Schema::table('Invoice', fn ($t) => $t->string('a')); DB::table('Invoice')->update(['a' => 1]);",
            "Schema::dropIfExists('invoice_adjustments');",
        ):
            patch = new_file(MIG, migration(up))
            self.assertEqual(additive_migration_paths([MIG], patch), set(), up)
            self.assertEqual(self.tiers(patch), (3, 3), up)

    def test_column_vocabulary_on_a_neutral_table_is_left_to_review(self):
        up = "Schema::table('rooms', fn (Blueprint $t) => $t->string('payment_note')->nullable());"
        self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), {MIG})

    def test_declaration_is_required(self):
        patch = new_file(MIG, ADD)
        self.assertEqual(classify_scope([MIG], patch)["machine_minimum_tier"], 3)
        self.assertEqual(classify_activation_scope([MIG], patch)["machine_minimum_tier"], 3)
        with mock.patch.object(gate, "ADDITIVE_MIGRATION_AUTO_ACTIVATION", True):
            # Activation also needs the caller's Codex confirmation, not only the declaration.
            self.assertEqual(classify_activation_scope([MIG], patch)["machine_minimum_tier"], 3)
        body = "Risk-Class: R2\nAutonomy-Tier: T2\n"
        self.assertFalse(validate_declaration(body, [MIG], patch)["valid"])
        self.assertTrue(validate_declaration(body + "Additive-Migration: yes\n", [MIG], patch)["valid"])

    def test_hidden_or_indented_declaration_does_not_count(self):
        for body in ("<!--\nAdditive-Migration: yes\n-->", "```\nAdditive-Migration: yes\n```",
                     "~~~text\nAdditive-Migration: yes\n~~~", "    Additive-Migration: yes",
                     "<!-- unterminated\nAdditive-Migration: yes"):
            self.assertFalse(declares_additive_migration(body), body)
        self.assertTrue(declares_additive_migration("<!-- note -->\nAdditive-Migration: yes\r\n"))

    def test_release_evidence_ignores_the_opt_in(self):
        files = [{"filename": MIG, "patch": new_file(MIG, ADD).split("\n", 1)[1]}]
        body = "Risk-Class: R2\nAutonomy-Tier: T2\nAdditive-Migration: yes\n"
        self.assertIsNone(gate.commit_effective_class(body, files))
        self.assertEqual(gate.commit_effective_class("Risk-Class: R3\nAutonomy-Tier: T3\n", files), 3)

    def test_lowercase_bare_placeholder_stays_t3(self):
        up = "Schema::table('rooms', fn (Blueprint $t) => $t->string(s0)->nullable());"
        self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set())

    def test_declaration_must_be_its_own_line(self):
        self.assertTrue(declares_additive_migration("x\nAdditive-Migration: yes\ny"))
        self.assertTrue(declares_additive_migration("additive-migration: YES"))
        for body in ("", "Additive-Migration: no", "Additive-Migration: yes please",
                     "<!-- Additive-Migration: yes -->", "`Additive-Migration: yes`"):
            self.assertFalse(declares_additive_migration(body), body)

    def test_bare_identifier_shadowing_a_literal_token_stays_t3(self):
        up = "Schema::table('t', fn (Blueprint $t) => $t->string(S0)->nullable());"
        self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set())

    def test_braceless_guard_return_is_t2(self):
        up = "if (!Schema::hasTable('c')) return; Schema::table('c', fn (Blueprint $t) => $t->boolean('a')->nullable());"
        self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), {MIG})

    def test_context_lines_mean_not_a_new_file(self):
        patch = new_file(MIG, ADD).replace("+<?php", " <?php", 1)
        self.assertEqual(additive_migration_paths([MIG], patch), set())

    def test_real_world_guard_and_arrow_shapes_are_t2(self):
        for up in (
            "if (Schema::hasTable('t') && !Schema::hasColumn('t', 'a')) { Schema::table('t', fn (Blueprint $t) => $t->string('a', 32)->nullable()); }",
            "if (Schema::hasTable('n')) { return; } Schema::create('n', function (Blueprint $t) { $t->id(); $t->timestamps(); });",
        ):
            patch = new_file(MIG, migration(up))
            self.assertEqual(additive_migration_paths([MIG], patch), {MIG}, up)

    def test_editing_existing_migration_stays_t3(self):
        patch = f"diff --git a/{MIG} b/{MIG}\n@@ -3,1 +3,1 @@\n-old\n+Schema::table('t', fn ($t) => $t->string('a'));\n"
        self.assertEqual(additive_migration_paths([MIG], patch), set())
        self.assertEqual(self.tiers(patch), (3, 3))

    def test_one_unsafe_migration_taints_the_whole_pr(self):
        other = MIG.replace("000000", "000001")
        patch = new_file(MIG, ADD) + new_file(other, migration("Schema::drop('t');"))
        self.assertEqual(additive_migration_paths([MIG, other], patch), set())
        self.assertEqual(self.tiers(patch, (MIG, other)), (3, 3))

    def test_missing_patch_fails_closed(self):
        self.assertEqual(additive_migration_paths([MIG], ""), set())
        self.assertEqual(self.tiers("", (MIG,))[1], 3)

    def test_other_paths_still_classified(self):
        patch = new_file(MIG, ADD) + "diff --git a/backend/app/Http/Controllers/Auth/X.php b/backend/app/Http/Controllers/Auth/X.php\n@@ -1 +1 @@\n-a\n+b\n"
        self.assertEqual(self.tiers(patch, (MIG, "backend/app/Http/Controllers/Auth/X.php")), (3, 3))


HEAD = "a" * 40
CODEX = {"login": "chatgpt-codex-connector[bot]"}


def summary(sha=HEAD, when="2026-10-14T12:47:02.745419Z"):
    return {"user": CODEX, "body": "<!-- codex-pull-request-review-summary -->\n| Review | Status | Commit |\n"
            f"| 📝 **Code Review** | ✅ **Completed** <relative-time datetime=\"{when}\">{when}</relative-time> | `{sha[:7]}` | New commits |"}


THUMB = {"user": CODEX, "content": "+1", "created_at": "2026-10-14T12:47:05Z"}


class CodexConfirmation(unittest.TestCase):
    def test_summary_plus_thumbs_up_without_review_confirms(self):
        self.assertTrue(gate.codex_confirms_head(HEAD, [summary()], [], [THUMB]))

    def test_missing_or_mismatched_evidence_does_not_confirm(self):
        other = {"login": "someone"}
        for comments, reviews, reactions in (
            ([], [], [THUMB]),
            ([summary("b" * 40)], [], [THUMB]),
            ([summary()], [{"user": CODEX, "commit_id": HEAD}], [THUMB]),
            ([summary()], [], []),
            ([summary()], [], [dict(THUMB, user=other)]),
            ([summary()], [], [dict(THUMB, content="eyes")]),
            ([summary()], [], [dict(THUMB, created_at="2026-10-14T10:00:00Z")]),
            ([summary()], [], [dict(THUMB, created_at="2026-10-14T12:46:00Z")]),
            ([dict(summary(), user=other)], [], [THUMB]),
            ([{"user": CODEX, "body": summary()["body"].split("\n", 1)[1]}], [], [THUMB]),
        ):
            self.assertFalse(gate.codex_confirms_head(HEAD, comments, reviews, reactions))
        self.assertFalse(gate.codex_confirms_head("short", [summary()], [], [THUMB]))
        self.assertFalse(gate.codex_confirms_head(HEAD, None, [], [THUMB]))

    def test_confirmation_survives_aggregation_only_when_every_commit_carries_it(self):
        base = {"number": "7", "merged_at": "x", "declared_risk": 2, "declared_tier": 2,
                "paths": [MIG], "patch": new_file(MIG, ADD), "patch_complete": True}
        both = gate.aggregate_landed_pr_effects([dict(base, additive_confirmed=True)] * 2)
        self.assertIs(both[0]["additive_confirmed"], True)
        mixed = gate.aggregate_landed_pr_effects([dict(base, additive_confirmed=True), dict(base)])
        self.assertIs(mixed[0]["additive_confirmed"], False)


if __name__ == "__main__":
    unittest.main()


BILLING_MIG = "backend/database/migrations/2026_10_28_000000_add_note_to_invoice.php"
BILLING = migration("Schema::table('Invoice', fn (Blueprint $t) => $t->string('note')->nullable());")
DECL = "Rollback: revert this PR\nRisk-Class: R2\nAutonomy-Tier: T2\nAdditive-Migration: yes\n"
CODEX = {"login": "chatgpt-codex-connector[bot]"}
THUMBS = [{"user": CODEX, "content": "+1", "created_at": "2026-10-28T02:00:00Z"}]
CLEAN = {"nodes": [], "pageInfo": {"hasNextPage": False}}


def fakes(body=DECL, threads=CLEAN, reactions=THUMBS):
    """GitHub API fakes: PR #11 squash-merged as one commit that adds BILLING_MIG; Codex 👍 on head e*40."""
    sha, head = f"{11:040x}", "e" * 40
    pr = {"number": 11, "merge_commit_sha": sha, "merged_at": "2026-10-28T01:30:00Z", "base": {"ref": "main"},
          "head": {"repo": {"full_name": "o/r"}, "sha": head}, "author_association": "OWNER", "body": body}
    summary = ("<!-- codex-pull-request-review-summary -->\n✅ **Completed** "
               f"<relative-time datetime=\"2026-10-28T01:00:00Z\">x</relative-time> | `{head[:7]}`")

    def api(path, *flags):
        if path.endswith(f"/compare/{'1' * 40}...{'2' * 40}"):
            return {"status": "ahead", "total_commits": 1, "commits": [{"sha": sha, "commit": {"message": "feat: note (#11)"}}]}
        if path.endswith(f"/commits/{sha}"):
            return {"parents": [{"sha": "9" * 40}], "files": [{"filename": BILLING_MIG, "status": "added", "sha": "b" * 40,
                                                              "patch": new_file(BILLING_MIG, BILLING).split("\n", 1)[1]}]}
        if path.endswith("/pulls/11"):
            return pr
        if "/issues/11/comments" in path:
            return [[{"user": CODEX, "body": summary}]]
        return [reactions] if "/reactions" in path else [[]]

    def graphql(query):
        if isinstance(threads, Exception) and "reviewThreads" in query:
            raise threads
        return {"data": {"repository": {"pullRequest": {"lastEditedAt": None, "editor": None, "reviewThreads": threads}}}}
    return api, graphql


def deployed_policy_before_this_change():
    """A copy of this module with the billing exclusion still in place: what range_additive loads until this deploys."""
    import importlib.util
    import re
    spec = importlib.util.spec_from_file_location("deployed_autonomy_gate", gate.__file__)
    old = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(old)
    old._ADDITIVE_EXCLUDED_TABLE_RE = re.compile(gate._ADDITIVE_EXCLUDED_TABLE_RE.pattern + "|invoice", re.IGNORECASE)
    return old


class AdditiveActivationEvidence(unittest.TestCase):
    """#4590 review: auto activation of an additive migration needs the deployed classifier and full review evidence."""

    def confirmed(self, classify=None, body=DECL, **kw):
        api, graphql = fakes(body=body, **kw)
        return gate.additive_activation_confirmed(repo="o/r", number=11, body=body, paths=[BILLING_MIG],
                                                  patch=new_file(BILLING_MIG, BILLING), api=api, graphql=graphql,
                                                  classify=classify)

    def test_target_accepts_but_deployed_policy_rejects_keeps_the_click(self):
        patch, old = new_file(BILLING_MIG, BILLING), deployed_policy_before_this_change()
        self.assertEqual(gate.additive_migration_paths([BILLING_MIG], patch), {BILLING_MIG})
        self.assertEqual(old.additive_migration_paths([BILLING_MIG], patch), set())
        self.assertTrue(self.confirmed(gate.additive_migration_paths))
        self.assertFalse(self.confirmed(old.additive_migration_paths))
        for broken in (lambda paths, patch: None, lambda paths, patch: 1 / 0, lambda paths, patch: {"x.php"}):
            self.assertFalse(self.confirmed(broken))
        # additive_confirmed False is the production-activation click.
        self.assertEqual(classify_activation_scope([BILLING_MIG], patch, False)["machine_minimum_tier"], 3)
        self.assertEqual(classify_activation_scope([BILLING_MIG], patch, True)["machine_minimum_tier"], 2)

    def test_open_or_unknown_review_threads_keep_the_click(self):
        self.assertTrue(self.confirmed())
        for label, kw in {
            "open thread": dict(threads={"nodes": [{"isResolved": False, "comments": {"totalCount": 1}}],
                                         "pageInfo": {"hasNextPage": False}}),
            "resolved without a reply": dict(threads={"nodes": [{"isResolved": True, "comments": {"totalCount": 1}}],
                                                      "pageInfo": {"hasNextPage": False}}),
            "thread evidence unavailable": dict(threads=None),
            "more than one page": dict(threads={"nodes": [], "pageInfo": {"hasNextPage": True}}),
            "graphql error": dict(threads=RuntimeError("HTTP 502")),
            "no Codex 👍 on the final head": dict(reactions=[]),
            "not declared": dict(body=DECL.replace("Additive-Migration: yes\n", "")),
        }.items():
            self.assertFalse(self.confirmed(**kw), label)

    def test_release_train_accepts_a_confirmed_additive_migration_only(self):
        def go(policy=gate, **kw):
            api, graphql = fakes(**kw)
            return policy.evaluate_founder_go_range(repo="o/r", base_sha="1" * 40, target_sha="2" * 40,
                                                    api=api, graphql=graphql)
        self.assertTrue(go()["ok"], go()["reason"])
        self.assertFalse(go(threads={"nodes": [{"isResolved": False, "comments": {"totalCount": 1}}],
                                     "pageInfo": {"hasNextPage": False}})["ok"])
        self.assertFalse(go(reactions=[])["ok"])
        self.assertFalse(go(body=DECL.replace("Additive-Migration: yes\n", ""))["ok"])
        # deploy.yml also requires the deployed copy to accept the range; until this policy is deployed it rejects.
        self.assertFalse(go(policy=deployed_policy_before_this_change())["ok"])

    def test_deploy_workflow_uses_the_deployed_classifier_and_full_review_evidence(self):
        text = (Path(gate.__file__).resolve().parents[2] / ".github/workflows/deploy.yml").read_text()
        block = text[text.index("def additive_confirmed(pr, paths, patch):"):text.index("base = os.environ[\"RUNTIME_BASE_SHA\"]")]
        self.assertIn('"trusted-policy/scripts/governance/autonomy_gate.py"', block)
        self.assertIn("classify = deployed.additive_migration_paths", block)
        self.assertIn("return additive_activation_confirmed(", block)
        self.assertIn('additive_confirmed(pr, actual_paths, actual_effect["patch"])', text)
        self.assertNotIn("codex_confirms_head(", text)
