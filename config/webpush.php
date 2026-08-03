<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VAPID keys for Web Push
    |--------------------------------------------------------------------------
    | Generate once with: Minishlink\WebPush\VAPID::createVapidKeys().
    | The public key is also exposed to the frontend (PushManager.subscribe).
    | "subject" must be a mailto: or https: URL identifying the sender.
    */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    | Browser subscriptions must point to a known Web Push provider. Without
    | this boundary an authenticated user could turn push delivery into SSRF.
    */
    'allowed_endpoint_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', env(
            'WEBPUSH_ALLOWED_ENDPOINT_HOSTS',
            'fcm.googleapis.com,updates.push.services.mozilla.com,web.push.apple.com'
        ))
    ))),

    'allowed_endpoint_suffixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('WEBPUSH_ALLOWED_ENDPOINT_SUFFIXES', '.notify.windows.com'))
    ))),

    'max_subscriptions_per_user' => (int) env('WEBPUSH_MAX_SUBSCRIPTIONS_PER_USER', 10),
];
