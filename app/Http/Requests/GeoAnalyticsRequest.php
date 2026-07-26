<?php

namespace App\Http\Requests;

use App\Support\AccountGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter Geo Analytics. Bentuk periode & scope-nya mengikuti
 * AnalyticsReportRequest, ditambah filter khas peta (provinsi, status,
 * kategori kebutuhan).
 */
class GeoAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $periodType = $this->input('period_type', 'monthly');
        $year = (int) ($this->input('year') ?: now()->year);

        $payload = [
            'period_type' => $periodType,
            'year' => $year,
        ];

        if ($periodType === 'monthly' && ! $this->filled('month')) {
            $payload['month'] = now()->month;
        }

        if ($periodType === 'weekly' && ! $this->filled('week_date')) {
            $payload['week_date'] = now()->toDateString();
        }

        if ($this->filled('account_group')) {
            $payload['account_group'] = AccountGroup::normalize($this->input('account_group'))
                ?? $this->input('account_group');
        }

        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'period_type' => ['required', Rule::in(['weekly', 'monthly', 'yearly', 'custom'])],
            'week_date' => ['nullable', 'date'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,' . (now()->year + 1)],
            'start_date' => ['required_if:period_type,custom', 'nullable', 'date', 'after_or_equal:2020-01-01'],
            'end_date' => ['required_if:period_type,custom', 'nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:' . now()->addYear()->toDateString()],
            'account' => ['nullable', 'integer', 'exists:accounts,id'],
            'account_group' => ['nullable', Rule::in(AccountGroup::values())],
            'status' => ['nullable', 'integer', 'exists:status_categories,id'],
            'needs_category' => ['nullable', 'integer', 'exists:needs_categories,id'],
            'province' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_type.in' => 'Tipe periode laporan tidak valid.',
            'year.between' => 'Tahun yang dipilih tidak valid.',
            'start_date.required_if' => 'Tanggal awal wajib diisi untuk periode kustom.',
            'end_date.required_if' => 'Tanggal akhir wajib diisi untuk periode kustom.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'account.exists' => 'Akun yang dipilih tidak valid.',
            'account_group.in' => 'Grup akun tidak valid. Pilih salah satu: '
                . implode(', ', AccountGroup::labels()) . '.',
            'status.exists' => 'Status yang dipilih tidak valid.',
            'needs_category.exists' => 'Kategori kebutuhan tidak valid.',
        ];
    }
}
