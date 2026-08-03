<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultationNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $body = str_replace(["\r\n", "\r"], "\n", (string) $this->input('body', ''));
        $body = preg_replace('/[ \t]+/u', ' ', $body);
        $body = preg_replace('/ *\n */u', "\n", $body);
        $body = trim(preg_replace('/\n{3,}/u', "\n\n", $body));

        $this->merge([
            'body' => $body === '' ? null : $body,
        ]);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000', 'regex:/^[^<>]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Catatan wajib diisi.',
            'body.max' => 'Catatan maksimal 2000 karakter.',
            'body.regex' => 'Catatan tidak boleh mengandung tag HTML atau simbol < >.',
        ];
    }
}
