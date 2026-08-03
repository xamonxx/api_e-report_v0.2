<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\PushEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    /**
     * GET /api/v1/push/public-key
     * The VAPID public key the frontend needs for PushManager.subscribe().
     */
    public function publicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('webpush.vapid.public_key'),
        ]);
    }

    /**
     * POST /api/v1/push/subscribe
     * Store (or refresh) the current user's push subscription for this device.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! PushEndpoint::isAllowed((string) $value)) {
                        $fail('Endpoint push tidak diizinkan.');
                    }
                },
            ],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'contentEncoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => auth()->id(),
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
            ]
        );

        $maxSubscriptions = max(1, (int) config('webpush.max_subscriptions_per_user', 10));
        $retainedIds = PushSubscription::query()
            ->where('user_id', auth()->id())
            ->whereKeyNot($subscription->id)
            ->latest('updated_at')
            ->latest('id')
            ->limit($maxSubscriptions - 1)
            ->pluck('id');

        $staleSubscriptions = PushSubscription::query()
            ->where('user_id', auth()->id())
            ->whereKeyNot($subscription->id);

        if ($retainedIds->isNotEmpty()) {
            $staleSubscriptions->whereNotIn('id', $retainedIds);
        }

        $staleSubscriptions->delete();

        return response()->json(['message' => 'Notifikasi perangkat diaktifkan.']);
    }

    /**
     * POST /api/v1/push/unsubscribe
     * Remove this device's subscription for the current user.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::where('endpoint', $data['endpoint'])
            ->where('user_id', auth()->id())
            ->delete();

        return response()->json(['message' => 'Notifikasi perangkat dinonaktifkan.']);
    }
}
