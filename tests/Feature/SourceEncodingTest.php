<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Penjaga mojibake di source backend.
 *
 * Insiden 2026-07-20: commit sinkron massal membaca file UTF-8 sebagai
 * Windows-1252 lalu menyimpannya lagi sebagai UTF-8, sehingga em dash
 * (byte E2 80 94) tersimpan sebagai tiga karakter U+00E2 U+20AC U+201D, dan
 * panah, en dash, garis kotak ikut rusak dengan pola yang sama. Sebagian teks
 * itu tampil ke user (pesan console, exception exporter, output seeder).
 *
 * Tidak butuh aplikasi/database: murni membaca file.
 */
class SourceEncodingTest extends TestCase
{
    private const DIRECTORIES = ['app', 'config', 'database', 'lang', 'resources', 'routes', 'tests'];

    private const EXTENSIONS = ['php', 'js', 'css', 'json', 'md'];

    /**
     * Byte lead UTF-8 (U+00C2..U+00F4) yang diikuti byte lanjutan
     * 0x80..0xBF versi Windows-1252/Latin-1, BOM yang ikut terbaca sebagai
     * teks, atau karakter pengganti U+FFFD.
     */
    private const MOJIBAKE = '/[\x{C2}-\x{F4}][\x{80}-\x{BF}\x{152}\x{153}\x{160}\x{161}\x{178}\x{17D}\x{17E}\x{192}\x{2C6}\x{2DC}\x{2013}\x{2014}\x{2018}\x{2019}\x{201A}\x{201C}\x{201D}\x{201E}\x{2020}\x{2021}\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]|\x{FFFD}|(?<!^)\x{FEFF}/u';

    public function test_source_files_are_valid_utf8_without_bom_or_mojibake(): void
    {
        $base = dirname(__DIR__, 2);
        $problems = [];

        foreach (self::DIRECTORIES as $directory) {
            if (! is_dir("{$base}/{$directory}")) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$directory}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true) || $file->getFilename() === basename(__FILE__)) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($base) + 1);
                $content = (string) file_get_contents($file->getPathname());

                if (str_starts_with($content, "\xEF\xBB\xBF")) {
                    $problems[] = "{$relative}: diawali UTF-8 BOM";
                }

                if (! mb_check_encoding($content, 'UTF-8')) {
                    $problems[] = "{$relative}: bukan UTF-8 valid";

                    continue;
                }

                foreach (preg_split('/\R/', $content) as $index => $line) {
                    if (preg_match(self::MOJIBAKE, $line) === 1) {
                        $problems[] = sprintf('%s:%d: %s', $relative, $index + 1, trim($line));
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Teks mojibake ditemukan:\n".implode("\n", $problems));
    }
}
