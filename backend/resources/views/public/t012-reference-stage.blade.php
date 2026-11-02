@php($t012Screen = $screen ?? 'blog')
@php($t012Ar = ($locale ?? 'en') === 'ar')
@php($t012Current = request()->route()?->getName())
@php($t012Copy = [
    'blog' => [
        'pill' => $t012Ar ? 'مركز المعرفة' : 'KNOWLEDGE HUB',
        'title' => $t012Ar ? 'المدونة التقنية' : 'Technical Blog',
        'subtitle' => $t012Ar ? 'حلول تساعد المهندسين على التشخيص والتسليم.' : 'Solutions that help engineers troubleshoot and ship.',
    ],
    'article' => [
        'pill' => $t012Ar ? 'دليل موثق' : 'VERIFIED GUIDE',
        'title' => $t012Ar ? 'تفاصيل المقال' : 'Article Detail',
        'subtitle' => $t012Ar ? 'السبب الجذري، إصلاح موثق، أوامر ومراجع.' : 'Root cause, verified fix, commands and references.',
    ],
    'lab' => [
        'pill' => $t012Ar ? 'أدوات هندسية' : 'ENGINEERING TOOLS',
        'title' => $t012Ar ? 'المختبر التقني' : 'Tech Lab',
        'subtitle' => $t012Ar ? 'أدوات وأدلة تشغيل وتجارب هندسية عملية.' : 'Tools, runbooks and practical engineering experiments.',
    ],
    'search' => [
        'pill' => $t012Ar ? 'البحث الشامل' : 'GLOBAL SEARCH',
        'title' => $t012Ar ? 'نتائج البحث' : 'Search Results',
        'subtitle' => $t012Ar ? 'اعثر على الإجابة أو المشروع أو المورد الهندسي المناسب.' : 'Find the right answer, project, or engineering resource.',
    ],
][$t012Screen])
@php($t012Secondary = match($t012Screen) {
    'article' => '#sources-title',
    'lab' => route('public.lab.runbooks', ['lang' => $locale]),
    default => '#t012-functional',
})
@push('styles')
<style>
body:has(.t012-reference-stage){margin:0;background:#f5f9fc;color:#184876;overflow-x:hidden}
body:has(.t012-reference-stage) .site-header{display:none!important}
body:has(.t012-reference-stage) .site-main{min-height:0}
body:has(.t012-reference-stage) .site-footer{margin-top:0}
.t012-nav-shell{height:100px;background:#184876;display:flex;align-items:center;padding:0 59px 0 55px;color:#fff}
.t012-brand{display:flex;align-items:center;text-decoration:none;white-space:nowrap;margin-right:47px;transform:translate(-2px,2px)}
.t012-brand span{display:inline-block;font-size:40px;font-weight:800;line-height:1;color:#0a9a8c;letter-spacing:-1.2px;transform:translate(1px,1px);transform-origin:left top}
.t012-brand strong{display:inline-block;font-size:25px;font-weight:700;color:#fff;margin-left:4px;letter-spacing:.18px;transform:translate(-1px,-3px) scaleY(.92);transform-origin:left top}
.t012-nav-links{height:100%;display:flex;align-items:center;flex:1;min-width:0;transform:translate(-9px,3px)}
.t012-nav-links a{display:flex;align-items:center;height:100%;color:#d8e6f2;text-decoration:none;font-size:16px;font-weight:400;white-space:nowrap;margin-right:0;flex:0 0 auto}
.t012-nav-links a.active{color:#0a9a8c}
.t012-nav-links a:nth-child(1){width:106px;letter-spacing:.33px}.t012-nav-links a:nth-child(2){width:108px;letter-spacing:.5px}.t012-nav-links a:nth-child(3){width:107px;letter-spacing:.5px}.t012-nav-links a:nth-child(4){width:106px;letter-spacing:.3px}
.t012-nav-links a:nth-child(5){width:108px;letter-spacing:.6px}.t012-nav-links a:nth-child(6){width:106px;letter-spacing:.33px}.t012-nav-links a:nth-child(7){width:108px;letter-spacing:1px}.t012-nav-links a:nth-child(8){width:62px;letter-spacing:.8px}
.t012-contact{display:flex;width:161px;height:50px;align-items:center;justify-content:center;border-radius:16px;background:#0a9a8c;color:#184876;text-decoration:none;font-size:18px;font-weight:800;letter-spacing:1.55px;white-space:nowrap;margin-left:auto;transform:translateY(2px);flex-shrink:0;padding-left:23px;padding-bottom:2px}
.t012-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.t012-reference-stage{height:760px;background:#f5f9fc}
.t012-reference-hero{height:326px;background:#edf7fc;position:relative;padding:35px 56px}
.t012-pill{height:39px;min-width:259px;width:max-content;border-radius:16px;background:#dcf6f1;color:#0a9a8c;display:flex;align-items:center;padding:0 15px;font-size:18px;font-weight:700;letter-spacing:.7px;transform:translateX(-4px)}
.t012-pill span{display:block;transform:translate(-1px,-1px)}
.t012-screen-blog .t012-pill{letter-spacing:.6px;font-weight:800}
.t012-screen-blog .t012-pill span{transform:translate(-1px,-2px)}
.t012-screen-article .t012-pill{letter-spacing:.6px;font-weight:800}
.t012-screen-article .t012-pill span{transform:translate(-1px,-2px)}
.t012-reference-hero h1{font-size:46px;line-height:1.15;margin:28px 0 21px;color:#184876;font-weight:700;letter-spacing:2.05px}
.t012-reference-subtitle{margin:0 0 0 2px;color:#3b6684;font-size:20px;line-height:1.4;letter-spacing:-.18px;position:relative;top:3px}
.t012-screen-blog .t012-reference-hero h1{transform:translate(-1px,3px) scale(.977,.913);transform-origin:left top}
.t012-screen-article .t012-reference-hero h1{transform:translate(-1px,3px) scale(1.01,.89);transform-origin:left top}
.t012-screen-lab .t012-reference-hero h1{transform:translate(-1px,1px) scale(.972,.943);transform-origin:left top}
.t012-screen-search .t012-reference-hero h1{transform:translate(1px,0) scale(.992,1.004);transform-origin:left top}
.t012-screen-blog .t012-reference-subtitle{transform:translateY(-1px) scale(1.01,.9);transform-origin:left top}
.t012-screen-article .t012-reference-subtitle{transform:scale(1.006,.85);transform-origin:left top}
.t012-screen-lab .t012-reference-subtitle{transform:translate(-1px,-1px) scale(1.01,.9);transform-origin:left top}
.t012-screen-search .t012-reference-subtitle{transform:translateY(-1px) scale(1.004,.9);transform-origin:left top}
.t012-reference-actions{display:flex;gap:19px;margin-top:27px}
.t012-reference-action{height:63px;width:245px;border-radius:16px;display:flex;align-items:center;padding-left:29px;text-decoration:none;color:#184876;font-size:23px;font-weight:700;letter-spacing:1px}
.t012-reference-action.primary{background:#0a9a8c;padding-bottom:2px;letter-spacing:1.3px}
.t012-reference-action.secondary{width:249px;background:#fff;border:2px solid #0a9a8c;padding-left:34px;padding-bottom:0;font-weight:400;letter-spacing:-.3px}
.t012-visual-stack{position:absolute;left:920px;top:80px;width:372px;height:226px}
.t012-visual-stack .tile{position:absolute;width:221px;height:171px;border:3px solid #14bc98;border-radius:21px;background:#306a94;color:#0a9a8c;font-weight:800;font-size:30px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.t012-visual-stack .one{left:0;top:0;background:#26608e;border-color:#149e98}.t012-visual-stack .two{left:75px;top:27px;background:#2b6591;border-color:#14ad98}.t012-visual-stack .three{left:150px;top:54px;background:#306a94;border-color:#14bc98}
.t012-visual-stack .one span{transform:translate(-38px,-18px)}.t012-visual-stack .two span{transform:translate(-30px,-18px)}.t012-visual-stack .three span{transform:translate(-37px,-18px)}
.t012-reference-body{height:434px;background:#f5f9fc;padding:44px 56px 49px}
.t012-reference-cards{display:grid;grid-template-columns:repeat(3,411px);gap:33px}
.t012-reference-card{height:341px;border:1px solid #e0e7ee;border-radius:16px;background:#fff;padding:22px 23px;position:relative}
.t012-card-icon{width:67px;height:65px;border-radius:12px;background:#edf7fc;color:#0a9a8c;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center;transform:translateX(-1px)}
.t012-card-icon span{display:block;transform:translateY(-7px)}
.t012-reference-card h2{font-size:26px;line-height:1.25;margin:36px 0 22px;color:#184876;font-weight:700;letter-spacing:1.5px;overflow-wrap:anywhere;transform:translateY(1px) scaleY(.92);transform-origin:left top}
.t012-screen-lab .t012-reference-card h2{font-weight:800}
.t012-reference-card p{font-size:16px;line-height:1.5;margin:0;color:#254a68;letter-spacing:.55px;position:relative;top:-3px;overflow-wrap:anywhere;transform:scaleY(.94);transform-origin:left top}
.t012-card-rule{height:2px;background:#dce7ec;position:absolute;left:19px;right:29px;top:244px}
.t012-card-link{position:absolute;top:271px;left:26px;color:#0a7e74;text-decoration:none;font-size:20px;font-weight:600;letter-spacing:1.35px;transform:translateY(2px) scaleY(.84);transform-origin:left top}
.t012-article-body{padding:30px 56px 36px;display:grid;grid-template-columns:minmax(0,946px) minmax(0,346px);gap:34px}
.t012-article-main,.t012-article-aside{height:368px;background:#fff;border-radius:16px}
.t012-article-main{padding:22px 34px}.t012-article-aside{padding:22px 34px}
.t012-article-main h2{font-size:28px;line-height:1.25;margin:0 0 17px;color:#184876;font-weight:700;letter-spacing:1.05px;transform:translate(-5px,4px) scaleY(.9);transform-origin:left top}
.t012-article-steps{font-size:21px;color:#254a68;letter-spacing:1.04px;margin:0 0 18px;transform:translateY(4px) scaleY(.95);transform-origin:left top}
.t012-article-meta{font-size:20px;color:#254a68;letter-spacing:.38px;margin:0 0 32px;transform:translateY(6px) scaleY(.95);transform-origin:left top}
.t012-article-code{height:116px;width:871px;max-width:100%;border-radius:14px;background:#184876;color:#7debcb;padding:15px 26px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:18px;line-height:1.8;white-space:pre-wrap;overflow:hidden;transform:translate(-1px,6px)}
.t012-article-aside h2{font-size:24px;margin:3px 0 31px;color:#184876;font-weight:700;letter-spacing:.7px;transform:translate(1px,2px) scaleY(.963);transform-origin:left top}
.t012-article-aside ul{list-style:none;padding:0;margin:0}.t012-article-aside li{font-size:20px;line-height:30.1px;color:#254a68;margin-bottom:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transform:scaleY(.875);transform-origin:left top}
.t012-search-body{padding:33px 55px 44px}
.t012-search-box{height:72px;width:1316px;max-width:100%;background:#fff;border-radius:16px;margin-bottom:28px;display:flex;align-items:center;padding:0 33px}
.t012-search-box input{width:100%;border:0;outline:0;background:transparent;font:inherit;font-size:23px;letter-spacing:.5px;color:#315471;transform:translateY(2px) scaleY(.96);transform-origin:left top}
.t012-search-box input::placeholder{color:#5c7b91;opacity:1}
.t012-search-grid{display:grid;grid-template-columns:repeat(3,411px);gap:27px}
.t012-search-card{height:256px;border-radius:18px;background:#fff;padding:18px 25px;position:relative}
.t012-search-badge{display:inline-flex;min-width:188px;height:33px;align-items:center;padding:0 14px;border-radius:15px;background:#e9f7f5;color:#0d8477;font-size:16px;font-weight:800;transform:translate(-5px,1px)}
.t012-search-card h2{font-size:26px;margin:35px 0 31px;color:#184876;line-height:1.15;font-weight:800;letter-spacing:1.5px;transform:translateY(1px) scaleY(.96);transform-origin:left top}
.t012-search-card p{font-size:18px;letter-spacing:.43px;color:#254a68;margin:0}
.t012-search-card a{position:absolute;left:25px;bottom:31px;color:#254a68;text-decoration:none;font-size:18px;letter-spacing:.65px}
.t012-functional{background:#fff;padding-top:48px}
@media(min-width:901px) and (max-width:1399px){.t012-nav-shell{padding-inline:24px}.t012-brand{margin-right:24px}.t012-nav-links a,.t012-nav-links a:nth-child(n){margin-right:clamp(8px,1.4vw,20px);font-size:14px}.t012-reference-cards{grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.t012-reference-hero{padding-inline:36px}.t012-visual-stack{left:auto;right:30px;transform:scale(.78);transform-origin:top right}.t012-reference-subtitle{max-width:65%}.t012-reference-body{padding-inline:36px}.t012-article-body{grid-template-columns:minmax(0,1fr) minmax(260px,346px);padding-inline:36px}}
@media(max-width:900px){.t012-nav-shell{height:auto;min-height:100px;padding:18px 24px;flex-wrap:wrap}.t012-nav-links{order:3;width:100%;overflow:auto}.t012-nav-links a{margin-right:28px}.t012-contact{margin-left:auto}.t012-reference-stage{height:auto}.t012-reference-hero{height:auto;min-height:420px;padding:32px 28px}.t012-visual-stack{position:relative;left:auto;top:auto;margin:35px auto 0;max-width:100%;transform:scale(.78);transform-origin:top left}.t012-reference-body{height:auto;padding:28px}.t012-reference-cards,.t012-search-grid,.t012-article-body{grid-template-columns:1fr}.t012-reference-card,.t012-search-card,.t012-article-main,.t012-article-aside{width:100%}.t012-article-body{padding:28px}.t012-search-body{padding:28px}.t012-reference-actions{flex-wrap:wrap}.t012-reference-action{max-width:100%;width:auto;min-width:0;padding-inline:22px}.t012-reference-subtitle{max-width:100%}}
</style>
@endpush
<header class="t012-nav-shell" aria-label="{{ $t012Ar ? 'التنقل الرئيسي' : 'Primary navigation' }}">
    <a class="t012-brand" href="{{ route('home', ['lang' => $locale]) }}">
        <span aria-hidden="true">WA</span><strong>{{ $t012Ar ? 'وليد عطية' : 'Waleed Atiya' }}</strong>
    </a>
    <nav class="t012-nav-links" aria-label="{{ $t012Ar ? 'روابط صفحات الموقع' : 'Site pages' }}">
        <a href="{{ route('home', ['lang' => $locale]) }}">{{ $t012Ar ? 'الرئيسية' : 'Home' }}</a>
        <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $t012Ar ? 'نبذة' : 'About' }}</a>
        <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $t012Ar ? 'الخبرات' : 'Expertise' }}</a>
        <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $t012Ar ? 'المشاريع' : 'Projects' }}</a>
        <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $t012Ar ? 'مختبر AI' : 'AI Lab' }}</a>
        <a @class(['active' => in_array($t012Current, ['public.blog','public.article'], true)]) href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $t012Ar ? 'المدونة' : 'Blog' }}</a>
        <a href="{{ route('public.github', ['lang' => $locale]) }}">GitHub</a>
        <a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $t012Ar ? 'السيرة العامة' : 'Resume' }}</a>
    </nav>
    <a class="t012-contact" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $t012Ar ? 'تواصل' : 'Get in touch' }}</a>
    @php($t012SwitchArgs = ['lang' => $t012Ar ? 'en' : 'ar'])
    @if($t012Current === 'public.article') @php($t012SwitchArgs['slug'] = request()->route('slug')) @endif
    <a class="t012-sr-only" href="{{ route($t012Current ?? 'home', $t012SwitchArgs) }}">{{ $t012Ar ? 'English' : 'العربية' }}</a>
</header>
<section class="t012-reference-stage t012-screen-{{ $t012Screen }}" aria-label="{{ $t012Copy['title'] }}">
    <div class="t012-reference-hero">
        <div class="t012-pill"><span>{{ $t012Copy['pill'] }}</span></div>
        <h1>{{ $t012Copy['title'] }}</h1>
        <p class="t012-reference-subtitle">{{ $t012Copy['subtitle'] }}</p>
        <div class="t012-reference-actions">
            <a class="t012-reference-action primary" href="#t012-functional">{{ $t012Ar ? 'استكشف' : 'Explore' }}</a>
            <a class="t012-reference-action secondary" href="{{ $t012Secondary }}">{{ $t012Ar ? 'عرض الأدلة' : 'View evidence' }}</a>
        </div>
        <div class="t012-visual-stack" aria-hidden="true">
            <div class="tile one"><span>{ }</span></div><div class="tile two"><span>&lt;/&gt;</span></div><div class="tile three"><span>DB</span></div>
        </div>
    </div>
    @if($t012Screen === 'article')
        @php(preg_match_all('/^##\\s+(.+)$/m', $revision->body_md, $t012HeadingMatches))
        @php($t012Headings = array_slice($t012HeadingMatches[1] ?? [], 0, 5))
        @php(preg_match('/(?:~~~|\x60\x60\x60)(?:sql|bash|shell)?\\s*\\n(.*?)(?:~~~|\x60\x60\x60)/s', $revision->body_md, $t012CodeMatch))
        @php($t012Code = trim($t012CodeMatch[1] ?? ''))
        <div class="t012-reference-body t012-article-body">
            <section class="t012-article-main">
                <h2>{{ $locale === 'ar' ? $article->title_ar : $article->title_en }}</h2>
                <p class="t012-article-steps">{{ $t012Ar ? 'الأعراض  >  التشخيص  >  السبب الجذري  >  الحل' : 'Symptoms  >  Diagnostics  >  Root cause  >  Resolution' }}</p>
                <p class="t012-article-meta">{{ $t012Ar ? 'فحوصات موثقة • أوامر • تراجع • مراجع' : 'Validated checks • SQL scripts • Rollback • References' }}</p>
                @if($t012Code !== '')<pre class="t012-article-code">{{ $t012Code }}</pre>@endif
            </section>
            <aside class="t012-article-aside">
                <h2>{{ $t012Ar ? 'في هذا المقال' : 'In this article' }}</h2>
                <ul>
                    @foreach($t012Headings as $heading)<li>{{ $heading }}</li>@endforeach
                </ul>
            </aside>
        </div>
    @elseif($t012Screen === 'search')
        <div class="t012-reference-body t012-search-body">
            <form class="t012-search-box" method="get" action="{{ route('public.search') }}" role="search">
                <input type="hidden" name="lang" value="{{ $locale }}"><input name="q" type="search" maxlength="80" value="" placeholder="{{ $t012Ar ? 'ابحث في الأدلة والمشاريع والكود والمقالات...' : 'Search runbooks, projects, code and articles...' }}">
            </form>
            @if($type === 'all')
            <div class="t012-search-grid">
                @foreach($articles->take(3) as $a)
                    <article class="t012-search-card"><span class="t012-search-badge">{{ $t012Ar ? 'حل موثق' : 'Verified solution' }}</span>
                        <h2>{{ $t012Ar ? $a->title_ar : $a->title_en }}</h2><p>{{ $t012Ar ? 'ملخص، أدلة، مراجع' : 'Summary, evidence, references' }}</p>
                        <a href="{{ route('public.article', ['slug'=>$a->slug,'lang'=>$locale,'source'=>'search','q'=>$search,'type'=>$type]) }}">{{ $t012Ar ? 'فتح الدليل التفصيلي' : 'Open detailed guide' }} &nbsp;&gt;</a>
                    </article>
                @endforeach
            </div>
            @endif
        </div>
    @else
        @php($t012Icons = ['<>','AI','DB'])
        <div class="t012-reference-body">
            <div class="t012-reference-cards">
                @if($t012Screen === 'blog')
                    @foreach($entries->take(3)->values() as $index => $entry)
                        @php($cardArticle = $entry['article']) @php($cardRevision = $entry['revision'])
                        <article class="t012-reference-card">
                            <div class="t012-card-icon"><span>{{ $t012Icons[$index] ?? 'DB' }}</span></div>
                            <h2>{{ $t012Ar ? $cardArticle->title_ar : $cardArticle->title_en }}</h2>
                            <p>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/[#*>\x60]+/u', '', $cardRevision->body_md)), 70) }}</p>
                            <div class="t012-card-rule" aria-hidden="true"></div>
                            <a class="t012-card-link" href="{{ route('public.article', ['slug'=>$cardArticle->slug,'lang'=>$locale]) }}">{{ $t012Ar ? 'عرض التفاصيل' : 'View details' }} &nbsp;&gt;</a>
                        </article>
                    @endforeach
                @else
                    @php($t012LabDescriptions = $t012Ar ? ['تشخيص منظم','وصفات تقنية موثقة','حوادث وفحوصات موجهة'] : ['Structured troubleshooting','Validated technical recipes','Guided incidents and checks'])
                    @foreach($runbooks->take(3)->values() as $index => $runbook)
                        <article class="t012-reference-card">
                            <div class="t012-card-icon"><span>{{ $t012Icons[$index] ?? 'DB' }}</span></div>
                            <h2>{{ $runbook['title'] }}</h2><p>{{ $t012LabDescriptions[$index] ?? ($t012Ar ? 'دليل تشغيل تمت مراجعته' : 'Version-reviewed operational guide') }}</p>
                            <div class="t012-card-rule" aria-hidden="true"></div>
                            <a class="t012-card-link" href="{{ route('public.lab.runbook', ['id'=>$runbook['id'],'lang'=>$locale]) }}">{{ $t012Ar ? 'عرض التفاصيل' : 'View details' }} &nbsp;&gt;</a>
                        </article>
                    @endforeach
                @endif
            </div>
        </div>
    @endif
</section>
