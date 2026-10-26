# 현재 위치 주변 그룹 찾기

배포 시 `php artisan project:migrate streetcat --force`를 먼저 실행합니다.
마이그레이션 000004는 care_groups에 공개 활동 동네 기준점을 추가하며 기존 데이터를 삭제하지 않습니다.

인증 필요: `POST /api/v1/projects/streetcat/groups/nearby`
본문: `{ "latitude": 37.56, "longitude": 126.92, "radius_m": 1000, "page": 1 }`
반경 500~2000m, 페이지당 20개. 응답 data.groups, has_more, page, radius_m.
공개 및 모집 중이고 기준점이 있는 그룹만 조회됩니다. 거리(distance_m)는 최소250m, 250m 단위 근삿값입니다.

생성/수정에 discovery_latitude, discovery_longitude를 함께 보낼 수 있습니다.
앱은 선택된 활동 동네 이름을 지오코딩합니다. 서버는 소수점 두 자리로 반올림하여 저장하고 응답에는 좌표를 내보내지 않습니다.
실제 돌봄장소 좌표를 공개 검색에 사용하지 않습니다. 검색자의 위치는 저장하지 않습니다.
기존 그룹은 운영자가 그룹 설정을 한 번 저장하면 기준점이 등록됩니다. 그 전에도 동네 이름 검색은 가능합니다.
대략적인 기준점이므로 반경 경계에서 검색 결과에 오차가 있을 수 있습니다.

제한: 인증 사용자별 분당30회. 위치 접근 거부 시 동네 이름 검색을 사용합니다.
PHP 런타임이 없는 개발 환경에서는 Feature 테스트를 실행하지 못했습니다.
서버에서 `php artisan test --filter=CareGroupTest`로 확인합니다.
