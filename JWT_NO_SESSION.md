# JWT 인증 - 세션 미사용 설정 완료

## 변경 사항

### 1. 세션 드라이버 변경
- ✅ `config/session.php`: 기본값을 `array`로 변경
- ✅ `env.example`: `SESSION_DRIVER=array` 설정

### 2. API 라우트에서 세션 미들웨어 제거
- ✅ `bootstrap/app.php`: API 미들웨어 그룹에서 세션 미들웨어 제거
  - `StartSession` 미들웨어 제거
  - `ShareErrorsFromSession` 미들웨어 제거

### 3. JWT 인증 설정
- ✅ `config/auth.php`: API 가드를 `jwt`로 설정
- ✅ User 모델: `JWTSubject` 인터페이스 구현
- ✅ AuthController: JWT 토큰 기반 인증 사용

## 설정 확인

### 1. .env 파일 확인
```bash
docker compose exec app cat .env | grep SESSION_DRIVER
```
출력: `SESSION_DRIVER=array`

### 2. 설정 캐시 클리어 (변경사항 적용)
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
```

### 3. 애플리케이션 재시작 (선택사항)
```bash
docker compose restart app
```

## JWT 토큰 사용 방법

### 회원가입
```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "홍길동",
    "email": "test@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "username": "honggildong"
  }'
```

### 로그인 (JWT 토큰 발급)
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "password123"
  }'
```

응답 예시:
```json
{
  "success": true,
  "message": "로그인 성공",
  "data": {
    "user": {...},
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer"
  }
}
```

### 인증이 필요한 API 호출
```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer {발급받은_토큰}"
```

### 토큰 새로고침
```bash
curl -X POST http://localhost:8000/api/auth/refresh \
  -H "Authorization: Bearer {발급받은_토큰}"
```

### 로그아웃
```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer {발급받은_토큰}"
```

## 중요 사항

1. **세션 미사용**: JWT는 stateless 인증 방식이므로 서버에 세션을 저장하지 않습니다.
2. **토큰 기반 인증**: 모든 API 요청에 `Authorization: Bearer {token}` 헤더를 포함해야 합니다.
3. **토큰 만료**: JWT 토큰은 설정된 시간 후 만료됩니다. 만료 시 `refresh` 엔드포인트를 사용하여 새 토큰을 발급받을 수 있습니다.
4. **보안**: 토큰은 안전하게 저장하고, HTTPS를 사용하는 것을 권장합니다.

## 문제 해결

### 세션 관련 오류가 계속 발생하는 경우

1. 설정 캐시 클리어:
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
```

2. .env 파일 확인:
```bash
docker compose exec app cat .env | grep SESSION
```

3. 애플리케이션 재시작:
```bash
docker compose restart app
```

