<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Support\PendingConfirmation;
use App\Support\PhoneNumber;
use App\Support\WilayahNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyelaraskan data lead lama dengan aturan baru:
 *
 * 1. Nama wilayah mengikuti master Excel ("Kabupaten Bandung" -> "Kab. Bandung",
 *    "DKI Jakarta" -> "Jakarta").
 * 2. Placeholder wilayah memakai label baru ("Belum ada konfirmasi" ->
 *    "Belum konfirmasi"), termasuk kolom district yang masih NULL.
 * 3. Nomor telepon disimpan E.164 supaya deteksi duplikat konsisten.
 * 4. Kolom address yang terlanjur berisi "Dalam Kota"/"Luar Kota" dikosongkan -
 *    itu nilai kolom Domisili yang dulu salah dipetakan saat import.
 *
 * Bersifat aman diulang: menjalankan dua kali tidak mengubah apa pun lagi.
 * Mode bawaan hanya menampilkan rencana; tambahkan --apply untuk menulis.
 */
class BackfillConsultations extends Command
{
    protected $signature = 'consultations:backfill
        {--apply : Tulis perubahan ke database (tanpa opsi ini hanya menampilkan rencana)}
        {--chunk=200 : Jumlah baris per batch}';

    protected $description = 'Selaraskan wilayah, placeholder, dan nomor telepon lead lama dengan format baru.';

    /** Nilai address yang sebenarnya berasal dari kolom Domisili. */
    private const DOMICILE_VALUES = ['dalam kota', 'luar kota'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $total = Consultation::withTrashed()->count();

        if ($total === 0) {
            $this->info('Tidak ada lead untuk diproses.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'MENULIS' : 'PRATINJAU (tambahkan --apply untuk menulis)').": {$total} lead.");
        $this->newLine();

        $stats = [
            'province' => 0,
            'city' => 0,
            'district' => 0,
            'phone' => 0,
            'address' => 0,
        ];
        $unmatchedDistricts = [];
        $unparsedPhones = [];
        $samples = [];
        $touched = 0;

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        Consultation::withTrashed()
            ->select(['id', 'consultation_id', 'province', 'city', 'district', 'phone', 'address'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($leads) use (&$stats, &$unmatchedDistricts, &$unparsedPhones, &$samples, &$touched, $apply, $progress) {
                foreach ($leads as $lead) {
                    $changes = [];

                    // ── Provinsi ────────────────────────────────────────
                    $province = $this->resolveRegion(
                        $lead->province,
                        fn (string $value) => WilayahNormalizer::canonicalProvince($value)
                    );
                    if ($province !== $lead->province) {
                        $changes['province'] = $province;
                        $stats['province']++;
                    }

                    // ── Kota / Kabupaten ────────────────────────────────
                    $city = $this->resolveRegion(
                        $lead->city,
                        fn (string $value) => WilayahNormalizer::canonicalCity($value)
                    );
                    if ($city !== $lead->city) {
                        $changes['city'] = $city;
                        $stats['city']++;
                    }

                    // ── Kecamatan ───────────────────────────────────────
                    $district = $lead->district;
                    if ($district === null || trim((string) $district) === '' || PendingConfirmation::matches($district)) {
                        $district = PendingConfirmation::REGION_LABEL;
                    } else {
                        $resolved = WilayahNormalizer::canonicalDistrict($district, $city);
                        if ($resolved['matched']) {
                            $district = $resolved['value'];
                        } else {
                            // Ejaan tidak dikenali dataset - biarkan apa adanya
                            // dan laporkan agar bisa ditinjau manual.
                            $unmatchedDistricts[] = "#{$lead->consultation_id}: {$district}";
                        }
                    }
                    if ($district !== $lead->district) {
                        $changes['district'] = $district;
                        $stats['district']++;
                    }

                    // ── Nomor telepon ───────────────────────────────────
                    if (filled($lead->phone)) {
                        $e164 = PhoneNumber::toE164($lead->phone);

                        if ($e164 === null) {
                            $unparsedPhones[] = "#{$lead->consultation_id}: {$lead->phone}";
                        } elseif ($e164 !== $lead->phone) {
                            $changes['phone'] = $e164;
                            $stats['phone']++;
                        }
                    }

                    // ── Address berisi Domisili ─────────────────────────
                    $address = trim((string) $lead->address);
                    if ($address !== '' && in_array(mb_strtolower($address), self::DOMICILE_VALUES, true)) {
                        $changes['address'] = null;
                        $stats['address']++;
                    }

                    if ($changes === []) {
                        $progress->advance();

                        continue;
                    }

                    $touched++;

                    if (count($samples) < 10) {
                        $samples[] = [
                            'id' => $lead->consultation_id ?: $lead->id,
                            'ubah' => collect($changes)
                                ->map(fn ($new, $field) => sprintf(
                                    '%s: %s -> %s',
                                    $field,
                                    var_export($lead->{$field}, true),
                                    var_export($new, true)
                                ))
                                ->implode('; '),
                        ];
                    }

                    if ($apply) {
                        // Update langsung lewat query builder: melewati mutator,
                        // event, dan timestamps supaya backfill tidak memicu
                        // efek samping seperti sinkronisasi status.
                        DB::table('consultations')->where('id', $lead->id)->update($changes);
                    }

                    $progress->advance();
                }
            });

        $progress->finish();
        $this->newLine(2);

        $this->table(
            ['Kolom', 'Baris berubah'],
            collect($stats)->map(fn ($count, $field) => [$field, $count])->values()->all()
        );
        $this->line("Total lead tersentuh: <options=bold>{$touched}</> dari {$total}");

        if ($samples !== []) {
            $this->newLine();
            $this->line('Contoh perubahan:');
            foreach ($samples as $sample) {
                $this->line("  {$sample['id']}  {$sample['ubah']}");
            }
        }

        $this->reportList('Kecamatan tidak dikenali dataset (dibiarkan apa adanya)', $unmatchedDistricts);
        $this->reportList('Nomor telepon gagal diurai (dibiarkan apa adanya)', $unparsedPhones);

        $this->newLine();
        if ($apply) {
            $this->info('Selesai. Perubahan sudah ditulis.');
        } else {
            $this->warn('Belum ada yang ditulis. Jalankan ulang dengan --apply bila rencana di atas sudah sesuai.');
        }

        return self::SUCCESS;
    }

    /**
     * Kosong atau placeholder -> label wilayah baru. Selain itu dicocokkan ke
     * dataset; nilai yang tidak dikenali dipertahankan agar tidak ada data
     * hilang diam-diam.
     */
    private function resolveRegion(?string $value, callable $canonicalize): string
    {
        if ($value === null || trim($value) === '' || PendingConfirmation::matches($value)) {
            return PendingConfirmation::REGION_LABEL;
        }

        return $canonicalize($value) ?? trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param  list<string>  $items
     */
    private function reportList(string $title, array $items): void
    {
        if ($items === []) {
            return;
        }

        $unique = array_values(array_unique($items));

        $this->newLine();
        $this->warn(sprintf('%s: %d', $title, count($unique)));

        foreach (array_slice($unique, 0, 15) as $item) {
            $this->line("  {$item}");
        }

        if (count($unique) > 15) {
            $this->line('  ... dan '.(count($unique) - 15).' lainnya');
        }
    }
}
