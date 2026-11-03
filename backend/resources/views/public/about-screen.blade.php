{{-- Owner-approved About composition, 2026-11-03: English-first; all text is seeded CMS copy.
     Career rows/certificates/claim sources still come only from publication-gated records. --}}
@php($aboutCopy = app(\App\Domain\Content\PageContentRepository::class)->publishedMap('about', $locale))
@php($aboutDefaults = \App\Domain\Content\PageContentDefaults::aboutCopy())
@php($aboutText = static fn (string $section, string $key): string => $aboutCopy[$section][$key] ?? $aboutDefaults[$section][$key][$locale])
@php($aboutTech = array_keys($aboutDefaults['technologies']))
@php($aboutTech = array_values(array_filter($aboutTech, fn($key) => $key !== 'heading')))
@php($aboutFocus = ['diwanmam','datacenter','biometric','wordpress','aimwweb','walidsite'])
@php($employerSlots = [
    'Amiri Diwan' => ['employer_amiri','amiri_summary'],
    'Crown Prince Diwan' => ['employer_crown','crown_summary'],
    'PAAET / Diyar United' => ['employer_paaet','paaet_summary'],
    'Bizware' => ['employer_bizware','bizware_summary'],
])
@push('styles')
<style>
body:has(.wa-about){background:#eef7fd}
.wa-about{--ink:#174371;--teal:#00a995;--edge:#d9e8f0;--muted:#456684;color:var(--ink);padding-block:0 48px}
.wa-about *{box-sizing:border-box}
.wa-about a:focus-visible,.wa-about button:focus-visible{outline:3px solid #00b89b;outline-offset:3px}
.wa-about section[id]{scroll-margin-top:22px}
.wa-about section[id]:target{outline:2px solid #00a995;outline-offset:3px}
.about-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:clamp(20px,5vw,72px);align-items:center;min-height:265px;padding:30px 0 22px}
.about-hero::before{content:"";position:absolute;z-index:-1;inset:0 50% 0 auto;transform:translateX(50%);width:100vw;background:#eaf5fb}
.about-kicker{display:inline-block;background:#d8f4ef;color:#008d82;border-radius:13px;padding:6px 13px;margin:0 0 8px;font-size:13px;font-weight:800;letter-spacing:.045em}
.about-hero h1{color:#174371;font-size:clamp(32px,3.3vw,44px);line-height:1.1;margin:2px 0 3px;font-weight:800}
.about-headline{font-size:clamp(15px,1.45vw,19px);font-weight:800;color:#174371;line-height:1.4;margin:0 0 9px}
.about-intro{font-size:14px;line-height:1.54;color:#355879;max-width:740px;margin:0 0 14px}
.about-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.about-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 18px;border-radius:11px;text-decoration:none;font-size:14px;font-weight:800;border:1px solid var(--teal)}
.about-actions .primary{background:#00a895;color:#fff;min-width:156px}
.about-actions .secondary{background:#fff;color:#174371;min-width:145px}
.about-actions .quiet{color:#237b7b;text-decoration:underline;border:0;padding-inline:9px}
.about-person{display:flex;align-items:center;gap:24px;min-width:0}
.about-portrait{margin:0;flex:0 0 auto;width:clamp(128px,11vw,164px)}
.about-portrait img{width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;object-position:center 17%;border-radius:17px;border:2px solid var(--teal);display:block;background:#fff}
.about-contact{font-size:13px;line-height:1.35;min-width:210px}
.about-contact a,.about-contact span{display:flex;gap:10px;align-items:center;min-height:29px;text-decoration:none;color:#213b55;overflow-wrap:anywhere}
.about-contact img{width:18px;height:18px;object-fit:contain}
.about-contact .about-icon{display:inline-flex;width:20px;align-items:center;justify-content:center;color:#009f8e;font-size:18px;font-weight:800;flex:0 0 20px}
.about-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0 17px}
.about-fact{min-width:0;display:flex;align-items:center;gap:14px;padding:14px 18px;min-height:74px;border:1px solid #e1ebf2;background:#fff;border-radius:13px;box-shadow:0 5px 15px rgba(17,62,105,.025)}
.about-fact img{width:36px;height:36px;object-fit:contain;background:#e9f8f5;border-radius:11px;padding:7px}
.about-fact b{display:block;font-size:clamp(13px,1.35vw,20px);color:#173f6a;line-height:1.2}
.about-fact span{display:block;font-size:12px;color:#254b6d}
.about-columns{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:12px;margin-bottom:12px;align-items:stretch}
.about-columns--wide{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr)}
.about-panel{background:#fff;border:1px solid #d9e5ef;border-radius:14px;box-shadow:0 5px 20px rgba(13,58,94,.035);min-width:0;padding:14px 16px}
.about-panel h2{font-size:20px;line-height:1.2;margin:0 0 12px;color:#153e6c;font-weight:800;display:flex;align-items:center;gap:9px}
.about-panel h2::before{content:"";width:4px;height:21px;border-radius:3px;background:#00a995;flex:0 0 4px}
.about-summary p{font-size:13px;line-height:1.56;color:#284e73;margin:0 0 13px}
.about-summary p:last-child{margin:0}
.about-timeline{list-style:none;margin:0;padding:0 0 0 20px;position:relative}
.about-timeline::before{content:"";position:absolute;top:14px;bottom:14px;inset-inline-start:7px;width:2px;background:#c9d6e0}
.about-timeline li{position:relative;min-width:0;margin:0 0 7px}
.about-timeline li:last-child{margin-bottom:0}
.about-timeline li::before{content:"";position:absolute;inset-inline-start:-19px;top:18px;width:10px;height:10px;background:#00a995;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1px #b7d9dd}
.about-career-row{display:flex;gap:10px;align-items:center;background:#f5f9fc;border-radius:9px;padding:9px 10px;min-width:0}
.about-career-logo{display:flex;align-items:center;justify-content:center;width:45px;height:42px;flex:0 0 45px;border-radius:10px;background:#fff;border:1px solid #dce9ed}
.about-career-logo img{width:30px;height:30px;object-fit:contain}
.about-career-body{min-width:0;flex:1}
.about-career-top{display:flex;justify-content:space-between;gap:8px;align-items:start}
.about-career-top h3{margin:0;color:#153e6c;font-size:13px;font-weight:800;line-height:1.27}
.about-period{white-space:nowrap;color:#087f73;border-radius:99px;background:#dff6ef;font-size:11px;font-weight:800;padding:4px 8px}
.about-career-body p{margin:2px 0 0;font-size:11px;line-height:1.35;color:#355878}
.about-empty{padding:13px;background:#eef8f9;color:#345b75;border:1px dashed #b8d7df;border-radius:10px;font-size:13px}
.about-technologies{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}
.about-tech{display:flex;align-items:center;justify-content:flex-start;gap:7px;min-height:51px;border:1px solid #e2ebf1;border-radius:10px;background:#fdfefe;padding:7px;min-width:0}
.about-tech img{width:28px;height:28px;object-fit:contain;flex:0 0 28px}
.about-tech span{font-size:12px;line-height:1.2;font-weight:800;color:#163e67;overflow-wrap:anywhere}
.about-focus{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.about-focus-card{display:flex;gap:8px;align-items:start;min-width:0;border:1px solid #e0eaf1;border-radius:11px;padding:11px 10px;background:#fff}
.about-focus-card img{width:32px;height:32px;flex:0 0 32px;background:#ecf9f6;border-radius:8px;padding:5px}
.about-focus-card h3{font-size:12px;color:#164371;line-height:1.25;margin:0 0 5px}
.about-focus-card p{font-size:11px;line-height:1.35;margin:0;color:#355a77}
.about-education{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;align-items:center}
.about-school{display:flex;gap:12px;align-items:center;padding-inline:10px}
.about-school:not(:first-child){border-inline-start:1px solid #c8d6e2}
.about-school img{width:30px;height:30px;flex:0 0 30px}
.about-school b,.about-school span{display:block;font-size:12px;line-height:1.45}
.about-school b{color:#174371}.about-school span{color:#44617d}
.about-claims{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,275px),1fr));gap:10px}
.about-claims li{padding:12px;border-radius:10px;border:1px solid #d8e9ef}
.about-claims li p{margin:0 0 8px;font-size:13px}
.about-claims a{color:#007e7c;font-size:13px;font-weight:800;text-underline-offset:3px}
.about-jump-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}
.about-jump-links a{font-size:12px;color:#24716e;font-weight:700}
.about-panel--proof{margin-top:12px}
.about-panel--proof h2{font-size:17px}
@media(max-width:1170px){.about-contact{min-width:180px}.about-columns{grid-template-columns:1fr}.about-focus{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:880px){.about-hero{grid-template-columns:1fr}.about-person{justify-content:flex-start}.about-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.wa-about{padding-bottom:25px}.about-hero{padding:22px 0}.about-person{flex-wrap:wrap;gap:12px}.about-portrait{width:128px}.about-intro{font-size:13px}.about-facts{grid-template-columns:1fr 1fr;gap:7px}.about-fact{padding:9px;gap:8px}.about-fact b{font-size:14px}.about-fact img{width:29px;height:29px}.about-technologies{grid-template-columns:repeat(2,minmax(0,1fr))}.about-focus{grid-template-columns:1fr 1fr}.about-education{grid-template-columns:1fr}.about-school{padding:8px}.about-school:not(:first-child){border-inline-start:0;border-top:1px solid #dce8ee}.about-career-top{flex-wrap:wrap}.about-period{order:-1}.about-panel{padding:13px}}
@media(max-width:410px){.about-focus{grid-template-columns:1fr}.about-contact{min-width:0}}
:lang(ar) .about-hero,:lang(ar) .wa-about{text-align:right}
:lang(ar) .about-timeline{padding-left:0;padding-right:20px}
:lang(ar) .about-timeline::before{inset-inline-start:7px}
@media print{.about-actions,.about-jump-links{display:none}.about-panel{break-inside:avoid;box-shadow:none}.about-hero::before{display:none}.about-hero{min-height:0}}
</style>
@endpush

<div class="wa-about about-screen">
    <header class="about-hero">
        <div class="about-hero-copy">
            <p class="about-kicker">{{ $aboutText('intro','eyebrow') }}</p>
            <h1 id="about-screen-title">{{ $aboutText('intro','heading') }}</h1>
            <p class="about-headline">{{ $aboutText('intro','headline') }}</p>
            <p class="about-intro">{{ $aboutText('intro','description') }}</p>
            <nav class="about-actions" aria-label="{{ $locale === 'ar' ? 'روابط النبذة' : 'About actions' }}">
                <a class="t007-reference-action primary" href="#t007-primary-content">{{ $locale === 'ar' ? 'استكشف مسيرتي' : 'Explore My Work' }}</a>
                <a class="about-resume secondary" href="{{ route('public.resume',['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'عرض السيرة' : 'View Resume' }}</a>
                <a class="t007-reference-action secondary quiet" href="#about-evidence">{{ $locale === 'ar' ? 'عرض الأدلة' : 'View evidence' }}</a>
            </nav>
        </div>
        <div class="about-person">
            <figure class="about-portrait">
                <img src="{{ route('public.about-media',['slot'=>'portrait']) }}"
                    alt="{{ $locale === 'ar' ? 'الصورة الأصلية المعتمدة لوليد عطية' : 'Owner-supplied original portrait of Waleed Atiya' }}"
                    width="285" height="356" loading="eager" decoding="async">
            </figure>
            <address class="about-contact" style="font-style:normal">
                <span><b class="about-icon" aria-hidden="true">⌖</b>{{ $aboutText('intro','location') }}</span>
                <a href="mailto:{{ $aboutText('intro','email') }}"><b class="about-icon" aria-hidden="true">@</b>{{ $aboutText('intro','email') }}</a>
                <a href="tel:{{ $aboutText('intro','phone_kuwait') }}"><b class="about-icon" aria-hidden="true">☎</b><bdi>{{ $aboutText('intro','phone_kuwait') }}</bdi></a>
                <a href="tel:{{ $aboutText('intro','phone_egypt') }}"><b class="about-icon" aria-hidden="true">☎</b><bdi>{{ $aboutText('intro','phone_egypt') }}</bdi></a>
            </address>
        </div>
    </header>

    <section class="about-facts" aria-label="{{ $locale === 'ar' ? 'الخبرات الرئيسية' : 'Career at a glance' }}">
        <article class="about-fact"><img src="{{ route('public.about-media',['slot'=>'tech_cicd']) }}" alt="" loading="lazy"><div><b>{{ $aboutText('metrics','experience') }}</b><span>{{ $aboutText('metrics','experience_label') }}</span></div></article>
        <article class="about-fact"><img src="{{ route('public.about-media',['slot'=>'employer_amiri']) }}" alt="" loading="lazy"><div><b>{{ $aboutText('metrics','government') }}</b></div></article>
        <article class="about-fact"><img src="{{ route('public.about-media',['slot'=>'tech_sql']) }}" alt="" loading="lazy"><div><b>{{ $aboutText('metrics','database') }}</b></div></article>
        <article class="about-fact"><img src="{{ route('public.about-media',['slot'=>'tech_ai']) }}" alt="" loading="lazy"><div><b>{{ $aboutText('metrics','ai') }}</b></div></article>
    </section>

    <div class="about-columns" id="t007-primary-content" tabindex="-1">
        <section class="about-panel about-summary" aria-labelledby="about-summary-heading">
            <h2 id="about-summary-heading">{{ $aboutText('summary','heading') }}</h2>
            <p>{{ $aboutText('summary','paragraph_1') }}</p>
            <p>{{ $aboutText('summary','paragraph_2') }}</p>
            <p>{{ $aboutText('summary','paragraph_3') }}</p>
        </section>
        <section id="about-career" class="about-panel" aria-labelledby="about-career-heading" tabindex="-1">
            <h2 id="about-career-heading">{{ $aboutText('career','heading') }}</h2>
            @if($positions->isEmpty())
                <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد فترات مهنية تم التحقق منها وإتاحتها للنشر حاليًا.' : 'No career periods have completed verification and publication review.' }}</p>
            @else
            <ol class="about-timeline">
                @foreach($positions as $position)
                    @php($mapping = $employerSlots[$position->org_name] ?? null)
                    <li>
                        <article class="about-career-row">
                            <div class="about-career-logo" aria-hidden="true">
                                @if($mapping)
                                    <img src="{{ route('public.about-media',['slot'=>$mapping[0]]) }}" alt="" loading="lazy">
                                @else
                                    <span>▣</span>
                                @endif
                            </div>
                            <div class="about-career-body">
                                <div class="about-career-top">
                                    <h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }} — {{ $position->org_name }}</h3>
                                    <span class="about-period"><time datetime="{{ $position->started_on->format('Y-m-d') }}">{{ $position->started_on->format('Y') }}</time> – @if($position->ended_on)<time datetime="{{ $position->ended_on->format('Y-m-d') }}">{{ $position->ended_on->format('Y') }}</time>@else{{ $locale === 'ar' ? 'حتى الآن' : 'Present' }}@endif</span>
                                </div>
                                @if($mapping)<p>{{ $aboutText('career',$mapping[1]) }}</p>@endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>
            @endif
        </section>
    </div>

    <div class="about-columns about-columns--wide">
        <section class="about-panel" aria-labelledby="about-technologies-heading">
            <h2 id="about-technologies-heading">{{ $aboutText('technologies','heading') }}</h2>
            <div class="about-technologies">
                @foreach($aboutTech as $key)
                <div class="about-tech">
                    <img src="{{ route('public.about-media',['slot'=>'tech_'.$key]) }}" alt="" loading="lazy">
                    <span>{{ $aboutText('technologies',$key) }}</span>
                </div>
                @endforeach
            </div>
        </section>
        <section class="about-panel" aria-labelledby="about-focus-heading">
            <h2 id="about-focus-heading">{{ $aboutText('focus','heading') }}</h2>
            <div class="about-focus">
                @foreach($aboutFocus as $key)
                    <article class="about-focus-card">
                        <img src="{{ route('public.about-media',['slot'=>'focus_'.$key]) }}" alt="" loading="lazy">
                        <div><h3>{{ $aboutText('focus',$key.'_title') }}</h3><p>{{ $aboutText('focus',$key.'_body') }}</p></div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    <section id="about-certifications" class="about-panel" aria-labelledby="about-certifications-heading" tabindex="-1">
        <h2 id="about-certifications-heading">{{ $aboutText('education','heading') }}</h2>
        <div class="about-education">
            <div class="about-school"><img src="{{ route('public.about-media',['slot'=>'tech_cicd']) }}" alt=""><div><b>{{ $aboutText('education','degree') }}</b><span>{{ $aboutText('education','university') }}</span></div></div>
            <div class="about-school"><img src="{{ route('public.about-media',['slot'=>'focus_biometric']) }}" alt=""><div><b>{{ $aboutText('education','pmp') }}</b><span>{{ $aboutText('education','certification_label') }}</span></div></div>
            <div class="about-school"><img src="{{ route('public.about-media',['slot'=>'focus_biometric']) }}" alt=""><div><b>{{ $aboutText('education','itil') }}</b><span>{{ $aboutText('education','certification_label') }}</span></div></div>
        </div>
        @if($certifications->isEmpty())
            <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد شهادات اجتازت التحقق من الأدلة وحقوق النشر بعد.' : 'No credentials have cleared evidence and publication checks yet.' }}</p>
        @else
            <ul class="about-claims">
                @foreach($certifications as $certification)
                    <li><strong>{{ $certification->title }}</strong>
                        @if($certification->issuer)<p>{{ $certification->issuer }}</p>@endif
                        @if($certification->issued_on)<time datetime="{{ $certification->issued_on->format('Y-m-d') }}">{{ $certification->issued_on->format('Y-m-d') }}</time>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section id="about-evidence" class="about-panel about-panel--proof" aria-labelledby="about-evidence-heading" tabindex="-1">
        <h2 id="about-evidence-heading">{{ $locale === 'ar' ? 'مصادر مهنية معتمدة' : 'Evidence-backed professional statements' }}</h2>
        @if(count($approvedClaims) === 0)
            <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد عبارات مهنية إضافية معتمدة بأدلة عامة حاليًا.' : 'No additional source-backed professional statements have been approved for display.' }}</p>
        @else
            <ul class="about-claims">
                @foreach($approvedClaims as $claim)
                    <li>
                        <p>{{ $claim['text'] }}</p>
                        <a href="{{ $claim['evidence_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ($locale === 'ar' ? 'افتح المصدر المراجع: ' : 'Open reviewed source: ').$claim['text'] }}">{{ $locale === 'ar' ? 'عرض الدليل' : 'View evidence' }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
        <nav class="about-jump-links" aria-label="{{ $locale === 'ar' ? 'الروابط المهنية' : 'Professional links' }}">
            <a href="#about-career">{{ $locale === 'ar' ? 'المسيرة المهنية' : 'Experience' }}</a>
            <a href="#about-certifications">{{ $locale === 'ar' ? 'المؤهلات' : 'Credentials' }}</a>
            <a href="{{ route('public.projects',['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'المشاريع الموثقة' : 'Verified case studies' }}</a>
            <a href="{{ route('public.contact',['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
            @if($hasPublishedOwnerCv)
                <a href="{{ route('public.resume.pdf',['lang'=>$locale,'track'=>'all']) }}" type="application/pdf" download>{{ $locale === 'ar' ? 'تحميل السيرة PDF' : 'Download verified CV (PDF)' }}</a>
            @endif
        </nav>
    </section>
</div>
