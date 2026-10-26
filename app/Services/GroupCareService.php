<?php
namespace App\Services;
use App\Models\ProjectAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
class GroupCareService {
    public function __construct(private ProjectDatabase $database, private GroupPlaceService $places) {}
    private function table(string $name) { return $this->database->database()->table($name); }
    private function summary(object $row): array {
        return ['id'=>$row->id,'place_id'=>$row->place_id,'group_id'=>$row->group_id,'created_by'=>(int)$row->created_by,
            'display_name'=>$this->table('project_accounts')->where('id',$row->created_by)->value('display_name'),
            'fed_at'=>CarbonImmutable::parse($row->fed_at,'UTC')->toIso8601String(),
            'food_amount_gram'=>$row->food_amount_gram === null ? null : (int)$row->food_amount_gram,
            'cost'=>(int)$row->cost,'tags'=>json_decode($row->tags,true),'sightings'=>(object)json_decode($row->sightings,true),'memo'=>$row->memo];
    }
    public function index(ProjectAccount $actor,string $group,string $place,int $page): array {
        $this->places->show($actor,$group,$place);
        $rows=$this->table('group_care_records')->where('project_id',$actor->project_id)->where('group_id',$group)->where('place_id',$place)
            ->orderByDesc('fed_at')->orderBy('id')->offset(($page-1)*20)->limit(21)->get();
        return ['records'=>$rows->take(20)->map(fn($r)=>$this->summary($r))->all(),'page'=>$page,'has_more'=>$rows->count()>20];
    }
    public function create(ProjectAccount $actor,string $group,string $place,array $data): array {
        return $this->database->database()->transaction(function()use($actor,$group,$place,$data) {
            $this->table('care_groups')->where('project_id',$actor->project_id)->where('id',$group)->lockForUpdate()->firstOrFail();
            $access=$this->places->show($actor,$group,$place);
            abort_unless($access['can_manage'] || $access['access']==='care',403,'이 장소에 돌봄 기록을 남길 권한이 없습니다.');
            $data['fed_at']=CarbonImmutable::parse($data['fed_at'])->utc()->format('Y-m-d H:i:s');
            $data['tags']=array_values(array_unique($data['tags'])); sort($data['tags']); ksort($data['sightings']);
            ksort($data);
            $hash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
            $existing=$this->table('group_care_records')->where('group_id',$group)->where('created_by',$actor->id)->where('request_id',$data['request_id'])->first();
            if($existing) { abort_unless(hash_equals($existing->payload_hash,$hash) && $existing->place_id===$place,409,'요청 내용이 바뀌었습니다. 새 요청으로 저장해 주세요.'); return $this->summary($existing); }
            $allowed=$this->table('group_animals as a')->join('group_animal_places as l','l.animal_id','=','a.id')
                ->where('a.project_id',$actor->project_id)->where('a.group_id',$group)->where('l.group_id',$group)->where('l.place_id',$place)
                ->whereIn('a.life_status',['active','missing'])->pluck('a.id')->all();
            abort_if(array_diff(array_keys($data['sightings']),$allowed),422,'현재 장소에서 돌보는 친구만 선택할 수 있습니다. 새로 조회해 주세요.');
            $id=(string)Str::uuid();
            $this->table('group_care_records')->insert([...$data,'tags'=>json_encode($data['tags'],JSON_THROW_ON_ERROR),
                'sightings'=>json_encode((object)$data['sightings'],JSON_THROW_ON_ERROR),'id'=>$id,'project_id'=>$actor->project_id,
                'group_id'=>$group,'place_id'=>$place,'created_by'=>$actor->id,'payload_hash'=>$hash,'created_at'=>now(),'updated_at'=>now()]);
            $this->table('group_audit_events')->insert(['project_id'=>$actor->project_id,'group_id'=>$group,'actor_id'=>$actor->id,
                'event'=>'care_record_created','metadata'=>json_encode(['record_id'=>$id,'place_id'=>$place],JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $this->summary($this->table('group_care_records')->where('id',$id)->firstOrFail());
        },3);
    }
}
