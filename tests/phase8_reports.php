<?php
declare(strict_types=1);

/**
 * Phase 8 tests: reports and exports.
 *   php tests/phase8_reports.php [base-url]
 * Creates temporary data (phase8test.*, "P8TEST" hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Helpers\ReportFormat;
use App\Models\Hub;
use App\Models\Picker;
use App\Models\Price;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\Exports\CsvExporter;
use App\Services\Exports\XlsxExporter;
use App\Services\PickerService;
use App\Services\ReportService;
use App\Services\Reports\CollectionsReport;
use App\Services\Reports\PickerPerformanceReport;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase8Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P8TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase8test.%' OR phone_e164 LIKE '+256700055%'");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P8TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc)) OR (entity_type = 'user' AND entity_id IN ($usr))");
    $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection' AND source_id IN ($col)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase8test%' OR identifier LIKE '%700055%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec("DELETE FROM sms_outbox WHERE user_id IS NULL");
};
$cleanup();

/** Read a stored (uncompressed) zip: name => bytes, verifying every CRC. Returns null if the structure is wrong. */
$readZip = function (string $zip): ?array {
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false) {
        return null;
    }
    $e = unpack('vdisk/vcd/ventries/vtotal/Vsize/Voffset', substr($zip, $eocd + 4, 16));
    $out = [];
    $p = $e['offset'];
    for ($i = 0; $i < $e['entries']; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") {
            return null;
        }
        $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($zip, $p + 4, 42));
        $name = substr($zip, $p + 46, $h['nlen']);
        $lh = unpack('vnlen/velen', substr($zip, $h['off'] + 26, 4));
        $data = substr($zip, $h['off'] + 30 + $lh['nlen'] + $lh['elen'], $h['csize']);
        if ($h['method'] !== 0 || strlen($data) !== $h['size'] || crc32($data) !== $h['crc']) {
            return null;
        }
        $out[$name] = $data;
        $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }
    return $out;
};

// ---------------------------------------------------------------- pure helpers
check('Formula-looking text is neutralised in CSV (= + - @ and tab)', ReportFormat::safeText('=SUM(A1)') === "'=SUM(A1)" && ReportFormat::safeText('+1') === "'+1"
    && ReportFormat::safeText('-2') === "'-2" && ReportFormat::safeText('@x') === "'@x" && ReportFormat::safeText("\tx") === "'\tx" && ReportFormat::safeText('Plain') === 'Plain');
check('CSV values are plain numbers; display values are grouped', ReportFormat::csv(1234567, 'ugx') === '1234567' && ReportFormat::csv('12.5', 'kg') === '12.50' && ReportFormat::display(1234567, 'int') === '1,234,567');

$synthetic = [
    'title' => 'Synthetic', 'summary' => [['label' => '=evil()', 'value' => 5, 'type' => 'int']], 'notes' => [],
    'tables' => [['title' => 'T', 'columns' => [['key' => 'a', 'label' => 'Name', 'type' => 'text'], ['key' => 'b', 'label' => 'Qty', 'type' => 'int']],
        'rows' => [['a' => '=HYPERLINK("http://x")', 'b' => 3], ['a' => 'Has, comma and "quotes"', 'b' => 4], ['a' => "Line\nbreak", 'b' => 5]], 'totals' => ['b' => 12]]],
];
$meta = ['period' => '01 Jan 2026 to 31 Jan 2026', 'filters' => 'Hub: All', 'generated' => 'now'];
$csv = CsvExporter::build($synthetic, $meta);
check('CSV starts with a UTF-8 BOM and uses CRLF', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "\r\n"));
check('CSV: formula cell escaped, commas and quotes quoted', str_contains($csv, "\"'=HYPERLINK(") && str_contains($csv, '"Has, comma and ""quotes"""') && str_contains($csv, "'=evil()"));
$fh = fopen('php://memory', 'w+'); fwrite($fh, substr($csv, 3)); rewind($fh);
$rowsBack = []; while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $rowsBack[] = $r; }
$flat = json_encode($rowsBack);
check('CSV round-trips through a parser (embedded newline kept, total row present)', str_contains($flat, 'Line\nbreak') && str_contains($flat, '"Total"') && str_contains($flat, '"12"'));

