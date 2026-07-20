<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot — notifications
    |--------------------------------------------------------------------------
    | Set these in .env to enable automatic Telegram alerts. Leave empty to
    | disable (notifications are skipped silently and never block requests).
    |
    |  TELEGRAM_BOT_TOKEN           — from @BotFather (e.g. 123456:ABC-DEF...)
    |  TELEGRAM_BUG_CHAT_ID         — target chat/group/channel for bug reports
    |  TELEGRAM_SECURITY_CHAT_ID    — target chat/group/channel for security alerts
    |                                 (failed login attempts, etc.)
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bug_chat_id' => env('TELEGRAM_BUG_CHAT_ID'),
    'security_chat_id' => env('TELEGRAM_SECURITY_CHAT_ID'),

    /*
    | Verify the TLS certificate when calling the Telegram API. Keep TRUE in
    | production. Set TELEGRAM_VERIFY_SSL=false only on a local/dev machine whose
    | cURL cert chain is broken (self-signed root injected by Laragon/AV/proxy),
    | which otherwise fails with "cURL error 60: SSL certificate ...".
    */
    'verify_ssl' => env('TELEGRAM_VERIFY_SSL', true),
];
