<?php

namespace App\Services;

use App\Models\BugReport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TelegramNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('telegram.bot_token')) && filled(config('telegram.bug_chat_id'));
    }

    public function sendBugReport(BugReport $report): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $token = config('telegram.bot_token');
        $chatId = config('telegram.bug_chat_id');
        $text = $this->buildText($report);

        try {
            $response = $this->http()->post($this->endpoint($token, 'sendMessage'), [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

            if ($response->failed()) {
                $this->logFail($report, 'sendMessage', $response->status(), $response->body());
            }
        } catch (\Throwable $exception) {
            $this->logFail($report, 'sendMessage', null, $exception->getMessage());
        }

        $paths = array_values(array_filter($report->image_paths ?? []));
        if ($paths !== []) {
            $this->sendPhotos($token, $chatId, $paths, $report);
        }
    }

    /** Upload screenshots directly because Telegram cannot access local URLs. */
    private function sendPhotos(string $token, string $chatId, array $paths, BugReport $report): void
    {
        try {
            $disk = Storage::disk('public');
            $request = $this->http()->timeout(25);
            $media = [];

            foreach (array_slice($paths, 0, 10) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $index = count($media);
                $field = 'photo' . $index;
                $request = $request->attach($field, $disk->get($path), basename($path));
                $media[] = [
                    'type' => 'photo',
                    'media' => 'attach://' . $field,
                    'caption' => $index === 0 ? $report->ticket_code : '',
                ];
            }

            if ($media === []) {
                return;
            }

            $response = $request->post($this->endpoint($token, 'sendMediaGroup'), [
                'chat_id' => $chatId,
                'media' => json_encode($media, JSON_THROW_ON_ERROR),
            ]);

            if ($response->failed()) {
                $this->logFail($report, 'sendMediaGroup', $response->status(), $response->body());
            }
        } catch (\Throwable $exception) {
            $this->logFail($report, 'sendMediaGroup', null, $exception->getMessage());
        }
    }

    private function buildText(BugReport $report): string
    {
        return implode("\n", [
            '[LAPORAN BUG BARU]',
            'Tiket   : ' . $report->ticket_code,
            'Status  : ' . $report->status,
            'Pelapor : ' . ($report->user_id ? ($report->user?->name ?? 'Pengguna') : 'Tamu'),
            'Email   : ' . ($report->reporter_email ?: '-'),
            'Halaman : ' . ($report->page_url ?: '-'),
            'Gambar  : ' . count($report->image_paths ?? []) . ' lampiran',
            '',
            Str::limit((string) $report->description, 800),
        ]);
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout(10);

        if (! config('telegram.verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    private function endpoint(string $token, string $method): string
    {
        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    private function logFail(BugReport $report, string $method, ?int $status, string $detail): void
    {
        Log::warning("Telegram bug report notification failed ({$method})", [
            'ticket' => $report->ticket_code,
            'status' => $status,
            'detail' => Str::limit($detail, 300),
        ]);
    }
}
