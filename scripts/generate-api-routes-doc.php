<?php
// #992: regenerate docs/REF_API_ROUTES.md from the live route table.
// Usage (from backend/): php artisan route:list --path=api --json | php ../scripts/generate-api-routes-doc.php > ../docs/REF_API_ROUTES.md
// Low-effort pass only: method/URI/action/middleware, no request/response
// schemas (golden contracts are a separate, higher-effort follow-up).

$routes = json_decode(stream_get_contents(STDIN), true);
if (!is_array($routes)) {
    fwrite(STDERR, "Expected JSON array on stdin (php artisan route:list --path=api --json)\n");
    exit(1);
}

function domainOf(string $uri): string {
    $parts = explode('/', $uri);
    if (count($parts) >= 3 && $parts[1] === 'v1') {
        return $parts[2] ?? '(root)';
    }
    return $parts[1] ?? '(root)';
}

$groups = [];
foreach ($routes as $r) {
    $groups[domainOf($r['uri'])][] = $r;
}

$priority = ['student-classes', 'class-sessions', 'schedules', 'auth', 'swipe-rfid', 'login', 'pay-report', 'payment-reports'];
$order = array_values(array_filter($priority, fn ($k) => isset($groups[$k])));
$other = array_diff(array_keys($groups), $order);
sort($other);
$order = array_merge($order, $other);

$out = [];
$out[] = '# API 路由參考（自動產生）';
$out[] = '';
$out[] = '> 由 `php artisan route:list --path=api --json` 自動產生，共 ' . count($routes) . ' 條路由。';
$out[] = '> 只列出 method / URI / controller action / middleware，不含 request/response schema（golden contracts 屬後續工作，見 #992）。';
$out[] = '> 重新產生：`php artisan route:list --path=api --json | php scripts/generate-api-routes-doc.php > docs/REF_API_ROUTES.md`（於 `backend/` 目錄執行）。';
$out[] = '';
$out[] = '## 目錄';
$out[] = '';
foreach ($order as $k) {
    $out[] = '- [' . $k . '](#' . str_replace('_', '-', $k) . ')（' . count($groups[$k]) . ' 條）';
}
$out[] = '';

foreach ($order as $k) {
    $out[] = '## ' . $k;
    $out[] = '';
    $out[] = '| Method | URI | Action | Middleware |';
    $out[] = '|---|---|---|---|';
    $rows = $groups[$k];
    usort($rows, fn ($a, $b) => $a['uri'] <=> $b['uri']);
    foreach ($rows as $r) {
        $action = $r['action'] ?? 'Closure';
        $action = str_replace('App\\Http\\Controllers\\', '', $action);
        $mw = implode(', ', array_filter($r['middleware'] ?? [], fn ($m) => $m !== 'api'));
        $out[] = "| {$r['method']} | \`{$r['uri']}\` | {$action} | {$mw} |";
    }
    $out[] = '';
}

echo implode("\n", $out);
