# 기존 탭의 공동돌봄 통합
배포 후 `php artisan project:migrate streetcat --force`로 000008 적용.
기준: /api/v1/projects/streetcat/groups/{group}/places/{place}/care-records
GET ?page=1: 현재 활성 계정/그룹 가입/장소 열람 권한 확인. data.records/page/has_more. 20개/페이지.
POST: 운영자 또는 장소 care 권한 돌보미만 작성. request_id(UUID), fed_at(ISO8601), cost(0~100000000), tags(최대30개), sightings(친구 UUID:seen/notSeen/unknown), 선택적 food_amount_gram, memo(500자), latitude/longitude/gps_accuracy.
친구는 그 장소에 현재 연결된 active/missing만 허용. 작성자는 인증 계정에서 지정하며 본문으로 바꿀 수 없음.
그룹 잠금으로 권한 회수와 쓰기를 직렬화한다. 그룹+작성자+request_id가 같고 본문이 같으면 기존 기록 반환. 본문이 다르면409. 새로운 돌봄은 새 요청 키 사용.
개인 비용 테이블과 연결하지 않는다. 그룹 조회 응답은 GPS를 노출하지 않음. 기록 수정/삭제와 사진 업로드는 현재 미제공.
Flutter는 권한 있는 장소·친구·기록을 메모리에만 유지하고 로그아웃/계정 변경/복귀 시 초기화하여 재조회한다. 한 장소 조회가 실패해도 오래된 위치를 남기지 않으며 로컬 탭은 계속 사용 가능.
서버 검증: `php artisan test --filter=GroupPlaceTest`. 로컬 PHP 런타임이 없어 미실행.
