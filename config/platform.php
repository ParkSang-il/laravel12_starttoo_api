<?php

return [
    // Server-owned allowlist. The client never supplies database credentials/names.
    'projects' => [
        'streetcat' => ['name' => '스트릿캣', 'connection' => 'streetcat'],
    ],
    'sms_driver' => env('PLATFORM_SMS_DRIVER', 'disabled'),
    'otp_minutes' => 3,
    'otp_attempts' => 5,
    'daily_sms_limit' => (int) env('PLATFORM_DAILY_SMS_LIMIT', 1000),
];
