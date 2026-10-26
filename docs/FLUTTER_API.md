# Flutter ↔ streetcat API 계약

## 공통

- 기본 경로: `https://API도메인/api/v1/projects/streetcat`
- `Accept: application/json`, JSON 요청은 `Content-Type: application/json`.
- 인증이 필요한 API: `Authorization: Bearer <access_token>`.
- project_id/project_code/database/connection을 본문·쿼리로 보내지 않는다. 프로젝트는 URL에서만 선택한다.
- 응답 성공은 `{ "data": ... }`, 유효성 오류는 Laravel의 `{ "message": ..., "errors": ... }`. 모든 오류에 errors가 있는 것은 아니다.
- 시간은 JSON 문자열이다. Flutter는 DateTime.parse로 읽고 표시할 때 로컬 시각으로 변환한다. expires_in은 초다.
- 서버 권한 검사가 기준이며 앱에서 버튼을 숨기는 것으로 권한을 대신하지 않는다.

## 지금 구현된 API 5개

| 기능 | Method | 경로 (기본 경로 뒤) | 인증 | 성공 |
|---|---|---|---|---|
| 인증번호 요청 | POST | /auth/phone/request | 없음 | 201 |
| 인증 확인 및 자동 가입/로그인 | POST | /auth/phone/verify | 없음 | 200 |
| 내 회원 정보 | GET | /me | JWT | 200 |
| 내 표시 이름 수정 | PATCH | /me | JWT | 200 |
| 로그아웃 | POST | /auth/logout | JWT | 204, 본문 없음 |

서버 생존 확인은 별도 `GET /up`. 회원가입 API를 따로 부르지 않는다. 인증 확인 성공 시 해당 프로젝트에 회원이 없으면 생성한다. 상태가 정지된 회원은 새 인증번호로 로그인할 수 없다.

### 1. 인증번호 요청

```http
POST /api/v1/projects/streetcat/auth/phone/request
Content-Type: application/json
Accept: application/json

{"phone":"01012345678"}
```

현재 국내 010 번호 11자리만 받는다. 하이픈과 공백은 앱에서 제거한다. 응답 예:

```json
{"data":{"challenge_id":"e7b4383a-c98c-4c90-86fa-e3fc08c2cffe","expires_in":180,"development_code":"123456"}}
```

development_code는 개발 preview에서만 존재한다. 앱은 운영 계약으로 의존하지 않고 사용자가 SMS로 받은 코드를 입력하게 한다. **실제 SMS 공급자는 미연결이므로 현재 운영 발급은 503이다.** 개발 중에는 응답의 development_code로 테스트할 수 있다.

제한: 같은 프로젝트·번호 60초 간격 / 3시간 5회. IP 제한은 여러 프로젝트에서 공유한다. 인증번호는 3분 유효하며 재발급하면 이전 미사용 인증번호는 무효다.

### 2. 인증 확인 및 로그인

```json
{"challenge_id":"e7b4383a-c98c-4c90-86fa-e3fc08c2cffe","code":"123456"}
```

`POST /auth/phone/verify`에 위 본문을 보낸다. 번호를 다시 보내지 않으며 발급 응답의 challenge_id를 사용한다. 코드 불일치 최대 5회, 사용한 인증번호는 재사용할 수 없다.

```json
{
  "data": {
    "access_token": "JWT_문자열",
    "token_type": "Bearer",
    "expires_in": 3600,
    "account": {
      "id": 1,
      "project_id": 1,
      "project_code": "streetcat",
      "display_name": null,
      "status": "active",
      "phone_verified_at": "2026-10-26T10:00:00.000000Z",
      "created_at": "2026-10-26T10:00:00.000000Z",
      "updated_at": "2026-10-26T10:00:00.000000Z"
    }
  }
}
```

값은 예시이며 TTL은 서버 JWT_TTL 설정에 따른다. 응답에는 phone이 없다. access_token은 보안 저장소에 저장하며 프로젝트와 회원 ID를 로컬 데이터 키에 함께 사용한다. 신규 회원의 display_name은 null일 수 있다.

### 3. 내 정보 / 자동 로그인 확인

`GET /me`에 JWT를 붙인다. 응답은 `{ "data": { ...위 account 필드... } }`이다. 앱 시작 시 토큰이 있으면 이 API로 로그인 상태를 확인한다. JWT 내용은 UI 참고용으로만 사용하고 서명 검증·권한 판정은 서버에 맡긴다.

### 4. 내 표시 이름 수정

`PATCH /me`에 JWT와 다음 본문을 보낸다.

```json
{"display_name":"나비 돌보미"}
```

필수 문자열, 최대 50자다. 응답은 수정된 회원 정보 `{ "data": { ... } }`이다. 전화번호·회원 소속·정지 상태·역할을 수정하는 API가 아니다.

### 5. 로그아웃

