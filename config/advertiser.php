<?php

/*
| 외부 광고주 웹 (AW-01 문의 · AW-02 견적서 · AW-03 성과 보고 · 광고주 계정)
| 광고 자리 · CPM 단가는 운영 콘솔 「광고 문의 · 계약 → 단가표」(starttoo_settings ad_external_rates)에서 바꾼다.
*/

return [
    // 새 문의 · 견적 수락 · 입금 알림을 받을 운영 메일
    // .env 에 키만 있고 값이 비어 있어도(빈 문자열) 기본값을 쓴다

    // 메일 · 콘솔에 넣는 광고주 링크의 주소 앞부분. 서버 APP_URL(다른 프로젝트와 공유)과 따로 둔다.
    // 스케줄러(성과 보고 · 최종 보고 메일)처럼 요청이 없는 곳에서도 이 주소로 링크를 만든다.
    'url' => rtrim(env('ADVERTISER_URL') ?: 'https://www.starttoo.com', '/'),

    'ops_email' => env('ADVERTISER_OPS_EMAIL') ?: 'ads@starttoo.kr',

    // 화면 하단 · 견적서 '보내는 곳'
    'company' => [
        'name' => env('ADVERTISER_COMPANY_NAME') ?: '(주)스타투',
        'ceo' => env('ADVERTISER_COMPANY_CEO') ?: '○○○',
        'biz_no' => env('ADVERTISER_COMPANY_BIZ_NO') ?: '000-00-00000',
        'mail_order_no' => env('ADVERTISER_COMPANY_MAIL_ORDER_NO') ?: '0000-서울마포-0000',
        'contact' => env('ADVERTISER_COMPANY_CONTACT') ?: 'ads@starttoo.kr · 평일 10:00 ~ 18:00',
    ],

    // 견적 수락 후에만 보여주는 입금 계좌
    'bank' => [
        'name' => env('ADVERTISER_BANK_NAME') ?: '○○은행',
        'account' => env('ADVERTISER_BANK_ACCOUNT') ?: '000-000000-00-000',
        'holder' => env('ADVERTISER_BANK_HOLDER') ?: '(주)스타투',
    ],

    // 화면 하단 링크(비워 두면 숨김)
    'privacy_url' => env('ADVERTISER_PRIVACY_URL') ?: null,

    'durations' => ['1주', '2주', '4주', '상담 후 결정'],
    'budgets' => ['100만 원 미만', '100~300만 원', '300~500만 원', '500만 원 이상', '아직 몰라요'],
    'industries' => ['타투 용품 · 애프터케어', '뷰티 · 스킨케어', '패션 · 액세서리', '음악 · 공연 · 전시', '기타'],

    // 개인정보 보관 기간 (동의 문구와 정리 작업에 같이 씀)
    'retention' => ['inquiry_days' => 365, 'contract_years' => 5],

    // AW-01 왼쪽 수치 · 상품 소개 하루 평균 노출 캐시
    'stats_cache_minutes' => 60,

    // 견적 기본값
    'quote_valid_days' => 7,

    // 비밀번호 설정 · 재설정 메일 링크 유효 시간(분)
    'password_link_minutes' => 60,
];
