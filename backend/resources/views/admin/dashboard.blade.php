@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</title>
<style>
:root{color-scheme:light;--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED}
*{box-sizing:border-box}body{margin:0;background:#F5F9FC;color:var(--ink);font:16px/1.6 Arial,sans-serif}
a:focus-visible,button:focus-visible{outline:3px solid var(--link);outline-offset:3px}
.skip{position:absolute;top:-100px;left:15px;padding:12px;background:#fff}.skip:focus{top:10px}
.layout{display:grid;grid-template-columns:230px 1fr;min-height:100vh}
aside{background:var(--nav);padding:26px;color:#fff}aside a{display:block;color:#fff;min-height:44px;padding:8px 0}
main{padding:clamp(20px,4vw,52px);min-width:0}h1{color:var(--nav);font-size:clamp(30px,4vw,46px)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:14px}
.card,.panel{background:#fff;border:1px solid var(--border);padding:22px;border-radius:16px}
.card strong{display:block;font-size:38px;color:var(--nav)}.panel{margin-top:22px}
.panel a{color:var(--link);display:inline-block;min-height:44px}
button{background:transparent;color:#fff;border:1px solid #fff;border-radius:8px;min-height:44px;padding:8px;font:inherit}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}}
</style></head>
<body><a class="skip" href="#content">{{ $locale === 'ar' ? 'اذهب للمحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin menu' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الموقع العام' : 'Public website' }}</a>
</nav><form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form></aside>
<main id="content">
<h1>{{ $locale === 'ar' ? 'نظرة عامة' : 'Administration overview' }}</h1>
<a href="{{ route('admin.home', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<p>{{ $locale === 'ar'
? 'هذه أعداد الإعدادات المحفوظة فعليًا، وليست إحصاءات استخدام أو تكلفة.'
: 'These values count saved configurations, not AI usage or costs.' }}</p>
<section aria-labelledby="provider-counters">
<h2 id="provider-counters">{{ $locale === 'ar' ? 'حالة المزودين' : 'Provider status' }}</h2>
<div class="cards">
@foreach ([
'configured' => ['en'=>'Configured', 'ar'=>'مسجل'],
'enabled' => ['en'=>'Enabled', 'ar'=>'مفعّل'],
'healthy' => ['en'=>'Healthy', 'ar'=>'سليم'],
'unverified' => ['en'=>'Unverified', 'ar'=>'غير متحقق منه']
] as $key => $labels)
<div class="card"><span>{{ $labels[$locale] }}</span><strong>{{ $stats[$key] }}</strong></div>
@endforeach
</div></section>
<section class="panel">
<h2>{{ $locale === 'ar' ? 'إدارة المزودين' : 'Provider management' }}</h2>
<p>{{ $locale === 'ar' ? 'الإعدادات محفوظة على الخادم.' : 'Provider configuration is managed on the server.' }}</p>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'فتح الإدارة' : 'Open settings' }}</a>
</section>
</main></div></body></html>
