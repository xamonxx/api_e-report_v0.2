<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Support\PendingConfirmation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConsultationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $pendingConfirmationCategoryId = $this->resolveNeedsCategoryId(PendingConfirmation::LABEL);
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

        $province = $trimmed($this->input('province')) ?? PendingConfirmation::LABEL;
        $city = $trimmed($this->input('city')) ?? PendingConfirmation::LABEL;
        $district = $trimmed($this->input('district')) ?? PendingConfirmation::LABEL;
        $productDetails = $trimmed($this->input('product_details'));
        $phone = $this->formatIndonesiaPhone($trimmed($this->input('phone')));

        if (! $otherNeedsCategoryId || ! in_array($otherNeedsCategoryId, $productIds, true)) {
            $productDetails = null;
        }

        $this->merge([
            'client_name' => $trimmed($this->input('client_name')),
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
                'required',
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
                    $digits = preg_replace('/[^0-9]/', '', $value);
                    if (strlen($digits) < 9) {
                        $fail('Nomor telepon minimal harus berisi 9 digit angka.');
                    }
                    if (strlen($digits) > 14) {
                        $fail('Nomor telepon tidak boleh lebih dari 14 digit angka.');
                    }
                    $duplicate = Consultation::findDuplicatePhone($accountId, $value, $consultationId);

                    if ($duplicate) {
                        $fail('Nomor telepon ini sudah digunakan pada lead lain di akun yang sama.');
                    }
                }
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

            $consultationId = $this->route('consultation')?->id;
            $duplicate = Consultation::findDuplicateLead($this->duplicateCheckPayload(), $consultationId);

            if ($duplicate) {
                $validator->errors()->add(
                    'client_name',
                    'Lead dengan nama, lokasi, dan detail produk yang sama sudah terdaftar pada akun ini.'
                );
            }
        });
    }

    /**
     * Custom validation messages in Bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'client_name.required'        => 'Nama klien wajib diisi.',
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
        $id = NeedsCategory::query()
            ->where('name', $name)
            ->value('id');

        return $id ? (int) $id : null;
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

    private function formatIndonesiaPhone(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '620')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '62')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        $segments = [substr($digits, 0, 3)];
        $remaining = substr($digits, 3);

        while (strlen($remaining) > 4) {
            $segments[] = substr($remaining, 0, 4);
            $remaining = substr($remaining, 4);
        }

        if ($remaining !== '') {
            $segments[] = $remaining;
        }

        return '+62 ' . implode('-', array_filter($segments));
    }
}
