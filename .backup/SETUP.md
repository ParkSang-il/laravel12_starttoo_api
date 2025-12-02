# Windows WSL 환경 - PHP 8.2 도커 설치 가이드

## 설치 순서

### 1단계: 프로젝트 디렉토리로 이동
```bash
cd /mnt/d/work/sns
```

### 2단계: 환경 설정 파일 생성
```bash
cp env.example .env
```

### 3단계: 도커 이미지 다운로드
```bash
docker compose pull
```

이 명령어는 다음 이미지들을 다운로드합니다:
- `php:8.2-fpm` - PHP 8.2 FPM 이미지
- `nginx:alpine` - Nginx 웹 서버 이미지
- `mysql:8.0` - MySQL 8.0 데이터베이스 이미지

### 4단계: 도커 컨테이너 실행
```bash
docker compose up -d
```

### 5단계: 컨테이너 상태 확인
```bash
docker compose ps
```

모든 컨테이너가 "Up" 상태인지 확인하세요.

### 6단계: PHP 확장 및 Composer 설치 (최초 1회만)

**한 줄 명령어 (권장):**
```bash
docker compose exec app sh -c "apt-get update && apt-get install -y git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && chmod +x /usr/local/bin/composer"
```

**단계별 설치:**
```bash
# 1. 시스템 패키지 업데이트
docker compose exec app apt-get update

# 2. 필요한 패키지 설치
docker compose exec app apt-get install -y git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev

# 3. PHP 확장 설치
docker compose exec app docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# 4. Composer 설치
docker compose exec app curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 5. Composer 실행 권한 부여
docker compose exec app chmod +x /usr/local/bin/composer
```

### 7단계: Laravel 12 프로젝트 생성
```bash
docker compose exec app composer create-project laravel/laravel . "^12.0"
```

### 8단계: 애플리케이션 키 생성
```bash
docker compose exec app php artisan key:generate
```

### 9단계: 데이터베이스 마이그레이션 실행
```bash
docker compose exec app php artisan migrate
```

### 10단계: 접속 확인
브라우저에서 `http://localhost:8000` 접속

## 설치 확인

### PHP 버전 확인
```bash
docker compose exec app php -v
```

### Composer 버전 확인
```bash
docker compose exec app composer --version
```

### 설치된 PHP 확장 확인
```bash
docker compose exec app php -m
```

## 문제 해결

### 포트 충돌
```bash
# 포트 사용 확인
netstat -ano | grep :8000
netstat -ano | grep :3306
```

### 컨테이너 재생성
```bash
docker compose up -d --force-recreate
```

### 권한 문제
```bash
sudo chown -R $USER:$USER .
sudo chmod -R 755 storage bootstrap/cache
```

### 컨테이너 로그 확인
```bash
docker compose logs app
docker compose logs nginx
docker compose logs mysql
```

