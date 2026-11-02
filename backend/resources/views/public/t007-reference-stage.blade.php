@php($screen = $screen ?? 'home')
@php($t007Ar = request()->query('lang') === 'ar')
@php($copy = [
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
        'subtitle' => 'Evaluate capabilities, relevant case studies and CV fit.',
        'portrait' => true,
    ],
][$screen])
@push('styles')
<style>
body:has(.t007-reference-stage){margin:0;background:#f5f9fc;color:#184876;overflow-x:hidden}
body:has(.t007-reference-stage) .site-main{min-height:0}
body:has(.t007-reference-stage) .site-footer{margin-top:0}
.t007-nav-shell{height:100px;background:#184876;display:flex;align-items:center;padding:0 59px 0 55px;color:#fff}
.t007-brand{display:flex;align-items:center;text-decoration:none;white-space:nowrap;margin-right:41px}
.t007-brand span{font-size:40px;font-weight:800;line-height:1;color:#0a9a8c;letter-spacing:-1.2px}
.t007-brand strong{font-size:25px;font-weight:800;color:#fff;margin-left:4px;letter-spacing:-.35px}
.t007-nav-links{height:100%;display:flex;align-items:center;flex:1}
.t007-nav-links a{display:flex;align-items:center;height:100%;color:#fff;text-decoration:none;font-size:16px;font-weight:400;white-space:nowrap;margin-right:60px}
.t007-nav-links a.active{color:#0a9a8c}
.t007-nav-links a:nth-child(4){margin-right:45px}.t007-nav-links a:nth-child(5){margin-right:61px}
.t007-nav-links a:nth-child(6){margin-right:71px}.t007-nav-links a:nth-child(7){margin-right:55px}.t007-nav-links a:nth-child(8){margin-right:0}
.t007-contact{display:flex;width:161px;height:51px;align-items:center;justify-content:center;border-radius:18px;background:#0a9a8c;color:#184876;text-decoration:none;font-size:18px;font-weight:800;white-space:nowrap;margin-left:auto}
.t007-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.t007-reference-stage{height:760px;background:#f5f9fc}
.t007-reference-hero{height:326px;background:#edf7fc;position:relative;padding:35px 56px}
.t007-pill{height:40px;min-width:259px;width:max-content;border-radius:16px;background:#dcf6f1;color:#0c9288;display:flex;align-items:center;padding:0 15px;font-size:18px;font-weight:700;letter-spacing:.2px}
.t007-reference-hero h1{font-size:46px;line-height:1.15;margin:27px 0 21px;color:#184876;font-weight:800;letter-spacing:-.7px}
.t007-reference-subtitle{margin:0;color:#306a94;font-size:20px;line-height:1.4;letter-spacing:.1px}
.t007-reference-actions{display:flex;gap:19px;margin-top:27px}
.t007-reference-action{height:63px;width:245px;border-radius:17px;display:flex;align-items:center;padding-left:29px;text-decoration:none;color:#184876;font-size:23px;font-weight:700}
.t007-reference-action.primary{background:#0a9a8c}.t007-reference-action.secondary{width:249px;background:#fff;border:2px solid #0a9a8c;padding-left:34px;font-weight:500}
.t007-visual-stack{position:absolute;left:920px;top:80px;width:372px;height:226px}
.t007-visual-stack .tile{position:absolute;width:220px;height:170px;border:3px solid #14bc98;border-radius:26px;background:#306a94;color:#0a9a8c;font-weight:800;font-size:30px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.t007-visual-stack .one{left:0;top:0}.t007-visual-stack .two{left:76px;top:28px}.t007-visual-stack .three{left:152px;top:55px}
.t007-visual-stack:not(.portrait) .one span{transform:translateX(62px)}.t007-visual-stack:not(.portrait) .two span{transform:translateX(45px)}
.t007-visual-stack.portrait .three{left:132px;top:-34px;width:259px;height:264px;border-radius:20px;background:#fff}
.t007-visual-stack.portrait .three img{display:block;width:100%;height:100%;object-fit:cover;object-position:center top}
.t007-reference-body{height:434px;background:#f5f9fc;padding:44px 56px 49px}
.t007-reference-cards{display:grid;grid-template-columns:repeat(3,411px);gap:33px}
.t007-reference-card{height:341px;border:1px solid #e0e7ee;border-radius:20px;background:#fff;padding:22px 23px;position:relative}
.t007-card-icon{width:67px;height:67px;border-radius:15px;background:#edf7fc;color:#0a9a8c;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center}
.t007-reference-card h2{font-size:26px;line-height:1.25;margin:35px 0 21px;color:#184876;font-weight:800;letter-spacing:.1px}
.t007-reference-card p{font-size:16px;line-height:1.5;margin:0;color:#254a68;letter-spacing:.2px}
.t007-card-rule{height:2px;background:#e0e7ee;position:absolute;left:19px;right:29px;top:244px}
.t007-card-link{position:absolute;top:274px;left:26px;color:#007f76;text-decoration:none;font-size:20px;font-weight:700;letter-spacing:.1px}
.t007-about-rows{display:grid;gap:19px;padding:6px 6px 0}
.t007-about-row{height:98px;border-radius:18px;background:#fff;display:flex;align-items:center;padding:0 25px}
.t007-about-period{width:176px;height:45px;border-radius:14px;background:#e2f2f3;color:#007174;display:flex;align-items:center;padding-left:14px;font-size:17px;font-weight:700;letter-spacing:.25px}
.t007-about-row strong{margin-left:32px;font-size:26px;line-height:1.25;color:#184876;font-weight:800;letter-spacing:.1px}
.t007-hire-cards{display:grid;grid-template-columns:repeat(3,412px);gap:28px;padding-top:-6px}
.t007-hire-card{height:348px;border-radius:20px;background:#fff;padding:34px 25px;color:#184876}
.t007-hire-card h2{font-size:30px;margin:0 0 34px;line-height:1.2}.t007-hire-card p{font-size:20px;margin:0 0 34px;color:#254a68;white-space:nowrap}
.t007-hire-card a{display:block;text-decoration:none;color:#254a68;font-size:20px;margin:0 0 31px}
.t007-functional{background:#fff;padding-top:48px}
@media(max-width:900px){.t007-nav-shell{height:auto;min-height:100px;padding:18px 24px;flex-wrap:wrap}.t007-nav-links{order:3;width:100%;overflow:auto}.t007-nav-links a{margin-right:28px}.t007-contact{margin-left:auto}.t007-reference-stage{height:auto}.t007-reference-hero{height:auto;min-height:420px;padding:32px 28px}.t007-visual-stack{position:relative;left:auto;top:auto;margin:35px auto 0}.t007-reference-body{height:auto;padding:28px}.t007-reference-cards,.t007-hire-cards{grid-template-columns:1fr}.t007-reference-card,.t007-hire-card{width:100%}}
</style>
@endpush
<section class="t007-reference-stage" aria-label="{{ $copy['title'] }}">
    <div class="t007-reference-hero">
        <div class="t007-pill">{{ $copy['pill'] }}</div>
        <h1>{{ $copy['title'] }}</h1>
        <p class="t007-reference-subtitle">{{ $copy['subtitle'] }}</p>
        <div class="t007-reference-actions">
            <a class="t007-reference-action primary" href="#t007-primary-content">Explore</a>
            <a class="t007-reference-action secondary" href="{{ route('public.projects', ['lang' => $locale]) }}">View evidence</a>
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
                <div class="t007-about-row"><span class="t007-about-period">2024 - Now</span><strong>Technology Leadership / SQL / AI</strong></div>
                <div class="t007-about-row"><span class="t007-about-period">2022 - 2024</span><strong>Enterprise &amp; Royal Authority Systems</strong></div>
                <div class="t007-about-row"><span class="t007-about-period">2009 - 2022</span><strong>PAAET / Diyar - Delivery &amp; Engineering</strong></div>
            </div>
        @elseif($screen === 'hire')
            <div class="t007-hire-cards">
                @foreach([
                    ['Profile fit', 'SQL DBA | Technical PM | AI Engineering'],
                    ['Evidence', 'Case studies • certifications • verified links'],
                    ['CV options', 'ATS PDF | Executive | Engineering'],
                ] as $card)
                    <article class="t007-hire-card">
                        <h2>{{ $card[0] }}</h2><p>{{ $card[1] }}</p>
                        <a href="{{ route('public.resume', ['lang' => $locale]) }}">Download profile</a>
                        <a href="{{ route('public.projects', ['lang' => $locale]) }}">Project evidence&nbsp;&nbsp;&gt;</a>
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
                        <a class="t007-card-link" href="{{ $card['href'] }}">View details&nbsp;&nbsp;&gt;</a>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
