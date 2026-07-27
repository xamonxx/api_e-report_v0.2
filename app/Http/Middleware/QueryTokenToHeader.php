<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DEPRECATED — jangan dipakai untuk endpoint baru.
 *
 * Middleware ini menerima token lewat `?token=` supaya unduhan `window.open`
 * bisa menembus auth. Frontend tidak lagi membutuhkannya: seluruh export sudah
 * pindah ke `api.downloadFile()` yang mengirim token di header `Authorization`
 * (lihat `useFileDownload`). Token di query string ikut tertulis ke DOM, riwayat
 * browser, header `Referer`, dan log server/proxy.
 *
 * Middleware dipertahankan sementara — bukan langsung dihapus — kalau-kalau ada
 * tautan lama yang sudah tersebar (bookmark, pesan chat, dokumen). Sekarang
 * dibatasi ke permintaan GET pada rute export saja, supaya token yang bocor
 * tidak bisa dipakai untuk operasi yang mengubah data.
 *
 * Hapus setelah dipastikan tidak ada lagi tautan bertoken yang beredar.
 */
class QueryTokenToHeader
{
    /**
     * Prefix rute yang masih menerima token lewat query string.
     */
    private const ALLOWED_PATHS = [
        'api/v1/export/*',
        'api/v1/report-attendances/export',
        'api/v1/master-data/users/export',
        'api/v1/consultations/import/template',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->has('token') || $request->headers->has('Authorization')) {
            return $next($request);
        }

        // Hanya GET: token yang bocor lewat riwayat atau Referer tidak boleh
        // bisa dipakai untuk menulis, menghapus, atau mengubah status.
        if (! $request->isMethod('GET') || ! $request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));

        return $next($request);
    }
}
