<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $botToken;
    private string $chatId;

    public function __construct()
    {
        $this->botToken = (string) config('telegram.bot_token');
        $this->chatId = (string) (config('telegram.security_chat_id') ?: config('telegram.bug_chat_id'));
    }

    public function sendFailedLoginAlert(
        string $email,
        string $ip,
        int $attemptCount,
        ?string $userAgent = null
    ): bool {
        if (! $this->isConfigured()) {
            Log::warning('Telegram security alert is not configured', compact('email', 'ip', 'attemptCount'));
            return false;
        }

        try {
            return $this->sendMessage($this->formatFailedLoginMessage($email, $ip, $attemptCount, $userAgent));
        } catch (\Throwable $exception) {
            Log::error('Failed to send Telegram security alert', [
                'error' => $exception->getMessage(),
                'email' => $email,
                'ip' => $ip,
            ]);
            return false;
        }
    }

    private function formatFailedLoginMessage(
        string $email,
        string $ip,
        int $attemptCount,
        ?string $userAgent = null
    ): string {
        $lines = [
            '*[ALERT KEAMANAN E-REPORT]*',
            '------------------------------',
            '*Aplikasi:* ' . config('app.name', 'E-Report'),
            "*Email:* `{$email}`",
            "*IP Address:* `{$ip}`",
            "*Percobaan gagal:* {$attemptCount}x",
            '*Waktu:* ' . now('Asia/Jakarta')->format('d-m-Y H:i:s') . ' WIB',
        ];

        if ($userAgent) {
            $lines[] = '*User Agent:* `' . mb_substr($userAgent, 0, 100) . '`';
        }

        $lines[] = '------------------------------';
        $lines[] = '*Tindakan:* Login akun diblokir sementara selama 15 menit.';

        return implode("\n", $lines);
    }

    private function sendMessage(string $message): bool
    {
        $response = Http::timeout(10)
            ->when(! config('telegram.verify_ssl', true), fn ($http) => $http->withoutVerifying())
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

        if ($response->failed()) {
            Log::warning('Telegram security alert was rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    private function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }
}
