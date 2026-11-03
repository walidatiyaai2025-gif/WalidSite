<?php

namespace App\Http\Controllers;

use App\Models\CareerPosition;
use App\Models\Profile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminAboutCareerController extends Controller
{
    private function profile(Request $request): Profile
    {
        abort_unless($request->user()?->status === 'active'
            && $request->user()->hasRole('owner'), 403);
        return Profile::query()->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function position(Request $request, CareerPosition $position): CareerPosition
    {
        $profile = $this->profile($request);
        abort_unless($profile->id === $position->profile_id, 404);
        return $position;
    }

    /** Validate year precision only; do not invent exact month/day of employment. */
    private function fields(Request $request): array
    {
        $input = $request->validate([
            'org_name' => ['required','string','min:2','max:200'],
            'role_en' => ['required','string','min:2','max:200'],
            'role_ar' => ['required','string','min:2','max:200'],
            'start_year' => ['required','integer','between:1900,2100'],
            'end_year' => ['nullable','integer','between:1900,2100'],
        ]);
        foreach (['org_name','role_en','role_ar'] as $field) {
            $input[$field] = trim($input[$field]);
            if (preg_match('/[<>]|[\x00-\x1f]/u', $input[$field])) {
                throw ValidationException::withMessages([$field => 'HTML and control characters are not allowed.']);
            }
        }
        $input['start_year'] = (int) $input['start_year'];
        $input['end_year'] = ($input['end_year'] ?? null) === null
            || ($input['end_year'] ?? '') === '' ? null : (int) $input['end_year'];
        if ($input['end_year'] !== null && $input['end_year'] < $input['start_year']) {
            throw ValidationException::withMessages(['end_year'=>'End year must not be before start year.']);
        }
        return $input;
    }

    public function create(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        $input = $this->fields($request);
        DB::transaction(function () use ($profile,$input,$request): void {
            if (CareerPosition::query()->where('profile_id',$profile->id)
                ->where('org_name',$input['org_name'])
                ->whereDate('started_on',sprintf('%04d-01-01',$input['start_year']))->exists()) {
                throw ValidationException::withMessages(['org_name'=>'This employer/year period already exists.']);
            }
            $row = CareerPosition::query()->create([
                'profile_id'=>$profile->id, 'org_name'=>$input['org_name'],
                'role_en'=>$input['role_en'], 'role_ar'=>$input['role_ar'],
                'started_on'=>sprintf('%04d-01-01',$input['start_year']),
                'ended_on'=>$input['end_year'] === null ? null : sprintf('%04d-12-31',$input['end_year']),
                'verified'=>false, 'publishable'=>false,
            ]);
            $row->forceFill([
                'date_precision'=>'year', 'cms_revision'=>1,
                'cms_edited_by'=>$request->user()->id,
                'draft_json'=>$input, 'source_refs_json'=>json_encode(['owner_cms_draft']),
            ])->save();
            $this->event($row,$request,'created_private',null,json_encode($input),null);
        }, 3);
        return $this->back($request,'Private career draft created. Publish only after source review.');
    }

    public function save(Request $request, CareerPosition $position): RedirectResponse
    {
        $position = $this->position($request,$position);
        $input = $this->fields($request);
        $data = $request->validate(['revision'=>['required','integer','min:0']]);
        DB::transaction(function () use ($position,$input,$data,$request): void {
            $row = CareerPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->cms_revision === (int) $data['revision'],409);
            $before = $row->draft_json;
            $row->forceFill([
                'draft_json'=>$input,'cms_edited_by'=>$request->user()->id,
                'cms_revision'=>$row->cms_revision+1,
            ])->save();
            $this->event($row,$request,'draft_saved',json_encode($before),json_encode($input),null);
        },3);
        return $this->back($request,'Career changes saved privately. Public timeline unchanged.');
    }

    public function publish(Request $request, CareerPosition $position): RedirectResponse
    {
        $position = $this->position($request,$position);
        $data = $request->validate([
            'revision'=>['required','integer','min:0'],
            'source_reviewed'=>['accepted'],
            'publication_rights_confirmed'=>['accepted'],
            'source_note'=>['required','string','min:12','max:240'],
        ]);
        DB::transaction(function () use ($position,$data,$request): void {
            $row = CareerPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->cms_revision === (int) $data['revision'],409);
            $draft = $row->draft_json;
            if (! is_array($draft) || empty($draft['role_en'])) {
                throw ValidationException::withMessages(['role_en'=>'No private career draft to publish.']);
            }
            $duplicate = CareerPosition::query()->where('profile_id',$row->profile_id)
                ->where('id','!=',$row->id)->where('org_name',$draft['org_name'])
                ->whereDate('started_on',sprintf('%04d-01-01',$draft['start_year']))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['org_name'=>'This employer/year period already exists.']);
            }
            $before = json_encode([$row->org_name,$row->role_en,$row->role_ar,$row->started_on,$row->ended_on]);
            $row->forceFill([
                'org_name'=>$draft['org_name'], 'role_en'=>$draft['role_en'],
                'role_ar'=>$draft['role_ar'],
                'started_on'=>sprintf('%04d-01-01',$draft['start_year']),
                'ended_on'=>$draft['end_year'] === null ? null : sprintf('%04d-12-31',$draft['end_year']),
                'verified'=>true,'publishable'=>true,'date_precision'=>'year',
                'draft_json'=>null,'cms_published_by'=>$request->user()->id,
                'cms_source_note'=>trim($data['source_note']),
                'cms_revision'=>$row->cms_revision+1,
            ])->save();
            $this->event($row,$request,'published',$before,json_encode($draft),trim($data['source_note']));
        },3);
        return $this->back($request,'Source-reviewed career period published.');
    }

    public function retract(Request $request, CareerPosition $position): RedirectResponse
    {
        $position = $this->position($request,$position);
        $data = $request->validate(['revision'=>['required','integer','min:0']]);
        DB::transaction(function () use ($position,$data,$request): void {
            $row = CareerPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->cms_revision === (int) $data['revision'],409);
            $before = $row->publishable ? 'published' : 'unpublished';
            $row->forceFill(['publishable'=>false,'cms_revision'=>$row->cms_revision+1])->save();
            $this->event($row,$request,'retracted',$before,'unpublished',null);
        },3);
        return $this->back($request,'Career period withdrawn from public timeline.');
    }

    private function event(CareerPosition $row,Request $request,string $action,
        ?string $before,?string $after,?string $note): void
    {
        DB::table('career_position_edit_events')->insert([
            'id'=>(string) Str::uuid(),'career_position_id'=>$row->id,
            'actor_id'=>$request->user()->id,'action'=>$action,
            'revision'=>$row->cms_revision,'source_note'=>$note,
            'old_hash'=>$before === null ? null : hash('sha256',$before),
            'new_hash'=>$after === null ? null : hash('sha256',$after),
            'created_at'=>now('UTC'),
        ]);
    }

    private function back(Request $request,string $message): RedirectResponse
    {
        return redirect()->route('admin.page-content.index',[
            'page_key'=>'about','section_key'=>'career',
            'lang'=>$request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status',$message);
    }
}
