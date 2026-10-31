<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Support\PendingConfirmation;
use App\Support\PhoneNumber;
use App\Support\WilayahNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConsultationRequest extends FormRequest
{
    /** Batas jumlah kab/kota yang disebut di pesan error sebelum diringkas. */
    private const MAX_LISTED_CITIES = 5;

    /** Pola karakter untuk nama wilayah yang diketik manual (bukan dari dataset). */
    private const MANUAL_REGION_REGEX = 'regex:/^[\pL0-9\s\-.,\/()\']+$/u';

    /**
     * Diisi prepareForValidation() bila kecamatan tanpa kota cocok dengan
     * lebih dari satu kab/kota.
     *
     * @var array{district: string, cities: list<string>}|null
     */
    private ?array $ambiguousDistrict = null;

    protected function prepareForValidation(): void
    {
        // Fallback ke ejaan lama supaya tetap berfungsi bila migrasi rename
        // belum dijalankan di environment tersebut.
        $pendingConfirmationCategoryId = $this->resolveNeedsCategoryId(NeedsCategory::PENDING_LABEL)
            ?? $this->resolveNeedsCategoryId(NeedsCategory::PENDING_LEGACY_LABEL);
        $productIds = $this->input('needs_category_ids');

        if ($productIds === null && $this->filled('needs_category_id')) {
            $productIds = [$this->input('needs_category_id')];
        }

        if (is_string($productIds) || is_int($productIds)) {
            $productIds = [$productIds];
        }

        $productIds = collect($productIds ?? [])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($productIds === [] && $pendingConfirmationCategoryId) {
            $productIds = [$pendingConfirmationCategoryId];
        }

        if (
            $pendingConfirmationCategoryId
            && count($productIds) > 1
            && in_array($pendingConfirmationCategoryId, $productIds, true)
        ) {
            $productIds = array_values(
                array_filter(
                    $productIds,
                    fn (int $id) => $id !== $pendingConfirmationCategoryId
                )
            );
        }

        $trimmed = function ($value) {
            if ($value === null) {
                return null;
            }

            $clean = trim(preg_replace('/\s+/u', ' ', (string) $value));

            return $clean === '' ? null : $clean;
        };

        // Wilayah kosong dikerucutkan ke label placeholder, bukan dibiarkan null,
        // supaya laporan "belum dikonfirmasi" bisa menghitungnya.
        $province = PendingConfirmation::normalizeRegion($trimmed($this->input('province')));
        $city = PendingConfirmation::normalizeRegion($trimmed($this->input('city')));
        $district = PendingConfirmation::normalizeRegion($trimmed($this->input('district')));

        $none = PendingConfirmation::REGION_LABEL;

        // Ejaan disamakan ke format master Excel ("Kab. X", "Jakarta") lewat
        // WilayahNormalizer, dan tingkat yang masih kosong diisi dari data yang
        // lebih spesifik: kota melengkapi provinsi, kecamatan melengkapi keduanya.
        if ($province !== $none) {
            $province = WilayahNormalizer::canonicalProvince($province) ?? $province;
        }

        if ($city !== $none) {
            $canonicalCity = WilayahNormalizer::canonicalCity($city);

            if ($canonicalCity !== null) {
                $city = $canonicalCity;

                if ($province === $none) {
                    $province = \App\Support\Wilayah::cityMapping()[$canonicalCity] ?? $province;
                }
            }
        }

        if ($district !== $none) {
            $resolved = WilayahNormalizer::canonicalDistrict($district, $city !== $none ? $city : null);
            $district = $resolved['value'] ?? $district;

            if ($resolved['matched'] && $city === $none) {
                // Nama kecamatan bisa dipakai di beberapa kab/kota (mis. Cibadak
                // di Kab. Lebak dan Kab. Sukabumi). Kota hanya diisi otomatis bila
                // kandidatnya tunggal setelah dibatasi provinsi terpilih; bila masih
                // ambigu, user diminta memilih kota lewat error validasi.
                $candidates = WilayahNormalizer::districtCandidates(
                    $district,
                    $province !== $none ? $province : null
                );

                if (count($candidates) === 1) {
                    $district = $candidates[0]['district'];
                    $city = $candidates[0]['city'];

                    if ($province === $none) {
                        $province = $candidates[0]['province'] ?: $province;
                    }
                } elseif (count($candidates) > 1) {
                    $this->ambiguousDistrict = [
                        'district' => $district,
                        'cities' => array_column($candidates, 'city'),
                    ];
                }
            } elseif ($resolved['matched'] && $province === $none) {
                // Kota terisi tapi tidak dikenal dataset (kota dikenal sudah
                // melengkapi provinsi di atas). Perilaku lama dipertahankan;
                // validasi kota tetap menolaknya.
                foreach (\App\Support\Wilayah::districtMapping() as $item) {
                    if (($item['district'] ?? null) === $district) {
                        $province = $item['province'] ?? $province;
                        break;
                    }
                }
            }
        }
        // Detail proyek selalu disimpan apa pun kategorinya (keputusan Mon
        // 2026-10-30). Dulu dibuang diam-diam bila "Lain-Lain" tidak dipilih,
        // padahal form mengajak semua lead mengisinya. Kewajiban mengisi saat
        // "Lain-Lain" dipilih tetap ada di rules().
        $productDetails = $trimmed($this->input('product_details'));
        // Disimpan E.164 supaya satu nomor selalu punya satu bentuk, apa pun
        // negaranya. Nomor tanpa "+" diperlakukan sebagai nomor Indonesia.
        // Nilai yang gagal diurai dibiarkan apa adanya agar aturan validasi di
        // bawah yang memberi pesan error, bukan hilang diam-diam.
        $rawPhone = $trimmed($this->input('phone'));
        $phone = PhoneNumber::toE164($rawPhone) ?? $rawPhone;
        $rawEmergencyPhone = $trimmed($this->input('emergency_phone'));
        $emergencyPhone = $rawEmergencyPhone
            ? (PhoneNumber::toE164($rawEmergencyPhone) ?? $rawEmergencyPhone)
            : null;

        $this->merge([
            'client_name' => $trimmed($this->input('client_name')) ?? Consultation::generatePlaceholderClientName(),
            'phone' => $phone,
            'emergency_phone' => $emergencyPhone,
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'address' => $trimmed($this->filled('address') ? $this->input('address') : null),
            'product_details' => $productDetails,
            'notes' => $trimmed($this->filled('notes') ? $this->input('notes') : null),
            'needs_category_ids' => $productIds,
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Admin selalu disimpan ke akun aktifnya (controller menimpa account_id),
        // jadi cek duplikat juga wajib di akun itu. Kalau memakai input, admin
        // bisa melewati cek akunnya sendiri dan membaca ID lead akun lain dari
        // pesan error.
        $user = auth()->user();
        $accountId = $user->isAdmin()
            ? (int) $user->activeAccountId()
            : (int) ($this->input('account_id') ?? $this->route('consultation')?->account_id ?? $user->activeAccountId());
        $consultationId = $this->route('consultation')?->id;

        return [
            'client_name'        => [
                'nullable',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\pL0-9\s\-.,\'&()]+$/u' // Allow letters, numbers, spaces, and basic punctuation
            ],
            'phone'              => [
                'required',
                'string',
                'max:30',
                'regex:/^([0-9\s\-\+\(\)]*)$/',
                function ($attribute, $value, $fail) use ($accountId, $consultationId) {
                    // Panjang nomor berbeda tiap negara (lokal Singapura hanya 8
                    // digit), jadi keabsahan diserahkan ke aturan libphonenumber
                    // alih-alih rentang digit tetap.
                    if (! PhoneNumber::isValid($value)) {
                        $fail('Nomor telepon tidak valid. Untuk nomor luar negeri, awali dengan kode negara, contoh +60 12-345 6789.');

                        return;
                    }

                    $duplicate = Consultation::findDuplicatePhone($accountId, $value, $consultationId);

                    if ($duplicate) {
                        $fail("Nomor telepon ini sudah digunakan pada lead {$duplicate->consultation_id} di akun yang sama. Gunakan nomor lain atau perbarui lead tersebut.");
                    }
                },
            ],
            'emergency_phone'    => [
                'nullable',
                'string',
                'max:30',
                'regex:/^([0-9\s\-\+\(\)]*)$/',
                function ($attribute, $value, $fail) {
                    if ($value && ! PhoneNumber::isValid($value)) {
                        $fail('Nomor telepon darurat tidak valid. Untuk nomor luar negeri, awali dengan kode negara, contoh +60 12-345 6789.');
                    }
                },
            ],
            'province'           => $this->regionRules(
                in_array($this->input('province'), \App\Support\Wilayah::provinces(), true)
            ),
            'city'               => $this->regionRules(
                is_string($this->input('city')) && isset(\App\Support\Wilayah::cityMapping()[$this->input('city')])
            ),
            'district'           => $this->regionRules(
                WilayahNormalizer::isKnownDistrict($this->input('district'))
            ),
            'address'            => ['nullable', 'string', 'min:5', 'max:500', 'regex:/^[^<>]+$/'], // No HTML tags
            'account_id'         => [
                Rule::requiredIf(auth()->user()->isSuperAdmin()),
                'nullable',
                'exists:accounts,id'
            ],
            'needs_category_ids' => ['required', 'array', 'min:1'],
            'needs_category_ids.*' => ['required', 'integer', 'exists:needs_categories,id'],
            'product_details'    => [
                Rule::requiredIf(fn () => $this->hasOtherNeedsCategorySelected()),
                'nullable',
                'string',
                'min:3',
                'max:1500',
                'regex:/^[^<>]+$/',
            ],
            'status_category_id' => 'required|exists:status_categories,id',
            'notes'              => ['nullable', 'string', 'min:3', 'max:1000', 'regex:/^[^<>]+$/'], // No HTML tags
            'consultation_date'  => 'nullable|date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Geolocation validation
            $province = $this->input('province');
            $city = $this->input('city');
            $district = $this->input('district');
            // Pakai matches() supaya label legacy pada data lama juga dianggap
            // placeholder dan tidak divalidasi terhadap dataset wilayah.
            $hasProvince = ($province !== null && ! PendingConfirmation::matches($province));
            $hasCity = ($city !== null && ! PendingConfirmation::matches($city));
            $hasDistrict = ($district !== null && ! PendingConfirmation::matches($district));

            // Data wilayah bersifat opsional dan boleh diisi sebagian: pengguna
            // boleh mengisi hanya provinsi, hanya kota/kabupaten, atau hanya
            // kecamatan. Setiap nilai yang diisi tetap divalidasi keabsahannya
            // dan konsistensinya terhadap tingkatan lain yang juga diisi.
            if ($hasProvince || $hasCity || $hasDistrict) {
                $provinces = config('wilayah.provinces', []);
                $cityMapping = \App\Support\Wilayah::cityMapping();
                $districtMapping = \App\Support\Wilayah::districtMapping();

                if ($hasProvince && ! in_array($province, $provinces, true)) {
                    $validator->errors()->add('province', 'Provinsi tidak ditemukan dalam data wilayah Indonesia.');
                }

                if ($hasCity) {
                    if (! isset($cityMapping[$city])) {
                        $validator->errors()->add('city', 'Kabupaten / Kota tidak ditemukan dalam data wilayah Indonesia.');
                    } elseif ($hasProvince && $cityMapping[$city] !== $province) {
                        $validator->errors()->add('city', 'Kabupaten / Kota tidak sesuai dengan Provinsi.');
                    }
                }

                if ($hasDistrict) {
                    $knownDistricts = collect($districtMapping)
                        ->filter(fn (array $item) => strcasecmp((string) ($item['district'] ?? ''), (string) $district) === 0)
                        ->values();

                    // Reference data is not exhaustive. A district absent from it is retained as
                    // a manual value, but a known district must still match the selected area.
                    if ($knownDistricts->isNotEmpty()) {
                        $matchingDistrict = $knownDistricts->first(function (array $item) use ($hasCity, $hasProvince, $city, $province) {
                            return (! $hasCity || strcasecmp((string) ($item['city'] ?? ''), (string) $city) === 0)
                                && (! $hasProvince || strcasecmp((string) ($item['province'] ?? ''), (string) $province) === 0);
                        });

                        if (! $matchingDistrict) {
                            $cities = $knownDistricts
                                ->pluck('city')
                                ->filter()
                                ->unique()
                                ->values();

                            if ($cities->count() <= 1) {
                                $actual = $knownDistricts->first();
                                $message = sprintf(
                                    'Kecamatan %s terdaftar di %s, %s.',
                                    $district,
                                    $actual['city'] ?? '-',
                                    $actual['province'] ?? '-'
                                );
                            } else {
                                $message = sprintf(
                                    'Kecamatan %s terdaftar di beberapa kabupaten/kota: %s. Pilih kabupaten/kota yang sesuai.',
                                    $district,
                                    $this->formatCityList($cities->all())
                                );
                            }

                            $validator->errors()->add('district', $message);
                        }
                    }
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

        });

        // Dicek terpisah dari blok di atas supaya tetap muncul bersama error
        // kolom lain: kota memang belum terisi, jadi tidak ada aturan lain yang
        // akan menangkapnya.
        $validator->after(function (Validator $validator) {
            if ($this->ambiguousDistrict === null) {
                return;
            }

            $validator->errors()->add(
                'city',
                sprintf(
                    'Kecamatan %s ada di beberapa kabupaten/kota: %s. Pilih kabupaten/kota.',
                    $this->ambiguousDistrict['district'],
                    $this->formatCityList($this->ambiguousDistrict['cities'])
                )
            );
        });
    }

    /**
     * Nama dari dataset sudah pasti sah, jadi cukup dibatasi panjangnya. Nama
     * resmi seperti "O", "Ki", atau "Tiro/Truseb" tidak boleh tertolak oleh
     * aturan untuk ketikan manual.
     *
     * @return list<string>
     */
    private function regionRules(bool $isKnown): array
    {
        if ($isKnown) {
            return ['nullable', 'string', 'max:100'];
        }

        return ['nullable', 'string', 'min:3', 'max:100', self::MANUAL_REGION_REGEX];
    }

    /**
     * "A, B, C, D, E, dan 2 lainnya".
     *
     * @param  list<string>  $cities
     */
    private function formatCityList(array $cities): string
    {
        $cities = array_values(array_unique($cities));
        $listed = array_slice($cities, 0, self::MAX_LISTED_CITIES);
        $rest = count($cities) - count($listed);

        $text = implode(', ', $listed);

        return $rest > 0 ? "{$text}, dan {$rest} lainnya" : $text;
    }

    /**
     * Custom validation messages in Bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'client_name.min'             => 'Nama klien minimal 2 karakter.',
            'client_name.max'             => 'Nama klien maksimal 100 karakter.',
            'client_name.regex'           => 'Nama klien hanya boleh berisi huruf, angka, spasi, dan tanda baca dasar (-.,\'&()).',
            'phone.required'              => 'Nomor telepon wajib diisi.',
            'phone.max'                   => 'Teks nomor telepon terlalu panjang (maksimal 30 karakter).',
            'phone.regex'                 => 'Format nomor telepon tidak valid (hanya mendukung angka dan simbol spesifik).',
            'emergency_phone.max'         => 'Teks nomor telepon darurat terlalu panjang (maksimal 30 karakter).',
            'emergency_phone.regex'       => 'Format nomor telepon darurat tidak valid (hanya mendukung angka dan simbol spesifik).',
            'province.min'                => 'Provinsi minimal 3 karakter.',
            'province.max'                => 'Provinsi terlalu panjang (maksimal 100 karakter).',
            'province.regex'              => 'Provinsi mengandung karakter yang tidak diizinkan.',
            'city.min'                    => 'Kota/Kabupaten minimal 3 karakter.',
            'city.max'                    => 'Kota/Kabupaten terlalu panjang (maksimal 100 karakter).',
            'city.regex'                  => 'Kota mengandung karakter yang tidak diizinkan.',
            'district.min'                => 'Kecamatan minimal 3 karakter.',
            'district.max'                => 'Kecamatan terlalu panjang (maksimal 100 karakter).',
            'district.regex'              => 'Kecamatan hanya boleh berisi huruf, angka, spasi, dan tanda baca - . , / ( ) \'.',
            'address.max'                 => 'Alamat terlalu panjang (maksimal 500 karakter).',
            'address.regex'               => 'Alamat tidak boleh mengandung tag HTML atau simbol < >.',
            'product_details.max'         => 'Detail kebutuhan produk terlalu panjang (maksimal 1500 karakter).',
            'notes.regex'                 => 'Catatan tidak boleh mengandung tag HTML atau simbol < >.',
            'notes.max'                   => 'Catatan terlalu panjang (maksimal 1000 karakter).',
            'account_id.required'         => 'Akun interior wajib dipilih untuk level Super Admin.',
            'account_id.exists'           => 'Akun interior tidak valid.',
            'needs_category_ids.required' => 'Minimal satu nama produk wajib dipilih.',
            'needs_category_ids.array'    => 'Format nama produk tidak valid.',
            'needs_category_ids.min'      => 'Minimal satu nama produk wajib dipilih.',
            'needs_category_ids.*.exists' => 'Nama produk yang dipilih tidak valid.',
            'product_details.required'    => 'Detaile Keterangan wajib diisi ketika produk Lain-lain dipilih.',
            'product_details.regex'       => 'Detail produk tidak boleh mengandung tag HTML atau simbol < >.',
            'status_category_id.required' => 'Status wajib dipilih.',
            'status_category_id.exists'   => 'Status tidak valid.',
        ];
    }

    private function hasOtherNeedsCategorySelected(): bool
    {
        $otherNeedsCategoryId = $this->resolveNeedsCategoryId(NeedsCategory::OTHER_OPTION_LABEL);

        if (! $otherNeedsCategoryId) {
            return false;
        }

        return collect($this->input('needs_category_ids', []))
            ->map(fn ($id) => (int) $id)
            ->contains($otherNeedsCategoryId);
    }

    private function resolveNeedsCategoryId(string $name): ?int
    {
        static $cache = [];

        if (! array_key_exists($name, $cache)) {
            $id = NeedsCategory::query()->where('name', $name)->value('id');
            $cache[$name] = $id ? (int) $id : null;
        }

        return $cache[$name];
    }

    private function duplicateCheckPayload(): array
    {
        $accountId = $this->input('account_id') ?? auth()->user()->activeAccountId();

        return [
            'account_id' => $accountId,
            'client_name' => $this->input('client_name'),
            'phone' => $this->input('phone'),
            'province' => $this->input('province'),
            'city' => $this->input('city'),
            'district' => $this->input('district'),
            'address' => $this->input('address'),
            'product_details' => $this->input('product_details'),
            'needs_category_ids' => $this->input('needs_category_ids', []),
        ];
    }

}