$zip = $readZip(XlsxExporter::build($synthetic, $meta));
check('XLSX is a valid zip with correct CRCs and required parts', $zip !== null && isset($zip['[Content_Types].xml'], $zip['xl/workbook.xml'], $zip['xl/styles.xml'], $zip['_rels/.rels']));
$xmlOk = $zip !== null;
foreach ($zip ?? [] as $n => $d) {
    if (str_ends_with($n, '.xml') || str_ends_with($n, '.rels')) {
        libxml_use_internal_errors(true);
        $xmlOk = $xmlOk && simplexml_load_string($d) !== false;
    }
}
check('Every XML part of the workbook is well-formed', $xmlOk);
check('XLSX: numbers are numeric cells and text is inline, formula text is not a formula', $zip !== null && str_contains($zip['xl/worksheets/sheet2.xml'] ?? '', '<v>3</v>') && !str_contains($zip['xl/worksheets/sheet2.xml'] ?? '', '<f>'));

$cut = ReportService::limitRows(['tables' => [['rows' => range(1, 120000)], ['rows' => range(1, 10)]], 'notes' => []], ReportService::EXPORT_MAX_ROWS, 'this file');
check('Export row cap: 120,000 rows cut to 50,000, small tables untouched, a note says so', count($cut['tables'][0]['rows']) === 50000 && count($cut['tables'][1]['rows']) === 10 && count($cut['notes']) === 1);

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P8 $k", 'email' => "phase8test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P8 hmB', 'email' => 'phase8test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P8 hoB', 'email' => 'phase8test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n) => Hub::create(['name' => $n, 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P8TEST Hub A'); $hubB = $mkHub('P8TEST Hub B');
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
foreach ([$hubA, $hubB] as $h) {
    Price::create($catId, $h, 1000, 'P8TEST price', $ids['sa']);
}
$U = function (string $k) use (&$ids): array { return User::findById($ids[$k]); };
$saUser = $U('sa');
$mkPicker = function (string $key, string $name, string $phone, int $hub) use ($saUser, $nbId, $PW, &$ids): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'national_id' => 'NIN' . substr($phone, -6) . 'ZZ', 'consent' => '1'], $saUser);
    $p = Picker::find((int) $r['id']);
    User::updatePassword((int) $p['user_id'], $PW);
    $ids[$key] = (int) $p['user_id'];
    return (int) $r['id'];
};
$pkA = $mkPicker('pkA', 'P8 Picker A', '0700055101', $hubA);
$pkB = $mkPicker('pkB', 'P8 Picker B', '0700055102', $hubB);
$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $hub, int $picker, string $gross, string $rec, string $appr) use ($U, $catId, $tok): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => ''], $U($rec));
    return CollectionService::approve((int) $r['id'], $U($appr))['error'] === null ? (int) $r['id'] : 0;
};
$cA1 = $mkCol($hubA, $pkA, '20', 'hoA', 'hmA');
$cA2 = $mkCol($hubA, $pkA, '10', 'hoA', 'hmA');
$cB1 = $mkCol($hubB, $pkB, '15', 'hoB', 'hmB');
$cB2 = $mkCol($hubB, $pkB, '10', 'hoB', 'hmB');
CollectionService::reverse($cB2, 'Picker delivered the wrong material', $U('pm'));
check('Setup: four collections approved, one then reversed', $cA1 > 0 && $cA2 > 0 && $cB1 > 0 && $cB2 > 0);

$sa = $U('sa'); $pm = $U('pm'); $hmA = $U('hmA'); $hoA = $U('hoA'); $hmB = $U('hmB');
$sql1 = fn(string $q) => $pdo->query($q)->fetch(PDO::FETCH_NUM);
$near = fn($a, $b) => abs((float) $a - (float) $b) < 0.005;
$byLabel = fn(array $t, string $label) => (array_values(array_filter($t['rows'], fn($r) => $r['label'] === $label))[0] ?? null);

