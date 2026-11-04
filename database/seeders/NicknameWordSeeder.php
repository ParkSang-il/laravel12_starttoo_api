<?php

namespace Database\Seeders;

use App\Services\ProjectDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 스트릿캣 자동 닉네임: "{수식어} {명사}#{계정ID}". */
class NicknameWordSeeder extends Seeder
{
    private const ADJECTIVES = <<<'WORDS'
다정한 상냥한 친절한 씩씩한 차분한 솔직한 순한 착한
성실한 꼼꼼한 부지런한 꾸준한 든든한 느긋한 조용한 순수한
온화한 겸손한 정직한 용감한 대담한 침착한 담담한 유쾌한
명랑한 쾌활한 활발한 발랄한 다감한 섬세한 세심한 신중한
현명한 영리한 똑똑한 총명한 영특한 기특한 대견한 의젓한
늠름한 듬직한 믿음직한 착실한 건실한 알뜰한 살뜰한 엉뚱한
천진한 무던한 털털한 수수한 소박한 담백한 진솔한 진중한
우직한 묵묵한 꿋꿋한 당당한 싹싹한 공손한 얌전한 조신한
참한 단정한 반듯한 근면한 태평한 도도한 새침한 시크한
쿨한 수더분한 순박한 다소곳한 노련한 능숙한 진득한 비범한
말랑한 몰랑한 말캉한 폭신한 푹신한 폭닥한 보송한 뽀송한
보들한 몽글한 몽실한 뭉실한 복슬한 북슬한 보슬한 포슬한
소복한 통통한 포동한 토실한 포실한 오동통한 동글한 매끈한
반질한 나른한 노곤한 꼬순한 꼬질한 쫀득한 쫄깃한 살랑한
사뿐한 날렵한 민첩한 유연한 우아한 고고한 늘씬한 날씬한
길쭉한 짤막한 땅딸한 오묘한 신비한 영롱한 초롱한 또렷한
말똥한 깜찍한 꼬물한 토실토실한 몽글몽글한 말랑말랑한
폭신폭신한 보송보송한 포동포동한
포근한 따뜻한 따스한 따끈한 뜨끈한 아늑한 넉넉한 푸근한
소중한 귀한 특별한 행복한 산뜻한 향긋한 달콤한 고소한
노릇한 바삭한 촉촉한 상큼한 청량한 시원한 선선한 화사한
환한 화창한 쾌청한 청명한 평온한 고요한 잔잔한 은은한
은근한 훈훈한 아련한 근사한 신기한 희한한 기발한 풍성한
풍족한 친근한 익숙한 소소한 아담한 자그마한 조그마한 우람한
거대한 무사한 건강한 튼튼한 튼실한 탄탄한 단단한 굳건한
거뜬한 가뿐한 홀가분한 개운한 말짱한 쌩쌩한 싱싱한 생생한
팔팔한 한적한 고즈넉한 호젓한 오붓한 단란한 화목한 흐뭇한
뿌듯한 뭉클한 애틋한 짠한 대단한 굉장한 위대한 영험한
용한 수상한 깔끔한 말끔한 정갈한
WORDS;

    private const NOUNS = <<<'WORDS'
집사 초보집사 수습집사 막내집사 프로집사 수석집사 대장집사
골목집사 동네집사 길냥집사 새벽집사 밤집사 주말집사
밥지기 냥지기 골목지기 급식소지기 숨숨집지기 물그릇지기
밥그릇지기 담벼락지기 화단지기 츄르지기 박스지기
급식반장 골목반장 사료반장 냥반장 밥당번 물당번 순찰당번
냥배달부 사료배달부 츄르배달부 간식배달부 행복배달부
츄르장인 꾹꾹이장인 숨숨집장인 박스장인 급식장인 식빵장인
냥이친구 길냥친구 고양이친구 골목친구 산책친구 동네친구
밥친구 털친구 꼬리친구 냥이이웃
츄르요정 간식요정 사료요정 밥요정
냥수호자 골목수호자 밤수호자
캔따개 밥셔틀 츄르셔틀 간식셔틀 캔셔틀
골목대장 급식대장 사료대장 츄르대장 냥이대장
밤산책러 새벽산책러 냥순찰대 골목순찰대
냥탐정 골목탐정 냥기자 냥사진사 냥작가 냥박사 냥선생
냥매니저 냥비서 냥셰프 츄르셰프 냥바리스타
냥이편 고양이편 냥이팬 냥덕후 냥러버
고양이 냥이 야옹이 나비 길냥이 동네냥 골목냥 담장냥
아깽이 아기냥 꼬마냥 뚱냥 식빵냥 치즈냥 고등어냥 삼색냥
턱시도냥 카오스냥 젖소냥 까망냥 하양냥 회색냥 노랑냥 냥아치
젤리 발바닥 솜방망이 앞발 꼬리 수염 꼬순내 털뭉치
털복숭이 냥냥펀치 꾹꾹이 골골송
츄르 참치캔 캔간식 사료 숨숨집 스크래쳐 캣타워 캣닢
마따따비 낚싯대 털실뭉치 방울 방석 담요 핫팩 쿠션
박스 종이상자 밥그릇 물그릇
WORDS;

    public function run(): void
    {
        // Never write the legacy/default database from a project seeder.
        $this->seedConnection(app(ProjectDatabase::class)->connectionName());
    }

    public function seedConnection(string $connection): void
    {
        $now = now();
        $rows = [];
        foreach (['adjective' => self::ADJECTIVES, 'noun' => self::NOUNS] as $type => $words) {
            foreach (array_unique(preg_split('/\s+/u', trim($words))) as $word) {
                $rows[] = ['type' => $type, 'word' => $word, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::connection($connection)->table('nickname_words')->upsert($chunk, ['type', 'word'], ['is_active', 'updated_at']);
        }
    }
}
