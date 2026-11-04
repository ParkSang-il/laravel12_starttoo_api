# 표시 이름과 고정 닉네임 번호

## 최종 계약

- `display_name`: 표시 이름 본문. 중복 가능, 사용자가 1~50자로 변경 가능하다.
- `nickname_tag`: 별도의 unsigned bigint 고유 필드. 프로젝트 DB 안에서 UNIQUE 인덱스로 중복을 차단한다. 생성된 계정 ID를 최초 번호로 사용하고 이후에는 바꾸지 않는다. API에서는 큰 번호도 손실되지 않게 문자열로 반환한다.
- `nickname`: 화면 표시용 `display_name#nickname_tag`. 서버에서 조합하는 읽기 전용 값이다.

예: 표시 이름이 같은 두 계정도 `다정한 밥지기#123`, `다정한 밥지기#124`로 구분한다. 첫 번째 계정이 이름을 바꾸면 `말랑한 젤리#123`이며 번호는 그대로다. 이전 문서의 “display_name 자체에 #번호를 저장하고 이름 변경 시 태그를 없앨 수 있음” 계약을 이 구조로 대체한다.

휴대폰 인증 후 신규 계정을 생성하는 트랜잭션에서 수식어 222개·명사 146개 중 활성 단어를 조합해 이름 본문을 만들고 고정 번호를 저장한다. 단어 유형이 비어 있으면 `다정한`/`밥지기`를 대체값으로 사용한다. 인증번호 재검증·재로그인 때 기존 이름과 번호는 유지한다. 번호나 이름이 누락된 이전 계정은 로그인 시에도 보완한다. 자동 생성은 현재 스트릿캣 프로젝트에 적용한다.

`PATCH /api/v1/projects/streetcat/me`는 `display_name`만 변경한다. `nickname_tag`, `nickname` 입력은 거부한다. 구버전 앱이 `변경 이름#본인번호`를 보내면 본문만 저장하고, 다른 번호를 붙여 바꾸려 하면 422를 반환한다. 표시 이름의 중복 검사는 하지 않는다.

인증·계정 응답과 홈/그룹/활동/멤버 프로필 응답은 본문 `display_name`, 고정 `nickname_tag`, 표시용 `nickname`을 제공한다. 앱은 목록에 `nickname`을 표시하고, 이름 편집창에는 본문만 입력받으며 `#번호`는 수정할 수 없는 suffix로 표시한다. 오래된 응답에 `nickname`이 없으면 기존 표시 이름으로 동작한다.

## 기존 계정 보정

프로젝트 migration `2026_11_04_000014_add_fixed_nickname_tags.php`는 번호 컬럼을 추가하고 기존 계정을 200개씩 처리한다.

1. 기존 계정 ID를 고정 번호로 저장한다. 이미 존재하는 회원을 복제하거나 새 계정을 만들지 않는다.
2. 기존 표시 이름은 유지하되, 이전 자동 생성 형식의 마지막 `#본인ID`만 분리한다.
3. 이름이 비어 있으면 조합 이름을 채운다. 사진·전화번호·권한·그룹 소속은 변경하지 않는다.

번호 UNIQUE 인덱스가 실제 DB의 중복 번호 쓰기도 거부한다. 표시 이름에는 UNIQUE 인덱스를 추가하지 않는다. 000014를 롤백하면 번호 컬럼이 삭제되므로 운영 적용 전 DB 백업이 필요하다.

## 배포

닉네임 관련 서비스·모델·컨트롤러·그룹 응답 변경과 migration 000013/000014, `database/seeders/NicknameWordSeeder.php`를 함께 배포한다. 번호 컬럼을 조회하므로 앱 사용 전에 프로젝트 마이그레이션을 적용해야 한다.

```bash
docker exec -w /var/www/html starttoo_app php artisan project:migrate streetcat --pretend
docker exec -w /var/www/html starttoo_app php artisan project:migrate streetcat --force
```

000013은 프로젝트 전용 DB에 `nickname_words`를 만들고 제공된 단어를 자동 등록한다. 별도 시더 실행은 필요하지 않다. 기본 목록을 다시 적용할 때만 다음 명령을 사용한다.

```bash
docker exec -w /var/www/html starttoo_app php artisan project:seed-nicknames streetcat
```

시더는 `(type, word)` 기준 upsert하며 기존 생성 시각은 보존하지만 기본 단어의 `is_active`는 true로 되돌린다. 프로젝트 문맥 없이 기본 DB에 시더를 실행하지 않는다. 서버의 실제 DB 적용·운영 배포는 이번 로컬 구현에서 실행하지 않았다.

## 검증

격리 PHP 8.3/SQLite 테스트로 이름 중복 허용, 이름 변경 후 번호 유지, 번호 변경 거부, 기존 계정/이름 보정, 번호 UNIQUE 인덱스, 기존 조합 이름 보존, 시더 중복 방지와 프로젝트 DB 격리를 확인한다. 앱 테스트는 편집창의 고정 번호 표시와 본문만 보내는 요청을 확인한다.
