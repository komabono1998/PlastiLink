<?php
declare(strict_types=1);

namespace App\Services\Exports;

/** Minimal ZIP writer (stored, no compression) so .xlsx files need neither the zip extension nor Composer. */
final class ZipWriter
{
    /** @var array<int,array{name:string,crc:int,size:int,offset:int}> */
    private array $entries = [];
    private string $data = '';

    public function add(string $name, string $content): void
    {
        $crc = crc32($content) & 0xFFFFFFFF;
        $size = strlen($content);
        $offset = strlen($this->data);
        [$time, $date] = $this->dosTime();
        $this->data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0) . $name . $content;
        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $offset];
    }

    public function build(): string
    {
        $central = '';
        [$time, $date] = $this->dosTime();
        foreach ($this->entries as $e) {
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $e['crc'], $e['size'], $e['size'], strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']) . $e['name'];
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entries), count($this->entries), strlen($central), strlen($this->data), 0);
        return $this->data . $central . $end;
    }

    /** @return array{0:int,1:int} MS-DOS time and date */
    private function dosTime(): array
    {
        $t = getdate();
        return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), ((max(1980, $t['year']) - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    }
}
