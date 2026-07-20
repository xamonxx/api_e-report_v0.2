<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Support\AccountGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter rekap jadwal surveyor.
 *
 * Hanya periode mingguan. Gridnya secara struktur satu minggu Seninâ€“Minggu
 * (7 kolom, satu tanggal masing-masing) â€” sebulan tidak muat, dan menerima
 * period_type=monthly berarti diam-diam merender satu minggu saja dari bulan
 * yang diminta. "Minggu ke-1/2/3" cukup dikirim sebagai week_date: resolver
 * menjepret tanggal apapun ke minggu Seninâ€“Minggu miliknya.
 */
class SurveyorScheduleRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $payload = ['period_type' => 'weekly'];

        if (! $this->filled('week_date')) {
            $payload['week_date'] = now()->toDateString();
        }

        if ($this->filled('account_group')) {
            // Terima "npp 1" / "NPP 1"; nilai tak dikenal dibiarkan lewat agar
            // ditolak Rule::in dengan pesan yang jelas.
            $payload['account_group'] = AccountGroup::normalize($this->input('account_group'))
                ?? $this->input('account_group');
        }

        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'period_type' => ['required', Rule::in(['weekly'])],
            'week_date' => ['required', 'date'],
            'account_group' => ['nullable', Rule::in(AccountGroup::values())],
            'account' => ['nullable', 'integer', 'exists:accounts,id'],
            'surveyor' => [
                'nullable',
                'integer',
                // Batasi ke user ber-role surveyor, bukan sembarang users.id:
                // memfilter ke admin akan mengembalikan rekap kosong yang
                // terlihat seperti "tidak ada survey", bukan salah input.
                Rule::exists('users', 'id')->where('role', UserRole::Surveyor->value),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'period_type.in' => 'Rekap jadwal hanya tersedia per minggu.',
            'week_date.required' => 'Tanggal acuan minggu wajib diisi.',
            'week_date.date' => 'Tanggal acuan minggu tidak valid.',
            'account_group.in' => 'Grup akun tidak valid. Pilih salah satu: '
                . implode(', ', AccountGroup::labels()) . '.',
            'account.exists' => 'Akun yang dipilih tidak valid.',
            'surveyor.exists' => 'Surveyor yang dipilih tidak valid.',
        ];
    }
}
