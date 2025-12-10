<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\UsernameGeneratorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usernameGenerator = new UsernameGeneratorService();

        // UsernameGeneratorService는 데이터베이스에서 형용사/명사를 가져옵니다.
        // 형용사/명사 데이터는 UsernameAdjectiveSeeder와 UsernameNounSeeder에서 관리됩니다.

        // 시드할 사용자 수 (필요시 수정)
        $userCount = 10;

        // 트랜잭션 시작
        DB::beginTransaction();

        try {
            for ($i = 0; $i < $userCount; $i++) {
                $phone = '010' . str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);

                // 중복 체크
                while (User::where('phone', $phone)->exists()) {
                    $phone = '010' . str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
                }

                User::create([
                    'user_type' => rand(1, 2), // 1: 일반 회원, 2: 사업자
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'username' => $usernameGenerator->generateUniqueUsername(),
                    'profile_image' => null,
                ]);
            }

            DB::commit();
            $this->command->info("{$userCount}명의 사용자가 생성되었습니다.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("사용자 생성 중 오류 발생: " . $e->getMessage());
            throw $e;
        }
    }
}

