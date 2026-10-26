<?php
namespace App\Http\Controllers;
use App\Services\GroupCareService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class GroupCareController extends Controller {
    public function __construct(private GroupCareService $care) {}
    private function input(Request $r,array $rules):array {
        if(array_diff(array_keys($r->all()),array_keys($rules))) throw ValidationException::withMessages(['request'=>['허용되지 않은 입력입니다.']]);
        return $r->validate($rules);
    }
    public function index(Request $r) {
        $d=$this->input($r,['page'=>['sometimes','integer','min:1','max:1000']]);
        return response()->json(['data'=>$this->care->index($r->user(),$r->route('group'),$r->route('place'),(int)($d['page']??1))]);
    }
    public function create(Request $r) {
        $d=$this->input($r,['request_id'=>['required','uuid'],'fed_at'=>['required','date','before_or_equal:'.now()->addMinute()->toIso8601String()],
            'food_amount_gram'=>['nullable','integer','min:1','max:1000000'],'cost'=>['required','integer','min:0','max:100000000'],
            'tags'=>['present','array','max:30'],'tags.*'=>['string','max:80'], 'sightings'=>['present','array','max:500'],
            'sightings.*'=>[Rule::in(['seen','notSeen','unknown'])],'memo'=>['nullable','string','max:500'],
            'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],
            'gps_accuracy'=>['nullable','numeric','min:0','max:100000']]);
        if(($d['latitude']??null)===null xor ($d['longitude']??null)===null) throw ValidationException::withMessages(['latitude'=>['좌표는 함께 보내 주세요.']]);
        foreach(['memo'=>'','food_amount_gram'=>null,'latitude'=>null,'longitude'=>null,'gps_accuracy'=>null] as $k=>$v) $d[$k]=$d[$k]??$v;
        abort_if($d['latitude']===null && $d['gps_accuracy']!==null,422,'좌표 없이 정확도를 보낼 수 없습니다.');
        return response()->json(['data'=>$this->care->create($r->user(),$r->route('group'),$r->route('place'),$d)],201);
    }
}