// ---------------------------------------------------------------- numbers match the database
$run = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA], $sa);
$res = $run['result'];
[$n, $kg, $val] = $sql1("SELECT COUNT(*), SUM(net_weight_kg), SUM(total_value_ugx) FROM collection_transactions WHERE hub_id = $hubA AND status = 'approved'");
check('Collections (hub A): approved count, kg and value equal direct SQL (2, 30 kg, UGX 30,000)', (int) $res['summary'][0]['value'] === (int) $n && $near($res['summary'][1]['value'], $kg)
    && (int) $res['summary'][2]['value'] === (int) $val && (int) $n === 2 && $near($kg, 30) && (int) $val === 30000);
$hubRow = $byLabel($res['tables'][1], 'P8TEST Hub A');
check('By-hub table has exactly hub A with the same numbers; period table total agrees', count($res['tables'][1]['rows']) === 1 && $hubRow && (int) $hubRow['approved'] === 2 && $near($hubRow['kg'], 30)
    && $near($res['tables'][0]['totals']['kg'], 30) && $near($res['tables'][2]['totals']['kg'], 30));

$all = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubB], $sa)['result'];
check('Hub B: one approved (15 kg) and one reversed shown separately; reversed kg not counted', (int) $all['summary'][0]['value'] === 1 && $near($all['summary'][1]['value'], 15)
    && (int) $all['summary'][4]['value'] === 1);

$wide = ReportService::run(CollectionsReport::class, ['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d', strtotime('+1 day'))], $sa)['result'];
[$n2, $kg2] = $sql1("SELECT COUNT(*), SUM(net_weight_kg) FROM collection_transactions WHERE status = 'approved' AND recorded_at >= UTC_TIMESTAMP() - INTERVAL 61 DAY");
check('System-wide numbers equal direct SQL for the same period', (int) $wide['summary'][0]['value'] === (int) $n2 && $near($wide['summary'][1]['value'], $kg2 ?? 0), "report {$wide['summary'][0]['value']} vs sql $n2");

foreach (['week', 'month'] as $g) {
    $x = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA, 'group' => $g], $sa)['result'];
    check("Grouping by $g gives the same totals", (int) $x['tables'][0]['totals']['approved'] === 2 && $near($x['tables'][0]['totals']['kg'], 30));
}
$nb = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA, 'neighbourhood_id' => (string) $nbId], $sa)['result'];
check('Neighbourhood filter keeps the matching rows', (int) $nb['summary'][0]['value'] === 2);

// ---------------------------------------------------------------- dates, time zone, odd input
$pdo->exec("UPDATE collection_transactions SET recorded_at = '2026-03-10 21:30:00' WHERE id = $cA2");   // 00:30 on 11 March in East Africa
$on11 = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA, 'from' => '2026-03-11', 'to' => '2026-03-11'], $sa)['result'];
$on10 = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA, 'from' => '2026-03-10', 'to' => '2026-03-10'], $sa)['result'];
check('East Africa Time day boundaries: 21:30 UTC counts on the next local day', (int) $on11['summary'][0]['value'] === 1 && (int) $on10['summary'][0]['value'] === 0);
$pdo->exec("UPDATE collection_transactions SET recorded_at = UTC_TIMESTAMP() WHERE id = $cA2");
$odd = ReportService::run(CollectionsReport::class, ['from' => 'banana', 'to' => '2026-02-30', 'hub_id' => 'x', 'group' => 'decade', 'neighbourhood_id' => '-4'], $sa);
check('Invalid filter input falls back to safe defaults', $odd['filters']['group'] === 'day' && $odd['filters']['hub_id'] === null && $odd['filters']['from'] <= $odd['filters']['to']);
$swap = ReportService::filters(['from' => '2026-05-10', 'to' => '2026-05-01'], CollectionsReport::class, $sa);
check('A reversed date range is swapped, not rejected', $swap['from'] === '2026-05-01' && $swap['to'] === '2026-05-10');
$huge = ReportService::filters(['from' => '1900-01-01', 'to' => '2026-01-01'], CollectionsReport::class, $sa);
check('Absurdly long ranges are limited', (new DateTimeImmutable($huge['from']))->diff(new DateTimeImmutable($huge['to']))->days <= 1826);
$empty = ReportService::run(CollectionsReport::class, ['from' => '2001-01-01', 'to' => '2001-01-02'], $sa)['result'];
check('A period with no data gives zeros and empty tables (no error)', (int) $empty['summary'][0]['value'] === 0 && ReportService::rowCount($empty) === 0);

