<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Services\ConsultationImportService;
use App\Services\Reports\LeadsExcelExporter;
use App\Services\Reports\LeadsReportService;
use App\Support\PendingConfirmation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sheet "Data Leads" punya tiga kolom turunan yang gampang rusak kalau urutan
 * kolom bergeser: Alamat Lengkap, Total Item, dan Kategori. Kolom-kolom itu
 * juga memindahkan rentang validasi dropdown, dan template yang sama dipakai
 * ulang oleh importer — jadi pergeserannya diuji dari kedua arah.
 */
class LeadsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_memuat_kolom_baru_pada_urutan_yang_benar(): void
    {
        $xml = $this->exportXml();
        // Baris legenda juga menyebut "Nama Konsumen", jadi baris header
        // dikenali lewat sel Kecamatan yang hanya ada di sana.
        $header = $this->findRowContaining($xml, '>Kecamatan<');

        $this->assertStringContainsString('Alamat Lengkap', $header);
        $this->assertStringContainsString('Total Item', $header);
        $this->assertStringContainsString('Kategori', $header);

        $urutan = ['Nama Konsumen', 'WA Konsumen', 'Alamat Lengkap', 'Domisili',
            'Provinsi', 'Kota Kab.', 'Kecamatan', 'Total Item', 'Jenis Kebutuhan',
            'Kategori', 'Detail Kebutuhan', 'Catatan', 'Status'];

        $posisi = array_map(fn (string $label) => strpos($header, '>'.$label.'<'), $urutan);
        foreach ($posisi as $index => $offset) {
            $this->assertNotFalse($offset, "Header \"{$urutan[$index]}\" tidak ditemukan.");
        }

        $terurut = $posisi;
        sort($terurut);
        $this->assertSame($terurut, $posisi, 'Urutan kolom header tidak sesuai.');
    }

    public function test_alamat_digabung_dengan_wilayah(): void
    {
        $this->lead('CNS-ALAMAT-1', 'Klien Alamat', [
            'address' => 'Jl. Merdeka No. 10',
            'district' => 'Coblong',
            'city' => 'Kota Bandung',
            'province' => 'Jawa Barat',
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Alamat');

        $this->assertStringContainsString(
            'Jl. Merdeka No. 10, Coblong, Kota Bandung, Jawa Barat',
            $row
        );
    }

    public function test_alamat_kosong_memakai_wilayah_saja(): void
    {
        $this->lead('CNS-ALAMAT-2', 'Klien Tanpa Jalan', [
            'address' => null,
            'district' => 'Coblong',
            'city' => 'Kota Bandung',
            'province' => 'Jawa Barat',
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Tanpa Jalan');

        $this->assertStringContainsString('Coblong, Kota Bandung, Jawa Barat', $row);
    }

    public function test_wilayah_tidak_diulang_bila_sudah_disebut_di_alamat(): void
    {
        $this->lead('CNS-ALAMAT-5', 'Klien Alamat Panjang', [
            'address' => 'Jl. Raya No. 7, dekat masjid, Blimbing, Kota Bandung, Jawa Barat',
            'district' => 'Blimbing',
            'city' => 'Kota Bandung',
            'province' => 'Jawa Barat',
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Alamat Panjang');

        $this->assertStringContainsString(
            'Jl. Raya No. 7, dekat masjid, Blimbing, Kota Bandung, Jawa Barat<',
            $row,
            'Wilayah yang sudah tertulis di alamat jalan seharusnya tidak ditambahkan lagi.'
        );
    }

    public function test_placeholder_wilayah_tidak_ikut_digabung(): void
    {
        $this->lead('CNS-ALAMAT-3', 'Klien Setengah', [
            'address' => null,
            'district' => PendingConfirmation::REGION_LABEL,
            'city' => 'Kota Bandung',
            'province' => PendingConfirmation::REGION_LABEL,
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Setengah');

        $this->assertStringContainsString('>Kota Bandung<', $row);
        $this->assertStringNotContainsString(
            'Belum konfirmasi, Kota Bandung',
            $row,
            'Placeholder wilayah seharusnya disaring sebelum digabung.'
        );
    }

    public function test_alamat_kosong_total_jadi_belum_konfirmasi(): void
    {
        $this->lead('CNS-ALAMAT-4', 'Klien Tanpa Alamat', [
            'address' => null,
            'district' => PendingConfirmation::REGION_LABEL,
            'city' => PendingConfirmation::REGION_LABEL,
            'province' => PendingConfirmation::REGION_LABEL,
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Tanpa Alamat');

        // Kolom Provinsi/Kota/Kecamatan memang menyimpan placeholder itu
        // masing-masing; yang diuji di sini adalah sel alamat gabungan tidak
        // menumpuk placeholder menjadi satu kalimat.
        $this->assertStringContainsString('>'.PendingConfirmation::REGION_LABEL.'<', $row);
        $this->assertStringNotContainsString(
            PendingConfirmation::REGION_LABEL.', '.PendingConfirmation::REGION_LABEL,
            $row,
            'Alamat gabungan seharusnya tidak mengulang placeholder.'
        );
    }

    public function test_dua_kebutuhan_jadi_dua_item_dan_kategori_lain_lain(): void
    {
        $lead = $this->lead('CNS-ITEM-1', 'Klien Dua Item');
        $lead->needsCategories()->sync([
            $this->category('Kitchenset')->id,
            $this->category('Backdrop TV')->id,
        ]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Dua Item');

        $this->assertStringContainsString('>2</Data>', $row);
        $this->assertStringContainsString('>'.NeedsCategory::OTHER_OPTION_LABEL.'<', $row);
    }

    public function test_satu_kebutuhan_memakai_namanya_sebagai_kategori(): void
    {
        $lead = $this->lead('CNS-ITEM-2', 'Klien Satu Item');
        $lead->needsCategories()->sync([$this->category('Kitchenset')->id]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Satu Item');

        $this->assertStringContainsString('>1</Data>', $row);
        $this->assertStringContainsString('>Kitchenset<', $row);
        $this->assertStringNotContainsString(NeedsCategory::OTHER_OPTION_LABEL, $row);
    }

    public function test_kebutuhan_belum_dikonfirmasi_dihitung_nol(): void
    {
        $lead = $this->lead('CNS-ITEM-3', 'Klien Belum Konfirmasi Kebutuhan');
        $lead->needsCategories()->sync([$this->category(NeedsCategory::PENDING_LABEL)->id]);

        $row = $this->findRowContaining($this->exportXml(), 'Klien Belum Konfirmasi Kebutuhan');

        $this->assertStringContainsString('>0</Data>', $row);
    }

    public function test_validasi_dropdown_mengikuti_kolom_yang_bergeser(): void
    {
        $xml = $this->exportXml();

        // Provinsi C9, Kota C10, Kecamatan C11, Jenis Kebutuhan C13, Status C17.
        $this->assertStringContainsString('R5C9', $xml);
        $this->assertStringContainsString('R5C13', $xml);
        $this->assertStringContainsString('R5C17', $xml);
        $this->assertStringContainsString('MATCH(I5,CityProvinceCol', $xml);
        $this->assertStringContainsString('MATCH(J5,DistrictCityCol', $xml);
    }

    public function test_importer_membaca_template_18_kolom(): void
    {
        $account = $this->account();
        $this->category('Kitchenset');

        $row = $this->templateRow18($account->name);
        $parsed = $this->parseRow($row);

        $this->assertSame('Jawa Barat', $parsed['province']);
        $this->assertSame('Kota Bandung', $parsed['city']);
        $this->assertSame('Coblong', $parsed['district']);
        $this->assertSame('Jl. Merdeka No. 10', $parsed['address']);
    }

    public function test_importer_masih_membaca_template_15_kolom(): void
    {
        $account = $this->account();
        $this->category('Kitchenset');

        $row = [
            '1', 'CNS-OLD-1', '01/10/2026', $account->name, 'Klien Lama', '+628120000001',
            'Dalam Kota', 'Jawa Barat', 'Kota Bandung', 'Coblong',
            'Kitchenset', 'Detail lama', 'Catatan lama', 'Masih Konsultasi', '01/10/2026',
        ];

        $parsed = $this->parseRow($row);

        $this->assertSame('Jawa Barat', $parsed['province']);
        $this->assertSame('Kota Bandung', $parsed['city']);
        $this->assertSame('Coblong', $parsed['district']);
        $this->assertNull($parsed['address'], 'Template lama tidak punya kolom alamat.');
    }

    public function test_importer_mengabaikan_placeholder_pada_kolom_alamat(): void
    {
        $account = $this->account();
        $this->category('Kitchenset');

        $row = $this->templateRow18($account->name);
        $row[6] = PendingConfirmation::REGION_LABEL;

        $this->assertNull($this->parseRow($row)['address']);
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function templateRow18(string $accountName): array
    {
        return [
            '1', 'CNS-NEW-1', '01/10/2026', $accountName, 'Klien Baru', '+628120000002',
            'Jl. Merdeka No. 10', 'Dalam Kota', 'Jawa Barat', 'Kota Bandung', 'Coblong',
            '1', 'Kitchenset', 'Kitchenset', 'Detail baru', 'Catatan baru',
            'Masih Konsultasi', '01/10/2026',
        ];
    }

    /** Jalankan satu baris template lewat parser importer yang sesungguhnya. */
    private function parseRow(array $row): array
    {
        $service = app(ConsultationImportService::class);
        $method = new \ReflectionMethod($service, 'parseDetailedTemplateRow');
        $method->setAccessible(true);

        $accounts = Account::query()->get();
        $accountNameMap = $accounts
            ->mapWithKeys(fn (Account $account) => [
                mb_strtolower(trim($account->name)) => $account->id,
            ])->all();

        $needsCategoryMap = NeedsCategory::query()->get()
            ->mapWithKeys(fn (NeedsCategory $category) => [
                mb_strtolower(trim($category->name)) => $category->id,
            ])->all();

        $statusCategoryMap = StatusCategory::query()->get()
            ->mapWithKeys(fn (StatusCategory $status) => [
                mb_strtolower(trim($status->name)) => $status->id,
            ])->all();

        $result = $method->invoke(
            $service,
            $row,
            2,
            $this->superAdmin(),
            $accounts->pluck('id')->all(),
            $accountNameMap,
            $needsCategoryMap,
            $statusCategoryMap,
            (int) NeedsCategory::query()->value('id'),
            (int) StatusCategory::query()->value('id'),
        );

        $this->assertIsArray($result, is_string($result) ? $result : 'Baris gagal dipetakan.');

        return $result;
    }

    private function exportXml(): string
    {
        $report = app(LeadsReportService::class)->buildForUser($this->superAdmin(), []);

        return app(LeadsExcelExporter::class)->buildWorkbook($report);
    }

    private function lead(string $id, string $clientName, array $attributes = []): Consultation
    {
        return Consultation::query()->create(array_merge([
            'consultation_id' => $id,
            'client_name' => $clientName,
            'account_id' => $this->account()->id,
            'consultation_date' => Carbon::parse('2026-10-01'),
        ], $attributes));
    }

    private function account(): Account
    {
        return Account::query()->firstOrCreate(['name' => 'Akun Export Uji']);
    }

    private function category(string $name): NeedsCategory
    {
        return NeedsCategory::query()->firstOrCreate(['name' => $name]);
    }

    private function superAdmin(): User
    {
        $user = new User([
            'name' => 'Super Admin Export',
            'email' => 'export-'.uniqid().'@audit.test',
            'password' => Hash::make('AuditPassword!123'),
        ]);
        $user->role = UserRole::SuperAdmin;
        $user->save();

        return $user->fresh();
    }

    private function findRowContaining(string $xml, string $needle): string
    {
        foreach (preg_split('/(?=<Row)/', $xml) as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail("Tidak ada <Row> yang memuat \"{$needle}\".");
    }
}
