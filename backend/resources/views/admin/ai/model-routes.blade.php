@php
$locale = $locale ?? (request()->query('lang') === 'ar' ? 'ar' : 'en');
$arabic = $locale === 'ar';
$primaryId = $taskRoute?->primary_model_id;
$fallbackIds = $taskRoute?->fallback_model_ids_json ?? [];
$budget = $taskRoute?->budget_json ?? [];
$limits = $taskRoute?->rate_limits_json ?? [];
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $arabic ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $arabic ? 'نماذج AI وتوجيه المهام — وليد سايت' : 'AI models and task routing — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:12px;padding:12px;background:#fff;z-index:4}.skip:focus{top:12px}
.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);padding:24px;color:white}aside a{color:white;display:block;padding:8px 0;min-height:44px}
main{padding:clamp(18px,4vw,48px);min-width:0;max-width:1350px}
.toolbar,.actions{display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.panel,.notice{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:20px}
.panel h2{margin-top:0;color:var(--nav)}.muted{color:#55718B}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,215px),1fr));gap:14px}
.field{margin-block:10px}.field label{display:block;font-weight:700;margin-bottom:6px}
input:not([type=checkbox]),select{display:block;width:100%;min-height:44px;padding:10px;border:1px solid #97B4C8;border-radius:9px;background:#fff;color:var(--ink);font:inherit}
select[multiple]{min-height:180px}input[type=checkbox]{width:22px;height:22px;vertical-align:middle}
button{background:var(--link);color:#fff;border:0;border-radius:9px;padding:10px 16px;min-height:44px;font:inherit;font-weight:700;cursor:pointer}
table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:12px;border-bottom:1px solid var(--border);vertical-align:middle}
th{color:var(--nav)}.scroll{overflow-x:auto}.rowform{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.state{font-weight:700}.status-note{border-inline-start:4px solid var(--accent);padding-inline-start:14px}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}aside a{display:inline-block;margin-inline-end:14px}.panel{padding:16px}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $arabic ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout">
<aside>
<h2>{{ $arabic ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $arabic ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $arabic ? 'نظرة عامة' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $arabic ? 'إدارة المزودين' : 'Provider settings' }}</a>
<a href="{{ route('admin.routing.index', ['lang' => $locale]) }}">{{ $arabic ? 'أولوية المزودين' : 'Provider priorities' }}</a>
<a href="{{ route('admin.models.index', ['lang' => $locale]) }}" aria-current="page">{{ $arabic ? 'النماذج ومسارات المهام' : 'Models and task routes' }}</a>
<a href="{{ route('admin.usage.index', ['lang' => $locale]) }}">{{ $arabic ? 'سجل التكاليف' : 'Cost receipts' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $arabic ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="toolbar">
<h1>{{ $arabic ? 'نماذج AI وتوجيه التشخيص' : 'AI models and diagnosis routing' }}</h1>
<a href="{{ route('admin.models.index', ['lang' => $arabic ? 'en' : 'ar', 'page' => $models->currentPage()]) }}">{{ $arabic ? 'English' : 'العربية' }}</a>
</div>
<p class="notice">{{ $arabic
? 'الخطوات: سجل مزودًا، أضف النموذج الخاص به، ثم اختر النموذج الأساسي والبدائل واضبط الحدود لمهمة التشخيص. هذه إعدادات محفوظة وليست وعدًا باتصال المزود أو نفاد/توفر الرصيد. لا تُجرى أي اتصالات AI عند عرض هذه الصفحة.'
: 'Journey: register a provider, add its model, then choose primary/fallback models and limits for diagnosis. These are saved settings, not live provider connectivity or remaining-credit claims. Opening this page never invokes AI.' }}</p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())
<div class="notice" role="alert"><strong>{{ $arabic ? 'لم تُحفظ الإعدادات. تحقق من الحقول.' : 'Settings were not saved. Check the fields.' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="panel" aria-labelledby="register-title">
<h2 id="register-title">{{ $arabic ? '1. تسجيل نموذج مزود' : '1. Register a provider model' }}</h2>
@if($providersForModels->isEmpty())
<p role="status">{{ $arabic ? 'لا يوجد مزود مسجل. أنشئ مزودًا قبل إضافة النماذج.' : 'No providers configured. Create a provider before registering models.' }}
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $arabic ? 'إعداد المزودين' : 'Provider settings' }}</a></p>
@else
<form method="post" action="{{ route('admin.models.create', ['lang' => $locale]) }}">
@csrf
<div class="grid">
<div class="field"><label for="model-provider">{{ $arabic ? 'المزود' : 'Provider' }}</label>
<select id="model-provider" name="provider_id" required>
@foreach($providersForModels as $provider)
<option value="{{ $provider->id }}" @selected(old('provider_id') === $provider->id)>{{ $provider->display_name }} ({{ $provider->provider_type }} · {{ $provider->currency }})</option>
@endforeach
</select></div>
<div class="field"><label for="model-id">{{ $arabic ? 'معرّف النموذج لدى المزود' : 'Upstream model ID' }}</label>
<input id="model-id" name="provider_model_id" value="{{ old('provider_model_id') }}" maxlength="90" pattern="[A-Za-z0-9_.:\-]{1,90}" placeholder="model-name" required autocomplete="off"></div>
</div>
<div class="rowform">
<label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', '1') === '1')> {{ $arabic ? 'تفعيل النموذج' : 'Enable model' }}</label>
<label><input type="hidden" name="supports_text" value="0"><input type="checkbox" name="supports_text" value="1" @checked(old('supports_text', '1') === '1')> {{ $arabic ? 'يدعم النصوص والتشخيص' : 'Supports text diagnosis' }}</label>
</div>
<p class="muted">{{ $arabic ? 'النماذج المدفوعة تستخدم عملة الميزانية المحددة لدى المزود. عدّل عملة المزود من محرره قبل التسجيل؛ لا يُجرى تحويل للعملات.' : 'Paid models inherit the provider budget currency. Change provider currency in its editor before registration; no FX conversion occurs.' }}</p>
<button type="submit">{{ $arabic ? 'تسجيل النموذج' : 'Register model' }}</button>
</form>
@endif
</section>

<section class="panel" aria-labelledby="registry-title">
<h2 id="registry-title">{{ $arabic ? '2. النماذج المسجلة' : '2. Registered models' }} ({{ $models->total() }})</h2>
@if($models->isEmpty())
<p role="status">{{ $arabic ? 'لا توجد نماذج مسجلة حتى الآن.' : 'No models registered yet.' }}</p>
@else
<div class="scroll"><table>
<thead><tr><th scope="col">{{ $arabic ? 'المزود' : 'Provider' }}</th><th scope="col">{{ $arabic ? 'النموذج' : 'Model' }}</th>
<th scope="col">{{ $arabic ? 'التوافق' : 'Capability' }}</th><th scope="col">{{ $arabic ? 'التفعيل' : 'Enablement' }}</th></tr></thead>
<tbody>
@foreach($models as $model)
<tr>
<td>{{ $model->provider?->display_name }}</td><td>{{ $model->provider_model_id }}</td>
<td>{{ in_array('text', $model->capabilities_json ?? [], true) ? ($arabic ? 'نصوص' : 'Text') : ($arabic ? 'ليس نصيًا' : 'Not text-capable') }}
· {{ $model->cost_policy_json['currency'] ?? ($arabic ? 'محلي/غير محدد' : 'Local/unset') }}</td>
<td><form class="rowform" method="post" action="{{ route('admin.models.update', ['model' => $model->id, 'lang' => $locale]) }}">
@csrf @method('PATCH')
<label for="enabled-{{ $model->id }}">{{ $arabic ? 'مفعّل' : 'Enabled' }}</label>
<select id="enabled-{{ $model->id }}" name="enabled">
<option value="1" @selected($model->enabled)>{{ $arabic ? 'نعم' : 'Yes' }}</option>
<option value="0" @selected(! $model->enabled)>{{ $arabic ? 'لا' : 'No' }}</option>
</select>
<button type="submit">{{ $arabic ? 'حفظ' : 'Save' }}</button></form></td>
</tr>
@endforeach
</tbody></table></div>
<nav aria-label="{{ $arabic ? 'صفحات النماذج' : 'Model registry pages' }}">{{ $models->links() }}</nav>
@endif
</section>

<section class="panel" aria-labelledby="route-title">
<h2 id="route-title">{{ $arabic ? '3. إعداد مهمة تشخيص AI' : '3. Configure AI diagnosis task' }}</h2>
<p class="muted">{{ $arabic
? 'تُطبّق هذه الإعدادات فعليًا على mobile-ai-diagnose: نموذج أساسي، حتى 8 بدائل، حجز لكل طلب، حدود لكل مستخدم. لا تحفظ نماذج غير مؤهلة أو عملات مدفوعة مختلفة.'
: 'These settings govern the real mobile-ai-diagnose route: primary, up to 8 fallbacks, per-job reservation and per-user rate limits. Ineligible models and incompatible paid currencies are rejected.' }}</p>
@if($choicesTruncated)
<p class="notice" role="alert">{{ $arabic ? 'يوجد أكثر من 200 نموذج مؤهل. هذه القائمة محدودة بأول 200؛ لا يمكن اختيار بقية النماذج من هنا.' : 'More than 200 eligible models exist. The selector shows only the first 200; other models are not selectable here.' }}</p>
@endif
@if($candidates->isEmpty())
<p role="status">{{ $arabic ? 'لا توجد نماذج نصية مفعّلة يمكن توجيه المهمة إليها. سجّل نموذجًا وفعّله أولًا.' : 'No enabled text models can be routed. Register and enable a model first.' }}</p>
@else
<form method="post" action="{{ route('admin.task-routes.save', ['lang' => $locale]) }}">
@csrf @method('PUT')
<div class="field"><label for="task-primary">{{ $arabic ? 'النموذج الأساسي' : 'Primary model' }}</label>
<select id="task-primary" name="primary_model_id" required>
<option value="">{{ $arabic ? 'اختر النموذج' : 'Choose a model' }}</option>
@foreach($candidates as $model)
<option value="{{ $model->id }}" @selected(old('primary_model_id', $primaryId) === $model->id)>{{ $model->provider?->display_name }} — {{ $model->provider_model_id }} ({{ $model->provider?->currency }})</option>
@endforeach
</select></div>
<div class="field"><label for="task-fallbacks">{{ $arabic ? 'النماذج البديلة (Ctrl أو ⌘ لتحديد أكثر من نموذج)' : 'Fallback models (Ctrl or ⌘ for multiple)' }}</label>
<select id="task-fallbacks" name="fallback_model_ids[]" multiple size="5" aria-describedby="fallback-help">
@foreach($candidates as $model)
<option value="{{ $model->id }}" @selected(in_array($model->id, old('fallback_model_ids', $fallbackIds), true))>{{ $model->provider?->display_name }} — {{ $model->provider_model_id }} ({{ $model->provider?->currency }})</option>
@endforeach
</select>
<p id="fallback-help" class="muted">{{ $arabic ? 'اختياري، بحد أقصى ثمانية نماذج. يجب ألا يتكرر الأساسي ضمن البدائل.' : 'Optional, up to eight distinct models. The primary must not repeat in fallbacks.' }}</p></div>
<div class="grid">
<div class="field"><label for="task-currency">{{ $arabic ? 'عملة الحجز' : 'Reservation currency' }}</label>
<select id="task-currency" name="currency">@foreach(['USD','KWD','EUR'] as $code)
<option value="{{ $code }}" @selected(old('currency', $budget['currency'] ?? 'USD') === $code)>{{ $code }}</option>
@endforeach</select></div>
<div class="field"><label for="task-reservation">{{ $arabic ? 'الحجز لكل طلب (وحدات صغرى)' : 'Per-job reservation (minor units)' }}</label>
<input id="task-reservation" type="number" name="reservation_minor" min="0" max="100000000" required value="{{ old('reservation_minor', $budget['reservation_minor'] ?? 0) }}"></div>
<div class="field"><label for="task-minute">{{ $arabic ? 'عدد الطلبات لكل دقيقة للمستخدم' : 'Per-user requests per minute' }}</label>
<input id="task-minute" type="number" name="per_minute" min="1" max="60" required value="{{ old('per_minute', $limits['per_minute'] ?? 4) }}"></div>
<div class="field"><label for="task-day">{{ $arabic ? 'عدد الطلبات لكل يوم للمستخدم' : 'Per-user requests per day' }}</label>
<input id="task-day" type="number" name="per_day" min="1" max="10000" required value="{{ old('per_day', $limits['per_day'] ?? 40) }}"></div>
</div>
<button type="submit">{{ $arabic ? 'حفظ مسار التشخيص' : 'Save diagnosis route' }}</button>
</form>
@endif
<section aria-labelledby="readiness-title" class="notice status-note">
<h3 id="readiness-title">{{ $arabic ? 'معاينة أهلية المسار المحفوظ (قراءة فقط)' : 'Saved route eligibility preview (read only)' }}</h3>
@if($selected === null)
<p role="status" class="state">{{ $arabic ? 'غير متاح حاليًا حسب قواعد القبول الفعلية.' : 'Currently unavailable under real admission rules.' }}</p>
@else
<p role="status" class="state">{{ $arabic ? 'يوجد نموذج مؤهل وفق الحالة المسجلة حاليًا:' : 'A model is eligible under current recorded state:' }}
{{ $selected['provider']->display_name }} — {{ $selected['model']->provider_model_id }}</p>
@endif
<p class="muted">{{ $arabic ? 'هذه المعاينة لا تستدعي المزود ولا تضمن نتيجة التشخيص أو الرصيد عند التنفيذ؛ يُعاد التحقق عند الإرسال.' : 'This preview does not contact the provider or guarantee an answer/budget at execution; eligibility is rechecked before inference.' }}</p>
</section>
</section>
</main></div></body></html>
