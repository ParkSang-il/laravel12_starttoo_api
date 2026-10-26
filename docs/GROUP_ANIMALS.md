# 그룹 친구 공유

배포 후 `php artisan project:migrate streetcat --force` (000005, 000006).
개인 SQLite 친구를 전송하지 않습니다. 그룹 친구는 서버에 별도로 등록합니다.

기준 경로: `/api/v1/projects/streetcat/groups/{group}/places/{place}/animals`
JWT 인증 필요. 모든 조회는 현재 그룹 가입 상태, 계정 활성 상태, 장소 열람 권한을 확인합니다.
운영자만 생성/수정/연결/해제 및 연결 가능한 친구 목록 조회를 할 수 있습니다.
돌보미와 열람자는 권한이 있는 장소의 친구 정보만 볼 수 있고 다른 장소 ID는 받지 않습니다.

| 메서드 | 경로 | 본문/쿼리 |
|---|---|---|
| GET | 기준 경로 | page=1 (20개) |
| GET | /available | page=1, 현재 장소에 미연결인 같은 그룹 친구 (운영자) |
| POST | 기준 경로 | name 필수; coat, age(추정 나이, 최대80자), sex(unknown/male/female), neutered, life_status, note 선택 |
| PATCH | /{animal} | version 필수, 수정할 필드 |
| POST | /link | animal_id, version |
| DELETE | /{animal} | version |

life_status: active / missing / rescued / moved / passedAway.
조회 응답 data: animals, can_manage, page, has_more.
생성/수정/연결 응답 data: id, group_id, name, coat, age, sex, neutered, life_status, note, version.
같은 그룹의 여러 장소에 동일 친구를 연결합니다. 수정은 연결된 모든 장소에 반영됩니다.
DELETE는 해당 장소 연결만 해제합니다. 친구와 다른 연결은 보존되며 available에서 다시 연결할 수 있습니다.
장소 중지 시 친구 데이터는 보존하고 장소 권한으로 조회를 차단합니다.

변경은 그룹 잠금으로 탈퇴/권한 회수와 직렬화하며, version 불일치는409입니다.
클라이언트는409를 자동 재전송하지 않고 새로 조회합니다.
변경 감사 이벤트에는 행위자, 친구/장소 ID, 변경 필드명 및 버전만 남깁니다. 사적인 메모 값은 기록하지 않습니다.

Flutter: 그룹 장소 → 이 장소의 친구들. 운영자는 친구 등록 / 기존 친구 연결 / 정보 수정 / 연결 해제.
백그라운드 복귀 시 목록을 비우고 권한을 재확인하며 편집 중에는 목록으로 돌아가 재확인합니다.
현재 사진 업로드, 상태 변경 제안·승인, 돌봄 기록 공유는 포함하지 않습니다.

검증: `php artisan test --filter=GroupPlaceTest`.
로컬 PHP 런타임이 없어 서버 Feature 테스트는 작성만 했으며 미실행입니다.

## 로컬 친구 가져오기 (000007)
POST 기준 경로/import, 동일 기본 필드 + source_local_id(필수, 최대80자).
운영자만 가능. source_account_id는 서버 인증 계정에서 지정하며 클라이언트 지정은 거부합니다.
응답 data: animal, created, linked. 항상200입니다.
그룹+인증 계정+로컬 ID로 재시도를 중복 방지합니다. 재등록은 기존 그룹 정보를 덮어쓰지 않고 필요한 장소 연결만 추가합니다.
동일 이름은 자동 병합하지 않습니다. 기존 친구 연결 기능으로 먼저 중복 여부를 확인합니다.
사진·메모를 제외한 기본 정보가 기본 공유 범위이며 메모는 사용자가 선택해야 합니다. 사진·위치·기록 파일은 이 API로 전송할 수 없습니다.
원본 로컬 친구를 삭제하거나 자동 동기화하지 않습니다. 서버 source 식별자는 열람자 응답에 노출하지 않습니다.
