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
    // 상점 주소 → 좌표 변환(카카오 로컬 API). 서버에만 둔다.
    'kakao_rest_key' => env('STARTTOO_KAKAO_REST_KEY'),
    // Phase 3 상담·채팅. false면 상담 시작·메시지 전송을 서버에서 막는다(조회는 허용).
    'consultation_enabled' => (bool) env('STARTTOO_CONSULTATION_ENABLED', true),
    // 앱이 접속할 Reverb 공개 주소(nginx 443). 비우면 REVERB_HOST를 쓴다.
    'realtime_host' => env('STARTTOO_REALTIME_HOST', env('REVERB_HOST')),
    'realtime_port' => (int) env('STARTTOO_REALTIME_PORT', 443),
    'realtime_scheme' => env('STARTTOO_REALTIME_SCHEME', 'https'),
    // 네이버 로그인 사용 여부. 앱 등록 전에는 꺼 둔다.
    'naver_enabled' => (bool) env('STARTTOO_NAVER_ENABLED', false),
    // Apple 로그인 identity token의 aud(iOS 번들 ID).
    'apple_client_id' => env('STARTTOO_APPLE_CLIENT_ID', 'com.pju2251.starttoo'),
    // 탈퇴 시 Apple 연결 해지용 Sign in with Apple 키(.p8). 키 원문은 파일로만 둔다.
    'apple_team_id' => env('STARTTOO_APPLE_TEAM_ID', 'T42QXLMFA4'),
    'apple_key_id' => env('STARTTOO_APPLE_KEY_ID'),
    'apple_private_key' => env('STARTTOO_APPLE_PRIVATE_KEY', '/run/secrets/starttoo/apple_signin.p8'),
    // 공유 링크 → 앱 열기(Android App Links). 앱 서명 인증서 SHA-256 지문(공개 값). 쉼표로 여러 개(디버그 · 배포 · Play 서명).
    'android_package' => env('STARTTOO_ANDROID_PACKAGE', 'com.pju2251.starttoo'),
    'android_cert_sha256' => env('STARTTOO_ANDROID_CERT_SHA256', ''),
    // 공유 링크 미리보기 페이지의 앱 받기 버튼(출시 후 채운다)
    'app_store_url' => env('STARTTOO_APP_STORE_URL', ''),
    'play_store_url' => env('STARTTOO_PLAY_STORE_URL', ''),
    // 앱 업데이트 안내(GET /v1/projects/starttoo/app/version). 비우면 안내하지 않는다.
    // min_version 보다 낮으면 강제 업데이트, latest_version 보다 낮으면 권장 업데이트(예: 1.2.0)
    'app_min_version' => env('STARTTOO_APP_MIN_VERSION', ''),
    'app_latest_version' => env('STARTTOO_APP_LATEST_VERSION', ''),
    // 업데이트 화면 안내 문구(비우면 앱 기본 문구) · 권장 안내 「나중에」 후 다시 띄우지 않는 일수
    'app_update_message' => env('STARTTOO_APP_UPDATE_MESSAGE', ''),
    'app_soft_snooze_days' => (int) env('STARTTOO_APP_SOFT_SNOOZE_DAYS', 7),
    // 점검 모드: 문구를 넣으면 모든 앱이 점검 화면으로 막힌다(비우면 해제)
    'app_maintenance_message' => env('STARTTOO_APP_MAINTENANCE_MESSAGE', ''),
    // 운영 콘솔(/admin): 접속 허용 IP(쉼표로 여러 개, CIDR 가능, 비우면 제한 없음) · 미사용 자동 로그아웃(분)
    'admin_allowed_ips' => env('STARTTOO_ADMIN_ALLOWED_IPS', ''),
    'admin_idle_minutes' => (int) env('STARTTOO_ADMIN_IDLE_MINUTES', 60),    // 개발용 초안은 별도 설정을 켠 환경에서만 노출하고 정식 문안 발행 시 버전을 올린다.
    'draft_terms_enabled' => env('STARTTOO_DRAFT_TERMS_ENABLED', false),
    'terms_version' => '2026-11-17-draft',
    // 공개 소셜의 운영 선행 조건을 검토하기 전에는 익명 조회를 열지 않는다.
    'guest_browsing_enabled' => env('STARTTOO_GUEST_BROWSING_ENABLED', false),
    // 아티스트 신청: 법정 문신사 면허 제도 시행 후 true로 바꾸면 면허 번호·자격증 사진을 필수로 받는다.
    'artist_license_required' => (bool) env('STARTTOO_ARTIST_LICENSE_REQUIRED', false),
    // 휴대폰 본인인증 제공자. dev는 개발 서버 전용(입력한 이름·번호를 그대로 인증 처리), 운영은 실명인증 업체 연동 후 지정.
    'identity_provider' => env('STARTTOO_IDENTITY_PROVIDER'),
    // 테스트용: true면 작가가 시술 시각 전에도 「시술 완료」를 누를 수 있다. 운영에서는 false.
    'booking_complete_anytime' => (bool) env('STARTTOO_BOOKING_COMPLETE_ANYTIME', false),
    // 가상 타투 피팅(나노바나나 = Gemini 이미지 모델). 키는 서버 .env에만 둔다(앱에 넣지 않음).
    'fit_enabled' => (bool) env('STARTTOO_FIT_ENABLED', true),
    'gemini_api_key' => env('STARTTOO_GEMINI_API_KEY', env('STARTTOO_NANOBANANA_API_KEY')),
    'gemini_image_model' => env('STARTTOO_GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
    // 가상 피팅 합성(도안을 피부에 입히기)만 쓸 상위 모델. 비우면 위 이미지 모델을 쓴다.
    // 예) gemini-3-pro-image(Nano Banana Pro) · gemini-3.1-flash-image(Nano Banana 2)
    // 피부 영역 찾기 · 가상 모델 만들기는 위 이미지 모델 그대로(비용 절약).
    'gemini_blend_model' => env('STARTTOO_GEMINI_BLEND_MODEL'),
    // 이미지 AI 응답을 기다리는 시간(초). 큐 작업 제한(ProcessStarttooFitResult 180초)보다 짧아야 한다.
    'gemini_image_timeout' => (int) env('STARTTOO_GEMINI_IMAGE_TIMEOUT', 140),
    // 피부 영역 분할(마스크)용 텍스트 모델 — 하루 AI 횟수에 세지 않는다
    // gemini-flash-latest = Google 이 최신 flash 모델로 바꿔 주는 이름(특정 버전이 종료돼도 계속 동작)
    'gemini_text_model' => env('STARTTOO_GEMINI_TEXT_MODEL', 'gemini-flash-latest'),
    // 피부 영역 분할 응답을 기다리는 시간(초). 넘으면 분할은 건너뛰고 이미지 마스크로 찾는다.
    'gemini_segment_timeout' => (int) env('STARTTOO_GEMINI_SEGMENT_TIMEOUT', 25),
    // 가상 모델 사진: 기본은 운영자가 올린 사진만(starttoo:fit-models-import). true면 없는 조건을 나노바나나로 생성.
    'fit_model_ai' => (bool) env('STARTTOO_FIT_MODEL_AI', false),
    // 회원당 하루 AI 횟수(가상 피팅 · 도안 추출 · 필러 · 커버업 공통, 실패는 세지 않음). 0 = 제한 없음
    'fit_daily_limit' => (int) env('STARTTOO_FIT_DAILY_LIMIT', 3),
    // 내 사진의 피부 영역 찾기(배치 화면을 열 때마다 1번, 이미지 1장 비용)를 회원당 하루 몇 번. 0 = 제한 없음
    'fit_skin_daily_limit' => (int) env('STARTTOO_FIT_SKIN_DAILY_LIMIT', 3),
    // 타투 필러(3안 = 1회 차감)를 하루 몇 번 만들 수 있는지. 0 = 제한 없음
    'fit_fill_daily_limit' => (int) env('STARTTOO_FIT_FILL_DAILY_LIMIT', 1),
];