`POST /auth/logout`에 JWT를 보내면 204로 응답한다. 204에는 JSON 디코딩을 시도하지 않는다. 서버에서 해당 JWT를 폐기하고 앱은 보안 저장소의 토큰과 로그인 상태를 지운다. 통신 실패로 서버 폐기를 확인하지 못했다면 로컬 로그아웃과 서버 폐기를 구분하고, 토큰을 로그에 출력하지 않는다.

refresh API·기기별 세션 목록·번호 변경/복구 API는 아직 없다. 토큰 만료 후에는 휴대폰 재인증한다. 만료 시 무한 재요청이나 존재하지 않는 refresh 경로 호출을 하지 않는다.

## Flutter 연결 순서

1. API origin과 projectCode=streetcat을 환경 설정으로 관리한다. DB 접속 정보나 JWT_SECRET을 앱에 넣지 않는다.
2. 전화번호 요청 → challenge_id와 expires_in을 메모리에 저장 → 코드 입력 화면 표시.
3. verify 성공 → access_token을 플랫폼 보안 저장소에 저장 → account를 상태에 반영.
4. JWT를 /me, PATCH /me, logout 요청에만 붙인다. 인증번호 요청/검증에는 토큰이 필요 없다.
5. 재실행 시 /me로 확인한다. 401이면 재인증, 403이면 소속/정지 안내, 429이면 재시도 대기를 표시한다.
6. 공동 돌봄 그룹·장소 승인이 구현되기 전에는 로그인 성공을 장소 열람 허가로 해석하지 않는다.

iOS/Android 기기에서 localhost는 Mac/서버가 아니라 기기 자신이다. 실제 접속 가능한 API 도메인을 사용하고 운영은 HTTPS로 연결한다. 인증번호·JWT·전화번호가 디버그 네트워크 로그에 출력되지 않도록 처리한다.

## 오류 처리

| HTTP | 의미 | 앱 처리 |
|---|---|---|
| 401 | 토큰 없음·만료·폐기 | 기존 로그인 토큰 제거 후 인증 화면 |
| 403 | 다른 프로젝트 토큰·정지 회원 등 보호 API 접근 불가 | 접근 제한 안내. 반복 재로그인 방지 |
| 404 | 미등록/비활성 프로젝트 또는 없는 경로 | base URL/project 설정 확인 |
| 422 | 입력 오류, 유효하지 않은/만료/사용한 코드, 정지 계정 인증 등 | 메시지 표시·입력 수정/재발급. 원인을 특정하지 않는 인증 실패도 있음 |
| 429 | 인증 요청·검증·일반 API 제한 | 대기 안내. Retry-After가 있으면 사용, 없으면 UI 정책으로 대기 |
| 503 | SMS 미설정/운영 preview 금지 | 서비스 준비 중 안내, 자동 반복 발급 금지 |
| 5xx/네트워크 | 서버 오류·연결 실패 | 재시도 안내. 발급/검증 요청은 결과 불명 시 자동 무한 재전송 금지 |

## 공동 돌봄 그룹 API — 서버 코드 추가, 배포 후 사용

동네 모집 검색·내 그룹·가입 신청·초대·운영자 심사·탈퇴/내보내기 API는 [그룹 API 계약](CARE_GROUPS.md)에 정리했다. 같은 기본 경로와 Bearer JWT를 사용한다. `php artisan project:migrate streetcat` 적용 후 호출한다. 그룹 응답의 data는 API별로 객체 또는 배열이므로 Flutter 인증 클라이언트의 객체 전용 파서와 구분해야 한다. Flutter 그룹 화면은 아직 연결하지 않았다.

## 후속 API — 아직 호출 불가

그룹 돌봄장소 등록·조회·수정·사용중지·장소별 구성원 권한은 [장소 API 계약](GROUP_PLACES.md)에 추가했다. 로그인만 하거나 그룹에 가입한 것만으로 장소를 조회할 수 없다.

| 구현 예정 영역 | 필요한 기능 |
|---|---|
| 그룹 고도화 | 운영자 이관·앱 내 대화·신청자 제한 해제·초대 딥링크 |
| 장소 고도화 | 공유 돌봄 기록·주기 알림·오프라인 동기화 |
| 친구 | 공유 목록·검색·상세·여러 장소 연결 |
| 돌봄·목격 | 기록 등록/수정/삭제·타임라인·사진 |
| 건강·TNR·생애주기 | 관찰 기록·상태 변경 제안·독립 승인·이력 |
| 비용·사진·리포트·통계 | 권한 범위 내 데이터 CRUD·업로드·집계·PDF |
| 동기화 | 변경 커서·중복 요청 방지·삭제/권한 회수 반영 |

이 표는 작업 범위이며 현재 서버 라우트가 아니다. 기존 Flutter 로컬 DB 화면은 이번 작업에서 서버와 연결하지 않았다. 승인·데이터 권한 API를 구현한 뒤 기능별로 연결한다.
