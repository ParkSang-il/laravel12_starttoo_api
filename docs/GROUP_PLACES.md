# 그룹 돌봄장소와 장소별 접근 권한

## 적용 범위

이번 단계는 그룹 장소 등록·조회·수정·사용중지와 장소별 구성원 권한이다. 친구·돌봄 기록의 서버 저장/공유, 위치 인증, 오프라인 공유 캐시, 로컬 기록 이관은 아직 구현하지 않는다. API 기본 경로는 `/api/v1/projects/streetcat/groups/{group}`이며 모든 요청에 Bearer JWT가 필요하다.

## 권한 기준

| 구성원 | 장소 열람 | 장소 변경 | 권한 관리 |
|---|---|---|---|
| 운영자(owner) | 본인 그룹의 모든 활성 장소 | 등록·수정·사용중지 | 가능 |
| 돌보미(caregiver) | 명시적으로 허용받은 장소만 | 불가 | 불가 |
| 열람자(viewer) | 명시적으로 허용받은 장소만 | 불가 | 불가 |
| 가입 대기·미가입·탈퇴·내보내기 | 불가 | 불가 | 불가 |

구성원은 승인만으로 장소 권한이 생기지 않는다. 장소 권한은 none/view/care 세 값이며 viewer에는 care를 줄 수 없다. care는 다음 공유 돌봄 기록 단계에서 사용할 권한이며 현재 기록 API가 있다는 뜻은 아니다. 운영자는 별도 grant 없이 manage다.

그룹 공개 검색·초대 소개·가입 신청 응답에는 장소 이름·메모·좌표·장소 개수를 추가하지 않는다. 장소 API는 현재 active 회원인지 매번 검사하고, 일반 회원은 실제 허용된 장소만 SQL 단계에서 선택한다. 권한 없는 UUID와 존재하지 않는 UUID는 모두 404다.

can_view_locations=true는 운영자이거나 허용된 활성 장소가 하나 이상 있다는 뜻이다. 일반 구성원에게 모든 그룹 장소를 허용한다는 뜻은 아니다. 실제 판정은 장소 API가 수행한다.

탈퇴/내보내기는 같은 트랜잭션에서 모든 장소 grant를 삭제한다. 재가입 승인 시에도 이전 grant를 복구하지 않는다. 회원 승인·탈퇴·내보내기는 그룹의 활성 장소 version을 증가시켜 열린 권한 편집 화면의 과거 스냅샷을 무효화한다.

## API 계약

| Method | 경로 (그룹 기본 경로 뒤) | 기능 |
|---|---|---|
| GET | /places?page=1 | 허용된 장소 목록, 페이지당 20건 |
| POST | /places | 운영자 장소 등록, 201 |
| GET | /places/{place} | 허용된 장소 상세, 200 |
| PATCH | /places/{place} | 운영자 수정, 최신 version 필수 |
| DELETE | /places/{place} | 운영자 사용중지, JSON body version 필수, 204 |
| GET | /places/{place}/permissions?page=1 | 운영자의 구성원별 권한 목록, 페이지당 20명 |
| PATCH | /places/{place}/permissions/{account} | 운영자의 권한 부여/회수, access·version 필수 |

장소 등록 예:

```json
{"name":"그룹 돌봄장소","note":"함께 지킬 주의사항","latitude":null,"longitude":null,"interval_hours":24}
```

name 필수 80자, note 선택 500자, interval_hours 선택 정수 1~2160(기본 24)다. latitude/longitude는 함께 보내거나 생략한다. 둘 다 null이면 위치 없이 저장한다. 한쪽만 보내거나 null 여부가 다르면 422다. 위도 -90~90, 경도 -180~180만 허용한다. 좌표/메모는 앱의 저장 버튼을 누를 때 전송한다.

장소 응답 예:

```json
{"data":{"id":"장소UUID","group_id":"그룹UUID","name":"그룹 돌봄장소","note":"함께 지킬 주의사항","latitude":null,"longitude":null,"interval_hours":24,"version":1,"access":"manage","can_manage":true}}
```

