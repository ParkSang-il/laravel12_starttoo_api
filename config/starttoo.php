<?php

return [
    'push_enabled' => (bool) env('STARTTOO_PUSH_ENABLED', false),
    'marketing_push_enabled' => (bool) env('STARTTOO_MARKETING_PUSH_ENABLED', false),
    'fcm_credentials' => env('STARTTOO_FCM_CREDENTIALS', '/run/secrets/starttoo/fcm.json'),
    'firebase_project_id' => env('STARTTOO_FIREBASE_PROJECT_ID', 'starttoo-edd0b'),
    'cdn_url' => env('STARTTOO_CDN_URL', 'https://fmutpixvaykd28944941.gcdn.ntruss.com'),
    'google_client_id' => env('STARTTOO_GOOGLE_CLIENT_ID', '232633950959-ata4nqqoc7ghd3tgte5sc2jedlu5tifh.apps.googleusercontent.com'),
    // 카카오 앱 ID(숫자). 다른 앱에서 발급된 액세스 토큰으로 로그인하지 못하게 비교한다.
    'kakao_app_id' => env('STARTTOO_KAKAO_APP_ID'),
    // 네이버 로그인 사용 여부. 앱 등록 전에는 꺼 둔다.
    'naver_enabled' => (bool) env('STARTTOO_NAVER_ENABLED', false),
    // Apple 로그인 identity token의 aud(iOS 번들 ID).
    'apple_client_id' => env('STARTTOO_APPLE_CLIENT_ID', 'com.pju2251.starttoo'),
    // 탈퇴 시 Apple 연결 해지용 Sign in with Apple 키(.p8). 키 원문은 파일로만 둔다.
    'apple_team_id' => env('STARTTOO_APPLE_TEAM_ID', 'T42QXLMFA4'),
    'apple_key_id' => env('STARTTOO_APPLE_KEY_ID'),
    'apple_private_key' => env('STARTTOO_APPLE_PRIVATE_KEY', '/run/secrets/starttoo/apple_signin.p8'),
    // 개발용 초안은 별도 설정을 켠 환경에서만 노출하고 정식 문안 발행 시 버전을 올린다.
    'draft_terms_enabled' => env('STARTTOO_DRAFT_TERMS_ENABLED', false),
    'terms_version' => '2026-11-17-draft',
    // 공개 소셜의 운영 선행 조건을 검토하기 전에는 익명 조회를 열지 않는다.
    'guest_browsing_enabled' => env('STARTTOO_GUEST_BROWSING_ENABLED', false),
];
