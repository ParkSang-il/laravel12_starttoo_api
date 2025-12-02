# Laravel Starttoo 시작 가이드

## 다음 단계 실행 순서

### 1단계: Laravel Sanctum 설치
```bash
docker compose exec app composer require laravel/sanctum
```

### 2단계: Sanctum 설정 파일 발행
```bash
docker compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 3단계: 애플리케이션 키 생성 (아직 안 했다면)
```bash
docker compose exec app php artisan key:generate
```

### 4단계: 데이터베이스 마이그레이션 실행
```bash
docker compose exec app php artisan migrate
```

### 5단계: Laravel 애플리케이션 접속 테스트
브라우저에서 `http://localhost:8000` 접속

### 6단계: API 테스트

#### 회원가입 테스트
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

#### 로그인 테스트
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "password123"
  }'
```

#### 현재 사용자 정보 조회 (토큰 필요)
```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer {토큰}"
```

## 생성된 파일

- `routes/api.php` - API 라우트 정의
- `app/Http/Controllers/AuthController.php` - 인증 컨트롤러
- `database/migrations/2024_12_02_000001_add_username_to_users_table.php` - username 필드 추가 마이그레이션
- `app/Models/User.php` - Sanctum HasApiTokens 추가

## API 엔드포인트

- `POST /api/auth/register` - 회원가입
- `POST /api/auth/login` - 로그인
- `GET /api/auth/me` - 현재 사용자 정보 (인증 필요)
- `POST /api/auth/logout` - 로그아웃 (인증 필요)
- `POST /api/auth/logout-all` - 모든 기기 로그아웃 (인증 필요)

