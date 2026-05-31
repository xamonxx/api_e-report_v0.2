<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushService
{
    /**
     * Send a push notification payload to every subscription of the given users.
     * Expired/invalid subscriptions are pruned. Failures are logged, never thrown
     * — a push problem must not break the request that triggered it.
     *
     * @param  array<int>  $userIds
     * @param  array{title:string, body:string, url?:string, tag?:string}  $payload
     */
    public function sendToUsers(array $userIds, array $payload): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return;
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        if (! $publicKey || ! $privateKey) {
            Log::warning('WebPush: VAPID keys not configured; skipping push.');
            return;
        }

        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('webpush.vapid.subject'),
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);

            $body = json_encode($payload);

            foreach ($subscriptions as $sub) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'keys' => [
                            'p256dh' => $sub->public_key,
                            'auth' => $sub->auth_token,
                        ],
                        'contentEncoding' => $sub->content_encoding,
                    ]),
                    $body
                );
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    if ($report->isSubscriptionExpired()) {
                        PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                    } else {
                        Log::info('WebPush delivery failed', [
                            'endpoint' => $report->getEndpoint(),
                            'reason' => $report->getReason(),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('WebPush send error: '.$e->getMessage());
        }
    }
}
