@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($trackNames = [
    'all' => ['en' => 'All verified skills', 'ar' => 'كل المهارات الموثقة'],
    'database' => ['en' => 'Database engineering', 'ar' => 'هندسة قواعد البيانات'],
    'software' => ['en' => 'Software and infrastructure', 'ar' => 'البرمجيات والبنية التحتية'],
    'delivery' => ['en' => 'Project delivery', 'ar' => 'إدارة وتسليم المشاريع'],
])
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'السيرة العامة — وليد عطية' : 'Public resume — Waleed Atiya')
@push('styles')
<style>
.resume{max-width:980px;padding-block:45px}.resume h1,.resume h2{color:var(--wa-primary)}
.resume section{background:#fff;border:1px solid var(--wa-soft-border);padding:24px;border-radius:18px;margin-block:18px}
.resume article{padding:13px 0;border-top:1px solid var(--wa-soft-border)}
.resume button{background:var(--wa-cta);border:0;border-radius:10px;color:#fff;padding:12px 18px;font:inherit;min-height:44px;cursor:pointer}
.resume-track-form{display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin:24px 0}
.resume-track-form label{display:block;font-weight:700;margin-bottom:6px}
.resume-track-form select{min-height:44px;min-width:220px;max-width:100%;font:inherit;padding:8px 12px;border:1px solid #97B4C8;border-radius:10px;background:#fff;color:var(--wa-strong)}
.resume-track-note{color:var(--wa-body)}
.resume-skill-tags{display:flex;flex-wrap:wrap;gap:10px;list-style:none;margin:0;padding:0}
.resume-skill-tags li{padding:8px 12px;background:var(--wa-muted-surface);border:1px solid var(--wa-soft-border);border-radius:10px}
.resume-actions{display:flex;flex-wrap:wrap;align-items:center;gap:16px}
@media print{.site-header,.site-footer,.resume-actions,.resume-track-form,.resume-track-note{display:none!important}.resume{padding:0}.resume section{break-inside:avoid}body{background:#fff}}
</style>
@endpush
@section('content')
<div class="site-wrap resume">
<h1>{{ $locale === 'ar' ? 'السيرة المهنية العامة' : 'Public resume' }}</h1>
<p>{{ $locale === 'ar' ? 'لا تظهر سوى الخبرات والشهادات المعتمدة للنشر بعد التحقق من الأدلة.' : 'Only evidence-verified experience and approved credentials are included.' }}</p>
<form class="resume-track-form" method="get" action="{{ route('public.resume') }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="resume-track">{{ $locale === 'ar' ? 'مسار السيرة المهنية' : 'Resume focus' }}</label>
<select id="resume-track" name="track">
@foreach($trackNames as $key => $names)
<option value="{{ $key }}" @selected($track === $key)>{{ $names[$locale] }}</option>
@endforeach
</select></div>
<button type="submit">{{ $locale === 'ar' ? 'تطبيق المسار' : 'Apply focus' }}</button>
</form>
<p class="resume-track-note">{{ $locale === 'ar'
? 'يُبرز المسار مهارات معتمدة فقط؛ سجل الخبرات يبقى كما تم التحقق منه دون تغيير المسميات الوظيفية.'
: 'Focus changes the verified skills shown; it never rewrites job titles or employment history.' }}</p>
<div class="resume-actions"><button type="button" onclick="window.print()">{{ $locale === 'ar' ? 'طباعة أو حفظ PDF' : 'Print or save PDF' }}</button>
<a href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a></div>
@if(!$profile)
<section role="status">{{ $locale === 'ar' ? 'السيرة العامة قيد المراجعة؛ لن ننشر بيانات خاصة أو غير موثقة.' : 'Public CV review is pending; private and unverified details remain hidden.' }}</section>
@else
<section><h2>{{ $profile->public_name }}</h2></section>
<section><h2>{{ $locale === 'ar' ? 'المهارات الموثقة' : 'Verified skills' }} — {{ $trackNames[$track][$locale] }}</h2>
@forelse($skills->groupBy('category') as $category => $group)
<h3>{{ $category }}</h3>
<ul class="resume-skill-tags">@foreach($group as $skill)<li>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</li>@endforeach</ul>
@empty
<p role="status">{{ $locale === 'ar' ? 'لا توجد مهارات معتمدة للنشر في هذا المسار حتى الآن.' : 'No skills in this focus have completed public evidence review.' }}</p>
@endforelse
</section>
<section><h2>{{ $locale === 'ar' ? 'الخبرات المعتمدة' : 'Verified experience' }}</h2>
@forelse($positions as $position)
<article><h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }}</h3>
<p>{{ $position->org_name }} — {{ $position->started_on?->format('Y') }} / {{ $position->ended_on?->format('Y') ?? ($locale === 'ar' ? 'حتى الآن' : 'Present') }}</p></article>
@empty
<p>{{ $locale === 'ar' ? 'لا توجد خبرات منشورة ومعتمدة بعد.' : 'No verified career periods are published yet.' }}</p>
@endforelse
</section>
<section><h2>{{ $locale === 'ar' ? 'الشهادات المتحقق منها' : 'Verified credentials' }}</h2>
@forelse($certifications as $certificate)
<article><h3>{{ $certificate->title }}</h3>
@if($certificate->issuer)<p>{{ $certificate->issuer }}</p>@endif</article>
@empty
<p>{{ $locale === 'ar' ? 'لا توجد شهادات مؤكدة للنشر حاليًا.' : 'No approved credential evidence is available yet.' }}</p>
@endforelse
</section>
@endif
</div>
@endsection
