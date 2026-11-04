# 스트릿캣 신규 가입 자동 닉네임

## 동작

휴대폰 인증번호 검증에 성공해 신규 `project_accounts` 행을 만드는 시점에 `display_name`을 `{수식어} {명사}#{계정ID}`로 저장한다. 예: `다정한 밥지기#123`. 단어를 선택할 때 `nickname_words.is_active=true`만 사용한다. 숫자는 난수 재시도가 아닌 DB의 고유한 계정 PK이며, 같은 조합을 고른 신규 가입자끼리도 숫자가 달라진다. 고유성 범위는 프로젝트 전용 DB이다.

계정 생성·닉네임 저장·인증번호 소비는 같은 트랜잭션이다. 재로그인은 생성 로직을 실행하지 않는다. 기존 회원의 사용자 지정 이름과 이름이 null인 계정도 변경하지 않는다. 현재 등록된 스트릿캣 프로젝트에만 자동 생성을 적용하고, 다른 프로젝트의 가입 동작은 유지한다. 사용자가 이후 이름을 직접 변경하는 기존 기능과 중복 허용 정책은 변경하지 않는다. 숫자 태그는 자동 생성 시점의 구분자이며 별도 로그인 ID가 아니다.

수식어 222개·명사 146개는 제공된 목록 그대로이며 `(type, word)` unique 인덱스로 중복 등록을 방지한다. 기본 조합은 최대 11자, 뒤에 `#`와 계정 ID를 붙여도 기존 `display_name` 50자 제한 이내다. 단어 유형이 모두 비활성화되거나 비어 있으면 `다정한`/`밥지기`를 해당 유형의 대체값으로 사용해 가입을 유지한다.

## 배포

새 파일 `app/Services/NicknameGenerator.php`, `database/seeders/NicknameWordSeeder.php`, `database/project-migrations/2026_11_04_000013_create_nickname_words.php`와 수정한 `PhoneChallengeService.php`, `routes/console.php`를 함께 커밋·배포한다.

DB 백업 후 서버 컨테이너에서:

```bash
php artisan project:migrate streetcat --pretend
php artisan project:migrate streetcat --force
```

000013은 프로젝트 전용 DB에 `nickname_words`를 생성하고 기본 단어를 자동 등록한다. 별도 시더 실행은 필요하지 않다. 기본 목록을 명시적으로 다시 적용하려면:

```bash
php artisan project:seed-nicknames streetcat
```

시더는 `(type, word)` 기준 upsert하며 기존 단어의 `created_at`은 보존한다. 재실행하면 기본 목록의 `is_active`는 true로 재설정된다. 비활성 상태를 운영 중 수동 관리한다면 재실행 전에 유의한다. `php artisan db:seed --class=NicknameWordSeeder`처럼 프로젝트 문맥 없이 실행하면 실패하도록 하여 기존 서비스 DB에 실수로 저장하는 것을 방지했다.

응답 필드는 기존 `account.display_name`이므로 Flutter 수정 없이 설정·공동돌봄에 표시된다. 기존 회원에 대한 일괄 닉네임 배정은 수행하지 않는다. 실제 운영 DB 적용·신규 실회원 가입 테스트는 별도 확인한다.

## 검증

격리된 PHP 8.3/SQLite 전체 테스트 73개(874 assertions) 통과. 신규 생성과 조회, 동일 단어 조합의 서로 다른 ID 태그, 비활성 단어 제외, 재로그인과 사용자 수정 이름 보존, 기존 null 이름 보존, 시더 반복 실행 및 기존 DB 격리, 빈 목록 대체값을 확인했다.