목록은 `data: {places: [...], page: 1, has_more: false, can_manage: true}`다. 일반 구성원이 장소 권한을 하나도 받지 못했다면 빈 places와 can_manage=false를 반환한다. 접근 불가 장소의 이름·개수를 따로 반환하지 않는다.

수정은 변경할 필드와 version을 보낸다. version 외에 클라이언트에서 created_by/group_id/project_id/권한 등을 바꿀 수 없다. 좌표를 변경/해제할 때도 두 필드를 함께 보낸다.

권한 목록은 `data: {place_version: 3, page: 1, has_more: false, members: [{account_id: 2, display_name: "돌보미", role: "caregiver", access: "none"}]}`다. 운영자는 access=manage로 표시하며 변경할 수 없다.

권한 변경은 다음 본문을 보낸다.

```json
{"version":3,"access":"view"}
```

회수는 access=none이다. 응답은 `data: {place_version:4, account_id:2, access:"view"}`다. 권한 변경과 장소 수정은 공통 version을 증가시킨다. 오래된 version은 409로 거절하고 최신 정보를 다시 조회해야 한다. Flutter는 오래된 입력을 자동 재전송하거나 새 version만 끼워 덮어쓰지 않는다. 권한 페이지를 더 불러오는 중 version이 바뀌면 기존 행을 버리고 1페이지부터 다시 조회한다.

사용중지는 deleted_at 소프트 삭제이며 데이터/메모/좌표를 물리 삭제하지 않는다. 모든 해당 장소 권한을 회수하고 일반 목록·상세에서 숨긴다. 복구/사용중지 장소 관리 화면은 아직 없으므로 UI에서 확인을 거친다.

## 구현과 검증

기존 프로젝트별 DB 연결을 사용하며 group_places/group_place_permissions 테이블만 추가한다. 프로젝트·그룹·장소·회원 관계는 복합 외래키로 보호한다. 변경은 그룹 행 잠금 → 회원 상태/운영자 권한 재검사 → version 검사 → 장소·권한·감사 이력 저장 순서다. 기존 그룹 탈퇴/승인도 같은 그룹 행 잠금을 사용한다. 실제 MySQL 동시성 검증은 별도로 필요하다.

감사 이벤트에는 장소/대상 회원 ID·변경 필드명·version·권한만 남기며 좌표/메모 값은 넣지 않는다. 원본 보존과 감사 로그는 서버/DB 관리자에 대한 변조 불가능성을 보장하지는 않는다.

```bash
php artisan project:migrate streetcat --pretend
php artisan project:migrate streetcat
# production의 승인된 배포 절차에서는 --force
php artisan test --filter=GroupPlaceTest
php artisan test
```

장소 HTTP 테스트는 루트 places.http를 사용한다. groups.http로 두 계정의 그룹 가입/승인까지 준비한 뒤, 내보내기(19번)를 실행하기 전에 places.http를 실행한다. 이 테스트는 개발용 그룹/위치 데이터를 저장·사용중지한다.

Flutter에서는 그룹 상세 → 그룹 돌봄장소에서 서버 목록을 조회한다. 운영자의 신규 등록/수정 화면은 기존 카카오 위치 선택기를 재사용한다. 공유 장소를 기기의 개인 장소 DB와 섞거나 자동 백업하지 않는다. 목록·상세는 앱 복귀/새로고침·화면 재진입 시 권한을 다시 확인하고, 실패 시 이전 상세 위치를 화면에서 지운다. 이미 본 위치·캡처까지 원격 회수할 수는 없고 서버가 오프라인 기기에 즉시 권한 회수를 알리는 기능은 아직 없다.

현재 개발 Mac에는 PHP/Composer/vendor가 없어 PHP 실행 테스트와 DB 적용은 수행하지 못했다. 서버에서 위 명령으로 확인해야 한다. 공식 참고: [Laravel 행 잠금](https://laravel.com/docs/13.x/queries#pessimistic-locking), [외래키](https://laravel.com/docs/13.x/migrations#foreign-key-constraints).
