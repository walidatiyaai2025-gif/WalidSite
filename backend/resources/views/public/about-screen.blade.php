{{-- WEB-02: source-verified public About screen. No private biography, invented
     credentials, fake experience lengths or unreviewed employer details. --}}
@push('styles')
<style>
.about-screen{display:grid;gap:24px}
.about-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(190px,285px);align-items:center;gap:clamp(20px,4vw,54px);background:var(--wa-base);border:1px solid var(--wa-soft-border);border-radius:var(--wa-radius);padding:clamp(20px,4vw,44px)}
.about-kicker{margin:0 0 9px;color:#126453;font-weight:800;letter-spacing:.03em;font-size:13px}
.about-hero h1{color:var(--wa-primary);font-size:clamp(32px,4.2vw,52px);line-height:1.24;margin:0 0 16px}
.about-hero p{color:var(--wa-body);margin:0;max-width:66ch}
.about-portrait{margin:0;max-width:285px;justify-self:center;width:100%}
.about-portrait img{display:block;width:100%;height:auto;aspect-ratio:4/5;object-fit:cover;object-position:center top;border-radius:22px;border:1px solid var(--wa-soft-border);background:var(--wa-muted-surface)}
.about-portrait figcaption{margin-top:8px;font-size:12px;color:var(--wa-muted-text);text-align:center}
.about-actions,.about-jump-links{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-block:20px 0}
.about-actions a,.about-jump-links a{display:inline-flex;align-items:center;min-height:44px;padding:10px 16px;border:1px solid var(--wa-soft-border);border-radius:11px;color:var(--wa-primary);font-weight:750;text-decoration:none}
.about-actions a:first-child{background:var(--wa-primary);border-color:var(--wa-primary);color:#fff}
.about-jump-links{margin-top:22px}
.about-jump-links a{background:var(--wa-muted-surface)}
.about-screen a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
.about-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(270px,1fr);gap:24px;align-items:start}
.about-panel{border:1px solid var(--wa-soft-border);border-radius:22px;background:#fff;padding:clamp(20px,3vw,32px);min-width:0}
.about-panel h2{color:var(--wa-primary);font-size:clamp(22px,2.6vw,27px);margin:0 0 10px}
.about-panel>p{color:var(--wa-body);margin:0 0 20px}
.about-timeline,.about-certifications,.about-claims{list-style:none;padding:0;margin:0}
.about-timeline{border-inline-start:3px solid var(--wa-soft-border);margin-inline-start:8px}
.about-timeline li{position:relative;padding:0 20px 26px;overflow-wrap:anywhere}
.about-timeline li:last-child{padding-bottom:0}
.about-timeline li::before{content:"";position:absolute;inset-inline-start:-9px;top:7px;width:15px;height:15px;background:var(--wa-accent);border:3px solid white;outline:1px solid var(--wa-accent);border-radius:50%}
.about-timeline h3,.about-certifications h3{color:var(--wa-strong);font-size:19px;line-height:1.4;margin:0 0 5px}
.about-timeline p,.about-certifications p{color:var(--wa-body);margin:0 0 4px}
.about-period{display:flex;gap:6px;flex-wrap:wrap;align-items:center;color:var(--wa-muted-text);font-size:14px;font-weight:700;margin-bottom:8px}
.about-certifications li,.about-claims li{border-top:1px solid var(--wa-soft-border);padding-block:16px;overflow-wrap:anywhere}
.about-certifications li:first-child,.about-claims li:first-child{border-top:0}
.about-certified{display:inline-flex;align-items:center;gap:6px;color:#176B4D;font-size:14px;font-weight:700}
.about-claims a{display:inline-flex;align-items:center;min-height:44px;color:var(--wa-cta);font-weight:800;overflow-wrap:anywhere;text-underline-offset:3px}
.about-empty{padding:18px;background:var(--wa-subtle);border:1px dashed var(--wa-soft-border);border-radius:12px;color:var(--wa-body)}
.about-panel--proof{grid-column:1/-1}
.about-claims{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.about-claims li{border:1px solid var(--wa-soft-border);border-radius:13px;padding:20px}
.about-claims li:first-child{border:1px solid var(--wa-soft-border)}
@media(max-width:850px){.about-grid{grid-template-columns:1fr}.about-panel--proof{grid-column:1}}
@media(max-width:620px){.about-hero{grid-template-columns:1fr}.about-portrait{grid-row:1;max-width:205px}.about-actions a,.about-jump-links a{flex:1 1 auto;justify-content:center}}
@media print{.site-header,.site-footer,.about-actions,.about-jump-links{display:none!important}.about-hero,.about-panel{break-inside:avoid;box-shadow:none}.about-screen{gap:12px}.about-grid{grid-template-columns:1fr}}
</style>
@endpush

<div class="about-screen">
    <header class="about-hero">
        <div>
            <p class="about-kicker">{{ $locale === 'ar' ? 'المسيرة المهنية المنشورة بعد مراجعة الأدلة' : 'Evidence-gated public profile' }}</p>
            <h1 id="about-screen-title">{{ $locale === 'ar' ? 'نبذة عن وليد عطية' : 'About Waleed Atiya' }}</h1>
            <p>{{ $locale === 'ar'
                ? 'الخبرات والشهادات المعروضة هنا مستمدة من سجلات المالك المعتمدة للنشر. لا تظهر بيانات أصحاب الحسابات الأخرى أو الادعاءات التي لم تجتز مراجعة المصادر.'
                : 'The experience and credentials below come from the owner’s publication-reviewed records. Other accounts and unapproved career statements remain private.' }}</p>
            <nav class="about-actions" aria-label="{{ $locale === 'ar' ? 'الخطوات المهنية التالية' : 'Professional next steps' }}">
                <a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'افتح السيرة القابلة للطباعة أو حفظ PDF' : 'Open printable CV / save PDF' }}</a>
                <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'دراسات حالة موثقة' : 'Verified case studies' }}</a>
                <a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
            </nav>
            <nav class="about-jump-links" aria-label="{{ $locale === 'ar' ? 'أقسام النبذة' : 'About sections' }}">
                <a href="#about-career">{{ $locale === 'ar' ? 'السجل المهني' : 'Experience' }}</a>
                <a href="#about-certifications">{{ $locale === 'ar' ? 'الشهادات' : 'Credentials' }}</a>
                <a href="#about-evidence">{{ $locale === 'ar' ? 'الأدلة' : 'Source links' }}</a>
            </nav>
        </div>
        <figure class="about-portrait">
            <img src="{{ route('public.owner-portrait') }}"
                alt="{{ $locale === 'ar' ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية' : 'Approved original portrait of Waleed Atiya' }}"
                width="285" height="356" loading="eager" decoding="async">
            <figcaption>{{ $locale === 'ar' ? 'الصورة الأصلية المقدمة من المالك' : 'Owner-supplied original portrait' }}</figcaption>
        </figure>
    </header>
    <div class="about-grid">
        <section id="about-career" class="about-panel" aria-labelledby="about-career-heading">
            <h2 id="about-career-heading">{{ $locale === 'ar' ? 'المسيرة المهنية الموثقة' : 'Verified career timeline' }}</h2>
            <p>{{ $locale === 'ar' ? 'تُعرض الفترات المعتمدة للنشر فقط، بترتيب زمني من الأحدث.' : 'Only approved, published periods appear, newest first.' }}</p>
            @if($positions->isEmpty())
                <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد فترات مهنية تم التحقق منها وإتاحتها للنشر حاليًا.' : 'No career periods have completed verification and publication review.' }}</p>
            @else
                <ol class="about-timeline">
                    @foreach($positions as $position)
                        <li>
                            <div class="about-period">
                                <time datetime="{{ $position->started_on->format('Y-m-d') }}">{{ $position->started_on->format('Y') }}</time>
                                <span aria-hidden="true">—</span>
                                @if($position->ended_on)
                                    <time datetime="{{ $position->ended_on->format('Y-m-d') }}">{{ $position->ended_on->format('Y') }}</time>
                                @else
                                    <span>{{ $locale === 'ar' ? 'الحاضر' : 'Present' }}</span>
                                @endif
                            </div>
                            <h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }}</h3>
                            <p>{{ $position->org_name }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
        <section id="about-certifications" class="about-panel" aria-labelledby="about-certifications-heading">
            <h2 id="about-certifications-heading">{{ $locale === 'ar' ? 'الشهادات المعتمدة' : 'Verified credentials' }}</h2>
            <p>{{ $locale === 'ar' ? 'لا تُعرض أي شهادة إلا إذا ظلت أدلتها وحقوق نشرها معتمدة.' : 'Certificates appear only while their supporting asset remains approved for public use.' }}</p>
            @if($certifications->isEmpty())
                <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد شهادات اجتازت التحقق من الأدلة وحقوق النشر بعد.' : 'No credentials have cleared evidence and publication checks yet.' }}</p>
            @else
                <ul class="about-certifications">
                    @foreach($certifications as $certification)
                        <li>
                            <h3>{{ $certification->title }}</h3>
                            @if($certification->issuer)<p>{{ $certification->issuer }}</p>@endif
                            @if($certification->issued_on)
                                <p><time datetime="{{ $certification->issued_on->format('Y-m-d') }}">{{ $certification->issued_on->format('Y-m-d') }}</time></p>
                            @endif
                            <span class="about-certified">{{ $locale === 'ar' ? 'تم التحقق من سجل الشهادة' : 'Credential record reviewed' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section id="about-evidence" class="about-panel about-panel--proof" aria-labelledby="about-evidence-heading">
            <h2 id="about-evidence-heading">{{ $locale === 'ar' ? 'عبارات مهنية مع روابط أدلتها' : 'Evidence-backed professional statements' }}</h2>
            <p>{{ $locale === 'ar' ? 'تؤدي الروابط إلى مصادر عامة اجتازت سياسة التحقق؛ لا تعتمد هذه القائمة أي شهادة أو جهة عمل إضافية.' : 'Each source link passed public evidence checks; this list does not invent further credentials or employers.' }}</p>
            @if(count($approvedClaims) === 0)
                <p class="about-empty" role="status">{{ $locale === 'ar' ? 'لا توجد عبارات مهنية إضافية معتمدة بأدلة عامة حاليًا.' : 'No additional source-backed professional statements have been approved for display.' }}</p>
            @else
                <ul class="about-claims">
                    @foreach($approvedClaims as $claim)
                        <li>
                            <p>{{ $claim['text'] }}</p>
                            <a href="{{ $claim['evidence_url'] }}" target="_blank" rel="noopener noreferrer"
                                aria-label="{{ ($locale === 'ar' ? 'عرض المصدر الموثق: ' : 'Open reviewed source: ').$claim['text'] }}">{{ $locale === 'ar' ? 'عرض الدليل' : 'View evidence' }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
