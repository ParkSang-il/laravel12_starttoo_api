# 공동돌봄 프로필 표시 계약

2026-11-04. 홈 공동돌봄 카드, 그룹 목록/헤더, 활동, 멤버/관리 화면은 같은 프로젝트 계정 프로필을 사용한다.

## 응답

- `GET me/groups`, 가입한 `GET groups/{group}`: `member_previews` (현재 가입 멤버 최대 4명). 각 항목은 `account_id`, `display_name`, `avatar_url`, `avatar_thumbnail_url`이다.
- 운영자용 `GET groups/{group}/members`, `GET groups/{group}/join-requests`: 기존 역할/신청 정보에 같은 프로필 필드를 더한다. 기존 운영자 전용 권한은 유지한다.
- 장소 돌봄 기록의 목록·생성 응답: `created_by`를 유지하고 `author_account_id`, `display_name`, `avatar_url`, `avatar_thumbnail_url`을 함께 반환한다. 닉네임으로 작성자를 추정하지 않는다. 작성자 프로필을 JOIN해 기록별 추가 조회를 제거했다.
- 공개 그룹 검색·외부인/가입 대기자의 그룹 조회·초대 미리보기에는 멤버 미리보기와 사진을 추가하지 않는다. 기존 장소 열람 권한이 있어야 해당 활동을 볼 수 있다.
- 프로필은 현재 프로젝트의 `project_accounts`가 원본이다. 다른 프로젝트/기존 `users.profile_image`, 전화번호, 개인 SQLite 사진과 자동 연결하지 않는다. 사진 필드는 절대 HTTP(S) URL 또는 null이며 내부 파일 경로는 반환하지 않는다.

## 표시 규칙

1. `avatar_thumbnail_url`이 유효하면 우선 사용한다. 없거나 실패하면 `avatar_url`, 원본도 없거나 실패하면 배경색과 닉네임 첫 글자를 표시한다. 사진 로딩 중에도 동일한 기본 표시를 유지한다.
2. 배경색은 앱 공통 팔레트 7색 중 `(account_id - 1).abs() % 7`로 고정한다. 그룹·리스트 순서·화면 크기·닉네임 변경에 영향받지 않는다. 사진·닉네임이 모두 없지만 ID가 있으면 같은 색 위에 `?`를 표시한다.
3. 닉네임은 앞뒤 공백을 제거한 첫 grapheme을 사용한다. 이모지·조합 문자도 중간에 자르지 않는다. 구버전 응답처럼 ID가 없을 때만 닉네임의 결정적 해시를 보조 기준으로 사용한다. 닉네임이 같다는 이유로 다른 회원의 사진을 연결하지 않는다.
4. 계정 ID와 이름을 전혀 받지 못한 기존 빈 디자인 자리는 실제 회원으로 취급하지 않는다. 중립 아이콘을 유지한다.

## 해상도

Flutter 원형 표시 크기에서 테두리 4px을 제외하고 기기 픽셀 비율을 곱한 값을 디코딩 폭으로 사용하며 최대 192px로 제한한다. 원본 비율을 유지하기 위해 디코딩 폭만 지정하고 표시 영역은 원형 cover로 자른다.

이 디코딩 최적화는 앱 메모리 사용을 줄이는 것이며, 원본 다운로드 바이트를 줄이는 서버 이미지 변환 기능은 아니다. 전송량을 줄이려면 업로드/이미지 처리 과정에서 작은 정사각 썸네일을 만들고 `avatar_thumbnail_url`에 기록해야 한다. 서버는 임의 외부 URL을 가져오거나 자동 이미지 변환 요청을 보내지 않는다.

## 저장소·배포

프로젝트 마이그레이션 `2026_11_04_000011_add_project_account_avatars.php`는 `project_accounts`에 nullable `avatar_url`, `avatar_thumbnail_url`을 추가한다. 기존 계정·닉네임·인증 정보는 유지하고 사진 값을 임의 생성하지 않는다.

이번 변경은 조회·표시 계약이며 프로필 사진 업로드/삭제 API나 앱의 사진 등록 화면을 추가하지 않는다. 사진 업로드가 연결되면 서버 소유 저장소에 원본·썸네일을 기록하고 위 필드를 갱신해야 한다. 일반 그룹 수정·가입 신청·돌봄 기록 요청으로 다른 회원의 사진 URL을 바꿀 수 없다.

배포 시 DB 백업 및 `php artisan project:migrate streetcat --pretend` 검토 후 `php artisan project:migrate streetcat --force`로 신규 컬럼을 먼저 적용한다. 이번 작업에서는 실제 DB 마이그레이션·운영 배포를 실행하지 않았다. 새 앱은 구버전 응답의 누락 필드도 처리한다.

## 검증

- 격리 PHP 8.3 컨테이너/SQLite 메모리 DB에서 전체 서버 테스트 62개(768 assertions) 통과.
- 프로필 미리보기·멤버·활동 값 일치, 동일 닉네임의 다른 ID 구분, 사진 없는 계정, 닉네임 변경, 공개/가입 대기 데이터 보호, 잘못된 URL 제외를 확인했다.
- Flutter 전체 테스트 205개 통과, 정적 분석 문제없음. 실제 네 화면을 연결해 같은 ID·URL을 전달하는지, 사진 디코딩·썸네일→원본→첫 글자 전환, 색상 고정, Unicode 첫 글자를 검증했다.
- 실제 사진 업로드·실서비스 URL·MySQL 배포는 미실행이다.

## 프로필 사진 등록·삭제 구현 (이전 업로드 미구현 기록 대체)

