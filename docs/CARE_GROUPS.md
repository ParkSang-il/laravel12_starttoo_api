# 공동 돌봄 그룹 API — 첫 구현

기본 경로는 `/api/v1/projects/streetcat`, 아래 모든 요청에 Bearer JWT가 필요하다. 현재 구현은 서버 API이며 Flutter 그룹 화면·실제 DB 배포는 아직 진행하지 않았다.

## 데이터와 권한

- 전용 streetcat DB에 care_groups, group_memberships, group_invitations, group_join_requests, group_audit_events를 추가한다. 과거 테이블/마이그레이션은 수정하지 않는다.
- 그룹은 UUID, 프로젝트/회원과의 관계는 복합 외래키로 보호한다. 생성자는 owner(운영자), 승인 역할은 caregiver(돌보미) 또는 viewer(열람자)다. 본문으로 owner를 부여할 수 없다.
- 그룹 생성 기본값은 비공개·공개 가입 신청 닫힘이다. 주변 검색에는 is_discoverable=true AND accepting_requests=true인 그룹만 나온다. 두 설정은 별도로 변경할 수 있다.
- 검색은 region_code가 같은 동네의 목록이다. **GPS 반경·거리 계산이나 위치 역지오코딩은 아직 구현하지 않았다.** region_code는 10자리 문자열 형식만 확인하며 행정구역 데이터셋과의 실제 일치 검증은 다음 작업이다. region_label에는 동 단위의 이름을 넣는다.
- 공개 응답은 id/name/description/region_code/region_label/공개·모집 여부만 포함한다. 회원 전화번호·목록, 좌표·사진·돌봄 일정은 포함하지 않는다. 운영자는 공개 이름·소개에 실제 급식소 주소/위치를 쓰지 않아야 한다.
- 초대는 기본 24시간·1회용(설정 범위 1~168시간, 1~10회). raw token은 발급 응답에만 반환하며 DB에는 SHA-256 해시를 저장한다. 토큰은 URL 대신 POST 본문으로 전달하고 로그에 남기지 않는다.
- phone을 지정하면 해당 인증 번호의 회원만 초대를 사용할 수 있다. 초대 DB에는 번호 원문 대신 APP_KEY 기반 HMAC을 저장한다. 전화번호 확인은 신뢰 심사를 대신하지 않는다.
- 초대를 사용하면 가입 신청만 생성된다. 초대 유효 사용 횟수는 **가입 신청 생성 시** 차감되며, 거절·취소해도 복구하지 않는다. 동일 신청자의 pending 재요청은 기존 신청을 반환하고 횟수를 추가 차감하지 않는다.
- 공개 모집이 닫혀 있어도 유효한 초대로 비공개 신청이 가능하다. 초대 취소/만료는 신규 신청을 막으며 이미 생성된 pending 신청은 운영자가 별도 거절한다.
- 일반 구성원은 승인 후에도 장소 권한이 자동으로 생기지 않는다. [장소별 권한 API](GROUP_PLACES.md)에서 운영자가 허용해야 한다. 운영자는 그룹 장소를 관리하므로 can_view_locations=true이고, 일반 구성원은 허용된 활성 장소가 하나라도 있을 때 true다. true가 모든 장소 열람 허가를 뜻하지는 않는다.
- 운영자만 신청 목록·회원 목록·초대 발급/취소·설정 변경·가입 심사·내보내기가 가능하다. 일반 가입자와 대기자는 다른 신청자의 정보에 접근할 수 없다.
- 운영자는 이관 기능이 구현되기 전까지 탈퇴/내보내기를 할 수 없다. 일반 회원의 자진 탈퇴는 재신청 가능하며, 내보내기는 removed 상태로 재신청을 차단한다. 제한 해제/운영자 이관은 다음 단계다.

## API 목록

| Method | 경로 | 요청 / 결과 |
|---|---|---|
| POST | /groups | 그룹 생성, 201 |
| GET | /groups/discover?region_code=...&page=1 | 모집 그룹 검색, 200 |
| GET | /me/groups | 본인의 active 그룹 목록, 200 |
| GET | /me/group-requests | 본인의 신청 상태/심사 안내, 최근 최대 100건 |
| GET | /groups/{group} | 공개 소개 + 본인 멤버십/신청. 비공개는 active 회원 또는 본인의 pending 신청이 있어야 조회 |
| PATCH | /groups/{group} | 운영자가 이름·소개·공개/모집 설정 변경 |
| POST | /groups/{group}/invitations | 초대 발급, 201 |
| POST | /invitations/preview | 초대 소개 확인. 소비하지 않으며 그룹 소개만 반환 |
| DELETE | /groups/{group}/invitations/{invitation} | 운영자의 초대 취소, 204 |
| POST | /groups/{group}/join-requests | 신규 신청 또는 기존 pending 반환, 200 |
| GET | /groups/{group}/join-requests | 운영자의 pending 신청 목록, 최대 100건 |
| POST | /groups/{group}/join-requests/{request}/review | 승인/거절, 200 |
| POST | /groups/{group}/join-requests/{request}/withdraw | 본인 신청 취소, 204 |
| GET | /groups/{group}/members | 운영자의 active 회원 목록, 최대 100건 |
| POST | /groups/{group}/leave | 본인 탈퇴, 204 |
| DELETE | /groups/{group}/members/{account} | 운영자의 내보내기, 204 |

