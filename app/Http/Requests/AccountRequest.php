<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $cleanText = fn ($value) => filled($value)
            ? trim(preg_replace('/\s+/u', ' ', (string) $value))
            : null;

        $this->merge([
            'name' => $cleanText($this->input('name')),
            'description' => $cleanText($this->input('description')),
            'remove_logo' => $this->boolean('remove_logo') ? '1' : '0',
        ]);

        if ($this->hasFile('logo') && !$this->file('logo')->isValid()) {
            $this->merge(['logo' => null]);
        }
    }

    public function rules(): array
    {
        $accountId = $this->route('account')?->id;

        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:100',
                Rule::unique('accounts', 'name')->ignore($accountId),
            ],
            'description' => ['nullable', 'string', 'max:120'],
            'target_leads' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama akun wajib diisi.',
            'name.min' => 'Nama akun minimal 3 karakter.',
            'name.max' => 'Nama akun terlalu panjang (maksimal 100 karakter).',
            'name.unique' => 'Nama akun sudah digunakan.',
            'description.max' => 'Kategori/tagline terlalu panjang (maksimal 120 karakter).',
            'target_leads.integer' => 'Target leads harus berupa angka.',
            'target_leads.min' => 'Target leads minimal 1.',
            'target_leads.max' => 'Target leads terlalu besar (maksimal 1.000.000).',
            'logo.image' => 'Logo harus berupa file gambar.',
            'logo.mimes' => 'Logo harus berformat jpeg, png, jpg, gif, svg, atau webp.',
            'logo.max' => 'Ukuran logo maksimal 2MB.',
        ];
    }
}
