# streetcat 서버 설치 및 사용법

## 1. 적용 범위

API 프로젝트 루트에서 실행한다. 기본 등록 대상은 `streetcat` 하나이며 전용 MySQL DB 이름도 기본값 `streetcat`이다. PHP/Composer와 MySQL이 필요하다. composer.json/lock에 지정된 실제 버전을 사용하며, 이번 변경은 프레임워크 업그레이드·다운그레이드를 하지 않는다.

서버에는 아직 명령을 실행하지 않았다. 아래 과정으로 DB 생성/프로젝트 등록을 완료해야 실제 요청이 동작한다. 운영 DB에 `migrate:fresh` / `migrate:refresh` / 무분별한 rollback을 실행하지 않는다.

## 2. DB와 계정 준비

기존 MySQL 데이터는 먼저 백업한다. 관리자 계정으로 [생성 SQL](../database/sql/create_streetcat_database.sql)을 실행하면 streetcat DB만 생성된다. 기존 starttoo DB는 삭제하지 않는다.

```sql
CREATE DATABASE IF NOT EXISTS streetcat
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

배포 환경의 보안 설정에서 별도 DB 사용자를 만들고 강력한 비밀번호를 설정한다. 애플리케이션 계정은 streetcat.*에만 SELECT/INSERT/UPDATE/DELETE를 허용한다. 다른 앱 DB에는 권한을 주지 않는다. 테이블 생성·변경에는 별도 DDL 권한의 마이그레이션 계정을 사용한다. 아래 마이그레이션 명령은 그 계정으로 실행하고, 이후 런타임 계정으로 되돌린다.

기존 Docker Compose의 MySQL 볼륨이 이미 있으면 init.sql은 재실행되지 않는다. Compose의 `/home/web/...` 경로와 기존 DB 설정도 그대로 유지되어 있으므로 자동으로 로컬이나 새 streetcat DB를 준비해 주지 않는다. DB 관리자 콘솔에서 새 DB와 계정을 준비한다.

## 3. 애플리케이션 환경 설정

```bash
composer install
# .env가 없을 때만 생성; 기존 설정을 덮어쓰지 않는다.
cp -n .env.example .env
```

`.env`에서 다음 값을 환경에 맞게 설정한다. MySQL이 Docker 서비스면 HOST는 보통 `mysql`, 호스트 설치면 실제 DB 주소를 쓴다.

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
STREETCAT_DB_HOST=127.0.0.1
STREETCAT_DB_PORT=3306
STREETCAT_DB_DATABASE=streetcat
STREETCAT_DB_USERNAME=streetcat
STREETCAT_DB_PASSWORD=환경에서_관리하는_비밀번호
JWT_TTL=60
JWT_BLACKLIST_ENABLED=true
PLATFORM_SMS_DRIVER=preview
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

신규 환경에 키가 없을 때만 `php artisan key:generate`와 `php artisan jwt:secret`을 실행한다. 기존 APP_KEY/JWT_SECRET을 재생성하면 기존 암호화 데이터/토큰에 영향이 있으므로 유지한다. 키·비밀번호는 저장소에 올리지 않는다.

```bash
php artisan optimize:clear
php artisan project:migrate streetcat --pretend
# SQL을 검토한 뒤 DDL 권한 계정으로 실제 적용
php artisan project:migrate streetcat
# production에서는 승인된 배포 절차 안에서 위 명령에 --force 추가
php artisan project:create streetcat
php artisan route:list --path=api
```

`project:create`는 이미 등록됐으면 그대로 둔다. 비활성 프로젝트를 재활성화하거나 다른 프로젝트를 자동 등록하지 않는다. 이 명령은 DB 생성이나 마이그레이션을 대신하지 않는다. `php artisan db:seed`의 기본 Seeder도 streetcat만 등록하지만, 먼저 전용 마이그레이션이 필요하다.

기존 통합 DB의 projects/project_accounts에 이미 회원이 있다면 새 DB로 자동 이동하지 않는다. [DB 문서의 이관 기준](PROJECT_DATABASES.md)을 먼저 따른다.

## 4. HTTP Client로 확인

PhpStorm에서 루트 [api.http](../api.http)를 열고 baseUrl을 서버 주소로 바꾼다. 01~13을 순서대로 실행하면 인증번호와 토큰을 응답에서 자동 저장한다. 전체 실행 시 일부러 실패해야 하는 요청도 포함되며, 각 요청의 테스트가 기대 HTTP 상태를 검사한다.

03 발급 → 05 인증은 3분 이내에 실행한다. 같은 번호로 다시 시작하면 60초 대기해야 하며 3시간에 5회까지 발급된다. 테스트는 테스트용 전화번호의 계정을 생성/조회하고 표시 이름을 `돌봄 테스트`로 수정한다. JWT/인증번호는 파일에 직접 적지 않는다.

## 5. 운영 전 필수 설정

- APP_ENV=production, APP_DEBUG=false, HTTPS 적용.
- 실제 SMS 공급자 연동 전에는 인증 요청이 503으로 차단된다. preview는 운영에서 허용되지 않는다.
- 여러 서버는 공통 영속 캐시(예: Redis)를 사용해야 제한·JWT 폐기가 공유된다. file 캐시는 단일 개발 인스턴스용이다. JWT_BLACKLIST_ENABLED=true 유지.
- 현재 SMS 일일 한도는 프로젝트 DB 단위이다. 유료 SMS 연동 시 전체 앱 공통 예산·차단 정책을 추가한다.
- Nginx 실제 HTTPS/프록시 구성, 백업/복구, MySQL 권한, 로그의 민감정보 미기록을 확인한다.
- `php artisan test --filter=ProjectIsolationTest`와 `php artisan test`를 실행한 후 MySQL 동시 요청과 로그아웃 토큰 폐기도 확인한다.

현재 `/up`은 앱 생존 확인이며 DB 연결·SMS 준비까지 보장하는 readiness 검사는 아니다.
