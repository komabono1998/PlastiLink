<?php
declare(strict_types=1);

/**
 * Build the deployment package (CLI only).
 *
 *   php deploy/build.php                 Layout A: one zip to unpack into the domain's public_html (the root .htaccess routes to /public)
 *   php deploy/build.php --split         Layout B (more private): plastilink-private.zip for a folder ABOVE public_html, and
 *                                        plastilink-public.zip for public_html itself (front controller stub + assets only)
 *
 * The package never contains: .git, tests, docs, deploy tooling, local config (passwords/keys), logs, receipts, backups, demo seeders.
 * Output goes to deploy/dist/ (git-ignored) with a SHA-256 checksum file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/Services/Exports/ZipWriter.php';

use App\Services\Exports\ZipWriter;

$root = dirname(__DIR__);
$split = in_array('--split', $argv, true);
$out = $root . '/deploy/dist';
@mkdir($out, 0755, true);

$exclude = [
    '#^\.git(/|$)#', '#^\.gitignore$#', '#^\.gitattributes$#', '#^\.claude(/|$)#', '#^tests(/|$)#', '#^docs(/|$)#', '#^deploy/(?!preflight\.php$)#',
    '#^seed\.php$#', '#^config/.*\.local\.php$#', '#^storage/logs/.+#', '#^storage/receipts/.+#', '#^storage/backups/.+#',
    '#^public/uploads/.+#', '#\.log$#', '#^CHANGELOG\.md$#',
];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    foreach ($exclude as $rx) {
        if (preg_match($rx, $rel)) {
            continue 2;
        }
    }
    $files[$rel] = $f->getPathname();
}
ksort($files);

$bad = array_filter(array_keys($files), static fn($r) => preg_match('#(\.local\.php|\.env|\.pem|\.key|id_rsa)$#', $r));
if ($bad) {
    fwrite(STDERR, "Refusing to build: secrets would be included: " . implode(', ', $bad) . "\n");
    exit(1);
}

$add = static function (ZipWriter $z, string $name, string $path) use ($root): void {
    $z->add($name, (string) file_get_contents($path));
};
$sums = [];
$save = static function (string $name, ZipWriter $z) use ($out, &$sums): void {
    file_put_contents($out . '/' . $name, $z->build());
    $sums[$name] = hash_file('sha256', $out . '/' . $name);
    printf("  %s (%s KB)\n", $name, number_format(filesize($out . '/' . $name) / 1024));
};
$prodHtaccess = (string) file_get_contents($root . '/deploy/htaccess.production');
$version = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>&1')) ?: 'unknown';
$stamp = "PlastiLink build $version, " . gmdate('c') . "\n";

echo "Building " . ($split ? 'split (Layout B)' : 'single (Layout A)') . " package...\n";
if (!$split) {
    $z = new ZipWriter();
    foreach ($files as $rel => $path) {
        if ($rel === '.htaccess') {
            continue;
        }
        $add($z, $rel, $path);
    }
    $z->add('.htaccess', $prodHtaccess);
    foreach (['storage/logs/.gitkeep', 'storage/receipts/.gitkeep', 'storage/backups/.gitkeep', 'public/uploads/.gitkeep'] as $keep) {
        $z->add($keep, '');
    }
    $z->add('BUILD.txt', $stamp);
    $save('plastilink.zip', $z);
} else {
    $priv = new ZipWriter();
    $pub = new ZipWriter();
    foreach ($files as $rel => $path) {
        if ($rel === '.htaccess' || str_starts_with($rel, 'public/assets/') || $rel === 'public/.htaccess') {
            $str = str_starts_with($rel, 'public/assets/') ? $rel : null;
            if ($str !== null) {
                $pub->add(substr($rel, 7), (string) file_get_contents($path));   // assets/... goes to public_html
            }
            if ($rel === 'public/.htaccess') {
                $pub->add('.htaccess', (string) file_get_contents($path));
            }
            if (str_starts_with($rel, 'public/assets/')) {
                $add($priv, $rel, $path);   // keep a copy privately too so asset() version stamps work
            }
            continue;
        }
        $add($priv, $rel, $path);
    }
    foreach (['storage/logs/.gitkeep', 'storage/receipts/.gitkeep', 'storage/backups/.gitkeep', 'public/uploads/.gitkeep'] as $keep) {
        $priv->add($keep, '');
    }
    $priv->add('BUILD.txt', $stamp);
    // public_html/index.php: a stub that runs the real front controller from the private folder next to public_html
    $pub->add('index.php', "<?php\n// Front controller stub: the application lives outside the web root, in ../plastilink\ndeclare(strict_types=1);\nrequire dirname(__DIR__) . '/plastilink/public/index.php';\n");
    $pub->add('uploads/.gitkeep', '');
    $save('plastilink-private.zip', $priv);
    $save('plastilink-public.zip', $pub);
}

$lines = '';
foreach ($sums as $n => $h) {
    $lines .= "$h  $n\n";
}
file_put_contents($out . '/SHA256SUMS.txt', $lines);
echo "Files in the package: " . count($files) . "\nChecksums: deploy/dist/SHA256SUMS.txt\nNext: see docs/DEPLOYMENT.md\n";
