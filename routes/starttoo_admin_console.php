<?php

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AdminAccounts;
use Illuminate\Support\Facades\Artisan;

// 운영 콘솔(/admin) 관리자 계정. 계정 만들기 · 인증 앱 다시 등록은 서버 쉘에서만 한다.
// 만들기) php artisan starttoo:admin-create ops@starttoo.com 운영관리자 --role=super
//         → 비밀번호는 실행 중에 입력(화면에 안 보임). 끝나면 인증 앱(Google Authenticator 등)에 넣을 키가 한 번 나온다.
// 역할) super 최고 관리자 · operator 운영자 · cs CS 담당 · viewer 열람 전용
Artisan::command('starttoo:admin-create {email} {name} {--role=operator}', function () {
    $password = (string) $this->secret('비밀번호(12자 이상)');
    if ($password !== (string) $this->secret('비밀번호 다시 입력')) { $this->error('비밀번호가 서로 달라요.'); return 1; }
    try {
        $r = app(ProjectDatabase::class)->run('starttoo', fn () => app(AdminAccounts::class)
            ->create((string) $this->argument('email'), (string) $this->argument('name'), (string) $this->option('role'), $password));
    } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
    $this->info("관리자 {$r['id']}번을 만들었어요.");
    $this->line('인증 앱에서 「설정 키 입력」으로 아래 키를 넣어주세요(이 화면에서 한 번만 보여요).');
    $this->line('  키: '.trim(chunk_split($r['secret'], 4, ' ')));
    $this->line('  또는 otpauth 주소: '.$r['otpauth']);
    return 0;
})->purpose('운영 콘솔 관리자 만들기(비밀번호 입력 · 2단계 인증 키 발급)');

// 인증 앱 다시 등록(휴대폰 분실 · 변경) → 새 키가 나온다. 이전 키는 바로 못 쓴다.
Artisan::command('starttoo:admin-otp {email}', function () {
    try {
        $r = app(ProjectDatabase::class)->run('starttoo', fn () => app(AdminAccounts::class)->resetOtp((string) $this->argument('email')));
    } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
    $this->info('새 인증 키를 만들었어요(이 화면에서 한 번만 보여요).');
    $this->line('  키: '.trim(chunk_split($r['secret'], 4, ' ')));
    $this->line('  또는 otpauth 주소: '.$r['otpauth']);
    return 0;
})->purpose('운영 콘솔 관리자 2단계 인증 다시 등록');

// 역할 바꾸기 · 비밀번호 바꾸기 · 사용 중지 · 다시 사용
// 예) php artisan starttoo:admin role ops@starttoo.com --role=cs   ·   password ops@starttoo.com   ·   off ops@starttoo.com   ·   on ops@starttoo.com
Artisan::command('starttoo:admin {action} {email} {--role=}', function () {
    $accounts = app(AdminAccounts::class);
    $email = (string) $this->argument('email');
    $action = (string) $this->argument('action');
    $password = $action === 'password' ? (string) $this->secret('새 비밀번호(12자 이상)') : '';
    try {
        app(ProjectDatabase::class)->run('starttoo', function () use ($accounts, $email, $action, $password) {
            match ($action) {
                'role' => $accounts->setRole($email, (string) $this->option('role')),
                'password' => $accounts->setPassword($email, $password),
                'off' => $accounts->setDisabled($email, true),
                'on' => $accounts->setDisabled($email, false),
                default => throw new \InvalidArgumentException('action 은 role · password · off · on 중 하나예요.'),
            };
        });
    } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
    $this->info('바꿨어요.');
    return 0;
})->purpose('운영 콘솔 관리자 역할 · 비밀번호 · 사용 중지');
