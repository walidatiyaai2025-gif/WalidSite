@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'الخصوصية والبيانات — وليد عطية' : 'Privacy and data — Waleed Atiya')
@section('meta_description', $locale === 'ar' ? 'إشعار معلوماتي مبدئي عن خصوصية الزوار وطلبات البيانات، لم يعتمد للنشر القانوني.' : 'Provisional visitor privacy and data request disclosure, pending legal approval.')
@push('styles')
<style>
.policy{padding-block:48px 64px;max-width:900px}.policy h1{font-size:clamp(30px,5vw,48px);color:var(--wa-primary);line-height:1.24}.policy h2{color:var(--wa-primary);margin-block:30px 12px;font-size:clamp(20px,3vw,26px)}.policy p,.policy li{overflow-wrap:anywhere}.policy ul,.policy ol{padding-inline-start:24px}.policy li{margin-block:9px;line-height:1.7}.policy-note{border:2px solid #BF7F12;border-radius:18px;background:#fff;padding:18px 22px}.policy-actions{display:flex;flex-wrap:wrap;gap:12px;margin-block:26px}.policy-actions a{min-height:44px;display:inline-flex;align-items:center;padding:10px 18px;border-radius:12px;background:var(--wa-primary);color:#fff;text-decoration:none;font-weight:700}.policy article{background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:clamp(20px,4vw,36px);margin-block:24px}.policy article a{color:#155cae;text-decoration:underline;text-underline-offset:3px}.policy :focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
</style>
@endpush
@section('content')
<section class="site-wrap policy" aria-labelledby="privacy-heading">
<h1 id="privacy-heading">{{ $locale === 'ar' ? 'الخصوصية وطلبات البيانات' : 'Privacy and data requests' }}</h1>
<div class="policy-note" role="status"><strong>{{ $locale === 'ar' ? 'مسودة معلوماتية — غير معتمدة قانونيًا' : 'Information draft — not legally approved' }}</strong>
<p>{{ $locale === 'ar' ? 'هذه المعلومات توضح سلوك الميزات البرمجية الحالي ولا تمثل سياسة قانونية نهائية. هوية مسؤول البيانات القانونية، فترات الاحتفاظ، جهات المعالجة، أساس المعالجة وشروط الولاية القضائية تحتاج إلى اعتماد قبل الإطلاق.' : 'This describes currently implemented software behavior, not a final legal policy. The legal data-controller identity, retention periods, processors, lawful basis and jurisdiction need approval before launch.' }}</p></div>
<nav class="policy-actions" aria-label="{{ $locale === 'ar' ? 'روابط سريعة لحقوق البيانات' : 'Quick links to data rights' }}">
<a href="#account-controls">{{ $locale === 'ar' ? 'الموافقات وحقوق حسابي' : 'Consent and account rights' }}</a>
<a href="{{ route('public.contact', ['lang'=>$locale, 'topic'=>'privacy']) }}">{{ $locale === 'ar' ? 'التواصل بشأن البيانات' : 'Contact about my data' }}</a>
</nav>
<article>
<h2>{{ $locale === 'ar' ? 'الرسائل والموافقة' : 'Messages and consent' }}</h2>
<p>{{ $locale === 'ar' ? 'نموذج التواصل يطلب الاسم والبريد الإلكتروني والموضوع والرسالة، ولا يرسل أيًا منها دون تحديد مربع موافقة منفصل وغير محدد مسبقًا. تستخدم الرسالة للرد على الاستفسار؛ ولا تصبح منشورًا عامًا. قد يتعذر الإرسال إن لم تضبط خدمة البريد.' : 'The contact form requests a name, email address, subject and message. Nothing is submitted without an independently selected, initially unchecked consent box. Messages are for replying to inquiries, not public publication. Delivery can be unavailable until mail service is configured.' }}</p>
<h2>{{ $locale === 'ar' ? 'الأدلة العامة وGitHub' : 'Public evidence and GitHub' }}</h2>
<p>{{ $locale === 'ar' ? 'تعرض صفحات المشاريع والمقالات معلومات عامة فقط عندما تجتاز مراجعة النشر والأدلة. قد تتضمن روابط إلى GitHub ومصادر خارجية ذات سياسات مستقلة. لا يُقصد عرض مستودعات خاصة أو أسرار.' : 'Project and article pages show public information only after publication and evidence checks. Public GitHub and third-party source links have their own policies. Private repositories and credentials are not intended for disclosure.' }}</p>
<h2>{{ $locale === 'ar' ? 'الذكاء الاصطناعي ومزودو الخدمة' : 'AI processing and providers' }}</h2>
<p>{{ $locale === 'ar' ? 'يمكن للمشرف إعداد خدمات ذكاء اصطناعي محلية أو خارجية لإنتاج مسودات ومراجعتها. عند تفعيل مزود خارجي قد تُنقل إليه مدخلات العمل حسب الإعدادات، ولم تُعتمد قائمة المزودين وفترات الاحتفاظ لديهم بعد. لا ترسل كلمات مرور أو بيانات سرية في نموذج التواصل.' : 'Administrators can configure local or external AI services for draft creation and review. If an external provider is enabled, task inputs may be sent to it under its configured processing terms. The final provider list and retention terms have not yet been approved. Do not submit passwords or confidential records through contact.' }}</p>
<h2 id="account-controls" tabindex="-1">{{ $locale === 'ar' ? 'الموافقة على الإشعارات والتحكم في الحساب' : 'Notification consent and account controls' }}</h2>
<p>{{ $locale === 'ar' ? 'تستخدم خدمات تسجيل الدخول جلسات ووسائل حماية أمنية عند الحاجة. زيارة الموقع أو إرسال نموذج التواصل لا تنشئ اشتراكًا ولا تمنح موافقة على التسويق. يطلب تطبيق الهاتف من المستخدم المسجل الدخول اختيار الموافقة بصورة مستقلة لكل غرض (نشاط الحساب، تحديثات المحتوى، تحديثات المنتج) ولكل قناة (البريد الإلكتروني، الإشعارات). كل اختيار معطل افتراضيًا، ويمكن إلغاؤه لاحقًا. ظهور الخيار لا يعني أن إرسال الإشعارات أو تسليمها مفعّل.' : 'Sign-in uses essential session and security protections where required. Visiting this website or submitting the contact form does not subscribe you to marketing or notifications. Signed-in users of the mobile companion can independently opt in to account activity, editorial updates or product updates via email or push. All six choices default to off and may be revoked individually. A stored preference does not mean notification sending or delivery is operational.' }}</p>
<h2>{{ $locale === 'ar' ? 'كيفية إلغاء الموافقة أو تصدير البيانات أو حذف الحساب' : 'Revoke consent, export data or delete an account' }}</h2>
<ol>
<li>{{ $locale === 'ar' ? 'افتح تطبيق وليد التقني، ثم المحفوظات والحساب، وسجل الدخول إلى حسابك.' : 'Open the Waleed Tech Companion app, select Saved & profile and sign in to your own account.' }}</li>
<li>{{ $locale === 'ar' ? 'من ملفي الشخصي اختر الخصوصية والموافقات وبياناتي لفتح شاشة خصوصية الحساب. عطّل أي قناة أو غرض لإلغاء الموافقة، ولا يعتبر الإلغاء ناجحًا حتى يؤكده الخادم.' : 'Under My profile choose Privacy, consent and my data to open Account privacy. Turn off a purpose/channel to revoke consent; the app waits for server confirmation before showing it as saved.' }}</li>
<li>{{ $locale === 'ar' ? 'يمكنك عرض نسخة JSON لبيانات الحساب وتحديدها أو نسخها باختيارك، أو طلب حذف الحساب بعد إعادة إدخال كلمة المرور وكتابة DELETE. يؤكد الخادم إلغاء الجلسات والموافقات قبل أن يعرض التطبيق نتيجة الحذف.' : 'You can explicitly view and copy your account JSON export, or request account deletion after re-entering your password and typing DELETE. The app requires server confirmation of token and consent revocation before reporting deletion.' }}</li>
</ol>
<p>{{ $locale === 'ar' ? 'هذه وظائف لحساب تطبيق الهاتف الموثّق وليست نموذج اشتراك عام على الموقع. يتطلب الوصول تسجيل دخول صالحًا؛ لا تكشف بيانات حسابك أو رموز الدخول في نموذج التواصل. قد لا تتوفر الوظائف عند تعطل الخادم أو عدم تهيئة تطبيق الهاتف.' : 'These controls belong to the authenticated mobile account, not a public website subscription form. A valid sign-in is required. Never send account tokens or private exports via the public contact form. Controls may be unavailable while the app or backend is not configured or reachable.' }}</p>
<p>{{ $locale === 'ar' ? 'حسابات التطبيق المصادق عليها فقط هي التي تتيح إجراءات بيانات الحساب. يجب التحقق من الهوية قبل تنفيذ طلب حساس، ولا يُعد نموذج التواصل العام وسيلة لتجاوز المصادقة.' : 'Authenticated app accounts have separate account-data actions; identity must be verified before a sensitive request is executed. The public contact form cannot bypass authentication.' }}</p>
<h2>{{ $locale === 'ar' ? 'الاحتفاظ والحذف وطلبات التصحيح' : 'Retention, deletion and correction requests' }}</h2>
<p>{{ $locale === 'ar' ? 'توجد الآن واجهة موثّقة لتصدير بيانات الحساب المحددة، وطلب حذف الحساب مع إعادة التحقق بكلمة المرور. قد تشمل عملية الحذف إخفاء البريد وإزالة المحفوظات وتفضيلات الإشعارات وإلغاء مهام التشخيص الجارية. لا يمحو ذلك بالضرورة سجلات التدقيق غير الشخصية أو يحدد أساسًا قانونيًا أو مدد احتفاظ ملزمة؛ لم تُعتمد مدد احتفاظ رقمية، وهوية المسؤول القانوني وجهات المعالجة بعد. يمكن طلب التصحيح أو المساعدة من نموذج التواصل عندما تكون خدمة البريد متاحة، لكن مجرد الإرسال لا يثبت تنفيذ الطلب.' : 'Authenticated account export and password-confirmed account deletion are now implemented. Deletion anonymizes the account email, removes saved items and preferences, revokes tokens and cancels related pending AI diagnosis jobs. This does not necessarily remove non-identifying audit records or establish a legally approved retention schedule. Retention periods, controller identity and processors still require approval. Correction and assistance requests can use the contact form when mail is configured; submission does not prove fulfillment.' }}</p>
<h2>{{ $locale === 'ar' ? 'أطراف خارجية وحدود الخدمة' : 'Third parties and availability' }}</h2>
<p>{{ $locale === 'ar' ? 'قد تتفاعل مع استضافة الموقع أو البريد أو GitHub أو مزود ذكاء اصطناعي بحسب الميزات المستخدمة. التفاصيل والجهات النهائية تحتاج مراجعة قانونية. عند تعطل البريد يعرض الموقع حالة غير متاحة بدل تأكيد إرسال غير صحيح.' : 'Hosting, mail, GitHub and configured AI providers may be involved depending on the feature used. Final provider identities and terms require legal review. When mail is unavailable, the site reports it unavailable rather than falsely confirming delivery.' }}</p>
</article>
<nav class="policy-actions" aria-label="{{ $locale === 'ar' ? 'إجراءات الخصوصية' : 'Privacy actions' }}">
<a href="{{ route('public.contact', ['lang'=>$locale, 'topic'=>'privacy']) }}">{{ $locale === 'ar' ? 'طلب وصول أو حذف أو تصدير' : 'Request access, deletion or export' }}</a>
<a href="{{ route('public.terms', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'عرض الشروط' : 'View terms' }}</a>
</nav>
<article aria-labelledby="privacy-rights-heading">
<h2 id="privacy-rights-heading">{{ $locale === 'ar' ? 'طلبات حقوق البيانات' : 'Data rights requests' }}</h2>
<p>{{ $locale === 'ar' ? 'اختر نوع الطلب لفتح نموذج التواصل بموضوع محدد. لا ينفذ إرسال الاستفسار حذفًا أو تصديرًا تلقائيًا، ويتطلب موافقة صريحة وخدمة بريد مهيأة.' : 'Choose a request to open the contact form with a specific subject. An inquiry does not automatically delete or export data; affirmative consent and configured mail delivery are required.' }}</p>
<ul>
@foreach([
 'access' => ['ar' => 'الاطلاع على بياناتي', 'en' => 'Access my data'],
 'correction' => ['ar' => 'تصحيح بياناتي', 'en' => 'Correct my data'],
 'export' => ['ar' => 'تصدير بياناتي', 'en' => 'Export my data'],
 'deletion' => ['ar' => 'طلب حذف بياناتي', 'en' => 'Request deletion'],
 'revoke' => ['ar' => 'سحب موافقة الإشعارات', 'en' => 'Revoke notification consent'],
 'notifications' => ['ar' => 'إلغاء الاشتراك في الإشعارات', 'en' => 'Notification preferences inquiry'],
] as $topic => $labels)
<li><a href="{{ route('public.contact', ['lang' => $locale, 'topic' => 'privacy', 'request' => $topic]) }}">{{ $labels[$locale] }}</a></li>
@endforeach
</ul>
</article>
</section>
@endsection
