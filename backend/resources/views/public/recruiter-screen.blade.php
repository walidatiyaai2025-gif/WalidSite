{{-- WEB-12: connected recruiter experience. Track matches use owner-approved
     skills only; reviewed general claims are NOT evidence of a specific track. --}}
@push('styles')
<style>
body:has(.recruiter-screen){overflow-x:clip}
body:has(.recruiter-screen) .site-header{background:#1C4976;border-bottom:0}
body:has(.recruiter-screen) .brand{color:#fff;font-size:20px}
body:has(.recruiter-screen) .brand-mark{background:transparent;color:#00A999;width:auto;border-radius:0;font-size:32px}
body:has(.recruiter-screen) .language-link{background:transparent;border-color:transparent;color:#e6f2fa;border-radius:8px}
body:has(.recruiter-screen) .header-links a[href*="/contact"]{background:#00A999;color:#123C5F;border-radius:14px}
.recruiter-screen{padding-bottom:65px}
.recruiter-hero{position:relative;z-index:0;display:grid;grid-template-columns:minmax(0,1fr) 370px;gap:40px;align-items:center;
    min-height:326px;padding:34px 0;background:#EAF5FB}
.recruiter-hero::before{content:"";position:absolute;z-index:-1;inset:0 auto 0 50%;width:100vw;transform:translateX(-50%);background:#EAF5FB}
.recruiter-kicker{display:inline-block;min-width:190px;padding:7px 15px;margin:0 0 20px;border-radius:15px;background:#D8F4EF;color:#078E80;font-size:16px;font-weight:800}
.recruiter-hero h1{font-size:clamp(34px,4vw,48px);color:#1C4976;line-height:1.2;margin:0 0 18px}
.recruiter-hero p{color:#315471;font-size:19px;margin:0;max-width:700px}
.recruiter-hero-actions{display:flex;flex-wrap:wrap;gap:18px;margin-top:25px}
.recruiter-hero-actions a{display:inline-flex;min-height:54px;align-items:center;justify-content:center;min-width:190px;
    border:1px solid #009B91;border-radius:17px;padding:10px 20px;color:#174870;background:#fff;text-decoration:none;font-weight:800;font-size:17px}
.recruiter-hero-actions a:first-child{background:#099F95;color:#fff}
.recruiter-portrait{position:relative;isolation:isolate;max-width:260px;width:100%;justify-self:center;margin:0}
.recruiter-portrait::before,.recruiter-portrait::after{content:"";position:absolute;z-index:-1;width:85%;height:78%;border-radius:23px;
    border:3px solid #00A999;background:#2B668C}
.recruiter-portrait::before{inset-block-start:13%;inset-inline-start:-52%}
.recruiter-portrait::after{inset-block-start:23%;inset-inline-start:-27%}
.recruiter-portrait img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;object-position:center top;
    border:4px solid #079F95;border-radius:20px;background:#EAF5FB}
.recruiter-portrait figcaption{color:#315471;font-size:12px;text-align:center;margin-top:6px}
.recruiter-body{padding-top:35px}
.recruiter-focus{display:flex;align-items:end;flex-wrap:wrap;gap:15px;margin:0 0 24px}
.recruiter-focus label{display:block;color:#163B66;font-weight:800;margin-bottom:7px}
.recruiter-focus select{min-height:44px;min-width:235px;border:1px solid #97B4C8;border-radius:11px;padding:9px;font:inherit;background:#fff;color:#163B66}
.recruiter-focus button{min-height:44px;padding:10px 20px;border:0;border-radius:11px;background:#099F95;color:#123C5F;font:inherit;font-weight:800;cursor:pointer}
.recruiter-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px}
.recruiter-card{min-height:310px;background:#fff;border-radius:21px;padding:29px 25px;display:flex;flex-direction:column;border:1px solid transparent;min-width:0}
.recruiter-card h2{font-size:27px;line-height:1.3;color:#163B66;margin:0 0 30px}
.recruiter-card p{color:#315471;margin:0 0 24px}
.recruiter-card ul{list-style:none;margin:0 0 23px;padding:0}
.recruiter-card li{padding-bottom:9px;color:#315471;overflow-wrap:anywhere}
.recruiter-card a{display:flex;align-items:center;min-height:44px;color:#163B66;text-underline-offset:4px;overflow-wrap:anywhere}
.recruiter-card .recruiter-card-links{margin-top:auto;display:grid;gap:4px}
.recruiter-proof{background:#fff;border:1px solid #D3E3ED;border-radius:20px;padding:25px;margin-top:26px}
.recruiter-proof h2{color:#163B66;font-size:24px;margin:0 0 10px}
.recruiter-proof p{color:#315471}
.recruiter-proof ul{list-style:none;padding:0;margin:0}
.recruiter-proof li{border-top:1px solid #D3E3ED;padding:14px 0;display:grid;gap:8px;overflow-wrap:anywhere}
.recruiter-proof a{color:#126DE1;display:inline-flex;align-items:center;min-height:44px;font-weight:800}
.recruiter-empty{border:1px dashed #97B4C8;background:#F5F9FC;border-radius:12px;padding:15px;color:#315471}
.recruiter-card-footer{margin-top:20px;display:flex;flex-wrap:wrap;gap:12px}
.recruiter-card-footer a{min-height:44px;padding:10px 15px;border:1px solid #D3E3ED;border-radius:11px;background:#fff;color:#163B66;font-weight:800;text-decoration:none}
.recruiter-screen a:focus-visible,.recruiter-screen select:focus-visible,.recruiter-screen button:focus-visible{outline:3px solid #126DE1;outline-offset:3px}
@media(max-width:1000px){.recruiter-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.recruiter-hero{grid-template-columns:minmax(0,1fr) 260px}}
@media(max-width:720px){.recruiter-hero{grid-template-columns:1fr}.recruiter-portrait{grid-row:1;max-width:200px}.recruiter-cards{grid-template-columns:1fr}.recruiter-hero-actions a{flex:1 1 170px}.recruiter-focus>*{flex:1 1 100%}.recruiter-focus select{width:100%}}
@media print{.recruiter-focus,.recruiter-hero-actions,.site-header,.site-footer{display:none!important}.recruiter-hero::before{display:none}.recruiter-cards{grid-template-columns:1fr}.recruiter-card{break-inside:avoid}}
</style>
@endpush
<div class="recruiter-screen">
    <header class="recruiter-hero">
        <div>
            <span class="recruiter-kicker">{{ $locale === 'ar' ? 'للجهات الباحثة عن الخبرات' : 'HIRING VIEW' }}</span>
            <h1 id="recruiter-title">{{ $locale === 'ar' ? 'وضع التوظيف' : 'Recruiter Mode' }}</h1>
            <p>{{ $locale === 'ar'
                ? 'راجع المهارات المنشورة بالأدلة والسيرة المهنية القابلة للطباعة والمشاريع المتحقَّق منها قبل التواصل.'
                : 'Explore published expertise, evidence-backed case studies and the printable public CV before getting in touch.' }}</p>
            <nav class="recruiter-hero-actions" aria-label="{{ $locale === 'ar' ? 'التنقل إلى بيانات التوظيف' : 'Recruiter actions' }}">
                <a href="#recruiter-fit">{{ $locale === 'ar' ? 'استكشاف' : 'Explore' }}</a>
                <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
            </nav>
        </div>
        <figure class="recruiter-portrait">
            <img src="{{ route('public.owner-portrait') }}"
                 alt="{{ $locale === 'ar' ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية' : 'Approved original portrait of Waleed Atiya' }}"
                 width="260" height="260" loading="eager" decoding="async">
            <figcaption>{{ $locale === 'ar' ? 'الصورة الأصلية المقدمة من المالك' : 'Owner-supplied original portrait' }}</figcaption>
        </figure>
    </header>
    <section class="recruiter-body" id="recruiter-fit">
        <form class="recruiter-focus" action="{{ route('public.hire') }}" method="get"
              aria-label="{{ $locale === 'ar' ? 'اختيار المسار المهني' : 'Choose career focus' }}">
            <input type="hidden" name="lang" value="{{ $locale }}">
            <div>
                <label for="career-track">{{ $locale === 'ar' ? 'المجال' : 'Career track' }}</label>
                <select id="career-track" name="track">
                    <option value="all" @selected($track === 'all')>{{ $locale === 'ar' ? 'كل المجالات' : 'All areas' }}</option>
                    <option value="database" @selected($track === 'database')>{{ $locale === 'ar' ? 'قواعد البيانات' : 'Database engineering' }}</option>
                    <option value="software" @selected($track === 'software')>{{ $locale === 'ar' ? 'البرمجيات والبنية التحتية' : 'Software and infrastructure' }}</option>
                    <option value="delivery" @selected($track === 'delivery')>{{ $locale === 'ar' ? 'إدارة المشاريع' : 'Project delivery' }}</option>
                </select>
            </div>
            <button type="submit">{{ $locale === 'ar' ? 'عرض النتائج' : 'Show matches' }}</button>
        </form>
        <div class="recruiter-cards">
            <section class="recruiter-card" aria-labelledby="recruiter-profile-heading">
                <h2 id="recruiter-profile-heading">{{ $locale === 'ar' ? 'ملاءمة الملف المهني' : 'Profile fit' }}</h2>
                @if($skills->isEmpty())
                    <p class="recruiter-empty" role="status">{{ $locale === 'ar' ? 'لا توجد مهارات موثقة ومتاحة في هذا المجال الآن.' : 'No evidence-verified skills are currently available for this track.' }}</p>
                @else
                    <ul>
                        @foreach($skills as $skill)
                            <li>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</li>
                        @endforeach
                    </ul>
                @endif
                <div class="recruiter-card-links">
                    <a href="{{ route('public.resume', ['lang' => $locale, 'track' => $track]) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
                    <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الخبرات التقنية' : 'Explore expertise' }}</a>
                </div>
            </section>
            <section class="recruiter-card" aria-labelledby="recruiter-evidence-card">
                <h2 id="recruiter-evidence-card">{{ $locale === 'ar' ? 'الأدلة' : 'Evidence' }}</h2>
                <p>{{ $locale === 'ar'
                    ? 'اطّلع على دراسات الحالة العامة وأدلتها؛ لا تعني روابط المشاريع إثبات تخصص بعينه.'
                    : 'Review case studies and their actual public evidence. Project links do not certify a particular specialty.' }}</p>
                <div class="recruiter-card-links">
                    <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
                    <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}</a>
                    <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة وخبرات' : 'About' }}</a>
                </div>
            </section>
            <section class="recruiter-card" aria-labelledby="recruiter-cv-card">
                <h2 id="recruiter-cv-card">{{ $locale === 'ar' ? 'خيارات السيرة المهنية' : 'CV options' }}</h2>
                <p>{{ $locale === 'ar'
                    ? 'افتح السيرة العامة للمسار المختار، ثم اطبعها أو احفظها PDF من المتصفح.'
                    : 'Open the public CV for this career focus, then print or save it as PDF in your browser.' }}</p>
                <div class="recruiter-card-links">
                    <a href="{{ route('public.resume', ['lang' => $locale, 'track' => $track]) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
                    <a href="{{ route('public.contact', array_merge(['lang' => $locale], $track !== 'all' ? ['track' => $track] : [])) }}">{{ $locale === 'ar' ? 'التواصل بشأن المجال' : 'Contact about this focus' }}</a>
                </div>
            </section>
        </div>
        {{-- Never infer that a general approved claim proves the selected track. --}}
        @if(count($approvedClaims) > 0)
            <section class="recruiter-proof" aria-labelledby="recruiter-evidence-heading">
                <h2 id="recruiter-evidence-heading">{{ $locale === 'ar' ? 'أدلة مهنية عامة معتمدة للنشر' : 'Published supporting evidence' }}</h2>
                <p>{{ $locale === 'ar'
                    ? 'هذه الأدلة عامة ولا تعني اعتمادًا خاصًا بالمجال المحدد. تُعرض فقط العبارات المراجعة ذات روابط الأدلة العامة الآمنة.'
                    : 'These public statements are not endorsements of the selected career track. Only reviewed statements with safe, public evidence links are shown.' }}</p>
                <ul>
                    @foreach($approvedClaims as $claim)
                        <li>
                            <span>{{ $claim['text'] }}</span>
                            <a href="{{ $claim['evidence_url'] }}" target="_blank" rel="noopener noreferrer"
                               aria-label="{{ ($locale === 'ar' ? 'افتح دليل: ' : 'Open evidence for: ').$claim['text'] }}">{{ $locale === 'ar' ? 'عرض الدليل' : 'View evidence' }}</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        <nav class="recruiter-card-footer" aria-label="{{ $locale === 'ar' ? 'إجراءات التوظيف الإضافية' : 'Additional recruiter actions' }}">
            <a href="{{ route('public.resume', ['lang' => $locale, 'track' => $track]) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
            <a href="{{ route('public.contact', array_merge(['lang' => $locale], $track !== 'all' ? ['track' => $track] : [])) }}">{{ $locale === 'ar' ? 'التواصل بشأن المجال' : 'Contact about this focus' }}</a>
            <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
        </nav>
    </section>
</div>