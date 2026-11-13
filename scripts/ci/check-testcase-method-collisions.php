<?php
/**
 * Fails when a test class (or a trait it uses) under backend/tests declares a method whose name already exists
 * on the framework TestCase it extends. Such a helper (`session()`, `count()` ...) shadows the framework method
 * and PHP dies with a fatal error before PHPUnit prints any per-test result, taking the whole shard with it
 * (#5201, three times). Runs in seconds, before PHPUnit.
 *
 * Usage: php scripts/ci/check-testcase-method-collisions.php [--tests-dir=DIR]
 * Needs backend/vendor/autoload.php after `composer install`, including nikic/php-parser (a transitive
 * dependency, e.g. of psy/psysh and phpunit/php-code-coverage), which parses the test files: a hand-written
 * token walker kept missing namespace, import and trait-adaptation edge cases. Test classes are never loaded
 * (loading one that redeclares a final method is itself the fatal); only the framework parents are reflected,
 * so the forbidden method list is never hand-maintained.
 * ALLTRUE_GUARD_AUTOLOAD / ALLTRUE_GUARD_EXTRA_AUTOLOAD override the autoloaders (self-test only).
 * Exit 0 = clean, 1 = collisions or unresolved parents, 2 = could not run.
 */

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$root = dirname(__DIR__, 2);
$testsDir = $root . '/backend/tests';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tests-dir=')) {
        $testsDir = substr($arg, strlen('--tests-dir='));
    }
}
$autoload = getenv('ALLTRUE_GUARD_AUTOLOAD') ?: $root . '/backend/vendor/autoload.php';
if (!is_file($autoload) || !is_dir($testsDir)) {
    fwrite(STDERR, "cannot run: missing $autoload or $testsDir\n");
    exit(2);
}
require $autoload;
if (($extra = getenv('ALLTRUE_GUARD_EXTRA_AUTOLOAD')) && is_file($extra)) {
    require $extra; // self-test only: stub framework classes (e.g. a Laravel-like session() trait)
}
if (!class_exists(ParserFactory::class)) {
    fwrite(STDERR, "cannot run: nikic/php-parser is not installed (run composer install in backend/); refusing to pass vacuously\n");
    exit(2);
}
// Tests\ is autoload-dev; make sure it resolves even when the autoloader was dumped without dev classes.
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = $root . '/backend/tests/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// Methods a test class legitimately overrides. Everything else on a framework TestCase is off limits.
// Each entry is overridden somewhere under backend/tests today:
const ALLOWED = [
    'setUp',               // fixtures; 196 test classes
    'tearDown',            // fixtures; 163 test classes
    'setUpBeforeClass',    // ContractSessionSchedulePropertiesTest: once-per-class fixtures
    'tearDownAfterClass',  // same class, matching teardown
    'createApplication',   // Tests\CreatesApplication: the documented Laravel override point
];

$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();

/** Class and trait declarations by lower-cased FQN (PHP class names are case-insensitive). */
$classes = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $file = $f->getPathname();
    try {
        $ast = $parser->parse((string) file_get_contents($file));
        $ast = (new NodeTraverser(new NameResolver()))->traverse($ast ?? []);
    } catch (PhpParser\Error $e) {
        fwrite(STDERR, "cannot parse $file: {$e->getMessage()}\n");
        exit(2);
    }
    foreach ($finder->find($ast, static fn(Node $n) => ($n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_) && $n->namespacedName !== null) as $decl) {
        $entry = ['name' => $decl->namespacedName->toString(), 'file' => $file, 'parent' => '', 'traits' => [], 'methods' => []];
        if ($decl instanceof Stmt\Class_ && $decl->extends !== null) {
            $entry['parent'] = $decl->extends->toString();
        }
        foreach ($decl->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                $entry['methods'][] = [$stmt->name->toString(), $stmt->getStartLine()];
            } elseif ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $entry['traits'][] = $trait->toString();
                }
                foreach ($stmt->adaptations as $adaptation) {
                    // `helper as protected count`: the alias is a method name on this class; insteadof adds none.
                    if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias && $adaptation->newName !== null) {
                        $entry['methods'][] = [$adaptation->newName->toString(), $adaptation->getStartLine()];
                    }
                }
            }
        }
        $classes[strtolower($entry['name'])] ??= $entry;
    }
}

