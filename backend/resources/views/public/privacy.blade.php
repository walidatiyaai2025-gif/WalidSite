@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'الخصوصية والبيانات — وليد عطية' : 'Privacy and data — Waleed Atiya')
@section('meta_description', $locale === 'ar' ? 'إشعار معلوماتي مبدئي عن خصوصية الزوار وطلبات البيانات، لم يعتمد للنشر القانوني.' : 'Provisional visitor privacy and data request disclosure, pending legal approval.')
@push('styles')
<style>
.policy{padding-block:48px 64px;max-width:900px}.policy h1{font-size:clamp(30px,5vw,48px);color:var(--wa-primary);line-height:1.24}.policy h2{color:var(--wa-primary);margin-block:30px 12px;font-size:clamp(20px,3vw,26px)}.policy p,.policy li{overflow-wrap:anywhere}.policy ul{padding-inline-start:24px}.policy li{margin-block:9px}.policy-note{border:2px solid #BF7F12;border-radius:18px;background:#fff;padding:18px 22px}.policy-actions{display:flex;flex-wrap:wrap;gap:12px;margin-block:26px}.policy-actions a{min-height:44px;display:inline-flex;align-items:center;padding:10px 18px;border-radius:12px;background:var(--wa-primary);color:#fff;text-decoration:none;font-weight:700}.policy article{background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:clamp(20px,4vw,36px);margin-block:24px}.policy article a{color:#155cae;text-decoration:underline;text-underline-offset:3px}.policy-request-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:12px;margin-block:18px}.policy-request-grid a{display:flex;align-items:center;min-height:56px;padding:14px;border:1px solid var(--wa-soft-border);border-radius:12px;color:#155cae;background:var(--wa-base);font-weight:700;overflow-wrap:anywhere}.policy :focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
</style>
@endpush
@section('content')
<section class="site-wrap policy" aria-labelledby="privacy-heading">
<h1 id="privacy-heading">{{ $locale === 'ar' ? 'الخصوصية وطلبات البيانات' : 'Privacy and data requests' }}</h1>
<div class="policy-note" role="status"><strong>{{ $locale === 'ar' ? 'مسودة معلوماتية — غير معتمدة قانونيًا' : 'Information draft — not legally approved' }}</strong>
<p>{{ $locale === 'ar' ? 'هذه المعلومات توضح سلوك الميزات البرمجية الحالي ولا تمثل سياسة قانونية نهائية. هوية مسؤول البيانات القانونية، فترات الاحتفاظ، جهات المعالجة، أساس المعالجة وشروط الولاية القضائية تحتاج إلى اعتماد قبل الإطلاق.' : 'This describes currently implemented software behavior, not a final legal policy. The legal data-controller identity, retention periods, processors, lawful basis and jurisdiction need approval before launch.' }}</p></div>
<article>
<h2>{{ $locale === 'ar' ? 'الرسائل والموافقة' : 'Messages and consent' }}</h2>
<p>{{ $locale === 'ar' ? 'نموذج التواصل يطلب الاسم والبريد الإلكتروني والموضوع والرسالة، ولا يرسل أيًا منها دون تحديد مربع موافقة منفصل وغير محدد مسبقًا. تستخدم الرسالة للرد على الاستفسار؛ ولا تصبح منشورًا عامًا. قد يتعذر الإرسال إن لم تضبط خدمة البريد.' : 'The contact form requests a name, email address, subject and message. Nothing is submitted without an independently selected, initially unchecked consent box. Messages are for replying to inquiries, not public publication. Delivery can be unavailable until mail service is configured.' }}</p>
<h2>{{ $locale === 'ar' ? 'الأدلة العامة وGitHub' : 'Public evidence and GitHub' }}</h2>
<p>{{ $locale === 'ar' ? 'تعرض صفحات المشاريع والمقالات معلومات عامة فقط عندما تجتاز مراجعة النشر والأدلة. قد تتضمن روابط إلى GitHub ومصادر خارجية ذات سياسات مستقلة. لا يُقصد عرض مستودعات خاصة أو أسرار.' : 'Project and article pages show public information only after publication and evidence checks. Public GitHub and third-party source links have their own policies. Private repositories and credentials are not intended for disclosure.' }}</p>
<h2>{{ $locale === 'ar' ? 'الذكاء الاصطناعي ومزودو الخدمة' : 'AI processing and providers' }}</h2>
<p>{{ $locale === 'ar' ? 'يمكن للمشرف إعداد خدمات ذكاء اصطناعي محلية أو خارجية لإنتاج مسودات ومراجعتها. عند تفعيل مزود خارجي قد تُنقل إليه مدخلات العمل حسب الإعدادات، ولم تُعتمد قائمة المزودين وفترات الاحتفاظ لديهم بعد. لا ترسل كلمات مرور أو بيانات سرية في نموذج التواصل.' : 'Administrators can configure local or external AI services for draft creation and review. If an external provider is enabled, task inputs may be sent to it under its configured processing terms. The final provider list and retention terms have not yet been approved. Do not submit passwords or confidential records through contact.' }}</p>
<h2>{{ $locale === 'ar' ? 'الجلسات والإشعارات' : 'Sessions and notifications' }}</h2>
<p>{{ $locale === 'ar' ? 'قد تستعمل وظائف تسجيل الدخول ملفات جلسة وحماية CSRF لازمة للأمان. لا تمنح زيارة الموقع موافقة تسويقية أو اشتراكًا في الإشعارات. لا تُعرض خدمة الاشتراك أو إرسال إشعارات باعتبارها مفعلة قبل تنفيذ وسيلة موافقة وإلغاء فعلية.' : 'Login features may use essential session and CSRF protection. Visiting the website does not grant marketing or notification consent. Subscription and notification delivery must not be represented as active until actual opt-in and revocation functionality is available.' }}</p>
<h2>{{ $locale === 'ar' ? 'الاحتفاظ والحذف والتصدير' : 'Retention, deletion and export' }}</h2>
<p>{{ $locale === 'ar' ? 'لم تُعتمد مدد احتفاظ نهائية. تتوافر للحسابات المسجلة في التطبيق واجهات مصادق عليها لتصدير بيانات الحساب والتحكم في الموافقة على الإشعارات وحذف الحساب بعد إعادة إثبات كلمة المرور. لا يمكن لزائر غير مسجل تفعيل هذه العمليات عبر رابط عام. يمكن أيضًا تقديم استفسار بيانات عبر نموذج التواصل عند توفر البريد؛ إرساله ليس إثباتًا لتنفيذ الطلب، ويلزم التحقق من هوية صاحب الحساب قبل أي إفصاح.' : 'Final retention periods are not yet approved. Authenticated app accounts have server-backed export, notification preference revocation and password-reauthenticated account deletion. Anonymous visitors cannot trigger these account operations through a public URL. You can also raise a data inquiry through contact when mail is configured; mail handoff is not proof that a request was fulfilled, and identity must be verified before any personal data is disclosed.' }}</p>
<h2>{{ $locale === 'ar' ? 'أطراف خارجية وحدود الخدمة' : 'Third parties and availability' }}</h2>
<p>{{ $locale === 'ar' ? 'قد تتفاعل مع استضافة الموقع أو البريد أو GitHub أو مزود ذكاء اصطناعي بحسب الميزات المستخدمة. التفاصيل والجهات النهائية تحتاج مراجعة قانونية. عند تعطل البريد يعرض الموقع حالة غير متاحة بدل تأكيد إرسال غير صحيح.' : 'Hosting, mail, GitHub and configured AI providers may be involved depending on the feature used. Final provider identities and terms require legal review. When mail is unavailable, the site reports it unavailable rather than falsely confirming delivery.' }}</p>
</article>
<section aria-labelledby="privacy-request-heading">
<h2 id="privacy-request-heading">{{ $locale === 'ar' ? 'اختر نوع طلب البيانات' : 'Choose a privacy data request' }}</h2>
<p>{{ $locale === 'ar' ? 'كل رابط يفتح نموذج التواصل مع موضوع محدد يمكنك تعديله. يلزم اختيار الموافقة بشكل منفصل قبل الإرسال؛ لا تُنشئ الروابط طلبات ولا تُغير موافقة الإشعارات تلقائيًا.' : 'Each link opens contact with a safe suggested subject you can edit. Sending requires separate affirmative consent. Following a link creates no request and never changes notification preferences automatically.' }}</p>
<div class="policy-request-grid">
@foreach([
    'access' => ['Access my data', 'الاطلاع على بياناتي'],
    'correction' => ['Correct my data', 'تصحيح بياناتي'],
    'export' => ['Export my data', 'تصدير بياناتي'],
    'deletion' => ['Delete my account/data', 'حذف حسابي أو بياناتي'],
    'notifications' => ['Revoke notification consent', 'إلغاء موافقة الإشعارات'],
] as $requestType => $label)
<a href="{{ route('public.contact', ['lang'=>$locale, 'topic'=>'privacy', 'request'=>$requestType]) }}">{{ $label[$locale === 'ar' ? 1 : 0] }}</a>
@endforeach
</div>
<p>{{ $locale === 'ar' ? 'للمستخدم المسجل في التطبيق: تصدير الحساب وإلغاء الموافقة والحذف تتم من إعدادات الحساب المصادق عليها. قد يتعطل البريد أو مزود الإشعارات؛ لا يظهر أي إشعار بإتمام الحذف أو الإرسال دون تنفيذ موثّق.' : 'Signed-in app users can export their account, revoke consent or request deletion through authenticated account settings. Mail and notification providers may be unavailable; no deletion or delivery is claimed without actual execution.' }}</p>
</section>
<nav class="policy-actions" aria-label="{{ $locale === 'ar' ? 'إجراءات الخصوصية' : 'Privacy actions' }}">
<a href="{{ route('public.contact', ['lang'=>$locale, 'topic'=>'privacy']) }}">{{ $locale === 'ar' ? 'طلب وصول أو حذف أو تصدير' : 'Request access, deletion or export' }}</a>
<a href="{{ route('public.terms', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'عرض الشروط' : 'View terms' }}</a>
</nav>
</section>
@endsection
