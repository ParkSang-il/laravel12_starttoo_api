<?php

return [
    // 서버에서 허용한 프로젝트만 등록하며 클라이언트는 DB 접속 정보나 DB명을 전달하지 않는다.
    'projects' => [
        'streetcat' => ['name' => '스트릿캣', 'connection' => 'streetcat'],
        'starttoo' => ['name' => 'STARTTOO', 'connection' => 'starttoo'],
    ],
    'sms_driver' => env('PLATFORM_SMS_DRIVER', 'disabled'),
    'otp_minutes' => 3,
    'otp_attempts' => 5,
    'daily_sms_limit' => (int) env('PLATFORM_DAILY_SMS_LIMIT', 1000),
];
