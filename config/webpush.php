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
];