// ---------------------------------------------------------------- who may open what
$keysOf = fn(array $u) => array_keys(ReportService::allowedFor($u));
check('Super Administrator and Project Manager see all 10 reports', count($keysOf($sa)) === 10 && count($keysOf($pm)) === 10);
check('Hub Manager sees collections, pickers, hubs, inventory and geographic only', $keysOf($hmA) === ['collections', 'pickers', 'hubs', 'inventory', 'geographic']);
check('Hub Operator sees no reports', $keysOf($hoA) === []);

$hm = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubB], $hmA);
check('Hub Manager A asking for hub B is quietly limited to hub A', $hm['filters']['hub_id'] === null && count($hm['result']['tables'][1]['rows']) === 1 && $hm['result']['tables'][1]['rows'][0]['label'] === 'P8TEST Hub A');
$hmAll = ReportService::run(CollectionsReport::class, ['from' => date('Y-m-d', strtotime('-60 days'))], $hmA)['result'];
check('Hub Manager totals cover only their hub (2 collections, 30 kg), not the whole system', (int) $hmAll['summary'][0]['value'] === 2 && $near($hmAll['summary'][1]['value'], 30));
$pk = json_encode(ReportService::run(PickerPerformanceReport::class, [], $hmA)['result']);
check('Hub Manager picker report lists their picker and not the other hub\'s', str_contains($pk, 'P8 Picker A') && !str_contains($pk, 'P8 Picker B'));
$pkAll = json_encode(ReportService::run(PickerPerformanceReport::class, [], $sa)['result']);
check('Super Administrator picker report lists both', str_contains($pkAll, 'P8 Picker A') && str_contains($pkAll, 'P8 Picker B'));

