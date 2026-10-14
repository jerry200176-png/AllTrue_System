"""Founder 2026-10-14 (三)④: additive-only migrations deploy as T2; destructive ones stay T3."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts.governance.autonomy_gate import (  # noqa: E402
    additive_migration_paths, classify_activation_scope, classify_scope,
)

MIG = "backend/database/migrations/2026_10_14_000000_x.php"


def new_file(path, body):
    lines = body.strip("\n").split("\n")
    return f"diff --git a/{path} b/{path}\n@@ -0,0 +1,{len(lines)} @@\n" + "".join(f"+{l}\n" for l in lines)


def migration(up, down="Schema::table('t', fn (Blueprint $t) => $t->dropColumn('c'));"):
    return f"""<?php
use Illuminate\\Database\\Migrations\\Migration;
return new class extends Migration {{
    public function up(): void {{ {up} }}
    public function down(): void {{ {down} }}
}};"""


ADD = migration("Schema::table('invoice', function (Blueprint $t) { $t->string('zero_reason')->nullable(); });")
CREATE = migration("Schema::create('notes', function (Blueprint $t) { $t->id(); $t->text('body'); $t->index('id'); });",
                   "Schema::dropIfExists('notes');")


class AdditiveMigrationGate(unittest.TestCase):
    def tiers(self, patch, paths=(MIG,)):
        return classify_scope(list(paths), patch)["machine_minimum_tier"], \
            classify_activation_scope(list(paths), patch)["machine_minimum_tier"]

    def test_add_column_and_create_table_are_t2(self):
        for body in (ADD, CREATE):
            patch = new_file(MIG, body)
            self.assertEqual(additive_migration_paths([MIG], patch), {MIG})
            self.assertEqual(self.tiers(patch), (2, 2))

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
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->nullable(false));",
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->default(null));",
            "Schema::table('t', fn (Blueprint $t) => $t->string('c')->nullable()->nullable(false));",
        )
        for up in bad:
            self.assertEqual(additive_migration_paths([MIG], new_file(MIG, migration(up))), set(), up)
        good = (
            "Schema::table('t', function (Blueprint $t) { $t->string('c')->nullable(); $t->integer('n')->default(0); $t->index('c'); $t->timestamps(); });",
            "Schema::create('n', function (Blueprint $t) { $t->id(); $t->string('code'); });",
            "Schema::table('t', fn (Blueprint $t) => $t->boolean('b')->nullable(true));",
            "Schema::table('t', fn (Blueprint $t) => $t->boolean('b')->default(false));",
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

    def test_braceless_guard_return_is_t2(self):
        up = "if (!Schema::hasTable('c')) return; Schema::table('c', fn (Blueprint $t) => $t->boolean('a')->default(true));"
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
