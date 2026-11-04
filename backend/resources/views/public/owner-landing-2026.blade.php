@php($w26Ar = $ar ?? false)
@php($w26Copy = app(\App\Domain\Content\PageContentRepository::class)->publishedMap('home', $locale))
{{-- Owner-directed replacement of WEB-01's top fold; functional data sections remain in welcome.blade.php.
     Composition is drawn in HTML/CSS so links and accessibility remain real, not a screenshot-as-website.
     Claims/statistics in the supplied design are illustrative and deliberately not published as facts. --}}
<style>
.w26-landing{--ink:#061941;--blue:#087bef;--teal:#00c4a4;--pale:#eaf5ff;isolation:isolate;color:var(--ink);font-family:Inter,Arial,sans-serif;background:#e9f5ff}
.w26-landing *{box-sizing:border-box}
.w26-landing a:focus-visible{outline:3px solid #008cf4;outline-offset:4px}
.w26-desktop{position:relative;container-type:inline-size;aspect-ratio:1672/941;max-width:1920px;margin-inline:auto;overflow:hidden;
 background:radial-gradient(circle at 69% 13%,#f8fbff 0%,#e7f4ff 35%,transparent 63%),linear-gradient(118deg,#bedff1 0%,#fff 38%,#deedff 76%,#c4e7ff 100%)}
.w26-desktop::before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(95deg,rgba(15,62,99,.05),transparent 38%),radial-gradient(ellipse at 18% 84%,#43b9ff 0%,rgba(45,147,219,.2) 22%,transparent 43%)}
.w26-ambient{position:absolute;left:0;top:0;width:30%;height:81%;background:linear-gradient(90deg,rgba(27,108,153,.25),transparent),repeating-linear-gradient(90deg,rgba(58,121,166,.16) 0 1.2%,transparent 1.2% 10%);filter:blur(8px);opacity:.75}
.w26-sweep{position:absolute;pointer-events:none;left:-5%;width:115%;height:18%;bottom:11%;border-radius:50% 50% 0 0;background:linear-gradient(7deg,#2f9ee3,rgba(113,204,255,.5) 48%,rgba(255,255,255,.15) 53%,transparent 70%);transform:rotate(-2deg)}
.w26-sweep::after{content:'';position:absolute;left:0;right:0;bottom:8%;height:2px;background:#fff;box-shadow:0 0 15px #fff}
.w26-portrait{position:absolute;left:-1.4%;top:-1%;width:33.3%;height:84%;margin:0;overflow:hidden;z-index:2;
 -webkit-mask-image:linear-gradient(to bottom,#000 0 79%,transparent 100%);mask-image:linear-gradient(to bottom,#000 0 79%,transparent 100%)}
.w26-portrait img{display:block;width:100%;height:100%;object-fit:cover;object-position:center top}
.w26-brand{position:absolute;left:32.6%;top:1.9%;display:flex;align-items:center;gap:.8cqw;text-decoration:none;z-index:4}
.w26-mark{display:inline-block;font-weight:950;line-height:1;letter-spacing:-.25cqw;font-size:4.75cqw;
 background:linear-gradient(115deg,#0078f4 14%,#0096c2 51%,#00bc97 84%);-webkit-background-clip:text;background-clip:text;color:transparent}
.w26-brand-copy{display:flex;flex-direction:column;white-space:nowrap;line-height:1.12}
.w26-brand-copy strong{font-size:2.02cqw;font-weight:850;letter-spacing:-.07cqw}
.w26-brand-copy small{font-size:1.21cqw;letter-spacing:-.04cqw}
.w26-headline{position:absolute;z-index:3;left:32.5%;top:10.4%;width:36.5%}
.w26-headline h1{white-space:nowrap;letter-spacing:-.29cqw;font-size:5.35cqw;line-height:1.05;font-weight:850;margin:0;color:#071a4c}
.w26-headline h1 span{color:#0780ee}
.w26-headline .w26-tagline{font-size:2.02cqw;line-height:1.1;font-weight:850;margin:.25cqw 0 1.07cqw;max-width:35cqw}
.w26-headline .w26-accent{display:block;width:6cqw;height:.25cqw;background:linear-gradient(90deg,#11d4b3,#00a7a4);border-radius:99px;margin-bottom:.95cqw}
.w26-headline .w26-intro{margin:0;max-width:35cqw;font-size:1.12cqw;line-height:1.38;font-weight:500}
.w26-headline .w26-intro strong{font-weight:650}
.w26-globe{position:absolute;right:-2.5%;top:-5%;width:35%;height:42%;opacity:.66;z-index:1;pointer-events:none}
.w26-globe svg{width:100%;height:100%;display:block;filter:drop-shadow(0 0 8px #a1ddff)}
.w26-signature{position:absolute;right:16.5%;top:25.3%;font-family:"Brush Script MT","Segoe Script",cursive;font-size:2.8cqw;font-style:italic;font-weight:400;letter-spacing:-.1cqw;transform:rotate(-11deg);z-index:2}
.w26-signature::after{content:'';display:block;width:100%;height:.3cqw;border-radius:50%;border-bottom:.14cqw solid currentColor;transform:skewX(-27deg) translateY(-.3cqw)}
.w26-skills{position:absolute;left:32.5%;top:39%;width:39%;z-index:5;display:flex;gap:.72cqw;align-items:stretch}
.w26-skill{display:flex;align-items:center;gap:.52cqw;padding:.45cqw .62cqw;background:rgba(242,249,255,.74);border:.08cqw solid #cee5f9;border-radius:1cqw;box-shadow:0 .18cqw .46cqw #80add429;white-space:nowrap;font-size:.87cqw;font-weight:750;line-height:1.15}
.w26-skill-icon{font-size:1.7cqw;color:#057ee9;line-height:1}
.w26-skill--ai .w26-skill-icon{color:#00bfa1}
.w26-proof{position:absolute;z-index:4;right:1.8%;top:39.6%;font-size:.85cqw;color:#122b56;font-weight:750;letter-spacing:.01cqw;display:flex;gap:1.2cqw}
.w26-proof span{padding:0 .35cqw;border-inline:1px solid #7aa4dc}
.w26-devices{position:absolute;inset:0;z-index:6;pointer-events:none}
.w26-devices a{pointer-events:auto}
.w26-laptop{position:absolute;left:22.6%;top:47.8%;width:30.8%;height:35%;text-decoration:none;filter:drop-shadow(0 .7cqw .47cqw #0a3b6580)}
.w26-laptop-screen{height:92%;border:.65cqw solid #17283a;border-bottom:.8cqw solid #17283a;border-radius:1.15cqw 1.15cqw .25cqw .25cqw;background:radial-gradient(circle at 79% 70%,#054aa0,#011025 59%);overflow:hidden;color:#fff}
.w26-laptop-nav{height:15%;background:#062643;display:flex;justify-content:space-between;align-items:center;padding:0 3%;font-size:.47cqw}
.w26-laptop-nav strong{font-size:.7cqw;color:#48d7e3}
.w26-laptop-nav span{opacity:.88}
.w26-laptop-body{display:flex;padding:5.5% 5.5% 0 5%;height:85%;gap:2%}
.w26-laptop-copy{width:53%;align-self:center}
.w26-laptop-copy strong{display:block;font-size:1.32cqw;line-height:1.22}
.w26-laptop-copy p{font-size:.7cqw;line-height:1.4;color:#d9eaff;max-width:23ch}
.w26-device-button{display:inline-block;margin-top:.15cqw;border-radius:99px;padding:.5cqw .65cqw;background:linear-gradient(90deg,#08dca6,#0ab0a6);color:#071b31;font-weight:850;font-size:.62cqw}
.w26-chip-illustration{align-self:center;width:44%;height:63%;position:relative;transform:rotate(-12deg)}
.w26-chip-illustration i{display:block;position:absolute;inset:18%;border:.35cqw solid #17afff;border-radius:1cqw;background:linear-gradient(135deg,#224bee,#03e8e3);box-shadow:0 0 1.2cqw #038fff,0 1cqw 0 #052d6d,0 1.4cqw 0 #08adcf;transform:skewY(-7deg)}
.w26-chip-illustration b{position:absolute;inset:30%;z-index:1;display:grid;place-items:center;color:white;font-size:2.6cqw;text-shadow:0 0 .5cqw white}
.w26-laptop-base{position:absolute;left:-5%;bottom:0;width:110%;height:8%;background:linear-gradient(#e5f4ff,#6c94ae 56%,#f0f8fc 88%);clip-path:polygon(7% 0,93% 0,100% 78%,94% 100%,6% 100%,0 78%);border-radius:50%;box-shadow:0 .3cqw .4cqw #1b3f60}
.w26-phone{position:absolute;left:53.3%;top:46%;width:9.5%;height:36.4%;background:#03101c;border:.55cqw solid #0b1925;border-radius:1.8cqw;box-shadow:0 .6cqw .8cqw #17527b70;color:#fff;text-decoration:none;text-align:center;padding:.7cqw .33cqw .6cqw}
.w26-phone::before{content:'';position:absolute;top:.25cqw;left:33%;width:34%;height:.35cqw;border-radius:99px;background:#04080c}
.w26-phone-logo{display:block;font-size:2.6cqw;font-weight:950;color:#02aed6;line-height:1;margin:1cqw 0 .3cqw}
.w26-phone strong{display:block;font-size:.93cqw;line-height:1.1}
.w26-phone small{display:block;font-size:.51cqw;opacity:.8;margin:.65cqw 0}
.w26-phone-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.25cqw;margin-top:.55cqw}
.w26-phone-grid span{background:#174baa;border-radius:.5cqw;padding:.6cqw .12cqw;font-size:.55cqw;font-weight:750}
.w26-phone-grid span:nth-child(2){background:#078bbf}.w26-phone-grid span:nth-child(3){background:#097661}.w26-phone-grid span:nth-child(4){background:#938006}
.w26-tablet{position:absolute;left:64.7%;top:50.4%;width:22%;height:29.5%;border:.62cqw solid #14283d;border-radius:1.15cqw;background:#071d37;color:#fff;overflow:hidden;box-shadow:0 .7cqw .6cqw #15527b67;text-decoration:none}
.w26-tablet-top{height:13%;display:flex;align-items:center;justify-content:space-between;padding:0 3%;font-size:.72cqw;font-weight:800}
.w26-tablet-body{height:87%;display:grid;grid-template-columns:25% 75%}
.w26-tablet-sidebar{background:#092f58;padding:.7cqw .43cqw;display:grid;align-content:start;gap:.53cqw;font-size:.5cqw}
.w26-tablet-sidebar span{opacity:.85}
.w26-tablet-data{background:#f7fbff;border-radius:.75cqw .75cqw 0 0;margin:.1cqw;color:#12345c;padding:.65cqw}
.w26-tablet-data strong{font-size:.75cqw}
.w26-tablet-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:.32cqw;margin:.55cqw 0}
.w26-tablet-cards span{min-height:3.5cqw;background:#fff;border:1px solid #cbe2f4;border-radius:.42cqw;padding:.35cqw;text-align:center;font-size:.55cqw;font-weight:750}
.w26-tablet-cards b{font-size:1.12cqw;color:#0672d3}
.w26-graph{width:100%;height:36%;border-bottom:1px solid #aacde8;background:linear-gradient(180deg,#dceeff88,transparent)}
.w26-github{position:absolute;left:87%;top:51%;width:11.6%;height:25%;background:#fff;border-radius:1cqw;box-shadow:0 .4cqw .9cqw #4a8bc12d;z-index:6;color:#11274a;overflow:hidden}
.w26-github h2{font-size:.93cqw;line-height:1.2;display:flex;align-items:center;gap:.5cqw;margin:0;padding:.9cqw .55cqw;border-bottom:1px solid #d5e6f6;white-space:nowrap}
.w26-github h2 span{font-size:1.5cqw}
.w26-github ul{list-style:none;padding:.55cqw .9cqw;margin:0;display:grid;gap:.54cqw}
.w26-github li{font-size:.72cqw;font-weight:650;display:flex;justify-content:space-between;gap:.2cqw;align-items:center}
.w26-github li::after{content:'✓';background:#08c69e;color:white;border-radius:50%;font-size:.67cqw;line-height:1;display:grid;place-items:center;width:.9cqw;height:.9cqw;flex-shrink:0}
.w26-github a{color:#0b56a6;text-decoration:none}
.w26-ribbon{position:absolute;left:1.6%;right:1.6%;bottom:3.1%;height:12.6%;z-index:7;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));align-items:stretch;background:linear-gradient(90deg,#105b78,#0e3563 43%,#0b2e54);border:.11cqw solid #e1f6ff;border-radius:1.6cqw;box-shadow:0 -.7cqw 1.6cqw #2e93d591,0 .8cqw 1.4cqw #35598b77;overflow:hidden}
.w26-ribbon a{color:#fff;text-decoration:none;display:flex;gap:.6cqw;align-items:center;padding:.75cqw .9cqw;min-width:0;position:relative}
.w26-ribbon a:not(:last-child)::after{content:'';height:63%;width:1px;position:absolute;right:0;top:19%;background:#52b3e4}
.w26-ribbon a:hover,.w26-ribbon a:focus-visible{background:#167c9d}
.w26-ribbon-icon{color:#75f7eb;font-size:2.85cqw;min-width:2.8cqw;text-align:center;line-height:1.1}
.w26-ribbon strong{font-size:1.05cqw;line-height:1.25;display:block;white-space:nowrap}
.w26-ribbon small{font-size:.68cqw;line-height:1.5;display:block;color:#f1f9ff;margin-top:.4cqw}
.w26-languages{position:absolute;left:56%;top:2.65%;z-index:8;font-size:.92cqw}
.w26-locale-form{margin:0;display:inline-flex;align-items:center}
.w26-locale-group{border:.08cqw solid #bed9e9;border-radius:99px;padding:.15cqw;display:inline-flex;gap:.12cqw;background:rgba(255,255,255,.91);box-shadow:0 .17cqw .55cqw #0b35651c}
.w26-locale-group legend{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.w26-locale-choice{display:inline-flex;position:relative;cursor:pointer;align-items:center}
.w26-locale-choice input{position:absolute;width:1px;height:1px;opacity:0;inset:50% auto auto 50%}
.w26-locale-choice span{display:inline-flex;align-items:center;gap:.35em;padding:.39em .68em;border-radius:99px;white-space:nowrap;font-weight:850;line-height:1.1;color:#21557e;min-height:2.25em}
.w26-locale-choice span::before{content:'';width:.86em;height:.86em;border:1.7px solid #3385ad;background:white;border-radius:50%;box-sizing:border-box;flex-shrink:0}
.w26-locale-choice input:checked + span{background:#096bc3;color:white}
.w26-locale-choice input:checked + span::before{border:2px solid white;box-shadow:inset 0 0 0 2px #096bc3;background:white}
.w26-locale-choice input:focus-visible + span{outline:3px solid #005f99;outline-offset:2px}
.w26-locale-choice:hover span{box-shadow:inset 0 0 0 1px #0c8bbd}
.w26-locale-apply{border:1px solid #bed9e9;border-radius:99px;background:white;color:#075c9a;cursor:pointer}
.w26-mobile-languages .w26-locale-group{font-size:12px;border-width:1px;padding:2px;gap:2px}
.w26-mobile-languages .w26-locale-choice span{padding:6px 8px;min-height:32px}
.w26-landing[dir=rtl] .w26-headline{direction:rtl;text-align:right}
.w26-landing[dir=rtl] .w26-intro{line-height:1.7}
.w26-landing[dir=rtl] .w26-ribbon{direction:rtl}
.w26-landing[dir=rtl] .w26-brand-copy{text-align:start}
.w26-mobile{display:none}
@media(max-width:900px){
 .w26-desktop{display:none}
 .w26-mobile{display:block;background:radial-gradient(circle at 90% 10%,#cfecff,#fafdff 55%,#e5f6ff);padding:24px 20px 30px;overflow:hidden}
 .w26-mobile-header{display:flex;justify-content:space-between;gap:14px;align-items:center}
 .w26-mobile-logo{font-size:42px;font-weight:900;line-height:1;background:linear-gradient(115deg,#0074f2,#00b6ac);background-clip:text;-webkit-background-clip:text;color:transparent}
 .w26-mobile-header strong{font-size:17px;white-space:nowrap}.w26-mobile-header a{font-size:14px;font-weight:750;color:#0759b0}
 .w26-mobile-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.9fr);gap:8px;align-items:center;padding-top:24px}
 .w26-mobile-hero h1{font-size:clamp(35px,7vw,64px);line-height:1.05;letter-spacing:-.05em;margin:0 0 12px}
 .w26-mobile-hero h1 span{color:#077dec}
 .w26-mobile-hero p{font-size:clamp(15px,2.5vw,19px);font-weight:700;line-height:1.5}
 .w26-mobile-photo{margin:0;align-self:stretch;max-height:365px;overflow:hidden;border-radius:24px;background:linear-gradient(140deg,#daf2ff,#fff)}
 .w26-mobile-photo img{width:100%;height:100%;object-fit:cover;object-position:center 15%}
 .w26-mobile-actions{display:flex;flex-wrap:wrap;gap:9px;margin:18px 0}
 .w26-mobile-actions a{background:#0a7de5;color:white;text-decoration:none;font-weight:800;padding:10px 14px;border-radius:999px;font-size:14px}
 .w26-mobile-actions a:nth-child(even){background:#e1f4fb;color:#075a91;border:1px solid #a2d5ea}
 .w26-mobile-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:20px}
 .w26-mobile-nav a{background:#103659;color:#fff;text-decoration:none;padding:16px;border-radius:16px;font-size:15px;font-weight:800;min-height:68px;display:flex;align-items:center}
 .w26-mobile-note{color:#335775;font-size:13px;margin-top:18px}
}
@media(max-width:480px){.w26-mobile-hero{grid-template-columns:1fr}.w26-mobile-photo{max-height:280px}.w26-mobile-hero h1{font-size:42px}.w26-mobile-header{gap:6px}.w26-mobile-header strong{font-size:14px}.w26-mobile-languages .w26-locale-group{font-size:11px}.w26-mobile-languages .w26-locale-choice span{padding:5px 6px}}
@media(prefers-reduced-motion:reduce){.w26-landing *{scroll-behavior:auto}}
</style>
<div class="w26-landing" dir="{{ $w26Ar ? 'rtl' : 'ltr' }}">
    <section class="w26-desktop" aria-labelledby="w26-title">
        <div class="w26-ambient" aria-hidden="true"></div><div class="w26-sweep" aria-hidden="true"></div>
        <figure class="w26-portrait">
            <img src="{{ route('public.owner-portrait') }}" alt="{{ $w26Ar ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية' : 'Approved real portrait of Waleed Atiya' }}" fetchpriority="high" decoding="async" width="640" height="760">
        </figure>
        <a class="w26-brand" href="{{ route('home', ['lang' => $locale]) }}" aria-current="page" aria-label="{{ $w26Ar ? 'الصفحة الرئيسية — وليد عطية' : 'Home — Waleed Atiya' }}">
            <span class="w26-mark" aria-hidden="true">W</span>
            <span class="w26-brand-copy"><strong>{{ $w26Ar ? 'وليد عطية' : 'Waleed Atiya' }}</strong><small>Personal Technology Platform</small></span>
        </a>
        <div class="w26-headline">
            <h1 id="w26-title"><span lang="en">Waleed <span>Atiya</span></span></h1>
            <p class="w26-tagline">{{ $w26Copy['hero']['tagline'] ?? ($w26Ar ? 'قيادة تقنية المؤسسات وهندسة حلول الذكاء الاصطناعي' : 'Enterprise Technology & AI Solutions Architecture') }}</p>
            <span class="w26-accent" aria-hidden="true"></span>
            <p class="w26-intro">{{ $w26Copy['hero']['intro'] ?? ($w26Ar ? 'قواعد البيانات، الأنظمة المؤسسية، هندسة البرمجيات، وتسليم المشاريع وتطوير حلول الذكاء الاصطناعي.' : 'Database & infrastructure, enterprise systems, software engineering, project delivery, and AI-driven development.') }}</p>
        </div>
        <div class="w26-globe" aria-hidden="true">
            <svg viewBox="0 0 590 360" role="presentation" xmlns="http://www.w3.org/2000/svg">
                <defs><pattern id="w26-dots" width="8" height="8" patternUnits="userSpaceOnUse"><circle cx="2.6" cy="2.6" r="2" fill="#1995f1"/></pattern><clipPath id="w26-sphere"><ellipse cx="338" cy="172" rx="256" ry="160"/></clipPath></defs>
                <ellipse cx="338" cy="172" rx="256" ry="160" fill="#edfaff" stroke="#b5e4ff" stroke-width="3"/>
                <g clip-path="url(#w26-sphere)" fill="url(#w26-dots)">
                    <path d="M126 64l25-30 48 1 35 15 20 25-22 13-9 43-33 13-2 44-26 23-19-10-20-34-38-25-1-48z"/>
                    <path d="M209 182l41-22 29 19 22 53-15 49-28 50-16-31-18-33-24-26z"/>
                    <path d="M289 67l32-22 45 4 20 26 41 8 20-15 61 18 39 34-2 49-43 11-38-8-18 20-25-3-24-24-34-3-14-39-32-17-31 4z"/>
                    <path d="M363 157l60 0 29 27 10 41-20 52-25 30-33-30-19-47-28-20z"/>
                    <path d="M488 246l46-6 24 27 7 39-44 5-35-24z"/>
                </g><ellipse cx="338" cy="172" rx="256" ry="160" fill="none" stroke="#def5ff" stroke-width="9"/>
            </svg>
        </div>
        <div class="w26-signature" aria-hidden="true">Waleed Atiya</div>
        <div class="w26-skills" aria-label="{{ $w26Ar ? 'المجالات التقنية' : 'Technology domains' }}">
            <span class="w26-skill"><span class="w26-skill-icon" aria-hidden="true">▣</span>{{ $w26Copy['skills']['project'] ?? ($w26Ar ? 'إدارة المشاريع' : 'Project Delivery') }}</span>
            <span class="w26-skill"><span class="w26-skill-icon" aria-hidden="true">▤</span>{{ $w26Copy['skills']['database'] ?? ($w26Ar ? 'قواعد البيانات' : 'SQL Server / DBA') }}</span>
            <span class="w26-skill"><span class="w26-skill-icon" aria-hidden="true">⌘</span>{{ $w26Copy['skills']['engineering'] ?? ($w26Ar ? 'هندسة البرمجيات' : 'Software Engineering') }}</span>
            <span class="w26-skill w26-skill--ai"><span class="w26-skill-icon" aria-hidden="true">▧</span>{{ $w26Copy['skills']['ai'] ?? ($w26Ar ? 'هندسة الذكاء الاصطناعي' : 'AI Engineering') }}</span>
        </div>
        <div class="w26-proof" aria-label="{{ $w26Ar ? 'مراجع العمل' : 'Public work and learning tracks' }}">
            <span>{{ $w26Ar ? 'مشاريع موثقة' : 'Verified projects' }}</span><span>{{ $w26Ar ? 'أدلة تقنية' : 'Technical guides' }}</span>
        </div>
        <div class="w26-languages" data-testid="home-header-locale">
            <form class="w26-locale-form" method="get" action="{{ route('home') }}" onchange="this.requestSubmit()">
                <fieldset class="w26-locale-group" aria-label="{{ $w26Ar ? 'لغة الموقع' : 'Site language' }}">
                    <legend>{{ $w26Ar ? 'لغة الموقع' : 'Site language' }}</legend>
                    <label class="w26-locale-choice" for="w26-desktop-en"><input type="radio" name="lang" id="w26-desktop-en" value="en" @checked(!$w26Ar)><span lang="en">EN</span></label>
                    <label class="w26-locale-choice" for="w26-desktop-ar"><input type="radio" name="lang" id="w26-desktop-ar" value="ar" @checked($w26Ar)><span lang="ar">عربي</span></label>
                </fieldset>
                <noscript><button class="w26-locale-apply" type="submit">{{ $w26Ar ? 'تغيير اللغة' : 'Apply' }}</button></noscript>
            </form>
        </div>
        <div class="w26-devices">
            <a class="w26-laptop" href="{{ route('public.projects', ['lang' => $locale]) }}" aria-label="{{ $w26Ar ? 'استكشف المشاريع ودراسات الحالة' : 'Explore projects and case studies' }}">
                <div class="w26-laptop-screen"><div class="w26-laptop-nav"><strong>W Waleed Atiya</strong><span>Home　 About　 Expertise　 Projects　 AI Lab</span></div>
                    <div class="w26-laptop-body"><div class="w26-laptop-copy"><strong>{{ $w26Copy['laptop']['heading'] ?? ($w26Ar ? 'هندسة التقنية وحلول الذكاء الاصطناعي' : 'Enterprise Technology & AI Solutions') }}</strong><p>{{ $w26Copy['laptop']['description'] ?? ($w26Ar ? 'المشاريع ودراسات الحالة المتحقق منها' : 'Verified engineering projects and case studies') }}</p><span class="w26-device-button">{{ $w26Ar ? 'استكشف المشاريع' : 'Explore projects' }} →</span></div><div class="w26-chip-illustration" aria-hidden="true"><i></i><b>AI</b></div></div>
                </div><div class="w26-laptop-base" aria-hidden="true"></div>
            </a>
            <a class="w26-phone" href="{{ route('public.lab', ['lang' => $locale]) }}" aria-label="{{ $w26Ar ? 'افتح المختبر التقني' : 'Open technical knowledge hub' }}">
                <span class="w26-phone-logo">W</span><strong>Waleed Tech<br>Companion</strong><small>AI-Powered<br>Developer & IT Assistant</small>
                <div class="w26-phone-grid"><span>AI Assistant</span><span>Solutions</span><span>Runbooks</span><span>Learning</span></div>
            </a>
            <a class="w26-tablet" href="{{ route('public.about', ['lang' => $locale]) }}" aria-label="{{ $w26Ar ? 'اعرف المزيد عن المنصة' : 'Explore the platform and its published work' }}">
                <div class="w26-tablet-top"><span>W</span><span>{{ $w26Ar ? 'مركز التحكم' : 'Admin Control Center' }}</span><span>◉</span></div>
                <div class="w26-tablet-body"><div class="w26-tablet-sidebar"><strong>Dashboard</strong><span>AI Providers</span><span>Content Engine</span><span>Projects</span><span>GitHub Sync</span><span>Settings</span></div>
                    <div class="w26-tablet-data"><strong>{{ $w26Ar ? 'نظرة عامة' : 'Platform overview' }}</strong><div class="w26-tablet-cards"><span>Content<br><b>—</b></span><span>Projects<br><b>—</b></span><span>AI Tasks<br><b>—</b></span></div>
                        <svg class="w26-graph" viewBox="0 0 210 70" preserveAspectRatio="none" aria-hidden="true"><polyline points="0,54 27,49 55,33 76,43 104,29 133,37 160,20 183,25 210,8" fill="none" stroke="#007cff" stroke-width="2.8"/><polyline points="0,65 35,56 55,59 80,48 118,52 150,37 175,41 210,25" fill="none" stroke="#17c587" stroke-width="2.5"/></svg>
                    </div></div>
            </a>
        </div>
        <div class="w26-github"><h2><span aria-hidden="true">◉</span>{{ $w26Ar ? 'ربط GitHub' : 'GitHub Integration' }}</h2>
            <ul><li><a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $w26Ar ? 'المستودعات' : 'Repositories' }}</a></li><li><a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $w26Ar ? 'المشاريع' : 'Projects' }}</a></li><li>{{ $w26Ar ? 'المساهمات' : 'Commits & releases' }}</li><li>{{ $w26Ar ? 'التطوير والتكامل' : 'CI/CD' }}</li><li>{{ $w26Ar ? 'النشاط العام' : 'Activity' }}</li></ul>
        </div>
        <nav class="w26-ribbon" aria-label="{{ $w26Ar ? 'أقسام المنصة' : 'Platform sections' }}">
            <a href="{{ route('public.expertise', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">▤</span><span><strong>{{ $w26Copy['ribbon']['database_title'] ?? 'Database & Infra' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['database_description'] ?? "SQL Server, HA/DR,\nSecurity & Compliance")) !!}</small></span></a>
            <a href="{{ route('public.projects', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">⌘</span><span><strong>{{ $w26Copy['ribbon']['software_title'] ?? 'Software Engineering' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['software_description'] ?? "Laravel, Flutter,\nAPIs & Enterprise Systems")) !!}</small></span></a>
            <a href="{{ route('public.hire', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">◇</span><span><strong>{{ $w26Copy['ribbon']['delivery_title'] ?? 'Project Delivery' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['delivery_description'] ?? "Governance, review,\nUAT & Rollout")) !!}</small></span></a>
            <a href="{{ route('public.ai-engineering', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">♧</span><span><strong>{{ $w26Copy['ribbon']['ai_title'] ?? 'AI Engineering' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['ai_description'] ?? "AI Tools, Automation,\nContent & Code")) !!}</small></span></a>
            <a href="{{ route('public.github', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">◉</span><span><strong>{{ $w26Copy['ribbon']['github_title'] ?? 'GitHub & DevOps' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['github_description'] ?? "Repositories, CI/CD,\nVersioning")) !!}</small></span></a>
            <a href="{{ route('public.lab', ['lang' => $locale]) }}"><span class="w26-ribbon-icon" aria-hidden="true">▱</span><span><strong>{{ $w26Copy['ribbon']['knowledge_title'] ?? 'Knowledge Hub' }}</strong><small>{!! nl2br(e($w26Copy['ribbon']['knowledge_description'] ?? "Technical Blog, Solutions,\nRunbooks & Learning")) !!}</small></span></a>
        </nav>
    </section>
    <section class="w26-mobile" aria-labelledby="w26-mobile-title">
        <div class="w26-mobile-header"><div style="display:flex;align-items:center;gap:10px"><span class="w26-mobile-logo" aria-hidden="true">W</span><strong>{{ $w26Ar ? 'وليد عطية' : 'Waleed Atiya' }}</strong></div>
            <div class="w26-mobile-languages" data-testid="home-mobile-header-locale">
                <form class="w26-locale-form" method="get" action="{{ route('home') }}" onchange="this.requestSubmit()">
                    <fieldset class="w26-locale-group" aria-label="{{ $w26Ar ? 'لغة الموقع' : 'Site language' }}">
                        <legend>{{ $w26Ar ? 'لغة الموقع' : 'Site language' }}</legend>
                        <label class="w26-locale-choice" for="w26-mobile-en"><input type="radio" name="lang" id="w26-mobile-en" value="en" @checked(!$w26Ar)><span lang="en">EN</span></label>
                        <label class="w26-locale-choice" for="w26-mobile-ar"><input type="radio" name="lang" id="w26-mobile-ar" value="ar" @checked($w26Ar)><span lang="ar">عربي</span></label>
                    </fieldset>
                    <noscript><button class="w26-locale-apply" type="submit">{{ $w26Ar ? 'تغيير اللغة' : 'Apply' }}</button></noscript>
                </form>
            </div>
        </div>
        <div class="w26-mobile-hero"><div><h1 id="w26-mobile-title">Waleed <span>Atiya</span></h1><p>{{ $w26Copy['mobile']['description'] ?? ($w26Ar ? 'هندسة التقنية المؤسسية وحلول الذكاء الاصطناعي' : 'Enterprise Technology & AI Solutions Architecture') }}</p></div>
            <figure class="w26-mobile-photo"><img src="{{ route('public.owner-portrait') }}" alt="{{ $w26Ar ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية' : 'Approved real portrait of Waleed Atiya' }}" loading="lazy" width="640" height="760"></figure></div>
        <div class="w26-mobile-actions"><a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $w26Ar ? 'استكشف المشاريع' : 'Explore projects' }}</a><a href="{{ route('public.ai-engineering', ['lang' => $locale]) }}">{{ $w26Ar ? 'هندسة الذكاء الاصطناعي' : 'AI Engineering' }}</a></div>
        <nav class="w26-mobile-nav" aria-label="{{ $w26Ar ? 'أقسام المنصة' : 'Platform sections' }}"><a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $w26Ar ? 'نبذة' : 'About' }}</a><a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $w26Ar ? 'الخبرات' : 'Expertise' }}</a><a href="{{ route('public.github', ['lang' => $locale]) }}">GitHub & DevOps</a><a href="{{ route('public.lab', ['lang' => $locale]) }}">{{ $w26Ar ? 'المختبر التقني' : 'Tech lab' }}</a><a href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $w26Ar ? 'المدونة التقنية' : 'Technical blog' }}</a><a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $w26Ar ? 'تواصل' : 'Contact' }}</a><a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $w26Ar ? 'السيرة العامة' : 'Public resume' }}</a><a href="{{ route('public.search', ['lang' => $locale]) }}">{{ $w26Ar ? 'البحث' : 'Search' }}</a></nav>
        <p class="w26-mobile-note">{{ $w26Ar ? 'يُعرض المحتوى المهني المعتمد للنشر فقط.' : 'Only published, evidence-reviewed professional information is shown.' }}</p>
    </section>
</div>
