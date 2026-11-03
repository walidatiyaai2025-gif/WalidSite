@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($isArabic = $locale === 'ar')
@extends('layouts.site')
@section('title', $project['name'].' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => 'en']) }}">
@endpush
@push('styles')
<style>
body:has(.project-case-live){background:#f1f8fc}
body:has(.project-case-live) .site-header{display:none}
.project-case-live{font-family:Inter,Arial,sans-serif;color:#143d65}
:lang(ar) .project-case-live{font-family:Tajawal,Arial,sans-serif}
.project-case-live *{box-sizing:border-box}
.project-case-live a:focus-visible{outline:3px solid #126de1;outline-offset:3px}
.pc-nav{background:#163f68;color:white}
.pc-wrap{max-width:1250px;padding-inline:clamp(18px,4vw,48px);margin:0 auto}
.pc-nav .pc-wrap{min-height:76px;display:flex;align-items:center;gap:clamp(12px,2vw,28px)}
.pc-brand{color:white;text-decoration:none;font-weight:800;font-size:22px;white-space:nowrap}
.pc-brand b{color:#04bca9;font-size:29px}
.pc-links{display:flex;gap:clamp(10px,2.5vw,32px);align-items:center;overflow-x:auto;white-space:nowrap;flex:1}
.pc-links a{color:#eaf3f7;text-decoration:none;min-height:44px;display:inline-flex;align-items:center}
.pc-links a[aria-current]{color:#14d2b5;border-bottom:3px solid #14d2b5}
.pc-contact{background:#08bba9;color:#093c54!important;border-radius:10px;padding:10px 16px;font-weight:800;text-decoration:none;white-space:nowrap}
.pc-breadcrumb{display:flex;flex-wrap:wrap;gap:10px;padding:26px 0;color:#2d7282}
.pc-breadcrumb a{color:#007e78;font-weight:800}
.pc-main{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(290px,.9fr);gap:30px;padding-bottom:36px}
.pc-hero{background:linear-gradient(130deg,#154d74,#0a8e91);border-radius:22px;min-height:335px;display:flex;flex-direction:column;justify-content:space-between;padding:clamp(23px,3vw,37px);color:white;position:relative;overflow:hidden}
.pc-hero::before{content:'';position:absolute;width:280px;height:280px;border:3px solid #ffffff22;border-radius:70px;transform:rotate(25deg);right:-90px;bottom:-100px}
.pc-chip{background:#ffffff29;border:1px solid #ffffff5c;padding:8px 13px;border-radius:25px;font-size:13px;font-weight:800;align-self:flex-start}
.pc-hero h1{color:white;font-size:clamp(28px,4.3vw,52px);max-width:16ch;line-height:1.18;margin:16px 0;overflow-wrap:anywhere}
.pc-hero p{color:#e6fcfc;font-size:17px;line-height:1.7;max-width:60ch;margin:0}
.pc-visual{font-size:40px;align-self:flex-end;color:#adfff3;letter-spacing:.08em;font-weight:900}
.pc-facts{display:flex;flex-direction:column;gap:16px}
.pc-panel{background:#fff;border:1px solid #d5e6ee;border-radius:17px;padding:23px;box-shadow:0 6px 17px #194d700c}
.pc-panel h2{color:#123d65;font-size:21px;margin:0 0 14px}
.pc-facts dl{display:grid;grid-template-columns:minmax(95px,.36fr) 1fr;gap:12px;align-items:start;margin:0}
.pc-facts dt{color:#56809a;font-weight:700}
.pc-facts dd{margin:0;color:#174569;overflow-wrap:anywhere}
.pc-status{display:inline-block;background:#e0f5ec;color:#047c64;border-radius:22px;padding:4px 12px;font-weight:800}
.pc-body{padding-bottom:65px}
.pc-body-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(270px,.38fr);gap:26px}
.pc-body p,.pc-body li{font-size:16px;line-height:1.75;color:#315471}
.pc-proof-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px}
.pc-proof-list li{padding:13px;border:1px solid #d9e7eb;background:#f7fbfd;border-radius:11px;overflow-wrap:anywhere}
.pc-proof-list a{color:#007e76;text-decoration:underline;font-weight:750;min-height:40px;display:inline-flex;align-items:center}
.pc-links-more{display:flex;flex-direction:column;gap:13px}
.pc-links-more a{background:#e3f7f3;border-radius:11px;color:#037f71;font-weight:850;padding:12px 16px;text-decoration:none;min-height:44px}
.pc-disclaimer{font-size:14px;color:#526e81}
@media(max-width:900px){.pc-main,.pc-body-grid{grid-template-columns:1fr}.pc-nav .pc-wrap{flex-wrap:wrap;padding-block:14px}.pc-links{order:3;flex-basis:100%}}
@media(max-width:560px){.pc-contact{margin-inline-start:auto}.pc-hero{min-height:290px}}
</style>
@endpush
@php($statusLabels = $isArabic
    ? ['completed' => 'مكتمل', 'in_progress' => 'قيد التنفيذ', 'research' => 'بحث', 'archived' => 'مؤرشف']
    : ['completed' => 'Completed', 'in_progress' => 'In progress', 'research' => 'Research', 'archived' => 'Archived'])
@php($groupLabels = $isArabic
    ? ['government'=>'حكومي','education'=>'تعليم','workflows'=>'إجراءات','ai'=>'منتجات وذكاء اصطناعي','enterprise'=>'أعمال']
    : ['government'=>'Government','education'=>'Education','workflows'=>'Workflows','ai'=>'AI & Products','enterprise'=>'Enterprise'])
@php($returnQRaw = request()->query()['q'] ?? null)
@php($returnQ = is_string($returnQRaw) ? mb_substr(trim($returnQRaw), 0, 80) : '')
@php($returnStatusRaw = request()->query()['status'] ?? null)
@php($returnStatus = is_string($returnStatusRaw) && in_array($returnStatusRaw, ['completed', 'in_progress', 'research', 'archived'], true) ? $returnStatusRaw : '')
@php($returnPageRaw = request()->query()['page'] ?? null)
@php($returnPage = is_scalar($returnPageRaw) ? filter_var($returnPageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) : false)
@php($returnCategoryRaw = request()->query()['category'] ?? null)
@php($returnCategory = is_string($returnCategoryRaw) && in_array($returnCategoryRaw, ['featured','government','education','workflows','ai','enterprise'], true) ? $returnCategoryRaw : '')
@php($returnToListing = route('public.projects', array_merge(
    ['lang' => $locale],
    $returnQ !== '' ? ['q' => $returnQ] : [],
    $returnStatus !== '' ? ['status' => $returnStatus] : [],
    $returnCategory !== '' ? ['category' => $returnCategory] : [],
    $returnPage !== false && $returnPage > 1 ? ['page' => $returnPage] : []
)))
@section('content')
<div class="project-case-live" lang="{{ $locale }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
  <header class="pc-nav">
    <div class="pc-wrap">
      <a class="pc-brand" href="{{ route('home', ['lang'=>$locale]) }}"><b>WA</b> {{ $isArabic ? 'وليد عطية' : 'Waleed Atiya' }}</a>
      <nav class="pc-links" aria-label="{{ $isArabic ? 'قائمة الموقع' : 'Site menu' }}">
        <a href="{{ route('home', ['lang'=>$locale]) }}">{{ $isArabic?'الرئيسية':'Home' }}</a>
        <a href="{{ route('public.expertise', ['lang'=>$locale]) }}">{{ $isArabic?'الخبرات':'Expertise' }}</a>
        <a href="{{ route('public.projects', ['lang'=>$locale]) }}" aria-current="page">{{ $isArabic?'المشاريع':'Projects' }}</a>
        <a href="{{ route('public.github', ['lang'=>$locale]) }}">GitHub</a>
        <a href="{{ route('public.resume', ['lang'=>$locale]) }}">{{ $isArabic?'السيرة':'Resume' }}</a>
      </nav>
      <a class="pc-contact" href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $isArabic?'تواصل معي':'Get in touch' }}</a>
    </div>
  </header>
  <div class="pc-wrap">
    <nav class="pc-breadcrumb" aria-label="{{ $isArabic ? 'مسار التنقل' : 'Breadcrumb' }}">
      <a href="{{ $returnToListing }}">{{ $isArabic ? 'المشاريع' : 'All projects' }}</a>
      <span aria-hidden="true">›</span>
      <span>{{ $project['name'] }}</span>
    </nav>
    <div class="pc-main">
      <article class="pc-hero">
        <span class="pc-chip">{{ $groupLabels[$project['category'] ?? 'enterprise'] ?? ($isArabic?'مشروع':'Project') }}</span>
        <div><h1>{{ $project['name'] }}</h1><p>{{ $project['summary'] }}</p></div>
        <span class="pc-visual" aria-hidden="true">&lt;/&gt; · DB · AI</span>
      </article>
      <div class="pc-facts">
        <section class="pc-panel" aria-labelledby="case-facts">
          <h2 id="case-facts">{{ $isArabic?'تفاصيل تم التحقق منها':'Verified facts' }}</h2>
          <p class="pc-status-line">{{ $isArabic ? 'الحالة' : 'Status' }}: {{ $statusLabels[$project['status']] ?? ($isArabic?'غير محددة':'Unavailable') }}</p>
          <dl>
            <dt>{{ $isArabic?'الدور':'Role' }}</dt><dd>{{ $project['role'] ?: ($isArabic?'غير محدد':'Unavailable') }}</dd>
            <dt>{{ $isArabic?'التصنيف':'Category' }}</dt><dd>{{ $groupLabels[$project['category'] ?? 'enterprise'] ?? 'Enterprise' }}</dd>
            @if(!empty($project['source_label']))<dt>{{ $isArabic?'المصدر':'Source' }}</dt><dd>{{ $project['source_label'] }}</dd>@endif
          </dl>
          <p class="pc-disclaimer">{{ $isArabic ? 'لا تُنشر التفاصيل السرية أو النتائج غير المثبتة.' : 'Confidential details and unverified outcomes are never published.' }}</p>
        </section>
        <section class="pc-panel">
          <h2>{{ $isArabic?'تواصل ومزيد من المعلومات':'Next steps' }}</h2>
          <div class="pc-links-more">
            <a href="{{ route('public.projects', ['lang'=>$locale,'status'=>$project['status']]) }}">{{ $isArabic?'عرض مشاريع أخرى بالحالة نفسها':'View projects with this status' }}</a>
            <a href="{{ route('public.contact', ['lang'=>$locale,'project'=>$project['slug']]) }}">{{ $isArabic?'استفسر عن دراسة الحالة الموثقة':'Ask about this verified case study' }}</a>
            <a href="{{ route('public.resume', ['lang'=>$locale]) }}">{{ $isArabic?'عرض السيرة المهنية الموثقة':'View verified public resume' }}</a>
          </div>
        </section>
      </div>
    </div>
    <div class="pc-body pc-body-grid">
      <section class="pc-panel" aria-labelledby="case-evidence">
        <h2 id="case-evidence">{{ $isArabic ? 'أدلة منشورة' : 'Published evidence' }}</h2>
        <p>{{ $isArabic?'روابط خارجية تم اعتمادها للعرض العام؛ لا تظهر الروابط الخاصة.':'Only safe, approved public source links are displayed; private evidence stays protected.' }}</p>
        <ul class="pc-proof-list">
          @foreach($project['evidence_urls'] as $url)
            <li><a href="{{ $url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">{{ $url }}</a></li>
          @endforeach
        </ul>
      </section>
      <aside class="pc-panel">
        <h2>{{ $isArabic?'عودة للتصفح':'Keep exploring' }}</h2>
        <p>{{ $isArabic?'اختر فئة أخرى أو ارجع إلى قائمة المشاريع المنشورة.':'Browse more of the published, evidence-reviewed work.' }}</p>
        <div class="pc-links-more"><a href="{{ $returnToListing }}">{{ $isArabic ? 'العودة إلى نتائج المشاريع' : 'Back to project results' }}</a>
          <a href="{{ route('public.expertise', ['lang'=>$locale]) }}">{{ $isArabic?'تصفح الخبرات':'Explore expertise' }}</a></div>
      </aside>
    </div>
  </div>
</div>
@endsection
