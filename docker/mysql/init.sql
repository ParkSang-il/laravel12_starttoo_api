-- MySQL 초기화 스크립트
-- 데이터베이스와 사용자 생성 및 권한 부여

-- 데이터베이스가 없으면 생성
CREATE DATABASE IF NOT EXISTS starttoo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 사용자 생성 (이미 존재하면 무시)
CREATE USER IF NOT EXISTS 'starttoo'@'%' IDENTIFIED BY 'root';

-- 모든 권한 부여
GRANT ALL PRIVILEGES ON starttoo.* TO 'starttoo'@'%';

-- 권한 새로고침
FLUSH PRIVILEGES;

