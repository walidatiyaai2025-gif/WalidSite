{{-- WEB-03: only owner-published, evidence-verified skills from the route's scoped query.
     The reference art is decorative; no mockup claims/metrics are data. --}}
@php($categoryLabelsAr = [
    'Database & Reliability' => 'قواعد البيانات والموثوقية',
    'Software & Integration' => 'البرمجيات والتكامل',
    'Infrastructure & Security' => 'البنية التحتية والأمن',
    'Project Delivery' => 'إدارة وتسليم المشاريع',
])
@php($categoryTracks = [
    'Database & Reliability' => 'database',
    'Software & Integration' => 'software',
    'Infrastructure & Security' => 'software',
    'Project Delivery' => 'delivery',
])
@push('styles')
<style>
body:has(.expertise-screen){overflow-x:clip}
body:has(.expertise-screen) .site-header{background:#1C4976;border-bottom:0}
body:has(.expertise-screen) .brand{color:#fff;font-size:20px}
body:has(.expertise-screen) .brand-mark{background:transparent;color:#00A999;width:auto;border-radius:0;font-size:32px}
body:has(.expertise-screen) .language-link{background:transparent;border-color:transparent;color:#e6f2fa;border-radius:8px}
body:has(.expertise-screen) .language-link[aria-current="page"]{color:#00A999}
body:has(.expertise-screen) .header-links a[href*="/contact"]{background:#00A999;color:#123C5F;border-radius:14px}
.expertise-screen{padding:0 0 60px}
.expertise-hero{position:relative;z-index:0;display:grid;grid-template-columns:minmax(0,1fr) 390px;align-items:center;gap:36px;
    min-height:326px;padding:34px 0;background:#EAF5FB}
.expertise-hero::before{content:"";position:absolute;z-index:-1;inset:0 auto 0 50%;width:100vw;transform:translateX(-50%);background:#EAF5FB}
.expertise-kicker{display:inline-block;min-width:190px;padding:7px 15px;margin:0 0 20px;border-radius:15px;background:#D8F4EF;color:#078E80;font-size:16px;font-weight:800}
.expertise-hero h1{font-size:clamp(34px,4vw,48px);color:#1C4976;line-height:1.2;margin:0 0 18px}
.expertise-hero p{color:#315471;font-size:19px;margin:0;max-width:750px}
.expertise-hero-actions{display:flex;flex-wrap:wrap;gap:18px;margin-top:25px}
.expertise-hero-actions a{display:inline-flex;min-height:54px;align-items:center;justify-content:center;min-width:190px;
    border:1px solid #009B91;border-radius:17px;padding:10px 20px;color:#174870;background:#fff;text-decoration:none;font-weight:800;font-size:17px}
.expertise-hero-actions a:first-child{background:#099F95;color:#fff}
.expertise-art{position:relative;height:235px;margin:0;isolation:isolate}
.expertise-art span{position:absolute;inset-block-start:18px;inset-inline-end:160px;width:198px;height:170px;background:#27638F;
    border:3px solid #00A999;border-radius:26px;display:grid;place-items:center;color:#00A999;font-size:27px;font-weight:800}
.expertise-art span:nth-child(2){inset-block-start:46px;inset-inline-end:94px}
.expertise-art span:nth-child(3){inset-block-start:74px;inset-inline-end:24px}
.expertise-body{padding-top:40px}
.expertise-section-heading{color:#163B66;margin:0 0 13px;font-size:23px}
.expertise-filter{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:25px}
.expertise-filter label{display:block;color:#163B66;font-weight:700;margin-bottom:5px}
.expertise-filter select{display:block;min-width:240px;min-height:44px;padding:8px;border:1px solid #97B4C8;background:#fff;color:#142E4D;border-radius:10px;font:inherit}
.expertise-filter button,.expertise-filter a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 18px;border:1px solid #00A999;border-radius:10px;
    background:#00A999;color:#123C5F;font:inherit;font-weight:800;text-decoration:none;cursor:pointer}
.expertise-filter a{background:#fff}
.expertise-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:26px;align-items:stretch}
.expertise-card{background:#fff;border:1px solid #D3E3ED;border-radius:20px;padding:23px 24px;display:flex;flex-direction:column;min-height:280px}
.expertise-symbol{width:66px;height:66px;display:grid;place-items:center;background:#EAF5FB;border-radius:15px;color:#009F92;font-size:22px;font-weight:800;margin-bottom:24px}
.expertise-card h3{color:#163B66;font-size:24px;line-height:1.25;margin:0 0 18px;overflow-wrap:anywhere}
.expertise-card .expertise-skills{display:flex;flex-wrap:wrap;gap:7px;color:#315471;margin:0 0 20px;list-style:none;padding:0}
.expertise-card .expertise-skills li{overflow-wrap:anywhere}
.expertise-card .expertise-skills li:not(:last-child)::after{content:" / ";white-space:pre}
.expertise-card-footer{border-top:2px solid #E4EEF5;padding-top:16px;margin-top:auto;display:flex;flex-wrap:wrap;gap:12px}
.expertise-card a{min-height:44px;display:inline-flex;align-items:center;color:#008578;font-weight:800;text-underline-offset:3px}
.expertise-proof-links{display:flex;gap:16px;flex-wrap:wrap;margin-top:28px}
.expertise-proof-links a{display:inline-flex;min-height:44px;align-items:center;padding:10px 16px;border:1px solid #D3E3ED;border-radius:11px;color:#163B66;background:#fff;font-weight:700;text-decoration:none}
.expertise-empty{background:#fff;border:1px dashed #97B4C8;border-radius:16px;padding:25px;color:#315471}
.expertise-screen a:focus-visible,.expertise-screen button:focus-visible,.expertise-screen select:focus-visible{outline:3px solid #126DE1;outline-offset:3px}
@media(max-width:1100px){.expertise-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.expertise-hero{grid-template-columns:minmax(0,1fr) 300px}.expertise-art{transform:scale(.8);transform-origin:center}}
@media(max-width:720px){.expertise-hero{grid-template-columns:1fr;gap:10px}.expertise-art{max-width:380px;width:100%;height:185px;transform:scale(.8);transform-origin:top right}.expertise-cards{grid-template-columns:1fr}.expertise-hero-actions a{flex:1 1 170px}.expertise-filter>*{flex:1 1 100%}.expertise-filter select{width:100%}}
@media(prefers-reduced-motion:reduce){.expertise-screen{scroll-behavior:auto}}
</style>
@endpush
<div class="expertise-screen">
    <header class="expertise-hero">
        <div>
            <span class="expertise-kicker">{{ $locale === 'ar' ? 'القدرات الموثقة' : 'CAPABILITIES' }}</span>
            <h1 id="expertise-title">{{ $locale === 'ar' ? 'الخبرات التقنية' : 'Expertise' }}</h1>
            <p>{{ $locale === 'ar'
                ? 'استكشف مجالات المهارات التقنية التي تمت الموافقة على نشر أدلتها، دون ادعاءات خبرة أو شهادات غير موثقة.'
                : 'Explore engineering capabilities backed by reviewed public evidence, without unverified qualifications or claims.' }}</p>
            <nav class="expertise-hero-actions" aria-label="{{ $locale === 'ar' ? 'روابط استكشاف الخبرات' : 'Explore expertise' }}">
                <a href="#expertise-catalog">{{ $locale === 'ar' ? 'استكشاف التخصصات' : 'Explore' }}</a>
                <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
            </nav>
        </div>
        <div class="expertise-art" aria-hidden="true"><span>{ }</span><span>&lt;/&gt;</span><span>DB</span></div>
    </header>
    <section id="expertise-catalog" class="expertise-body" aria-labelledby="professional-section">
        <h2 class="expertise-section-heading" id="professional-section">{{ $locale === 'ar' ? 'المهارات المعتمدة' : 'Evidence-verified skills' }}</h2>
        <form class="expertise-filter" action="{{ route('public.expertise') }}" method="get" role="search"
              aria-label="{{ $locale === 'ar' ? 'تصفية المهارات الموثقة' : 'Filter verified skills' }}">
            <input type="hidden" name="lang" value="{{ $locale }}">
            <div>
                <label for="expertise-category">{{ $locale === 'ar' ? 'التخصص' : 'Specialty' }}</label>
                <select id="expertise-category" name="category">
                    <option value="" @selected($expertiseCategory === '')>{{ $locale === 'ar' ? 'كل التخصصات' : 'All specialties' }}</option>
                    @foreach($expertiseCategories as $key => $category)
                        <option value="{{ $key }}" @selected($expertiseCategory === $key)>{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit">{{ $locale === 'ar' ? 'تطبيق التصفية' : 'Apply filter' }}</button>
            @if($expertiseCategory !== '')
                <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إزالة التصفية' : 'Clear filter' }}</a>
            @endif
        </form>
        @if($skills->isEmpty())
            <p class="expertise-empty" role="status">{{ $locale === 'ar' ? 'لم تُعتمد مهارات للنشر بعد.' : 'No skills have been approved with verified evidence yet.' }}</p>
        @else
            <div class="expertise-cards">
                @foreach($skills->groupBy('category') as $category => $group)
                    <article class="expertise-card">
                        <div class="expertise-symbol" aria-hidden="true">{{ $category === 'Database & Reliability' ? 'DB' : ($category === 'Software & Integration' ? '</>' : ($category === 'Infrastructure & Security' ? '◇' : '✓')) }}</div>
                        <h3>{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</h3>
                        <ul class="expertise-skills">
                            @foreach($group as $skill)
                                <li>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</li>
                            @endforeach
                        </ul>
                        <div class="expertise-card-footer">
                            @if(isset($categoryTracks[$category]))
                                <a class="professional-track-link" href="{{ route('public.hire', ['lang' => $locale, 'track' => $categoryTracks[$category]]) }}">{{ $locale === 'ar' ? 'تصفية الخبرات حسب ' : 'Filter expertise by ' }}{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</a>
                            @else
                                <span>{{ $locale === 'ar' ? 'المهارات المنشورة في هذا المجال' : 'Published verified skills' }}</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        <nav class="expertise-proof-links" aria-label="{{ $locale === 'ar' ? 'أدلة الخبرات والاتصال' : 'Explore public evidence' }}">
            <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
            <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}</a>
            <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة وخبرات' : 'About' }}</a>
            <a href="{{ route('public.hire', ['lang' => $locale, 'track' => 'all']) }}">{{ $locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
            <a href="{{ route('public.resume', ['lang' => $locale, 'track' => 'all']) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
            <a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التواصل بشأن المجال' : 'Contact about this focus' }}</a>
        </nav>
    </section>
</div>