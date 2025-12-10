<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsernameAdjective;
use App\Models\UsernameNoun;
use Illuminate\Support\Collection;

class UsernameGeneratorService
{
    /**
     * 형용사/명사 조합으로 고유한 username 생성
     *
     * @param int $maxAttempts 최대 시도 횟수
     * @param string|null $tone 특정 tone으로 필터링 (선택사항)
     * @param string|null $category 특정 category로 필터링 (선택사항)
     * @return string
     * @throws \Exception
     */
    public function generateUniqueUsername(int $maxAttempts = 100, ?string $tone = null, ?string $category = null): string
    {
        // 필터링된 형용사/명사 리스트 가져오기
        $filteredAdjectives = $this->getFilteredAdjectives($tone);
        $filteredNouns = $this->getFilteredNouns($category);

        if ($filteredAdjectives->isEmpty() || $filteredNouns->isEmpty()) {
            throw new \Exception('필터링된 형용사 또는 명사가 없습니다.');
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $adjective = $filteredAdjectives->random();
            $noun = $filteredNouns->random();
            $number = rand(100, 999);

            $username = $adjective->word . $noun->word . $number;

            // 중복 체크
            if (!User::where('username', $username)->exists()) {
                return $username;
            }
        }

        // 최대 시도 횟수 초과 시 타임스탬프 추가
        $adjective = $filteredAdjectives->random();
        $noun = $filteredNouns->random();
        $timestamp = time();

        return $adjective->word . $noun->word . $timestamp;
    }

    /**
     * tone으로 필터링된 형용사 리스트 가져오기
     *
     * @param string|null $tone
     * @return Collection
     */
    private function getFilteredAdjectives(?string $tone = null): Collection
    {
        $query = UsernameAdjective::query();

        if ($tone !== null) {
            $query->where('tone', $tone);
        }

        return $query->get();
    }

    /**
     * category로 필터링된 명사 리스트 가져오기
     *
     * @param string|null $category
     * @return Collection
     */
    private function getFilteredNouns(?string $category = null): Collection
    {
        $query = UsernameNoun::query();

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query->get();
    }

    /**
     * 형용사 리스트 가져오기
     *
     * @return Collection
     */
    public function getAdjectives(): Collection
    {
        return UsernameAdjective::all();
    }

    /**
     * 명사 리스트 가져오기
     *
     * @return Collection
     */
    public function getNouns(): Collection
    {
        return UsernameNoun::all();
    }

    /**
     * 특정 tone의 형용사 리스트 가져오기
     *
     * @param string $tone
     * @return Collection
     */
    public function getAdjectivesByTone(string $tone): Collection
    {
        return $this->getFilteredAdjectives($tone);
    }

    /**
     * 특정 category의 명사 리스트 가져오기
     *
     * @param string $category
     * @return Collection
     */
    public function getNounsByCategory(string $category): Collection
    {
        return $this->getFilteredNouns($category);
    }
}
