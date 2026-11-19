<?php

return [
    'push_enabled' => (bool) env('STARTTOO_PUSH_ENABLED', false),
    'marketing_push_enabled' => (bool) env('STARTTOO_MARKETING_PUSH_ENABLED', false),
    'fcm_credentials' => env('STARTTOO_FCM_CREDENTIALS', '/run/secrets/starttoo/fcm.json'),
    'firebase_project_id' => env('STARTTOO_FIREBASE_PROJECT_ID', 'starttoo-edd0b'),
    'cdn_url' => env('STARTTOO_CDN_URL', 'https://6n86dw2k13558.edge.naverncp.com'),
    'google_client_id' => env('STARTTOO_GOOGLE_CLIENT_ID', '232633950959-ata4nqqoc7ghd3tgte5sc2jedlu5tifh.apps.googleusercontent.com'),
    // 개발용 초안은 별도 설정을 켠 환경에서만 노출하고 정식 문안 발행 시 버전을 올린다.
    'draft_terms_enabled' => env('STARTTOO_DRAFT_TERMS_ENABLED', false),
    'terms_version' => '2026-11-17-draft',
    // 공개 소셜의 운영 선행 조건을 검토하기 전에는 익명 조회를 열지 않는다.
    'guest_browsing_enabled' => env('STARTTOO_GUEST_BROWSING_ENABLED', false),
];
