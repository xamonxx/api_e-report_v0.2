<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $remember = $this->has('remember')
            ? filter_var($this->input('remember'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        $this->merge([
            'email' => filled($this->input('email'))
                ? mb_strtolower(trim((string) $this->input('email')))
                : null,
            'remember' => $remember,
        ]);
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
            'email.max'      => 'Email terlalu panjang.',
            'password.required' => 'Password wajib diisi.',
            'password.max'   => 'Password terlalu panjang.',
        ];
    }
}