$allowed = array_map('strtolower', ALLOWED);
$frameworkMethods = [];
$frameworkOf = static function (string $fqn) use (&$frameworkMethods): ?array {
    $key = strtolower($fqn);
    if (!array_key_exists($key, $frameworkMethods)) {
        $frameworkMethods[$key] = null;
        if (class_exists($fqn) && is_a($fqn, 'PHPUnit\Framework\TestCase', true)) {
            $m = [];
            foreach ((new ReflectionClass($fqn))->getMethods() as $method) {
                if (!$method->isPrivate()) {
                    $m[strtolower($method->getName())] = $method->getDeclaringClass()->getName() . '::' . $method->getName();
                }
            }
            $frameworkMethods[$key] = $m;
        }
    }
    return $frameworkMethods[$key];
};
$relative = static fn(string $f): string => ltrim(str_replace($root, '', $f), '/');
$ownMethods = static function (string $key, int $guard = 0) use (&$ownMethods, $classes): array {
    $out = [];
    foreach ($classes[$key]['methods'] ?? [] as [$name, $line]) {
        $out[] = [$name, $line, $classes[$key]['file']];
    }
    foreach ($classes[$key]['traits'] ?? [] as $trait) {
        if (isset($classes[strtolower($trait)]) && $guard < 20) {
            array_push($out, ...$ownMethods(strtolower($trait), $guard + 1));
        }
    }
    return $out;
};

$problems = [];
$unresolved = [];
$checked = 0;
foreach ($classes as $key => $c) {
    $terminal = $c['parent'];
    $guard = 0;
    while (isset($classes[strtolower($terminal)]) && $guard++ < 50) {
        $terminal = $classes[strtolower($terminal)]['parent'];
    }
    $framework = $terminal !== '' ? $frameworkOf($terminal) : null;
    if ($framework === null) {
        // A parent that cannot be loaded would be skipped silently (fail open); a loadable non-TestCase parent
        // (exception, Mockery class) or no parent at all (worker script, plain helper, shared trait) is fine.
        if ($terminal !== '' && !class_exists($terminal)) {
            $unresolved[] = sprintf('%s:%s extends unresolvable %s', $relative($c['file']), $c['name'], $terminal);
        }
        continue;
    }
    $checked++;
    foreach ($ownMethods($key) as [$name, $line, $file]) {
        $lower = strtolower($name);
        if (isset($framework[$lower]) && !in_array($lower, $allowed, true)) {
            $problems[] = sprintf('%s:%d %s() collides with %s', $relative($file), $line, $name, $framework[$lower]);
        }
    }
}
$problems = array_values(array_unique($problems));
sort($problems);
sort($unresolved);
if ($unresolved || $checked === 0) {
    fwrite(STDERR, implode("\n", $unresolved) . ($unresolved ? "\n" : ''));
    fwrite(STDERR, $checked === 0
        ? "no PHPUnit test class was checked under $testsDir: the guard would pass vacuously\n"
        : "parent classes above could not be loaded, so their test methods were not checked\n");
    if ($problems) {
        fwrite(STDERR, implode("\n", $problems) . "\n");
    }
    exit(1);
}
if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    fwrite(STDERR, "\nA test method named like a framework TestCase method kills the whole PHPUnit shard with a fatal error. Rename it (e.g. loginSession()). Allowed overrides: " . implode(', ', ALLOWED) . ".\n");
    exit(1);
}
echo 'ok: no test method collides with a framework TestCase method (' . $checked . ' test classes checked, ' . count($classes) . " classes/traits scanned)\n";
