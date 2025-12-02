# JWT 인증 설치 가이드

## 1단계: JWT 패키지 설치
```bash
docker compose exec app composer require php-open-source-saver/jwt-auth
```

## 2단계: JWT 설정 파일 발행
```bash
docker compose exec app php artisan vendor:publish --provider="PHPOpenSourceSaver\JWT\JWTAuthServiceProvider"
```

## 3단계: JWT Secret 키 생성
```bash
docker compose exec app php artisan jwt:secret
```

## 4단계: config/auth.php 설정 확인
JWT 가드를 사용하도록 설정되어 있는지 확인하세요.

## 5단계: User 모델에 JWT 메서드 추가
User 모델에 `getJWTIdentifier()`와 `getJWTCustomClaims()` 메서드를 추가해야 합니다.

## 6단계: AuthController 수정
Sanctum 대신 JWT를 사용하도록 AuthController를 수정해야 합니다.

