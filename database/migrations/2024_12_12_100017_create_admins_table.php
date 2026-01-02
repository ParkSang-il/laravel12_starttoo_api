<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->string('username', 50)->unique()->comment('관리자 아이디');
            $table->string('password')->comment('비밀번호 (암호화)');
            $table->string('role', 20)->default('admin')->comment('권한 (super_admin: 최고관리자, admin: 일반관리자)');
            $table->string('name', 100)->nullable()->comment('이름');
            $table->timestamp('last_login_at')->nullable()->comment('마지막 로그인 일시');
            $table->string('last_login_ip', 45)->nullable()->comment('마지막 로그인 IP');
            $table->timestamps();
            $table->softDeletes();

            $table->index('username', 'idx_username');
            $table->index('role', 'idx_role');
        });

        DB::statement("ALTER TABLE admins COMMENT = '관리자 테이블'");

        // 최고 관리자 초기 계정 생성
        DB::table('admins')->insert([
            'username' => 'admin',
            'password' => Hash::make('1234'),
            'role' => 'super_admin',
            'name' => '최고 관리자',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};

