<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\IntegrationClient;
use Illuminate\Console\Command;

class IntegrationClientCommand extends Command
{
    protected $signature = 'integration:client
        {action : create | list | revoke}
        {name? : Nama client (create) atau ID client (revoke)}
        {--accounts= : Daftar ID akun yang boleh dibaca, dipisah koma (create)}
        {--expires= : Tanggal kedaluwarsa opsional, contoh 2027-12-31 (create)}';

    protected $description = 'Kelola token integrasi read-only (mis. untuk NACIRA Sales AI CRM).';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'list' => $this->listClients(),
            'revoke' => $this->revoke(),
            default => $this->invalid(),
        };
    }

    private function create(): int
    {
        $name = (string) $this->argument('name');
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('accounts'))));
        if ($name === '' || empty($ids)) {
            $this->error('Gunakan: php artisan integration:client create "Nama" --accounts=1,3');

            return self::FAILURE;
        }
        $known = Account::query()->whereIn('id', $ids)->pluck('name', 'id');
        $missing = array_diff($ids, $known->keys()->all());
        if ($missing) {
            $this->error('Akun tidak ditemukan: '.implode(', ', $missing));

            return self::FAILURE;
        }
        $expires = $this->option('expires') ? now()->parse($this->option('expires'))->endOfDay() : null;
        [$client, $token] = IntegrationClient::issue($name, $ids, $expires);

        $this->info("Client #{$client->id} \"{$client->name}\" dibuat (read-only, akun: ".$known->implode(', ').').');
        $this->warn('Token hanya ditampilkan SEKALI. Simpan di env server aplikasi tujuan (EREPORT_API_TOKEN):');
        $this->line($token);

        return self::SUCCESS;
    }

    private function listClients(): int
    {
        $rows = IntegrationClient::query()->orderBy('id')->get()->map(fn (IntegrationClient $c) => [
            $c->id, $c->name, $c->token_prefix.'…', implode(',', $c->accountIds()), $c->is_active ? 'aktif' : 'dicabut',
            $c->expires_at?->toDateString() ?? '-', $c->last_used_at?->toDateTimeString() ?? '-',
        ]);
        $this->table(['ID', 'Nama', 'Token', 'Akun', 'Status', 'Kedaluwarsa', 'Terakhir dipakai'], $rows);

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $client = IntegrationClient::query()->find((int) $this->argument('name'));
        if (! $client) {
            $this->error('Client tidak ditemukan.');

            return self::FAILURE;
        }
        $client->update(['is_active' => false]);
        $this->info("Client #{$client->id} \"{$client->name}\" dicabut.");

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Aksi harus create, list, atau revoke.');

        return self::FAILURE;
    }
}
