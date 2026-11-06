<?php
namespace App\Services;
use App\Models\ProjectAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
class GroupCareService {
    public function __construct(private ProjectDatabase $database, private GroupPlaceService $places, private GroupProfile $profiles, private GroupMessageService $messages) {}
    private function table(string $name) { return $this->database->database()->table($name); }
    private function records() {
        return $this->table('group_care_records as r')->leftJoin('project_accounts as author', function ($join) {
            $join->on('author.id', '=', 'r.created_by')->on('author.project_id', '=', 'r.project_id');
        })->select('r.*', 'r.created_by as account_id', 'author.display_name', 'author.nickname_tag', 'author.avatar_url', 'author.avatar_thumbnail_url', 'author.avatar_disk', 'author.avatar_path', 'author.avatar_thumbnail_path');
    }
    private function summary(object $row): array {
        $profile = $this->profiles->summary($row);
        return ['id'=>$row->id,'place_id'=>$row->place_id,'group_id'=>$row->group_id,'created_by'=>(int)$row->created_by,
            'author_account_id'=>$profile['account_id'], 'display_name'=>$profile['display_name'],
            'nickname_tag'=>$profile['nickname_tag'], 'nickname'=>$profile['nickname'],
            'avatar_url'=>$profile['avatar_url'], 'avatar_thumbnail_url'=>$profile['avatar_thumbnail_url'],
            'fed_at'=>CarbonImmutable::parse($row->fed_at,'UTC')->toIso8601String(),
            'food_amount_gram'=>$row->food_amount_gram === null ? null : (int)$row->food_amount_gram,
            'cost'=>(int)$row->cost,'tags'=>json_decode($row->tags,true),'sightings'=>(object)json_decode($row->sightings,true),'memo'=>$row->memo];
    }
    public function index(ProjectAccount $actor,string $group,string $place,int $page): array {
        $this->places->show($actor,$group,$place);
        $rows=$this->records()->where('r.project_id',$actor->project_id)->where('r.group_id',$group)->where('r.place_id',$place)
            ->orderByDesc('r.fed_at')->orderBy('r.id')->offset(($page-1)*20)->limit(21)->get();
        return ['records'=>$rows->take(20)->map(fn($r)=>$this->summary($r))->all(),'page'=>$page,'has_more'=>$rows->count()>20];
    }
    public function show(ProjectAccount $actor, string $group, string $place, string $record): array {
        // Reuse the same active membership and place grant checks as record lists.
        $access = $this->places->show($actor, $group, $place);
        $row = $this->records()->where('r.project_id', $actor->project_id)
            ->where('r.group_id', $group)->where('r.place_id', $place)->where('r.id', $record)->first();
        abort_unless($row, 404, '돌봄 기록을 찾을 수 없습니다.');
        $summary = $this->summary($row);
        $animals = $this->table('group_animals as a')
            ->join('group_animal_places as l', function ($join) {
                $join->on('l.animal_id', '=', 'a.id')->on('l.group_id', '=', 'a.group_id');
            })
            ->where('a.project_id', $actor->project_id)->where('a.group_id', $group)
            ->where('l.place_id', $place)->whereIn('a.id', array_keys((array) $summary['sightings']))
            ->select('a.id', 'a.name')->get();
        return ['record' => $summary, 'place' => $access,
            'group_name' => $this->table('care_groups')->where('project_id', $actor->project_id)->where('id', $group)->value('name'),
            'animals' => $animals->map(fn ($animal) => ['id' => $animal->id, 'name' => $animal->name])->all()];
    }
    public function create(ProjectAccount $actor,string $group,string $place,array $data): array {
        [$result, $messageId] = $this->database->database()->transaction(function()use($actor,$group,$place,$data) {
            $this->table('care_groups')->where('project_id',$actor->project_id)->where('id',$group)->lockForUpdate()->firstOrFail();
            $access=$this->places->show($actor,$group,$place);
            abort_unless($access['can_manage'] || $access['access']==='care',403,'이 장소에 돌봄 기록을 남길 권한이 없습니다.');
            $data['fed_at']=CarbonImmutable::parse($data['fed_at'])->utc()->format('Y-m-d H:i:s');
            $data['tags']=array_values(array_unique($data['tags'])); sort($data['tags']); ksort($data['sightings']);
            ksort($data);
            $hash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
            $existing=$this->table('group_care_records')->where('group_id',$group)->where('created_by',$actor->id)->where('request_id',$data['request_id'])->first();
            if($existing) { abort_unless(hash_equals($existing->payload_hash,$hash) && $existing->place_id===$place,409,'요청 내용이 바뀌었습니다. 새 요청으로 저장해 주세요.'); return [$this->summary($this->records()->where('r.id', $existing->id)->firstOrFail()), null]; }
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
            return [$this->summary($this->records()->where('r.id',$id)->firstOrFail()),
                $this->messages->insertCareRecord((int) $actor->project_id, $group, (int) $actor->id, $id)];
        },3);
        if ($messageId !== null) $this->messages->announce($group, $messageId);
        return $result;
    }
}
