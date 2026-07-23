<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Penanganan nomor telepon lintas negara.
 *
 * Memakai libphonenumber (port resmi Google) - mesin yang sama dengan
 * libphonenumber-js di frontend, sehingga hasil parsing dan validasi identik
 * di kedua sisi.
 *
 * Nomor disimpan dalam format E.164 ("+6283134774955"): satu bentuk baku tanpa
 * spasi atau strip. Ini membuat deteksi duplikat akurat - "0831..." dan
 * "+62 831..." menghasilkan nilai yang sama - dan tampilannya dibentuk ulang
 * saat dirender.
 */
final class PhoneNumber
{
    /** Negara yang diasumsikan bila nomor diketik tanpa awalan "+". */
    public const DEFAULT_REGION = 'ID';

    private static ?PhoneNumberUtil $util = null;

    private static function util(): PhoneNumberUtil
    {
        return self::$util ??= PhoneNumberUtil::getInstance();
    }

    /**
     * Ubah masukan apa pun menjadi E.164, atau null bila tidak bisa diurai.
     */
    public static function toE164(?string $raw, string $defaultRegion = self::DEFAULT_REGION): ?string
    {
        $parsed = self::parse($raw, $defaultRegion);

        if ($parsed === null) {
            return null;
        }

        return self::util()->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * True bila nomor valid menurut aturan negaranya. Nomor tanpa "+" divalidasi
     * sebagai nomor Indonesia.
     */
    public static function isValid(?string $raw, string $defaultRegion = self::DEFAULT_REGION): bool
    {
        $parsed = self::parse($raw, $defaultRegion);

        return $parsed !== null && self::util()->isValidNumber($parsed);
    }

    /**
     * Bentuk internasional yang enak dibaca, mis. "+62 831 3477 4955".
     * Dipakai untuk tampilan dan export. Nilai yang gagal diurai dikembalikan
     * apa adanya supaya data lama tetap terlihat.
     */
    public static function format(?string $raw, string $defaultRegion = self::DEFAULT_REGION): string
    {
        $parsed = self::parse($raw, $defaultRegion);

        if ($parsed === null) {
            return trim((string) $raw);
        }

        return self::util()->format($parsed, PhoneNumberFormat::INTERNATIONAL);
    }

    /**
     * Kunci pembanding untuk deteksi duplikat: E.164 tanpa tanda "+".
     * Bila nomor tidak bisa diurai, jatuh ke deretan angkanya saja supaya tetap
     * ada kunci yang stabil.
     */
    public static function key(?string $raw, string $defaultRegion = self::DEFAULT_REGION): string
    {
        $e164 = self::toE164($raw, $defaultRegion);

        if ($e164 !== null) {
            return ltrim($e164, '+');
        }

        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }

    private static function parse(?string $raw, string $defaultRegion): ?\libphonenumber\PhoneNumber
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return self::util()->parse($raw, $defaultRegion);
        } catch (NumberParseException) {
            return null;
        }
    }
}