// ---------------------------------------------------------------- HTTP
$cl = [];
foreach (['sa', 'pm', 'hma', 'hoa', 'hmb'] as $k) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase8test.$k@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login');
}
$cl['pkA'] = newClient(); login($cl['pkA'], '0700055101', $PW);
$g = newClient();
foreach (['/reports', '/reports/collections', '/reports/collections/export/csv'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
check('Hub Operator is forbidden (403) from /reports and an export', req($cl['hoa'], 'GET', '/reports')['status'] === 403 && req($cl['hoa'], 'GET', '/reports/collections/export/csv')['status'] === 403);
check('Waste picker is forbidden (403)', req($cl['pkA'], 'GET', '/reports')['status'] === 403 && req($cl['pkA'], 'GET', '/reports/collections')['status'] === 403);

$idx = req($cl['sa'], 'GET', '/reports');
check('Index shows all 10 reports to the Super Administrator', $idx['status'] === 200 && substr_count($idx['body'], 'href="' . $basePath . '/reports/') >= 10);
$idxHm = req($cl['hma'], 'GET', '/reports');
check('Hub Manager index lists 5 reports and not Financial', $idxHm['status'] === 200 && !str_contains($idxHm['body'], '/reports/financial') && str_contains($idxHm['body'], '/reports/collections'));
foreach (['financial', 'payments', 'impact', 'recyclers'] as $k) {
    check("Hub Manager gets 404 for the $k report and its export", req($cl['hma'], 'GET', "/reports/$k")['status'] === 404 && req($cl['hma'], 'GET', "/reports/$k/export/csv")['status'] === 404);
}
check('Unknown report and unknown format give 404', req($cl['sa'], 'GET', '/reports/nothing')['status'] === 404 && req($cl['sa'], 'GET', '/reports/collections/export/exe')['status'] === 404);

foreach (['collections', 'pickers', 'hubs', 'inventory', 'recyclers', 'financial', 'payments', 'impact', 'geographic', 'programme'] as $k) {
    $r = req($cl['sa'], 'GET', "/reports/$k");
    check("Screen /reports/$k renders for the Super Administrator", $r['status'] === 200 && str_contains($r['body'], 'Generated') || ($r['status'] === 200 && str_contains($r['body'], 'generated')), 'status ' . $r['status']);
}
$scr = req($cl['sa'], 'GET', '/reports/collections?hub_id=' . $hubA);
check('Screen shows hub A, the totals and export buttons', str_contains($scr['body'], 'P8TEST Hub A') && str_contains($scr['body'], 'export/csv') && str_contains($scr['body'], 'export/xlsx') && str_contains($scr['body'], 'export/pdf'));
check('Chart is lazy: Chart.js is not loaded on the page until asked', str_contains($scr['body'], 'data-chart-toggle') && !preg_match('#<script[^>]+chart\.umd#', $scr['body']));
$bad = req($cl['sa'], 'GET', '/reports/collections?from=%3Cscript%3E&to=zz&hub_id=999999&group=%27');
check('Hostile query strings do not break the page or echo back unescaped', $bad['status'] === 200 && !str_contains($bad['body'], '<script>alert'));
$emp = req($cl['sa'], 'GET', '/reports/collections?from=2001-01-01&to=2001-01-02');
check('Empty period renders a friendly message', $emp['status'] === 200 && str_contains($emp['body'], 'No data for this selection'));

// ---------------------------------------------------------------- exports over HTTP
$auditBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported'")->fetchColumn();
$q = '?hub_id=' . $hubA;
$c = req($cl['sa'], 'GET', '/reports/collections/export/csv' . $q);
check('CSV download: 200, text/csv, attachment filename, no-store', $c['status'] === 200 && stripos($c['headers'], 'Content-Type: text/csv') !== false
    && preg_match('/Content-Disposition: attachment; filename="plastilink-collections-[\d-]+_to_[\d-]+\.csv"/i', $c['headers']) === 1 && stripos($c['headers'], 'no-store') !== false);
check('CSV body carries the report, hub A and a Total row', str_starts_with($c['body'], "\xEF\xBB\xBF") && str_contains($c['body'], 'Collections report') && str_contains($c['body'], 'P8TEST Hub A') && str_contains($c['body'], 'Total'));
check('CSV contains hub A numbers only (no hub B)', !str_contains($c['body'], 'P8TEST Hub B') && str_contains($c['body'], '30.00') && str_contains($c['body'], '30000'));

$x = req($cl['sa'], 'GET', '/reports/collections/export/xlsx' . $q);
$zipHttp = $readZip($x['body']);
check('XLSX download: 200, spreadsheet content type, valid zip', $x['status'] === 200 && stripos($x['headers'], 'spreadsheetml.sheet') !== false && $zipHttp !== null);
$sheets = array_filter(array_keys($zipHttp ?? []), fn($n) => str_starts_with($n, 'xl/worksheets/sheet'));
check('XLSX has a Summary sheet and one per table (4) containing hub A', count($sheets) === 4 && str_contains(implode('', $zipHttp ?? []), 'P8TEST Hub A'));

$p = req($cl['sa'], 'GET', '/reports/collections/export/pdf' . $q);
$b = $p['body'];
preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $b, $sm);
check('PDF download: 200, application/pdf, header and %%EOF trailer', $p['status'] === 200 && stripos($p['headers'], 'application/pdf') !== false && str_starts_with($b, '%PDF-') && str_ends_with(rtrim($b), '%%EOF'));
check('PDF startxref points at the xref table and every object offset is right', isset($sm[1]) && substr($b, (int) $sm[1], 4) === 'xref' && (function () use ($b, $sm) {
    preg_match('/xref\s+0\s+(\d+)\s+(.*?)trailer/s', substr($b, (int) $sm[1]), $m);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $m[2] ?? ''))));
    foreach ($lines as $i => $ln) {
        if ($i === 0) { continue; }
        $off = (int) substr($ln, 0, 10);
        if (!preg_match('/^' . $i . ' 0 obj/', substr($b, $off, 20))) { return false; }
    }
    return count($lines) === (int) $m[1];
})());
check('PDF text includes the title and hub name', str_contains($b, 'Collections report') && str_contains($b, 'P8TEST Hub A'));

