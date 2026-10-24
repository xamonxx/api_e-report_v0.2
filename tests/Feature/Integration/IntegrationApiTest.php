<?php

namespace Tests\Feature\Integration;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Account $allowed;

    private Account $other;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowed = Account::query()->create(['name' => 'Homeputra IG']);
        $this->other = Account::query()->create(['name' => 'Akun Lain']);
        $creator = new User(['name' => 'Admin', 'email' => 'admin-int@audit.test', 'password' => Hash::make('x'), 'account_id' => $this->allowed->id]);
        $creator->role = UserRole::Admin;
        $creator->save();

        foreach ([[$this->allowed, 'A', '01.2609.0001'], [$this->allowed, 'B', '01.2609.0002'], [$this->other, 'Rahasia', '02.2609.0001']] as [$acc, $name, $id]) {
            Consultation::query()->create([
                'consultation_id' => $id, 'client_name' => "Klien {$name}", 'phone' => '0812345678'.random_int(10, 99),
                'account_id' => $acc->id, 'created_by' => $creator->id, 'consultation_date' => now()->toDateString(),
            ]);
        }

        [, $this->token] = IntegrationClient::issue('NACIRA CRM', [$this->allowed->id]);
    }

    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json'];
    }

    public function test_requires_a_valid_token(): void
    {
        $this->getJson('/api/v1/integration/consultations')->assertUnauthorized();
        $this->getJson('/api/v1/integration/consultations', $this->auth('erpi_wrong'))->assertUnauthorized();
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        IntegrationClient::query()->update(['is_active' => false]);
        $this->getJson('/api/v1/integration/accounts', $this->auth())->assertUnauthorized();

        [, $expired] = IntegrationClient::issue('Expired', [$this->allowed->id], now()->subDay());
        $this->getJson('/api/v1/integration/accounts', $this->auth($expired))->assertUnauthorized();
    }

    public function test_write_methods_are_not_allowed(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json(strtoupper($method), '/api/v1/integration/consultations', [], $this->auth())->assertStatus(405);
        }
        $this->json('DELETE', '/api/v1/integration/consultations/01.2609.0001', [], $this->auth())->assertStatus(405);
    }

    public function test_only_allowlisted_accounts_are_visible(): void
    {
        $res = $this->getJson('/api/v1/integration/consultations', $this->auth())->assertOk();
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertEqualsCanonicalizing(['01.2609.0001', '01.2609.0002'], collect($res->json('data'))->pluck('consultation_id')->all());

        // Asking for another account explicitly returns nothing.
        $this->getJson('/api/v1/integration/consultations?account='.$this->other->id, $this->auth())
            ->assertOk()->assertJsonPath('meta.total', 0);

        // Detail by id across the allowlist boundary is a 404.
        $this->getJson('/api/v1/integration/consultations/02.2609.0001', $this->auth())->assertNotFound();
        $this->getJson('/api/v1/integration/consultations/01.2609.0001', $this->auth())
            ->assertOk()->assertJsonPath('data.client_name', 'Klien A');

        $accounts = $this->getJson('/api/v1/integration/accounts', $this->auth())->assertOk()->json('data');
        $this->assertSame([$this->allowed->id], collect($accounts)->pluck('id')->all());
    }

    public function test_response_does_not_leak_internal_fields(): void
    {
        $row = $this->getJson('/api/v1/integration/consultations/01.2609.0001', $this->auth())->json('data');
        $this->assertArrayNotHasKey('created_by', $row);
        $this->assertArrayNotHasKey('creator', $row);
        $this->assertArrayHasKey('updated_at', $row);
    }

    public function test_integration_token_cannot_use_normal_user_routes(): void
    {
        $this->getJson('/api/v1/consultations', $this->auth())->assertUnauthorized();
        $this->postJson('/api/v1/consultations', ['client_name' => 'X'], $this->auth())->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->auth())->assertUnauthorized();
    }

    public function test_updated_since_and_include_deleted_filters(): void
    {
        Consultation::query()->where('consultation_id', '01.2609.0001')->update(['updated_at' => now()->subDays(10)]);
        $res = $this->getJson('/api/v1/integration/consultations?updated_since='.urlencode(now()->subDay()->toIso8601String()), $this->auth())->assertOk();
        $this->assertSame(['01.2609.0002'], collect($res->json('data'))->pluck('consultation_id')->all());

        Consultation::query()->where('consultation_id', '01.2609.0002')->first()->delete();
        $this->assertSame(0, $this->getJson('/api/v1/integration/consultations?updated_since='.urlencode(now()->subDay()->toIso8601String()), $this->auth())->json('meta.total'));
        $withDeleted = $this->getJson('/api/v1/integration/consultations?include_deleted=1&updated_since='.urlencode(now()->subDay()->toIso8601String()), $this->auth())->json('data');
        $this->assertNotNull($withDeleted[0]['deleted_at']);
    }
}
