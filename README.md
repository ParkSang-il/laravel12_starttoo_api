# 프로젝트별 DB를 사용하는 API

현재 등록 대상은 **streetcat 하나**다. Laravel/JWT/MySQL/Nginx 공통 기반을 사용하고, 앱별 회원·인증·업무 데이터는 별도 DB에 저장한다. 프레임워크는 기존 composer.json의 Laravel 13.38.0 설정을 유지했다.

| 문서 | 내용 |
|---|---|
| [서버 설치·사용법](docs/SETUP_STREETCAT.md) | streetcat DB 생성, 전용 계정, 마이그레이션, 프로젝트 등록, 운영 설정 |
| [Flutter API 목록](docs/FLUTTER_API.md) | 인증 API와 그룹·장소 계약 링크, 요청·응답·오류 |
| [DB 분리 구조](docs/PROJECT_DATABASES.md) | 프로젝트 선택, 전용 연결, JWT 검증, 기존 데이터 처리, 다음 앱 추가법 |
| [공동 돌봄 그룹 API](docs/CARE_GROUPS.md) | 동네 모집 검색·생성·초대·가입 신청·승인·탈퇴·내보내기 |
| [그룹 돌봄장소 API](docs/GROUP_PLACES.md) | 장소 등록·수정·사용중지·장소별 권한과 회수 |
| [HTTP 요청 테스트](api.http) | streetcat만 사용하는 순차 테스트 13개 |
| [그룹 HTTP 요청 테스트](groups.http) | 운영자·신청자 두 계정의 가입 승인·초대·접근 제한 테스트 |

빠른 시작: MySQL에 streetcat DB/계정 준비 → .env의 STREETCAT_DB_* 설정 → `php artisan project:migrate streetcat` → `php artisan project:create streetcat`.

기존 DB와 과거 마이그레이션은 보존했다. **프로젝트 DB에 일반 `php artisan migrate`를 실행하지 않는다.** `project:migrate`는 `database/project-migrations`만 적용하며 과거 포트폴리오 테이블을 생성하지 않는다. 기존 데이터의 자동 복사·삭제는 없다. 과거 START/SETUP/JWT 문서와 .backup 자료는 이전 서비스의 참고 자료이며 이 README의 링크가 현재 안내다.

운영 SMS 제공자는 아직 연결되지 않았다. 개발에서만 `APP_ENV=local` + `PLATFORM_SMS_DRIVER=preview`로 인증번호를 응답받을 수 있다. production에서는 503으로 차단된다. 그룹 생성·가입 심사와 그룹 돌봄장소·장소별 권한 API를 제공한다. Flutter 그룹·장소 화면도 연결했으며 친구·상태 승인·공유 돌봄 기록은 다음 단계다. 그룹 가입만으로 위치 열람 권한이 부여되지는 않는다.

그룹 DB 추가 적용: `php artisan project:migrate streetcat --pretend` 확인 후 `php artisan project:migrate streetcat` (production 배포에서는 `--force`). 검증: `php artisan test --filter=CareGroupTest`. 현재 작업 환경에는 PHP/vendor가 없어 실행 검증은 하지 못했다. 서버 DB에는 아직 적용하지 않았다.

검증 명령: `php artisan test --filter=ProjectIsolationTest`, `php artisan test`, `vendor/bin/pint --test`. 격리 테스트의 두 번째 앱은 메모리 DB 테스트에만 존재하며 실제 설정/운영 등록에는 추가하지 않는다. PHP/Composer/vendor가 없는 현재 작업 환경에서는 실행 검증하지 못했다. 실제 MySQL 동시 요청·잠금·Redis 토큰 폐기는 서버 통합 검증이 필요하다.
