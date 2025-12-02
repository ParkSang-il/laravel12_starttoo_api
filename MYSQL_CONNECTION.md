# MySQL 접속 정보

## 로컬 MySQL 클라이언트 프로그램 접속 정보

### 기본 접속 정보
- **호스트**: `localhost` 또는 `127.0.0.1`
- **포트**: `3306`
- **사용자명**: `starttoo` 또는 `root`
- **비밀번호**: `root`
- **데이터베이스**: `starttoo`

### starttoo 사용자로 접속
```
호스트: localhost
포트: 3306
사용자명: starttoo
비밀번호: root
데이터베이스: starttoo
```

### root 사용자로 접속
```
호스트: localhost
포트: 3306
사용자명: root
비밀번호: root
데이터베이스: starttoo (또는 모든 데이터베이스 접근 가능)
```

## MySQL Workbench 접속 방법

1. MySQL Workbench 실행
2. "MySQL Connections"에서 "+" 버튼 클릭
3. 다음 정보 입력:
   - **Connection Name**: Starttoo MySQL
   - **Hostname**: localhost
   - **Port**: 3306
   - **Username**: starttoo
   - **Password**: root (Store in Keychain 체크)
   - **Default Schema**: starttoo
4. "Test Connection" 클릭하여 연결 테스트
5. "OK" 클릭하여 저장
6. 연결 더블클릭하여 접속

## DBeaver 접속 방법

1. DBeaver 실행
2. "새 연결" 클릭
3. "MySQL" 선택
4. 다음 정보 입력:
   - **호스트**: localhost
   - **포트**: 3306
   - **데이터베이스**: starttoo
   - **사용자명**: starttoo
   - **비밀번호**: root
5. "테스트 연결" 클릭
6. "완료" 클릭

## phpMyAdmin 접속 방법

phpMyAdmin을 사용하려면 docker-compose.yml에 phpMyAdmin 서비스를 추가해야 합니다.

## 명령줄에서 접속 (MySQL Client)

### Windows에서
```bash
mysql -h localhost -P 3306 -u starttoo -proot starttoo
```

### WSL/Linux에서
```bash
mysql -h localhost -P 3306 -u starttoo -proot starttoo
```

## 연결 테스트

### 컨테이너에서 연결 테스트
```bash
docker compose exec mysql mysql -ustarttoo -proot starttoo -e "SELECT 'Connection successful!' AS message;"
```

### 외부에서 연결 테스트
```bash
# MySQL 클라이언트가 설치되어 있는 경우
mysql -h localhost -P 3306 -u starttoo -proot -e "SELECT 'Connection successful!' AS message;"
```

## 문제 해결

### 연결이 안 되는 경우

1. **포트 확인**
   ```bash
   docker compose ps
   # starttoo_mysql 컨테이너가 실행 중인지 확인
   ```

2. **포트가 사용 중인지 확인**
   ```bash
   netstat -ano | findstr :3306
   # Windows에서
   ```

3. **컨테이너 재시작**
   ```bash
   docker compose restart mysql
   ```

4. **방화벽 확인**
   - Windows 방화벽에서 포트 3306이 허용되어 있는지 확인

5. **MySQL 사용자 권한 확인**
   ```bash
   docker compose exec mysql mysql -uroot -proot -e "SELECT user, host FROM mysql.user WHERE user='starttoo';"
   ```

## 추가 설정

### 원격 접속 허용 (필요한 경우)

MySQL 컨테이너에서 원격 접속을 허용하려면:

```bash
docker compose exec mysql mysql -uroot -proot -e "GRANT ALL PRIVILEGES ON *.* TO 'starttoo'@'%' IDENTIFIED BY 'root' WITH GRANT OPTION; FLUSH PRIVILEGES;"
```

