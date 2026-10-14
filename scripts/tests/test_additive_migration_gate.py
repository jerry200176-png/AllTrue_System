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
    lines = body.strip("\n").splitlines()
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
