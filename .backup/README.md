# Starttoo API

PHP 8.2 + Laravel 12 기반 Starttoo 서비스의 백엔드 API 프로젝트입니다.

## 요구사항

- Docker & Docker Compose
- Windows WSL2 환경

## 설치 방법 (Windows WSL 환경)

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

### 4단계: 도커 컨테이너 실행
```bash
docker compose up -d
```

### 5단계: 컨테이너 상태 확인
```bash
docker compose ps
```

### 6단계: PHP 확장 및 Composer 설치 (최초 1회만)

**한 줄 명령어로 설치:**
```bash
docker compose exec app sh -c "apt-get update && apt-get install -y git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && chmod +x /usr/local/bin/composer"
```

**또는 단계별로 설치:**
```bash
# 시스템 패키지 업데이트
docker compose exec app apt-get update

# 필요한 패키지 설치
docker compose exec app apt-get install -y git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev

# PHP 확장 설치
docker compose exec app docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Composer 설치
docker compose exec app curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Composer 실행 권한 부여
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

## 도커 서비스

- **app**: PHP 8.2-FPM 애플리케이션 서버
- **nginx**: 웹 서버 (포트 8000)
- **mysql**: MySQL 8.0 데이터베이스 (포트 3306)

## 유용한 명령어

### 컨테이너 로그 확인
```bash
docker compose logs -f app
```

### 컨테이너 중지
```bash
docker compose stop
```

### 컨테이너 시작
```bash
docker compose start
```

### 컨테이너 재시작
```bash
docker compose restart
```

### 컨테이너 중지 및 제거
```bash
docker compose down
```

### 컨테이너 내부 접속
```bash
docker compose exec app bash
```

### Artisan 명령어 실행
```bash
docker compose exec app php artisan [명령어]
```

### Composer 명령어 실행
```bash
docker compose exec app composer [명령어]
```

## 데이터베이스 접속 정보

- **호스트**: mysql (컨테이너 내부) 또는 localhost (외부)
- **포트**: 3306
- **데이터베이스**: starttoo
- **사용자명**: starttoo
- **비밀번호**: root
- **Root 비밀번호**: root