$auditAfter = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported'")->fetchColumn();
$last = $pdo->query("SELECT user_id, details FROM audit_logs WHERE action = 'report.exported' ORDER BY id DESC LIMIT 1")->fetch();
$d = json_decode((string) $last['details'], true) ?: [];
check('Each export is audited with who, report, format and row count', $auditAfter - $auditBefore === 3 && (int) $last['user_id'] === $ids['sa'] && ($d['report'] ?? '') === 'collections' && ($d['format'] ?? '') === 'pdf' && ($d['rows'] ?? -1) > 0);

$hx = req($cl['hma'], 'GET', '/reports/collections/export/csv?hub_id=' . $hubB);
check('Hub Manager export is scoped too: hub B asked for, only hub A delivered', $hx['status'] === 200 && str_contains($hx['body'], 'P8TEST Hub A') && !str_contains($hx['body'], 'P8TEST Hub B'));
$ho = req($cl['pm'], 'GET', '/reports/financial/export/csv');
check('Project Manager can export a finance report', $ho['status'] === 200 && str_contains($ho['body'], 'inancial'));

// ---------------------------------------------------------------- privacy
$leak = false;
foreach (['sa', 'pm'] as $who) {
    foreach (['collections', 'pickers', 'hubs', 'inventory', 'recyclers', 'financial', 'payments', 'impact', 'geographic', 'programme'] as $k) {
        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $body = req($cl[$who], 'GET', "/reports/$k/export/$fmt?from=" . date('Y-m-d', strtotime('-400 days')))['body'];
            $hay = $fmt === 'xlsx' ? implode('', $readZip($body) ?? [$body]) : $body;
            if (preg_match('/NIN\d{6}ZZ|0700055\d{3}|\+256700055\d{3}/', $hay)) {
                $leak = true;
                echo "  leak in $who $k.$fmt\n";
            }
        }
    }
}
check('No export (30 combinations x 2 roles) contains a national ID or phone number', !$leak);

// ---------------------------------------------------------------- performance with a few thousand rows
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO collection_transactions (reference, submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, status, gross_weight_kg, tare_weight_kg, net_weight_kg,
    price_per_kg_ugx, total_value_ugx, recorded_by, recorded_at, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', 10.00, 0.00, 10.00, 1000, 10000, ?, ?, ?, ?)");
for ($i = 0; $i < 4000; $i++) {
    $at = gmdate('Y-m-d H:i:s', time() - ($i % 28) * 86400 - ($i % 24) * 3600);
    $ins->execute(['P8T-' . $i . '-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16)), $pkA, $hubA, $nbId, $catId, $ids['hoA'], $at, $ids['hmA'], $at]);
}
$pdo->commit();
$t0 = microtime(true);
$big = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hubA], $sa)['result'];
$ms = (microtime(true) - $t0) * 1000;
check('4,000 more rows: totals still exact (4,002 approved, 40,030 kg)', (int) $big['summary'][0]['value'] === 4002 && $near($big['summary'][1]['value'], 40030));
check('Report with 4,000 rows runs fast (< 1.5 s)', $ms < 1500, sprintf('%.0f ms', $ms));
$t0 = microtime(true);
$bigPick = ReportService::run(PickerPerformanceReport::class, ['hub_id' => (string) $hubA], $sa)['result'];
$bigCsv = CsvExporter::build($bigPick, $meta);
$bigX = XlsxExporter::build($big, $meta);
$ms2 = (microtime(true) - $t0) * 1000;
check('Building a picker report CSV and a collections XLSX from 4,000 rows takes < 3 s', $ms2 < 3000 && $readZip($bigX) !== null, sprintf('%.0f ms', $ms2));
$t0 = microtime(true);
$page = req($cl['sa'], 'GET', '/reports/collections?hub_id=' . $hubA . '&group=day');
check('The screen with 4,000 rows loads in < 3 s', $page['status'] === 200 && (microtime(true) - $t0) < 3, sprintf('%.0f ms', (microtime(true) - $t0) * 1000));

// ---------------------------------------------------------------- finish
$cleanup();
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase8test.%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P8TEST%'")->fetchColumn();
check('Test data cleaned up', $left === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
