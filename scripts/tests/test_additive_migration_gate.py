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
            # Merge is T2 now; activation stays Founder-gated until deploy.yml can roll code
            # back after an additive migration (the follow-up PR flips the flag).
            self.assertEqual(self.tiers(patch), (2, 3))
            with mock.patch.object(gate, "ADDITIVE_MIGRATION_AUTO_ACTIVATION", True):
                self.assertEqual(self.tiers(patch), (2, 2))

    def test_activation_flag_is_off_until_deploy_rollback_lands(self):
        self.assertIs(gate.ADDITIVE_MIGRATION_AUTO_ACTIVATION, False)

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

    def test_identity_and_billing_tables_stay_t3(self):
        for up in (
            "Schema::create('auth_tokens', function (Blueprint $t) { $t->id(); });",
            "Schema::table('permissions', fn (Blueprint $t) => $t->string('scope')->nullable());",
            "Schema::table('Invoice', fn (Blueprint $t) => $t->string('note')->nullable());",
            "Schema::table('StudentClass', fn (Blueprint $t) => $t->string('memo')->nullable());",
            "Schema::create('roles', function (Blueprint $t) { $t->id(); });",
            "Schema::table('User', fn (Blueprint $t) => $t->boolean('is_super_admin')->nullable());",
            "Schema::create('student_guardians', function (Blueprint $t) { $t->id(); });",
            "Schema::table('payroll_runs', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('session_deduction_ledger', fn (Blueprint $t) => $t->string('x')->nullable());",
            "if (Schema::hasTable('Payment')) { return; } Schema::create('notes', function (Blueprint $t) { $t->id(); });",
            "Schema::table('pop_approval_events', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('Student', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('TempRfid', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('StudentSingIn', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('system_settings', fn (Blueprint $t) => $t->string('x')->nullable());",
            "Schema::table('chat_thread_members', fn (Blueprint $t) => $t->string('x')->nullable());",
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


if __name__ == "__main__":
    unittest.main()
