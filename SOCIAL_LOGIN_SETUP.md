# 소셜 로그인 설정 가이드

## 개요

구글, 카카오, 인스타그램 소셜 로그인을 통해 JWT 토큰을 발급받을 수 있습니다.

## 설치 단계

### 1단계: Laravel Socialite 패키지 설치

```bash
docker compose exec app composer require laravel/socialite
```

### 2단계: 데이터베이스 마이그레이션 실행

```bash
docker compose exec app php artisan migrate:fresh
```

또는 기존 테이블이 있다면:

```bash
docker compose exec app php artisan migrate
```

## 소셜 로그인 앱 설정

### 구글 (Google)

1. [Google Cloud Console](https://console.cloud.google.com/) 접속
2. 프로젝트 생성 또는 선택
3. "API 및 서비스" > "사용자 인증 정보" 이동
4. "사용자 인증 정보 만들기" > "OAuth 클라이언트 ID" 선택
5. 애플리케이션 유형: "웹 애플리케이션"
6. 승인된 리디렉션 URI 추가:
   ```
   http://localhost:8000/api/auth/social/google/callback
   ```
7. 클라이언트 ID와 클라이언트 보안 비밀번호 복사

### 카카오 (Kakao)

1. [Kakao Developers](https://developers.kakao.com/) 접속
2. 내 애플리케이션 추가
3. "앱 설정" > "앱 키"에서 REST API 키 확인
4. "제품 설정" > "카카오 로그인" 활성화
5. "카카오 로그인" > "Redirect URI" 등록:
   ```
   http://localhost:8000/api/auth/social/kakao/callback
   ```
6. REST API 키를 클라이언트 ID로 사용
7. "제품 설정" > "카카오 로그인" > "보안"에서 클라이언트 시크릿 확인

### 인스타그램 (Instagram)

1. [Facebook Developers](https://developers.facebook.com/) 접속
2. 앱 생성
3. "Instagram Basic Display" 제품 추가
4. "Instagram App ID"와 "Instagram App Secret" 확인
5. "Instagram Basic Display" > "Basic Display" 설정
6. "Valid OAuth Redirect URIs" 추가:
   ```
   http://localhost:8000/api/auth/social/instagram/callback
   ```
7. Instagram App ID를 클라이언트 ID로, Instagram App Secret을 클라이언트 시크릿으로 사용

## 환경 변수 설정

`.env` 파일에 다음 정보를 추가하세요:

```env
# 구글
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://localhost:8000/api/auth/social/google/callback

# 카카오
KAKAO_CLIENT_ID=your_kakao_client_id
KAKAO_CLIENT_SECRET=your_kakao_client_secret
KAKAO_REDIRECT_URI=http://localhost:8000/api/auth/social/kakao/callback

# 인스타그램
INSTAGRAM_CLIENT_ID=your_instagram_client_id
INSTAGRAM_CLIENT_SECRET=your_instagram_client_secret
INSTAGRAM_REDIRECT_URI=http://localhost:8000/api/auth/social/instagram/callback
```

## API 엔드포인트

### 소셜 로그인 시작

#### 구글
```
GET /api/auth/social/google
```

#### 카카오
```
GET /api/auth/social/kakao
```

#### 인스타그램
```
GET /api/auth/social/instagram
```

### 콜백 (자동 처리)

소셜 로그인 후 자동으로 콜백되어 JWT 토큰이 발급됩니다.

- 구글: `/api/auth/social/google/callback`
- 카카오: `/api/auth/social/kakao/callback`
- 인스타그램: `/api/auth/social/instagram/callback`

## 응답 형식

소셜 로그인 성공 시:

```json
{
  "success": true,
  "message": "Google 로그인 성공",
  "data": {
    "user": {
      "id": 1,
      "name": "홍길동",
      "email": "user@example.com",
      "username": "user123",
      "provider": "google",
      "provider_id": "123456789",
      "avatar": "https://...",
      "profile_image": "https://...",
      "is_verified": true,
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-01T00:00:00.000000Z"
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

## 사용 예시

### 브라우저에서 직접 접속

1. 브라우저에서 다음 URL 중 하나로 접속:
   - `http://localhost:8000/api/auth/social/google`
   - `http://localhost:8000/api/auth/social/kakao`
   - `http://localhost:8000/api/auth/social/instagram`

2. 소셜 로그인 페이지에서 인증 진행

3. 콜백 후 JWT 토큰이 포함된 JSON 응답 받기

### 프론트엔드에서 사용

```javascript
// 구글 로그인
window.location.href = 'http://localhost:8000/api/auth/social/google';

// 카카오 로그인
window.location.href = 'http://localhost:8000/api/auth/social/kakao';

// 인스타그램 로그인
window.location.href = 'http://localhost:8000/api/auth/social/instagram';
```

## 주의사항

1. **프로덕션 환경**: 프로덕션에서는 `APP_URL`을 실제 도메인으로 변경하고, 각 소셜 로그인 앱의 리디렉션 URI도 업데이트해야 합니다.

2. **HTTPS**: 프로덕션 환경에서는 HTTPS를 사용해야 합니다.

3. **에러 처리**: 소셜 로그인 실패 시 적절한 에러 메시지가 반환됩니다.

4. **기존 사용자**: 같은 이메일로 일반 회원가입을 한 사용자가 소셜 로그인을 시도하면, 기존 계정에 소셜 로그인 정보가 연결됩니다.

5. **고유 사용자명**: 소셜 로그인 사용자는 자동으로 고유한 사용자명이 생성됩니다.

## 문제 해결

### "Client ID not found" 오류
- `.env` 파일에 클라이언트 ID가 제대로 설정되었는지 확인
- `docker compose exec app php artisan config:clear` 실행

### "Redirect URI mismatch" 오류
- 소셜 로그인 앱의 리디렉션 URI가 정확히 일치하는지 확인
- 프로토콜(http/https), 도메인, 경로가 모두 일치해야 함

### "Invalid client secret" 오류
- 클라이언트 시크릿이 정확한지 확인
- 카카오의 경우 "보안" 설정에서 클라이언트 시크릿 확인

