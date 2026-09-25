<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicBugReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_public_report_rejects_non_web_page_url_schemes(): void
    {
        $this->postJson('/api/v1/bug-reports', [
            'description' => 'Tautan berbahaya harus ditolak oleh validasi.',
            'page_url' => 'javascript:alert(document.domain)',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('page_url');

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_public_report_accepts_https_page_url(): void
    {
        $this->postJson('/api/v1/bug-reports', [
            'description' => 'Halaman dashboard menampilkan data yang tidak sesuai.',
            'page_url' => 'https://app.example.test/dashboard?tab=summary',
        ])->assertCreated()
            ->assertJsonStructure(['message', 'ticket_code']);

        $this->assertDatabaseHas('bug_reports', [
            'page_url' => 'https://app.example.test/dashboard?tab=summary',
        ]);
    }
}
