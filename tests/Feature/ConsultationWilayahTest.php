<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengisian wilayah di form konsultasi (ConsultationRequest).
 *
 * Nama kecamatan tidak unik secara nasional: "Cibadak" ada di Kab. Lebak
 * (Banten) dan Kab. Sukabumi (Jawa Barat). Kota hanya boleh diisi otomatis
 * bila kandidatnya tunggal; bila ambigu, user harus memilih kota sendiri.
 */
class ConsultationWilayahTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private int $needsCategoryId;

    private int $statusCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = new User([
            'name' => 'Super Admin Wilayah',
            'email' => 'wilayah-'.uniqid().'@test.local',
            'password' => Hash::make('Password!123'),
        ]);
        $user->role = UserRole::SuperAdmin;
        $user->save();
        Sanctum::actingAs($user->fresh());

        $this->account = Account::query()->create(['name' => 'Akun Uji Wilayah']);
        $this->needsCategoryId = NeedsCategory::query()->firstOrCreate(['name' => 'Kitchen Set Uji Wilayah'])->id;
        $this->statusCategoryId = StatusCategory::query()->create([
            'name' => 'Status Uji Wilayah',
            'color' => '#737c7f',
            'sort_order' => 99,
        ])->id;
    }

    public function test_kecamatan_kembar_tanpa_kota_dan_provinsi_ditolak_dengan_daftar_kandidat(): void
    {
        $response = $this->store(['district' => 'Cibadak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city'])
            ->assertJsonMissingValidationErrors(['district', 'province']);

        $message = $response->json('errors.city.0');
        $this->assertSame(
            'Kecamatan Cibadak ada di beberapa kabupaten/kota: Kab. Lebak, Kab. Sukabumi. Pilih kabupaten/kota.',
            $message
        );
        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_kecamatan_kembar_dengan_provinsi_mengisi_kota_yang_unik_di_provinsi_itu(): void
    {
        // Cibadak hanya satu di Jawa Barat (Kab. Sukabumi); yang lain di Banten.
        $this->store(['province' => 'Jawa Barat', 'district' => 'Cibadak'])
            ->assertCreated()
            ->assertJsonPath('data.province', 'Jawa Barat')
            ->assertJsonPath('data.city', 'Kab. Sukabumi')
            ->assertJsonPath('data.district', 'Cibadak');
    }

    public function test_kecamatan_kembar_dengan_provinsi_tidak_lagi_memilih_baris_pertama_lintas_provinsi(): void
    {
        // Dulu terisi Kab. Aceh Besar (baris pertama dataset) lalu gagal
        // "Kabupaten/Kota tidak sesuai dengan Provinsi".
        $this->store(['province' => 'Jawa Barat', 'district' => 'Sukamakmur'])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Kab. Bogor')
            ->assertJsonPath('data.province', 'Jawa Barat');
    }

    public function test_kecamatan_kembar_dengan_kota_tersimpan_benar(): void
    {
        $this->store(['city' => 'Kab. Sukabumi', 'district' => 'Cibadak'])
            ->assertCreated()
            ->assertJsonPath('data.province', 'Jawa Barat')
            ->assertJsonPath('data.city', 'Kab. Sukabumi')
            ->assertJsonPath('data.district', 'Cibadak');
    }

    public function test_kecamatan_unik_tanpa_kota_tetap_melengkapi_kota_dan_provinsi(): void
    {
        $this->store(['district' => 'Coblong'])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Kota Bandung')
            ->assertJsonPath('data.province', 'Jawa Barat');
    }

    public function test_nama_kecamatan_resmi_dengan_garis_miring_diterima(): void
    {
        $this->store(['city' => 'Kab. Pidie', 'district' => 'Tiro/Truseb'])
            ->assertCreated()
            ->assertJsonPath('data.province', 'Aceh')
            ->assertJsonPath('data.city', 'Kab. Pidie')
            ->assertJsonPath('data.district', 'Tiro/Truseb');

        $this->store(['city' => 'Kab. Raja Ampat', 'district' => 'Misool (Misool Utara)'], '081234567891')
            ->assertCreated()
            ->assertJsonPath('data.district', 'Misool (Misool Utara)');
    }

    public function test_nama_kecamatan_resmi_dua_huruf_dan_satu_huruf_diterima(): void
    {
        $this->store(['city' => 'Kab. Boven Digoel', 'district' => 'Ki'])
            ->assertCreated()
            ->assertJsonPath('data.district', 'Ki')
            ->assertJsonPath('data.province', 'Papua Selatan');

        // Ejaan dataset di NTT adalah "KI"; ketikan "Ki" dirapikan ke sana.
        $this->store(['city' => 'Kab. Timor Tengah Selatan', 'district' => 'Ki'], '081234567891')
            ->assertCreated()
            ->assertJsonPath('data.district', 'KI');

        $this->store(['city' => 'Kab. Nias Selatan', 'district' => 'O'], '081234567892')
            ->assertCreated()
            ->assertJsonPath('data.district', 'O');
    }

    public function test_nama_kota_tanpa_awalan_dirapikan_ke_kota(): void
    {
        $this->store(['city' => 'Bandung'])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Kota Bandung')
            ->assertJsonPath('data.province', 'Jawa Barat');
    }

    public function test_kecamatan_manual_dengan_kota_valid_tersimpan_apa_adanya(): void
    {
        $this->store(['city' => 'Kab. Sukabumi', 'district' => 'Desa Baru Sekali'])
            ->assertCreated()
            ->assertJsonPath('data.city', 'Kab. Sukabumi')
            ->assertJsonPath('data.district', 'Desa Baru Sekali');

        // Tanda baca / ( ) ' sekarang diizinkan untuk ketikan manual.
        $this->store(['city' => 'Kab. Sukabumi', 'district' => "Kampung D'Sari (Blok/2)"], '081234567891')
            ->assertCreated()
            ->assertJsonPath('data.district', "Kampung D'Sari (Blok/2)");
    }

    public function test_kecamatan_manual_tetap_minimal_tiga_karakter_dan_tanpa_simbol_aneh(): void
    {
        $this->store(['city' => 'Kab. Sukabumi', 'district' => 'Zq'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district']);

        $this->store(['city' => 'Kab. Sukabumi', 'district' => 'Desa <b>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district']);
    }

    public function test_kecamatan_di_kota_yang_salah_menyebut_semua_kandidat(): void
    {
        $response = $this->store(['city' => 'Kota Sukabumi', 'district' => 'Cibadak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district']);

        $this->assertSame(
            'Kecamatan Cibadak terdaftar di beberapa kabupaten/kota: Kab. Lebak, Kab. Sukabumi. Pilih kabupaten/kota yang sesuai.',
            $response->json('errors.district.0')
        );
    }

    public function test_daftar_kandidat_diringkas_setelah_lima_kota(): void
    {
        // Karanganyar ada di enam kab/kota.
        $message = $this->store(['district' => 'Karanganyar'])
            ->assertUnprocessable()
            ->json('errors.city.0');

        $this->assertStringStartsWith('Kecamatan Karanganyar ada di beberapa kabupaten/kota: ', $message);
        $this->assertStringContainsString(', dan 1 lainnya. Pilih kabupaten/kota.', $message);
    }

    public function test_error_kecamatan_ambigu_muncul_bersama_error_kolom_lain(): void
    {
        $this->store(['district' => 'Cibadak', 'phone' => 'bukan nomor'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city', 'phone']);
    }

    public function test_update_memakai_aturan_wilayah_yang_sama(): void
    {
        $id = $this->store(['city' => 'Kab. Sukabumi', 'district' => 'Cibadak'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/consultations/{$id}", $this->payload(['district' => 'Cibadak']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city']);

        $this->putJson("/api/v1/consultations/{$id}", $this->payload(['city' => 'Kab. Pidie', 'district' => 'Tiro/Truseb']))
            ->assertOk()
            ->assertJsonPath('data.district', 'Tiro/Truseb');
    }

    private function store(array $wilayah, string $phone = '081234567890'): TestResponse
    {
        return $this->postJson('/api/v1/consultations', $this->payload($wilayah, $phone));
    }

    private function payload(array $overrides, string $phone = '081234567890'): array
    {
        return array_merge([
            'client_name' => 'Klien Uji Wilayah',
            'phone' => $phone,
            'account_id' => $this->account->id,
            'needs_category_ids' => [$this->needsCategoryId],
            'status_category_id' => $this->statusCategoryId,
        ], $overrides);
    }
}
