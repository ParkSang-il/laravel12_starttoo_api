# 프로젝트별 데이터베이스 분리

## 현재 구조

```text
Flutter → HTTPS /api/v1/projects/streetcat/... → 서버 allowlist
                                                ↓
                                   streetcat 연결 / MySQL streetcat DB
                                   ├ migrations
                                   ├ projects (streetcat 1행)
                                   ├ project_accounts
                                   └ project_phone_challenges
```

현재 config/platform.php의 projects에는 streetcat만 등록돼 있다. 중앙 프로젝트/회원 DB를 새로 만들지 않는다. 서버 설정이 사용 가능한 프로젝트의 목록이고, 각 앱 DB의 projects 행이 해당 앱의 이름·활성 상태를 관리한다. 미등록 코드는 DB 조회 전에 404다.

config/database.php의 streetcat 연결은 STREETCAT_DB_*만 사용한다. 기존 DB_URL/DB_DATABASE/DB_USERNAME으로 폴백하지 않는다. 기본 DB 연결을 요청마다 바꾸거나 클라이언트의 database 파라미터로 연결을 만들지 않는다. 프로젝트 문맥이 없는 모델 접근은 예외로 차단한다.

각 요청은 ResolveProject → 서버 설정 연결 선택 → 해당 DB의 projects 확인 → 회원 로드 → JWT project_code/project_id 대조 순서다. 요청 종료·예외 시 문맥과 인증 Guard를 정리한다. 동일한 서버의 연속 요청에서 앞선 앱의 회원을 재사용하지 않는다.

## 식별자와 경계

| 값 | 범위 / 용도 |
|---|---|
| project_code | 서버 전체에서 앱 구분. 현재 streetcat |
| project_id | 앱 DB 안의 projects PK. 다른 DB에서도 같은 숫자일 수 있음 |
| account.id | 앱 DB 안에서만 유일. 전역 사용자 ID로 사용하지 않음 |
| JWT | project_code + project_id + sub 검사. 다른 DB에 같은 회원 ID가 있어도 접근 거부 |
| 캐시/로컬 저장 | project_code와 account.id 조합으로 분리 |

프로젝트 코드는 비밀 키가 아니다. URL에 streetcat을 적는다고 로그인이나 돌봄 장소 권한을 얻는 것은 아니다. 앱의 회원 가입과 공동 돌봄 그룹 가입·장소 승인은 별도 절차다.

Project/ProjectAccount는 UsesProjectDatabase trait를 사용하고, 휴대폰 인증 서비스는 ProjectDatabase의 명시적 연결로 트랜잭션/조회한다. 인증번호·발급 제한·회원 상태도 각각의 DB에 저장된다. 향후 업무 모델, 조인, 파일 경로, 알림/큐, 통계와 리포트에도 같은 경계를 적용해야 한다. 큐 작업은 프로젝트 코드를 직렬화하고 처리 시 run(code, callback)으로 문맥을 설정한다.

DB를 나누는 것은 데이터 저장 단위의 분리다. 서버 프로세스는 설정된 여러 DB 접속 정보를 보유할 수 있으므로 독립 서버 수준의 장애·침해 격리를 뜻하지 않는다. DB 사용자도 앱별로 구분하고 각 DB에만 권한을 준다. 별칭/프록시 주소까지 포함한 모든 잘못된 운영 설정을 코드가 탐지할 수는 없으므로 배포 시 실제 접속 DB를 점검한다.

## 마이그레이션과 기존 데이터

앱 DB에는 `php artisan project:migrate streetcat`만 사용한다. 이 명령은 database/project-migrations만 실행하고 마이그레이션 이력도 해당 DB에 남긴다. 기본 database/migrations는 과거 서비스 이력 보존용이며 신규 앱 DB에 실행하지 않는다. `--pretend`로 SQL을 미리 확인할 수 있다.

이번 변경은 기존 starttoo DB와 테이블·회원·파일을 자동 복사/삭제하지 않는다. 실제 DB 생성, 테이블 생성과 streetcat 행 등록은 서버 명령 실행이 필요하다. 기존 통합 DB에 새 API로 기록한 회원이 있다면 아래 별도 이관 절차가 필요하다.

1. 백업하고 쓰기 중지 시점을 정한다.
2. streetcat에 해당하는 projects, project_accounts만 추출하고 대상 DB의 PK·관계 충돌을 검토한다.
3. 대상 DB에 마이그레이션을 먼저 적용하고, 명시적 이관 스크립트로 프로젝트/회원 값을 맞춘다.
4. 인증번호는 이관하지 않고 폐기한다. 기존 프로젝트 코드 없는 JWT는 허용하지 않으며 재로그인한다.
5. 건수·관계·회원 상태를 검증한 뒤 연결을 전환한다. 원본 백업을 보관한다.

실제 기존 데이터가 확인되지 않아 이관 스크립트는 구현/실행하지 않았다. 루트의 과거 마이그레이션은 그대로 보존했다.

## 다음 앱 추가 시

지금은 추가하지 않는다. 나중에 앱 전용 DB/DB 사용자 생성 → 별도 환경변수와 이름 있는 connection 추가 → config/platform.php allowlist 추가 → config 캐시 갱신 → project:migrate 코드 → project:create 코드 순서로 등록한다. 서로 다른 프로젝트가 같은 연결 또는 같은 주소·DB 이름을 사용하는 일반적인 설정 오류는 차단한다.

공식 참고: [Laravel 다중 연결](https://laravel.com/docs/13.x/database#using-multiple-database-connections).
