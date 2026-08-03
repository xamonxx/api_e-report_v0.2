<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\PushEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushSubscriptionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_endpoint_rejects_ssrf_targets_and_unbounded_keys(): void
    {
        Sanctum::actingAs($this->createUser());

        foreach ([
            'http://127.0.0.1/latest/meta-data',
            'https://127.0.0.1/internal',
            'https://169.254.169.254/latest/meta-data',
            'https://attacker.example/push',
        ] as $endpoint) {
            $this->postJson('/api/v1/push/subscribe', $this->payload($endpoint))
                ->assertUnprocessable();
        }

        $payload = $this->payload('https://fcm.googleapis.com/fcm/send/valid');
        $payload['keys']['p256dh'] = str_repeat('A', 256);
        $this->postJson('/api/v1/push/subscribe', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_only_known_push_providers_are_allowed(): void
    {
        $this->assertTrue(PushEndpoint::isAllowed('https://fcm.googleapis.com/fcm/send/test'));
        $this->assertTrue(PushEndpoint::isAllowed('https://updates.push.services.mozilla.com/wpush/test'));
        $this->assertTrue(PushEndpoint::isAllowed('https://web.push.apple.com/Q/test'));
        $this->assertTrue(PushEndpoint::isAllowed('https://wns2-par02p.notify.windows.com/w/test'));
        $this->assertFalse(PushEndpoint::isAllowed('https://notify.windows.com.attacker.example/test'));
    }

    public function test_subscription_count_is_bounded_per_user(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        for ($index = 1; $index <= 11; $index++) {
            $this->postJson(
                '/api/v1/push/subscribe',
                $this->payload("https://fcm.googleapis.com/fcm/send/device-{$index}")
            )->assertOk();
        }

        $this->assertSame(10, PushSubscription::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1',
        ]);
    }

    public function test_delivery_prunes_legacy_subscription_with_untrusted_endpoint(): void
    {
        $user = $this->createUser();
        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://127.0.0.1/internal',
            'public_key' => str_repeat('A', 87),
            'auth_token' => str_repeat('B', 22),
            'content_encoding' => 'aes128gcm',
        ]);
        config()->set('webpush.vapid.public_key', 'audit-public-key');
        config()->set('webpush.vapid.private_key', 'audit-private-key');

        app(WebPushService::class)->sendToUsers([$user->id], [
            'title' => 'Audit',
            'body' => 'Endpoint tidak tepercaya harus dibuang sebelum pengiriman.',
        ]);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    private function payload(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => str_repeat('A', 87),
                'auth' => str_repeat('B', 22),
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    private function createUser(): User
    {
        $user = new User([
            'name' => 'Push Audit User',
            'email' => 'push-'.uniqid().'@audit.test',
            'password' => Hash::make('AuditPassword!123'),
        ]);
        $user->role = UserRole::Admin;
        $user->save();

        return $user->fresh();
    }
}
