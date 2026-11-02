@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'الشروط والاستخدام — وليد عطية' : 'Terms of use — Waleed Atiya')
@section('meta_description', $locale === 'ar' ? 'شروط استخدام مبدئية قيد المراجعة، ولا تشكل نصًا قانونيًا معتمدًا.' : 'Provisional terms of use pending legal review and approval.')
@push('styles')
<style>
.terms{max-width:900px;padding-block:48px 64px}.terms h1{font-size:clamp(30px,5vw,48px);color:var(--wa-primary)}.terms h2{font-size:clamp(20px,3vw,26px);color:var(--wa-primary);margin-top:30px}.terms article,.terms-warning{background:#fff;border:1px solid var(--wa-soft-border);padding:clamp(20px,4vw,36px);border-radius:18px;margin-block:24px}.terms-warning{border:2px solid #BF7F12}.terms a{color:#155cae;text-underline-offset:3px}.terms p{overflow-wrap:anywhere}.terms-links{display:flex;flex-wrap:wrap;gap:16px}.terms-links a{display:inline-flex;align-items:center;min-height:44px}.terms a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
</style>
@endpush
@section('content')
<section class="site-wrap terms" aria-labelledby="terms-heading">
<h1 id="terms-heading">{{ $locale === 'ar' ? 'شروط الاستخدام' : 'Terms of use' }}</h1>
<div class="terms-warning" role="status"><strong>{{ $locale === 'ar' ? 'مسودة وليست شروطًا قانونية نافذة' : 'Draft — not effective legal terms' }}</strong>
<p>{{ $locale === 'ar' ? 'هذه صفحة تعريفية لتوضيح استخدام الموقع أثناء التطوير. لا تُقدم باعتبارها موافقة قانونية أو عقدًا نهائيًا؛ يجب اعتماد الصياغة القانونية والجهة المسؤولة قبل الإطلاق.' : 'This page explains intended site use during development. It is not a binding agreement or final legal notice; legal language and the responsible entity must be approved before launch.' }}</p></div>
<article>
<h2>{{ $locale === 'ar' ? 'المعلومات المهنية والأدلة' : 'Professional information and evidence' }}</h2>
<p>{{ $locale === 'ar' ? 'المحتوى المهني المنشور يُعرض للأغراض المعلوماتية فقط بعد التحقق المناسب. لا تُعد المخططات التقنية أو المقالات أو المشاريع شهادة أو ضمانًا لصلاحية تطبيقها دون اختبار مستقل.' : 'Published professional content is informational and subject to evidence review. Technical guides, articles and projects are not certifications or guarantees of suitability without independent testing.' }}</p>
<h2>{{ $locale === 'ar' ? 'المصادر والروابط الخارجية' : 'Sources and external links' }}</h2>
<p>{{ $locale === 'ar' ? 'قد تقود روابط GitHub أو الوثائق إلى مواقع لا يديرها هذا الموقع. راجع شروط تلك الخدمات؛ ولا تفترض أن الرابط يمنح ترخيصًا بإعادة نشر المحتوى أو صور الأشخاص.' : 'GitHub and documentation links may lead to services outside this site. Review their own terms; a link does not grant permission to republish content or personal photographs.' }}</p>
<h2>{{ $locale === 'ar' ? 'نماذج التواصل' : 'Contact requests' }}</h2>
<p>{{ $locale === 'ar' ? 'يتطلب إرسال نموذج التواصل موافقة واضحة على معالجة البيانات للرد فقط. لا يضمن إرسال الرسالة قبول طلب أو سرعة استجابة. يجب عدم إرسال أسرار أو معلومات لا تملك حق مشاركتها.' : 'Contact submission requires affirmative consent to process a message for a reply only. It does not guarantee acceptance or a response deadline. Do not submit secrets or information you lack permission to share.' }}</p>
<h2>{{ $locale === 'ar' ? 'التحكم في الحساب والميزات التي تحتاج تهيئة' : 'Account controls and configuration-dependent services' }}</h2>
<p>{{ $locale === 'ar' ? 'توجد في تطبيق الهاتف المرتبط بالحساب وظائف موثّقة لتصدير البيانات، وإلغاء موافقات الإشعارات المنفصلة، وطلب حذف الحساب مع تأكيد كلمة المرور. تتطلب هذه الوظائف تسجيل الدخول واتصالًا بخادم مهيّأ؛ ولا يُفهم من وجودها أن خدمة إرسال الإشعارات أو الذكاء الاصطناعي الخارجي أو أي تكامل آخر يعمل فعلًا دون اختبار واعتماد.' : 'The authenticated companion app implements personal data export, individual notification-consent revocation, and password-confirmed account deletion. These require a configured, reachable backend and valid session. Their existence does not prove notification delivery, external AI generation or other integrations are activated or verified.' }}</p>
<p>{{ $locale === 'ar' ? 'تتيح واجهات الحساب المصادق عليها تصدير البيانات وطلب حذف الحساب والتحكم بموافقات الإشعارات وفق التحقق من الهوية وتوفر الخادم. توصيل الإشعارات الفعلي يعتمد على إعدادات الخدمات الخارجية، وظهور الوظيفة في الواجهة لا يثبت نجاح التنفيذ.' : 'Authenticated app account endpoints support data export, deletion requests and notification preferences subject to identity checks and backend availability. Actual notification delivery depends on external provider configuration; the presence of a control is not proof of successful execution.' }}</p>
<h2>{{ $locale === 'ar' ? 'تحديثات ومراجعة قانونية' : 'Changes and legal review' }}</h2>
<p>{{ $locale === 'ar' ? 'التواريخ القانونية والسياسات النهائية والمسؤوليات وحقوق المستخدم تخضع لاعتماد مستقل قبل نشر شروط نهائية. لا تحد هذه المسودة من أي حقوق يقررها القانون الواجب التطبيق.' : 'Effective dates, final policies, responsibilities and user rights require independent legal approval before final publication. This draft does not limit rights under applicable law.' }}</p>
</article>
<nav class="terms-links" aria-label="{{ $locale === 'ar' ? 'معلومات أخرى' : 'Related information' }}">
<a href="{{ route('public.privacy', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الخصوصية وطلبات البيانات' : 'Privacy and data requests' }}</a>
<a href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
</nav>
</section>
@endsection