- `POST /api/v1/projects/{projectCode}/me/avatar`: Bearer 인증, multipart 필드 `photo` 하나. 인증 계정만 수정하며 요청의 계정 ID·저장 경로·URL은 사용하지 않는다. 분당 10회 제한.
- JPG/PNG/WebP 이미지 5MB 이하, 가로·세로 각각 4096px 이하. SVG는 거부한다. PHP GD 확장이 필요하다. 앱은 기기에서 방향을 보정하고 최대 1080px PNG로 변환해 전송하므로 서버의 JPEG/WebP 디코더가 없어도 앱 업로드가 가능하다.
- 서버는 비율을 유지한 최대 1080px 원본과 최대 192px 썸네일을 다시 인코딩해 EXIF 등 원본 메타데이터를 저장하지 않는다. JPEG 인코더가 있으면 JPEG(품질 82), 없으면 PNG로 저장한다. GD가 없으면 503을 반환한다.
- 저장 경로: `profile/YYYY/MM/DD/{projectCode}/{accountId}/{uuid}.{jpg|png}` 및 같은 이름의 `-thumb` 파일. 날짜는 서버 `APP_TIMEZONE` 기준이며 기본값은 Asia/Seoul이다. Object Storage의 폴더는 객체 키의 접두사로 자동 표시된다.
- 저장소는 명시적인 `s3` 디스크이며 `AWS_BUCKET=streetcat`. 기존 `FILESYSTEM_DISK=local`은 유지한다. 객체는 비공개이며 DB에는 `avatar_disk`, `avatar_path`, `avatar_thumbnail_path`를 저장한다. 만료되는 URL을 DB에 저장하지 않는다.
- 응답 `data`는 기존 계정 형식에 `avatar_url`, `avatar_thumbnail_url`을 포함한다. 원본 경로·disk·전화번호는 노출하지 않는다. 조회 시 1시간 유효한 임시 URL을 발급한다. 그룹·활동·멤버 응답도 같은 저장 경로에서 임시 URL을 생성한다. 기존 절대 URL 데이터는 호환용으로 유지한다.
- `DELETE /api/v1/projects/{projectCode}/me/avatar`: 본인의 사진 정보 초기화 후 관리 중인 기존 객체를 삭제하고 사진 URL이 null인 계정을 반환한다. 반복 요청도 성공한다. 다른 회원이나 기존 외부 URL의 객체는 삭제하지 않는다.
- 업로드 두 파일 저장 후 계정 행을 잠가 DB를 변경한다. 저장 실패는 신규 객체를 정리하고 기존 사진을 유지한다. 성공 후 이전 객체를 정리한다. 객체 삭제 실패는 기록하고 DB 성공 결과를 뒤집지 않는다. 이 경우 운영 로그 확인 후 잔여 객체를 정리해야 한다.
- 앱: 설정 → 프로필 → 프로필 사진 변경 → 카메라/앨범/삭제. 업로드 중 중복 입력 방지, 취소 시 기존 사진 유지, 성공 후 공유 화면 재조회. 개인 친구/기록 사진 저장소는 사용하지 않는다. 앱 복귀 시 `/me`를 재조회해 임시 URL을 갱신한다. 로그아웃·계정 변경·사진 변경 전에 시작한 오래된 응답은 적용하지 않는다.

### 배포 순서

1. 새 서비스 `app/Services/ProfileAvatar.php`, migration `2026_11_04_000012_add_project_account_avatar_paths.php`, 수정한 서비스/모델/컨트롤러/라우트를 모두 Git에 포함한다. 누락 파일이 없도록 `git status --short`를 확인한다.
2. 서버 `.env`의 NCP S3 설정 및 GD/PNG 지원을 확인한다. 인증키는 서버에만 둔다. `php -r 'var_export(extension_loaded("gd") ? gd_info() : false);'`로 확인 가능하다.
3. DB 백업 후 `php artisan project:migrate streetcat --pretend` 검토, `php artisan project:migrate streetcat --force` 실행. 새 앱을 배포하기 전에 000012를 포함한 전체 프로젝트 마이그레이션이 적용되어야 한다.
4. 서버에서 `composer install --no-dev --optimize-autoloader`, `php artisan config:clear`, `php artisan route:clear` 실행. PHP OPcache가 기존 파일을 유지하는 환경이면 PHP 컨테이너 재시작. 저장소 연결만으로 업로드 API가 생기지는 않으며 위 서버 코드를 배포해야 한다.
5. 앱 재빌드 후 실제 기기에서 등록/재실행/공동돌봄 조회/교체/삭제를 확인한다.

격리 PHP 8.3 + SQLite + 가짜 Object Storage에서 전체 67개 테스트(830 assertions) 통과. 실제 운영 DB 마이그레이션·새 API 배포·앱에서 운영 버킷으로 사진 등록은 이번 구현 검증에 포함하지 않았다. 이전 터미널의 버킷 연결 성공은 새 API 통합 테스트와 구분한다.

## 고정 닉네임 번호 후속 계약

프로필 응답은 표시 이름 본문 `display_name`에 더해 고정 문자열 `nickname_tag`와 화면용 `nickname`을 제공한다. 같은 표시 이름도 번호로 구분하며 번호는 이름 변경으로 바뀌지 않는다. 앱의 홈·그룹·활동·멤버 목록은 `nickname`을 우선 표시한다. 고정 배경색은 계속 계정 ID 기준이며 이름/번호 표기 변경에 영향받지 않는다. migration 000014와 모델/프로필 JOIN 변경을 함께 배포해야 한다. 기존 계정 보정 및 API 계약은 `docs/NICKNAMES.md`를 따른다.
