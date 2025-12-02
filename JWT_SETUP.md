# JWT 인증 설정 완료

## 변경 사항

### 1. 불필요한 테이블 제거
- ✅ `sessions` 테이블 제거 (JWT는 stateless)
- ✅ `password_reset_tokens` 테이블 제거
- ✅ `remember_token` 필드 제거

### 2. User 모델 수정
- ✅ Sanctum 제거
- ✅ JWTSubject 인터페이스 구현
- ✅ `getJWTIdentifier()`, `getJWTCustomClaims()` 메서드 추가

### 3. AuthController 수정
- ✅ Sanctum 대신 JWT 사용
- ✅ `refresh()` 메서드 추가
- ✅ `logoutAll()` 제거 (JWT는 stateless)

### 4. 라우트 수정
- ✅ `auth:sanctum` → `auth:api` 변경

### 5. config/auth.php 수정
- ✅ JWT 가드 추가

## 다음 단계

### 1단계: JWT 패키지 설치
```bash
docker compose exec app composer require php-open-source-saver/jwt-auth
```

### 2단계: JWT 설정 파일 발행
```bash
docker compose exec app php artisan vendor:publish --provider="PHPOpenSourceSaver\JWT\JWTAuthServiceProvider"
```

### 3단계: JWT Secret 키 생성
```bash
docker compose exec app php artisan jwt:secret
```

### 4단계: 데이터베이스 마이그레이션 재실행
```bash
# 기존 테이블 삭제 후 재생성
docker compose exec app php artisan migrate:fresh
```

### 5단계: API 테스트

#### 회원가입
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

#### 로그인
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "password123"
  }'
```

#### 현재 사용자 정보 조회
```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer {토큰}"
```

#### 토큰 새로고침
```bash
curl -X POST http://localhost:8000/api/auth/refresh \
  -H "Authorization: Bearer {토큰}"
```

#### 로그아웃
```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer {토큰}"
```

## API 엔드포인트

- `POST /api/auth/register` - 회원가입
- `POST /api/auth/login` - 로그인
- `GET /api/auth/me` - 현재 사용자 정보 (인증 필요)
- `POST /api/auth/logout` - 로그아웃 (인증 필요)
- `POST /api/auth/refresh` - 토큰 새로고침 (인증 필요)

