<?php
/**
 * Fails when a test class (or a trait it uses) under backend/tests declares a method whose name already exists
 * on the framework TestCase it extends. Such a helper (`session()`, `count()` ...) shadows the framework method
 * and PHP dies with a fatal error before PHPUnit prints any per-test result, taking the whole shard with it
 * (#5201, three times). Runs in seconds, before PHPUnit.
 *
 * Usage: php scripts/ci/check-testcase-method-collisions.php [--tests-dir=DIR]
 * Needs backend/vendor/autoload.php (override with ALLTRUE_GUARD_AUTOLOAD, used by the self-test only).
 * The method list comes from Reflection on the real parent classes, so it is never hand-maintained.
 * Exit 0 = clean, 1 = collisions, 2 = could not run.
 */

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

/** @return array<string, array{file: string, parent: string, traits: list<string>, methods: list<array{string, int}>}> */
function parseFile(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $ns = '';
    $uses = [];
    $classes = [];
    $depth = 0;
    $stack = []; // [fqn, depth of its body]
    $n = count($tokens);
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $text = static fn($t) => is_array($t) ? $t[1] : $t;
    $next = static function (int $i) use ($tokens, $n, $skip): int {
        for ($i++; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $skip, true)) {
                return $i;
            }
        }
        return $n;
    };
    $nameAt = static function (int &$i) use ($tokens, $n, $next): string {
        $name = '';
        $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR];
        while ($i < $n && is_array($tokens[$i]) && in_array($tokens[$i][0], $nameTokens, true)) {
            $name .= $tokens[$i][1];
            $i = $next($i);
        }
        return $name;
    };
    $resolve = static function (string $name) use (&$ns, &$uses): string {
        if ($name === '') {
            return '';
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name, 2);
        if (isset($uses[strtolower($parts[0])])) {
            return $uses[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }
        return ($ns !== '' ? $ns . '\\' : '') . $name;
    };
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $id = is_array($t) ? $t[0] : null;
        if ($id === T_NAMESPACE) {
            $j = $next($i);
            $ns = $nameAt($j);
        } elseif ($id === T_USE && $stack === [] && $depth === 0) {
            $j = $next($i);
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            $full = ltrim($nameAt($j), '\\');
            $segments = explode('\\', $full);
            $alias = end($segments);
            if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                $j = $next($j);
                $alias = $text($tokens[$j]);
            }
            if ($full !== '') {
                $uses[strtolower($alias)] = $full;
            }
        } elseif (($id === T_CLASS || $id === T_TRAIT) && $stack === []) {
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], $skip, true)) {
                $p--;
            }
            if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $j = $next($i);
            $fqn = ($ns !== '' ? $ns . '\\' : '') . $text($tokens[$j]);
            $parent = '';
            $j = $next($j);
            while ($j < $n && $tokens[$j] !== '{') {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                    $j = $next($j);
                    $parent = $resolve($nameAt($j));
                    continue;
                }
                $j = $next($j);
            }
            $classes[$fqn] = ['file' => $file, 'parent' => $parent, 'traits' => [], 'methods' => []];
            $stack[] = [$fqn, $depth + 1];
            $i = $j - 1; // the next iteration sees the opening brace
        } elseif ($t === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($t === '}') {
            if ($stack !== [] && end($stack)[1] === $depth) {
                array_pop($stack);
            }
            $depth--;
        } elseif ($stack !== [] && $depth === end($stack)[1]) {
            $cur = end($stack)[0];
            if ($id === T_USE) {
                $j = $next($i);
                while ($j < $n) {
                    $classes[$cur]['traits'][] = $resolve($nameAt($j));
                    if ($tokens[$j] !== ',') {
                        break;
                    }
                    $j = $next($j);
                }
            } elseif ($id === T_FUNCTION) {
                $j = $next($i);
                if ($tokens[$j] === '&') {
                    $j = $next($j);
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $classes[$cur]['methods'][] = [$tokens[$j][1], $tokens[$j][2]];
                }
            }
        }
    }
    return $classes;
}

$classes = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $classes += parseFile($f->getPathname());
    }
}

$allowed = array_map('strtolower', ALLOWED);
$frameworkMethods = [];
$frameworkOf = static function (string $fqn) use (&$frameworkMethods): ?array {
    if (!array_key_exists($fqn, $frameworkMethods)) {
        $frameworkMethods[$fqn] = null;
        if (class_exists($fqn) && is_a($fqn, 'PHPUnit\Framework\TestCase', true)) {
            $m = [];
            foreach ((new ReflectionClass($fqn))->getMethods() as $method) {
                if (!$method->isPrivate()) {
                    $m[strtolower($method->getName())] = $method->getDeclaringClass()->getName() . '::' . $method->getName();
                }
            }
            $frameworkMethods[$fqn] = $m;
        }
    }
    return $frameworkMethods[$fqn];
};
$relative = static fn(string $f): string => ltrim(str_replace($root, '', $f), '/');
$ownMethods = static function (string $fqn, int $guard = 0) use (&$ownMethods, $classes): array {
    $out = [];
    foreach ($classes[$fqn]['methods'] ?? [] as $m) {
        $out[] = [$m[0], $m[1], $classes[$fqn]['file']];
    }
    foreach ($classes[$fqn]['traits'] ?? [] as $t) {
        if (isset($classes[$t]) && $guard < 20) {
            array_push($out, ...$ownMethods($t, $guard + 1));
        }
    }
    return $out;
};

$problems = [];
foreach ($classes as $fqn => $c) {
    $terminal = $c['parent'];
    $guard = 0;
    while (isset($classes[$terminal]) && $guard++ < 50) {
        $terminal = $classes[$terminal]['parent'];
    }
    $framework = $terminal !== '' ? $frameworkOf($terminal) : null;
    if ($framework === null) {
        continue; // not a PHPUnit test class (worker script, plain helper, shared trait)
    }
    foreach ($ownMethods($fqn) as [$name, $line, $file]) {
        $key = strtolower($name);
        if (isset($framework[$key]) && !in_array($key, $allowed, true)) {
            $problems[] = sprintf('%s:%d %s() collides with %s', $relative($file), $line, $name, $framework[$key]);
        }
    }
}
$problems = array_values(array_unique($problems));
sort($problems);
if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    fwrite(STDERR, "\nA test method named like a framework TestCase method kills the whole PHPUnit shard with a fatal error. Rename it (e.g. loginSession()). Allowed overrides: " . implode(', ', ALLOWED) . ".\n");
    exit(1);
}
echo 'ok: no test method collides with a framework TestCase method (' . count($classes) . " classes/traits scanned)\n";
