<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicBugReportSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->withoutMiddleware([
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        ]);
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

    public function test_bug_report_screenshots_are_stored_privately_and_served_only_to_super_admin(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $image = UploadedFile::fake()->image('screenshot.png', 100, 100);

        $this->post('/api/v1/bug-reports', [
            'description' => 'Laporan bug dengan tangkapan layar rahasia.',
            'page_url' => 'https://app.example.test/leads',
            'images' => [$image],
        ], ['Accept' => 'application/json'])->assertCreated();

        $report = BugReport::firstOrFail();
        $this->assertCount(1, $report->image_paths ?? []);
        $storedPath = $report->image_paths[0];

        Storage::disk('local')->assertExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);
        $this->assertSame(
            [url("/api/v1/bug-reports/{$report->id}/images/0")],
            $report->imageUrls()
        );

        // Guest -> 401
        $this->getJson("/api/v1/bug-reports/{$report->id}/images/0")
            ->assertUnauthorized();

        // Admin biasa -> 403
        $admin = new User([
            'name' => 'Regular Admin',
            'email' => 'admin-bug@audit.test',
            'password' => Hash::make('Secret#12345'),
        ]);
        $admin->role = UserRole::Admin;
        $admin->save();

        Sanctum::actingAs($admin->fresh());
        $this->getJson("/api/v1/bug-reports/{$report->id}/images/0")
            ->assertForbidden();

        // Super admin -> 200
        $superAdmin = new User([
            'name' => 'Super Admin',
            'email' => 'super-bug@audit.test',
            'password' => Hash::make('Secret#12345'),
        ]);
        $superAdmin->role = UserRole::SuperAdmin;
        $superAdmin->save();

        Sanctum::actingAs($superAdmin->fresh());
        $this->get("/api/v1/bug-reports/{$report->id}/images/0")
            ->assertOk();

        $this->getJson("/api/v1/bug-reports/{$report->id}/images/1")
            ->assertNotFound();
    }
}
