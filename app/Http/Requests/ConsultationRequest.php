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
    protected function prepareForValidation(): void
    {
        // Fallback ke ejaan lama supaya tetap berfungsi bila migrasi rename
        // belum dijalankan di environment tersebut.
        $pendingConfirmationCategoryId = $this->resolveNeedsCategoryId(NeedsCategory::PENDING_LABEL)
            ?? $this->resolveNeedsCategoryId(NeedsCategory::PENDING_LEGACY_LABEL);
        $otherNeedsCategoryId = $this->resolveNeedsCategoryId(NeedsCategory::OTHER_OPTION_LABEL);
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

            if ($resolved['matched'] && ($city === $none || $province === $none)) {
                foreach (\App\Support\Wilayah::districtMapping() as $item) {
                    if (($item['district'] ?? null) !== $district) {
                        continue;
                    }

                    if ($city === $none) {
                        $city = $item['city'] ?? $city;
                    }

                    if ($province === $none) {
                        $province = $item['province'] ?? $province;
                    }

                    break;
                }
            }
        }
        $productDetails = $trimmed($this->input('product_details'));
        // Disimpan E.164 supaya satu nomor selalu punya satu bentuk, apa pun
        // negaranya. Nomor tanpa "+" diperlakukan sebagai nomor Indonesia.
        // Nilai yang gagal diurai dibiarkan apa adanya agar aturan validasi di
        // bawah yang memberi pesan error, bukan hilang diam-diam.
        $rawPhone = $trimmed($this->input('phone'));
        $phone = PhoneNumber::toE164($rawPhone) ?? $rawPhone;

        if (! $otherNeedsCategoryId || ! in_array($otherNeedsCategoryId, $productIds, true)) {
            $productDetails = null;
        }

        $this->merge([
            'client_name' => $trimmed($this->input('client_name')) ?? Consultation::generatePlaceholderClientName(),
            'phone' => $phone,
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
        $accountId = (int) ($this->input('account_id') ?? auth()->user()->account_id);
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
            'province'           => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[\pL0-9\s\-.,]+$/u'],
            'city'               => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[\pL0-9\s\-.,]+$/u'],
            'district'           => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[\pL0-9\s\-.,]+$/u'],
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
                            $actual = $knownDistricts->first();
                            $validator->errors()->add(
                                'district',
                                sprintf(
                                    'Kecamatan %s terdaftar di %s, %s.',
                                    $district,
                                    $actual['city'] ?? '-',
                                    $actual['province'] ?? '-'
                                )
                            );
                        }
                    }
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

        });
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
            'province.max'                => 'Provinsi terlalu panjang (maksimal 100 karakter).',
            'province.regex'              => 'Provinsi mengandung karakter yang tidak diizinkan.',
            'city.max'                    => 'Kota/Kabupaten terlalu panjang (maksimal 100 karakter).',
            'city.regex'                  => 'Kota mengandung karakter yang tidak diizinkan.',
            'district.max'                => 'Kecamatan terlalu panjang (maksimal 100 karakter).',
            'district.regex'              => 'Kecamatan mengandung karakter yang tidak diizinkan.',
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
        $accountId = $this->input('account_id') ?? auth()->user()->account_id;

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
