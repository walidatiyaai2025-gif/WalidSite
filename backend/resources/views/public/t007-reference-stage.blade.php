@php($screen = $screen ?? 'home')
@php($t007Ar = request()->query('lang') === 'ar')
@php($t007HasAiSkill = isset($skills) && $skills->pluck('slug')->contains('ai-engineering'))
@php($t007ReferenceReady = match ($screen) {
    // Visual-reference copy is allowed only when its specific claims are verified.
    // A verified AI capability alone does not substantiate the broad enterprise
    // delivery claim in the reference hero and cards.
    'home' => isset($publicProfile, $hasVerifiedAiCapability)
        && is_array($publicProfile) && $hasVerifiedAiCapability === true
        && ! empty($publicProfile['enterprise_delivery_verified']),
    'expertise' => $t007HasAiSkill,
    'ai' => ! empty($aiData['skills'] ?? []),
    default => false,
})
@php($referenceCopy = [
    'home' => [
        'pill' => 'PERSONAL BRAND',
        'title' => 'Waleed Atiya',
        'subtitle' => 'Engineering reliable enterprise systems with practical AI.',
        'portrait' => true,
        'cards' => [
            ['icon' => '<>', 'title' => 'Enterprise Delivery', 'text' => 'Government & enterprise systems', 'href' => route('public.projects', ['lang' => $locale])],
            ['icon' => 'AI', 'title' => 'AI Engineering', 'text' => 'Provider-neutral automation', 'href' => route('public.ai-engineering', ['lang' => $locale])],
            ['icon' => 'DB', 'title' => 'Technical Knowledge', 'text' => 'Solutions and practical guides', 'href' => route('public.lab', ['lang' => $locale])],
        ],
    ],
    'about' => [
        'pill' => 'CAREER STORY',
        'title' => 'About',
        'subtitle' => 'A career built across databases, government systems and delivery.',
        'portrait' => true,
    ],
    'expertise' => [
        'pill' => 'CAPABILITIES',
        'title' => 'Expertise',
        'subtitle' => 'Evidence-led expertise: AI, SQL, software and technical leadership.',
        'portrait' => false,
        'cards' => [
            ['icon' => '<>', 'title' => 'Database Engineering', 'text' => 'AlwaysOn / SQL / HA-DR', 'href' => route('public.expertise', ['lang' => $locale, 'category' => 'database'])],
            ['icon' => 'AI', 'title' => 'Architecture & Delivery', 'text' => 'System design / DevOps / APIs', 'href' => route('public.hire', ['lang' => $locale, 'track' => 'delivery'])],
            ['icon' => 'DB', 'title' => 'AI Engineering', 'text' => 'Agents / guardrails / evaluation', 'href' => route('public.ai-engineering', ['lang' => $locale])],
        ],
    ],
    'ai' => [
        'pill' => 'AI PRACTICE',
        'title' => 'AI Engineering',
        'subtitle' => 'AI-assisted engineering with measurable safeguards.',
        'portrait' => false,
        'cards' => [
            ['icon' => '<>', 'title' => 'AI Workflow Design', 'text' => 'Research to validation to publication', 'href' => route('public.blog', ['lang' => $locale])],
            ['icon' => 'AI', 'title' => 'Multi-provider AI', 'text' => 'Self-hosted + paid providers', 'href' => route('public.ai-engineering', ['lang' => $locale]).'#ai-evidence'],
            ['icon' => 'DB', 'title' => 'Delivery Evidence', 'text' => 'PR, CI, test and review traces', 'href' => route('public.github', ['lang' => $locale])],
        ],
    ],
    'hire' => [
        'pill' => 'HIRING VIEW',
        'title' => 'Recruiter Mode',
        'subtitle' => 'Review published capabilities, case studies and public CV details.',
        'portrait' => true,
    ],
])
@php($safeCopy = [
    'home' => [
        'pill' => 'PERSONAL BRAND',
        'title' => 'Waleed Atiya',
        'subtitle' => 'Evidence-gated software, data and AI engineering work.',
        'portrait' => true,
        'cards' => [
            ['icon' => '<>', 'title' => 'Verified Projects', 'text' => 'Published case studies only', 'href' => route('public.projects', ['lang' => $locale])],
            ['icon' => 'AI', 'title' => 'AI Engineering', 'text' => 'Evidence-gated capabilities', 'href' => route('public.ai-engineering', ['lang' => $locale])],
            ['icon' => 'DB', 'title' => 'Technical Knowledge', 'text' => 'Solutions and practical guides', 'href' => route('public.lab', ['lang' => $locale])],
        ],
    ],
    'about' => [
        'pill' => 'CAREER STORY',
        'title' => 'About',
        'subtitle' => 'Only career records that passed publication review are shown.',
        'portrait' => true,
    ],
    'expertise' => [
        'pill' => 'CAPABILITIES',
        'title' => 'Expertise',
        'subtitle' => 'Only evidence-reviewed skills are presented as personal expertise.',
        'portrait' => false,
        'cards' => [
            ['icon' => '<>', 'title' => 'Database & Reliability', 'text' => 'Published skills only', 'href' => route('public.expertise', ['lang' => $locale, 'category' => 'database'])],
            ['icon' => 'AI', 'title' => 'Project Delivery', 'text' => 'Evidence-reviewed skills only', 'href' => route('public.hire', ['lang' => $locale, 'track' => 'delivery'])],
            ['icon' => 'DB', 'title' => 'AI Engineering', 'text' => 'Evidence-approved capabilities only', 'href' => route('public.ai-engineering', ['lang' => $locale])],
        ],
    ],
    'ai' => $referenceCopy['ai'],
    'hire' => [
        'pill' => 'HIRING VIEW',
        'title' => 'Recruiter Mode',
        'subtitle' => 'Review published skills, case studies and the public CV before contact.',
        'portrait' => true,
    ],
])
@php($copy = ($t007ReferenceReady ? $referenceCopy : $safeCopy)[$screen])
@php($t007Ui = $t007Ar ? ['explore' => 'استكشف', 'evidence' => 'عرض الأدلة', 'details' => 'عرض التفاصيل', 'download' => 'عرض السيرة العامة', 'projects' => 'أدلة المشاريع'] : ['explore' => 'Explore', 'evidence' => 'View evidence', 'details' => 'View details', 'download' => 'View public profile', 'projects' => 'Project evidence'])
@push('styles')
<style>
body:has(.t007-reference-stage){margin:0;background:#f5f9fc;color:#184876;overflow-x:hidden}
body:has(.t007-reference-stage) .site-main{min-height:0}
body:has(.t007-reference-stage) .site-footer{margin-top:0}
.t007-nav-shell{height:100px;background:#184876;display:flex;align-items:center;padding:0 59px 0 55px;color:#fff}
.t007-brand{display:flex;align-items:center;text-decoration:none;white-space:nowrap;margin-right:47px;transform:translateY(3px)}
.t007-brand span{font-size:40px;font-weight:800;line-height:1;color:#0a9a8c;letter-spacing:-1.2px}
.t007-brand strong{font-size:25px;font-weight:800;color:#fff;margin-left:4px;letter-spacing:-.35px}
.t007-nav-links{height:100%;display:flex;align-items:center;flex:1;transform:translateY(3px)}
.t007-nav-links a{display:flex;align-items:center;height:100%;color:#fff;text-decoration:none;font-size:16px;font-weight:500;white-space:nowrap;margin-right:60px}
.t007-nav-links a.active{color:#0a9a8c}
.t007-nav-links a:nth-child(3){margin-right:41px}
.t007-nav-links a:nth-child(4){margin-right:45px}.t007-nav-links a:nth-child(5){margin-right:61px}
.t007-nav-links a:nth-child(6){margin-right:71px}.t007-nav-links a:nth-child(7){margin-right:55px}.t007-nav-links a:nth-child(8){margin-right:0}
.t007-contact{display:flex;width:161px;height:50px;align-items:center;justify-content:center;border-radius:18px;background:#0a9a8c;color:#184876;text-decoration:none;font-size:18px;font-weight:800;white-space:nowrap;margin-left:auto;transform:translateY(2px)}
.t007-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.t007-reference-stage{height:760px;background:#f5f9fc}
.t007-reference-hero{height:326px;background:#edf7fc;position:relative;padding:35px 56px}
.t007-pill{height:39px;min-width:259px;width:max-content;border-radius:16px;background:#dcf6f1;color:#0a9a8c;display:flex;align-items:center;padding:0 15px;font-size:18px;font-weight:600;letter-spacing:.6px;transform:translateX(-4px)}
.t007-reference-hero h1{font-size:46px;line-height:1.15;margin:28px 0 21px;color:#184876;font-weight:800;letter-spacing:2.05px}
.t007-reference-subtitle{margin:0;color:#306a94;font-size:20px;line-height:1.4;letter-spacing:.1px;position:relative;top:2px}
.t007-reference-actions{display:flex;gap:19px;margin-top:27px}
.t007-reference-action{height:63px;width:245px;border-radius:17px;display:flex;align-items:center;padding-left:29px;text-decoration:none;color:#184876;font-size:23px;font-weight:800;letter-spacing:1px}
.t007-reference-action.primary{background:#0a9a8c}.t007-reference-action.secondary{width:249px;background:#fff;border:2px solid #0a9a8c;padding-left:34px;font-weight:500}
.t007-visual-stack{position:absolute;left:920px;top:80px;width:372px;height:226px}
.t007-visual-stack .tile{position:absolute;width:221px;height:171px;border:3px solid #14bc98;border-radius:26px;background:#306a94;color:#0a9a8c;font-weight:800;font-size:30px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.t007-visual-stack .one{left:0;top:0;background:#26608e;border-color:#149e98}.t007-visual-stack .two{left:75px;top:27px;background:#2b6591;border-color:#14ad98}.t007-visual-stack .three{left:150px;top:54px;background:#306a94;border-color:#14bc98}
.t007-visual-stack:not(.portrait) .one span{transform:translateX(-46px)}.t007-visual-stack:not(.portrait) .two span{transform:translateX(-48px)}.t007-visual-stack:not(.portrait) .three span{transform:translate(-37px,-18px)}
.t007-visual-stack.portrait .three{left:132px;top:-34px;width:259px;height:264px;border-radius:20px;background:#fff}
.t007-visual-stack.portrait .three img{display:block;width:100%;height:100%;object-fit:cover;object-position:center top}
.t007-reference-body{height:434px;background:#f5f9fc;padding:44px 56px 49px}
.t007-reference-cards{display:grid;grid-template-columns:repeat(3,411px);gap:33px}
.t007-reference-card{height:341px;border:1px solid #e0e7ee;border-radius:20px;background:#fff;padding:22px 23px;position:relative}
.t007-card-icon{width:67px;height:65px;border-radius:15px;background:#edf7fc;color:#0a9a8c;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center;transform:translateX(-1px)}
.t007-reference-card h2{font-size:26px;line-height:1.25;margin:37px 0 21px;color:#184876;font-weight:800;letter-spacing:1.5px}
.t007-reference-card p{font-size:16px;line-height:1.5;margin:0;color:#254a68;letter-spacing:.55px;position:relative;top:-3px}
.t007-card-rule{height:2px;background:#dce7ec;position:absolute;left:19px;right:29px;top:244px}
.t007-card-link{position:absolute;top:271px;left:26px;color:#0a7e74;text-decoration:none;font-size:20px;font-weight:700;letter-spacing:1.15px}
.t007-about-rows{display:grid;gap:19px;padding:6px 6px 0}
.t007-about-row{height:98px;border-radius:18px;background:#fff;display:flex;align-items:center;padding:0 25px}
.t007-about-period{width:176px;height:45px;border-radius:14px;background:#e2f2f3;color:#007174;display:flex;align-items:center;padding-left:14px;font-size:17px;font-weight:700;letter-spacing:.25px}
.t007-about-row strong{margin-left:32px;font-size:26px;line-height:1.25;color:#184876;font-weight:800;letter-spacing:.1px}
.t007-hire-cards{display:grid;grid-template-columns:repeat(3,412px);gap:28px;padding-top:-6px}
.t007-hire-card{height:348px;border-radius:20px;background:#fff;padding:34px 25px;color:#184876}
.t007-hire-card h2{font-size:30px;margin:0 0 34px;line-height:1.2}.t007-hire-card p{font-size:20px;margin:0 0 34px;color:#254a68;white-space:nowrap}
.t007-hire-card a{display:block;text-decoration:none;color:#254a68;font-size:20px;margin:0 0 31px}
.t007-functional{background:#fff;padding-top:48px}
@media(min-width:901px) and (max-width:1399px){.t007-nav-shell{padding-inline:24px}.t007-brand{margin-right:24px}.t007-nav-links a,.t007-nav-links a:nth-child(n){margin-right:clamp(8px,1.4vw,20px);font-size:14px}.t007-reference-cards,.t007-hire-cards{grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.t007-reference-hero{padding-inline:36px}.t007-visual-stack{left:auto;right:30px;transform:scale(.78);transform-origin:top right}.t007-reference-subtitle{max-width:65%}.t007-reference-body{padding-inline:36px}.t007-hire-card p{white-space:normal;overflow-wrap:anywhere}}
@media(max-width:900px){.t007-reference-actions{flex-wrap:wrap}.t007-reference-action{max-width:100%;width:auto;min-width:0;padding-inline:22px}.t007-reference-subtitle{max-width:100%}.t007-visual-stack{max-width:100%;transform:scale(.78);transform-origin:top left}.t007-about-row{height:auto;min-height:98px;gap:12px;flex-wrap:wrap;padding-block:16px}.t007-about-row strong{margin-left:0;font-size:clamp(17px,4vw,26px);overflow-wrap:anywhere}.t007-about-period{flex-shrink:0}.t007-hire-card p{white-space:normal;overflow-wrap:anywhere}.t007-reference-card h2{overflow-wrap:anywhere}}
@media(max-width:900px){.t007-nav-shell{height:auto;min-height:100px;padding:18px 24px;flex-wrap:wrap}.t007-nav-links{order:3;width:100%;overflow:auto}.t007-nav-links a{margin-right:28px}.t007-contact{margin-left:auto}.t007-reference-stage{height:auto}.t007-reference-hero{height:auto;min-height:420px;padding:32px 28px}.t007-visual-stack{position:relative;left:auto;top:auto;margin:35px auto 0}.t007-reference-body{height:auto;padding:28px}.t007-reference-cards,.t007-hire-cards{grid-template-columns:1fr}.t007-reference-card,.t007-hire-card{width:100%}}
</style>
@endpush
<section class="t007-reference-stage" aria-label="{{ $copy['title'] }}">
    <div class="t007-reference-hero">
        <div class="t007-pill">{{ $copy['pill'] }}</div>
        <h1>{{ $copy['title'] }}</h1>
        <p class="t007-reference-subtitle">{{ $copy['subtitle'] }}</p>
        <div class="t007-reference-actions">
            <a class="t007-reference-action primary" href="#t007-primary-content">{{ $t007Ui['explore'] }}</a>
            <a class="t007-reference-action secondary" href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $t007Ui['evidence'] }}</a>
        </div>
        <div class="t007-visual-stack {{ $copy['portrait'] ? 'portrait' : '' }}" aria-hidden="true">
            <div class="tile one"><span>{ }</span></div>
            <div class="tile two"><span>&lt;/&gt;</span></div>
            @if($copy['portrait'])
                <div class="tile three"><img src="{{ route('public.owner-portrait') }}" alt=""></div>
            @else
                <div class="tile three"><span>DB</span></div>
            @endif
        </div>
    </div>
    <div class="t007-reference-body" id="t007-primary-content">
        @if($screen === 'about')
            <div class="t007-about-rows">
                @if(isset($positions) && $positions->isNotEmpty())
                    @foreach($positions->take(3) as $position)
                        <div class="t007-about-row">
                            <span class="t007-about-period">{{ $position->started_on?->format('Y') ?? ($t007Ar ? 'غير محدد' : 'Undated') }} - {{ $position->ended_on ? $position->ended_on->format('Y') : ($t007Ar ? 'الحاضر' : 'Present') }}</span>
                            <strong>{{ $t007Ar ? $position->role_ar : $position->role_en }} — {{ $position->org_name }}</strong>
                        </div>
                    @endforeach
                @else
                    <div class="t007-about-row"><strong>{{ $t007Ar ? 'لا توجد فترات مهنية اجتازت التحقق والنشر بعد.' : 'No career periods have completed verification and publication review.' }}</strong></div>
                @endif
            </div>
        @elseif($screen === 'hire')
            <div class="t007-hire-cards">
                @foreach($t007Ar ? [
                    ['ملاءمة الملف', 'المهارات المنشورة بالأدلة فقط'],
                    ['الأدلة', 'دراسات الحالة والروابط العامة المراجعة'],
                    ['خيارات السيرة', 'السيرة العامة حسب المسار المختار'],
                ] : [
                    ['Profile fit', 'Evidence-reviewed published skills only'],
                    ['Evidence', 'Published case studies and reviewed public links'],
                    ['CV options', 'Public CV for the selected career track'],
                ] as $card)
                    <article class="t007-hire-card">
                        <h2>{{ $card[0] }}</h2><p>{{ $card[1] }}</p>
                        <a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $t007Ui['download'] }}</a>
                        <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $t007Ui['projects'] }}&nbsp;&nbsp;&gt;</a>
                    </article>
                @endforeach
            </div>
        @else
            <div class="t007-reference-cards">
                @foreach($copy['cards'] as $card)
                    <article class="t007-reference-card">
                        <div class="t007-card-icon">{{ $card['icon'] }}</div>
                        <h2>{{ $card['title'] }}</h2>
                        <p>{{ $card['text'] }}</p>
                        <div class="t007-card-rule" aria-hidden="true"></div>
                        <a class="t007-card-link" href="{{ $card['href'] }}">{{ $t007Ui['details'] }}&nbsp;&nbsp;&gt;</a>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