지역 목록은 `data: {groups: [...], page: 1, has_more: false}`이고 한 페이지 20건이다. 내 그룹·내 신청·회원/신청 목록은 `data: [...]`, 나머지 조회/생성/심사 응답은 `data: {...}`다. 204 응답은 JSON 디코딩하지 않는다. 이번 단계에서 신청/회원 100건 이후 페이지 이동은 미구현이며, pending 신청은 앞선 건을 심사하면 뒤의 건이 목록에 나타난다.

### 그룹 생성

```json
{
  "name": "우리 동네 돌봄팀",
  "description": "주말 돌봄을 함께할 분을 찾습니다.",
  "region_code": "0000000000",
  "region_label": "테스트 동네",
  "is_discoverable": false,
  "accepting_requests": false
}
```

지역 값은 예시다. name 80자, description 500자, region_label 80자까지다. 응답의 `data.id`가 그룹 UUID다. 소개와 두 boolean은 생략 가능하다. 좌표·회원 권한 등 허용하지 않은 입력 필드는 422로 거부한다. 지역 정보 수정은 이번 PATCH 범위에 포함하지 않았다.

### 초대 발급 / 확인

발급 본문은 `{}` 또는 `{"expires_in_hours":24,"max_uses":1,"phone":"01012345678"}`. phone은 선택이다. 응답은 `data: {id, token, expires_at, max_uses}`다. 발급자가 token을 안전하게 복사해 전달한다. SMS/메신저 자동 발송과 초대 앱 딥링크는 아직 구현하지 않았다.

받은 사람이 `POST /invitations/preview`에 `{"token":"발급된64자리문자열"}`을 보내면 그룹 소개와 `requires_approval:true`가 온다. 유효하지 않은/만료/사용 완료/번호가 다른 초대는 모두 동일한 422 안내를 반환한다.

### 가입 신청

```json
{
  "message": "동네에서 저녁 돌봄에 참여하고 싶어요.",
  "availability": "평일 오후 7시 이후",
  "experience": "개인 돌봄 1년",
  "invitation_token": "초대신청일때만_발급된64자리문자열"
}
```

공개 그룹 신청은 invitation_token을 생략한다. message는 필수 500자, availability는 선택 200자, experience는 선택 300자다. 응답은 신청 id, group_id, status=pending, version=1, 신청 내용·시각·심사 안내다. **active 그룹 목록에는 승인 전까지 나타나지 않는다.** 대기 화면은 내 신청 API를 사용한다.

신청은 그룹+회원별 한 행이며 취소/거절 이후 재신청은 version을 증가시킨다. 이전 상태 전이는 감사 이벤트로 남긴다. 신청자가 보낸 역할·상태·임의 account_id는 거부한다. 심사자는 신청자의 자기 기재 내용과 별도의 관계 확인을 바탕으로 판단해야 한다. 앱 내 대화 API는 아직 없다.

### 승인 / 거절 / 신청 취소

운영자는 신청 목록에서 최신 id와 version을 읽은 뒤 전송한다.

```json
{"version":1,"decision":"approve","role":"caregiver","note":"동행 활동 후 참여 확인"}
```

거절은 `{"version":1,"decision":"reject","note":"먼저 운영자와 이야기해 주세요."}`이며 role을 보내지 않는다. note는 선택 300자다. 일반 신청자의 자기 승인, 임의 owner 승격을 허용하지 않는다. 본인 신청 취소는 withdraw에 `{"version":1}`을 보낸다.

그룹 행 잠금 → 권한 재검사 → 최신 신청 상태/version 검사 → 회원 생성/변경·신청 전이·감사 기록을 같은 트랜잭션으로 처리한다. 초대 사용/취소, 탈퇴/내보내기도 같은 그룹 행 잠금을 사용한다. 중복 심사·취소 뒤 승인·오래된 version은 409이며 새로 조회해야 한다. 실제 MySQL 동시성 검증은 별도로 필요하다.

## 배포와 검증

```bash
php artisan project:migrate streetcat --pretend
php artisan project:migrate streetcat
# production 배포 시 --force
php artisan route:list --path=api
php artisan test --filter=CareGroupTest
php artisan test
```

기존 인증 API는 유지한다. [groups.http](../groups.http)는 운영자/신청자 두 테스트 계정으로 비공개 그룹 생성부터 승인·내보내기·초대 취소까지 실행한다. 실제 테스트용 그룹/가입 신청을 만들므로 개발 DB에서 실행한다. 각 재실행은 인증 발급 제한 외에도 그룹 생성 시간당 3회, 가입 신청 시간당 10회 제한을 받는다.

현재 작업 환경에는 PHP/Composer/vendor가 없어 PHP 테스트·문법 검사·서버 DB 적용을 실행하지 못했다. 정적 코드/경로 검토와 HTTP 파일 검증 결과를 실행 통과로 간주하지 않는다. 다음은 Flutter 그룹 화면 → 장소/친구 권한 API 순서다.

공식 참고: [Laravel 입력 검증](https://laravel.com/docs/13.x/validation), [트랜잭션과 행 잠금](https://laravel.com/docs/13.x/queries#pessimistic-locking), [복합 외래키 구성](https://laravel.com/docs/13.x/migrations#foreign-key-constraints).
