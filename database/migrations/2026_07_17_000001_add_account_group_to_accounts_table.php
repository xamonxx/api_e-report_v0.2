<?php

use App\Support\AccountGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menjadikan grup akun (PC / NPP1 / NPP2) kolom tersendiri.
 *
 * Sebelumnya grup ditebak dari teks bebas `description` â€” yang juga dipakai
 * sebagai filter kategori akun dan ditulis oleh AccountGroupSeeder. Kolom ini
 * memisahkan kedua tugas itu; `description` tetap ada sebagai tagline/kategori.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // string, bukan ENUM native: menambah nilai ke ENUM butuh migration
            // + doctrine/dbal. Konsisten dengan surveys.state dan users.role.
            $table->string('account_group', 10)
                ->default(AccountGroup::PC)
                ->after('description');
        });

        $this->backfillFromDescription();

        Schema::table('accounts', function (Blueprint $table) {
            $table->index('account_group', 'accounts_account_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('accounts_account_group_index');
            $table->dropColumn('account_group');
        });
    }

    /**
     * Grup diturunkan dari `description`, bukan diseragamkan ke PC.
     *
     * AccountGroupSeeder sudah pernah menulis "NPP1"/"NPP2" ke sana, jadi
     * menyeragamkan semua ke PC akan menghapus penetapan grup yang disengaja
     * tanpa jejak.
     *
     * Query mentah, bukan Account::all(): model ini memakai TracksAuditUser,
     * dan menyimpan lewat model akan menstempel updated_by/updated_at di
     * seluruh akun hanya karena migration dijalankan.
     */
    private function backfillFromDescription(): void
    {
        DB::table('accounts')
            ->select('id', 'description')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) {
                foreach ($accounts as $account) {
                    DB::table('accounts')
                        ->where('id', $account->id)
                        ->update(['account_group' => AccountGroup::fromDescription($account->description)]);
                }
            });
    }
};
