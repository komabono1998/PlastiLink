<?php
declare(strict_types=1);

namespace App\Services\Backup;

/** Reads archives written by ZipWriter (stored entries). Used to restore uploaded files from a backup. Refuses unsafe paths. */
final class ZipReader
{
    /** @return array<string,string> name => bytes */
    public static function read(string $zip): array
    {
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false) {
            throw new \RuntimeException('Not a zip archive.');
        }
        $e = unpack('vdisk/vcd/ventries/vtotal/Vsize/Voffset', substr($zip, $eocd + 4, 16));
        $out = [];
        $p = $e['offset'];
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($zip, $p, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('Damaged archive.');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($zip, $p + 4, 42));
            $name = substr($zip, $p + 46, $h['nlen']);
            $lh = unpack('vnlen/velen', substr($zip, $h['off'] + 26, 4));
            $data = substr($zip, $h['off'] + 30 + $lh['nlen'] + $lh['elen'], $h['csize']);
            if ($h['method'] !== 0 || strlen($data) !== $h['size'] || (crc32($data) & 0xFFFFFFFF) !== $h['crc']) {
                throw new \RuntimeException("Entry $name is damaged.");
            }
            $out[$name] = $data;
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        }
        return $out;
    }

    /** Extract below $root, never outside it. Only receipts/ and uploads/ are accepted. @return int files written */
    public static function extractTo(array $entries, string $storageRoot, string $uploadsRoot): int
    {
        $n = 0;
        foreach ($entries as $name => $bytes) {
            if (!preg_match('#^(receipts|uploads)/([A-Za-z0-9._\-]+/)*[A-Za-z0-9._\-]+$#', $name) || str_contains($name, '..')) {
                continue;
            }
            [$kind, $rel] = explode('/', $name, 2);
            $dest = ($kind === 'receipts' ? $storageRoot : $uploadsRoot) . '/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0750, true);
            }
            file_put_contents($dest, $bytes);
            $n++;
        }
        return $n;
    }
}
