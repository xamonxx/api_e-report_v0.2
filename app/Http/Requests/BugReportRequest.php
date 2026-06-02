<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BugReportRequest extends FormRequest
{
    /**
     * Public form — anyone (incl. guests on the login screen) may submit.
     * Abuse is contained by route-level throttling + a honeypot field.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Strip tags to neutralise stored-XSS payloads before they ever hit the DB.
            'description' => filled($this->input('description'))
                ? trim(strip_tags((string) $this->input('description')))
                : null,
            'reporter_email' => filled($this->input('reporter_email'))
                ? mb_strtolower(trim((string) $this->input('reporter_email')))
                : null,
            'page_url' => filled($this->input('page_url'))
                ? mb_substr(trim((string) $this->input('page_url')), 0, 2048)
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'description'   => ['required', 'string', 'min:10', 'max:2000'],
            'page_url'      => ['nullable', 'string', 'max:2048'],
            'reporter_email' => ['nullable', 'email:rfc', 'max:255'],

            // Honeypot: a hidden field real users never fill. Bots auto-fill it,
            // so any non-empty value fails validation and rejects the request.
            'website'       => ['nullable', 'max:0'],

            // Up to 3 screenshots. `image` runs getimagesize() (rejects polyglots),
            // `mimes` whitelists raster formats only (no SVG → no embedded scripts),
            // `dimensions` caps pixel count to block decompression-bomb DoS.
            'images'        => ['nullable', 'array', 'max:3'],
            'images.*'      => [
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Mohon jelaskan bug atau error yang Anda temukan.',
            'description.min'      => 'Penjelasan terlalu singkat (minimal 10 karakter).',
            'description.max'      => 'Penjelasan terlalu panjang (maksimal 2000 karakter).',
            'reporter_email.email' => 'Format email tidak valid.',
            'website.max'          => 'Permintaan ditolak.',
            'images.max'           => 'Maksimal 3 gambar.',
            'images.*.image'       => 'File harus berupa gambar.',
            'images.*.mimes'       => 'Gambar harus berformat JPG, PNG, atau WEBP.',
            'images.*.max'         => 'Ukuran setiap gambar maksimal 2MB.',
            'images.*.dimensions'  => 'Resolusi gambar terlalu besar.',
        ];
    }
}
