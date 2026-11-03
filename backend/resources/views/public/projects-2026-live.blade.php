{{-- Canonical WEB-04 real page, entirely driven by published and evidence-safe
     database results. No client-side hard-coded project list. --}}
@php
    $navGroups = [
        'featured' => ['en' => 'Featured', 'ar' => 'الكل والمميز'],
        'government' => ['en' => 'Government', 'ar' => 'الحكومية'],
        'education' => ['en' => 'Education', 'ar' => 'التعليمية'],
        'workflows' => ['en' => 'Workflows', 'ar' => 'الإجراءات'],
        'ai' => ['en' => 'AI & Products', 'ar' => 'الذكاء والمنتجات'],
        'enterprise' => ['en' => 'Enterprise', 'ar' => 'الأعمال'],
    ];
    $isArabic = $locale === 'ar';
    $filters = ['lang' => $locale];
    if ($search !== '') $filters['q'] = $search;
    if ($status !== '') $filters['status'] = $status;
    $portfolioIndex = array_search($portfolioCategory, array_keys($navGroups), true) + 1;
@endphp
<style>
body:has(.portfolio-live) {background:#edf7fb;overflow-x:hidden}
body:has(.portfolio-live) .site-header, body:has(.portfolio-live) .web04-projects,
body:has(.portfolio-live) #web04-project-catalog {display:none!important}
.portfolio-live{--p-navy:#17436d;--p-dark:#0f3157;--p-accent:#009c8c;--p-border:#d2e5ed;
  font-family:Inter,Arial,sans-serif; color:var(--p-dark);overflow:hidden}
:lang(ar) .portfolio-live{font-family:Tajawal,Arial,sans-serif}
.portfolio-live *{box-sizing:border-box}
.portfolio-live a{color:inherit;text-decoration:none}
.portfolio-live a:focus-visible,.portfolio-live button:focus-visible,.portfolio-live input:focus-visible{outline:3px solid #126de1;outline-offset:3px}
.portfolio-live .pl-container{max-width:1490px;margin:auto;padding-inline:clamp(18px,4vw,70px)}
.pl-nav{background:#163f68;color:#fff}
.pl-nav .pl-container{min-height:78px;display:flex;align-items:center;gap:clamp(14px,3vw,46px)}
.pl-brand{display:inline-flex;align-items:center;white-space:nowrap;gap:8px;font-size:24px;font-weight:800}
.pl-brand span{font-size:34px;font-weight:900;color:#05b2a2}
.pl-links{display:flex;align-items:center;gap:clamp(9px,2vw,30px);flex:1;white-space:nowrap;overflow-x:auto;scrollbar-width:thin}
.pl-links a{font-size:15px;min-height:45px;display:inline-flex;align-items:center;opacity:.95}
.pl-links a[aria-current]{color:#17d4bf;border-bottom:3px solid #17d4bf;font-weight:800}
.pl-contact{border-radius:11px;padding:12px 18px;background:#08bba9;color:#063a4f!important;font-weight:800;white-space:nowrap}
.pl-hero{position:relative;background:radial-gradient(circle at 82% 42%,#d4f6f1 0,#eaf7fc 45%,#f0f9fd 90%);padding:clamp(32px,5vw,65px) 0 50px}
.pl-hero-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(200px,.85fr);align-items:center;gap:35px;min-height:265px}
.pl-eyebrow{display:inline-flex;background:#d4f5ed;color:#007d72;border-radius:30px;padding:8px 18px;font-weight:900;font-size:14px;letter-spacing:.07em}
.pl-hero h1{font-size:clamp(38px,5vw,62px);line-height:1.1;letter-spacing:-.035em;margin:18px 0 14px;color:#15436f}
.pl-hero p{max-width:65ch;font-size:clamp(16px,1.5vw,20px);line-height:1.7;color:#355b7a;margin:0}
.pl-hero-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
.pl-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:11px 19px;border:2px solid var(--p-accent);border-radius:12px;font-weight:800}
.pl-btn.primary{background:var(--p-accent);color:#fff}
.pl-btn.secondary{background:#fff;color:var(--p-navy)}
.pl-art{position:relative;display:flex;align-items:center;justify-content:center;min-height:230px}
.pl-art::before{content:'';position:absolute;border:2px solid #29caba;border-radius:50%;width:90%;height:80%;transform:rotate(-18deg)}
.pl-art::after{content:'';position:absolute;border:2px solid #9adde1;border-radius:50%;width:80%;height:90%;transform:rotate(25deg)}
.pl-laptop{z-index:1;background:linear-gradient(135deg,#073d57,#0b7174);border:9px solid #1f527a;box-shadow:0 14px 0 -4px #88bfce,0 22px 35px #12496840;
color:#51f0cf;border-radius:15px;width:min(75%,300px);height:170px;display:flex;align-items:center;justify-content:center;font-size:66px;font-weight:900;transform:rotate(-5deg)}
.pl-icon{z-index:2;position:absolute;background:linear-gradient(140deg,#086084,#09b9aa);border:2px solid #8ceadb;border-radius:15px;color:white;font-size:25px;font-weight:900;display:grid;place-items:center;width:65px;height:65px;box-shadow:0 13px 24px #2259783b}
.pl-icon.one{inset-block-start:5%;inset-inline-start:12%}.pl-icon.two{inset-block-start:4%;inset-inline-end:10%}
.pl-icon.three{inset-block-end:4%;inset-inline-start:10%}.pl-icon.four{inset-block-end:3%;inset-inline-end:9%}
.pl-controls{padding-block:24px 14px}
.pl-tab-nav{display:flex;align-items:center;gap:9px;overflow-x:auto;white-space:nowrap;padding:7px 1px 15px;scrollbar-width:thin}
.pl-tab-nav a{border:1px solid #bedbe6;border-radius:22px;padding:9px 16px;font-size:15px;min-height:43px;display:inline-flex;align-items:center;background:#fff;color:#164368;font-weight:700}
.pl-tab-nav a[aria-current]{background:linear-gradient(120deg,#057c91,#03b69a);border-color:transparent;color:#fff}
.pl-count{margin-inline-start:auto;border:1px solid var(--p-border);border-radius:25px;padding:8px 12px;background:#fff;font-weight:800;color:#305475}
.pl-search{display:flex;align-items:end;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #d1e5ed;border-radius:16px;padding:14px 17px;margin-bottom:20px}
.pl-search label{display:block;font-size:13px;font-weight:800;margin-bottom:5px}
.pl-search .pl-field{flex:1 1 230px}
.pl-search input,.pl-search select{min-height:44px;width:100%;border:1px solid #a4c7d6;border-radius:9px;padding:8px 12px;background:#fff;font:inherit}
.pl-search button{min-height:44px;border:0;border-radius:9px;background:#009c8c;padding:8px 22px;color:white;font:inherit;font-weight:800;cursor:pointer}
.pl-section-head{display:flex;justify-content:space-between;align-items:end;flex-wrap:wrap;gap:12px;margin:20px 0}
.pl-section-head h2{font-size:clamp(23px,2.8vw,34px);color:#123d67;margin:0 0 4px}
.pl-section-head p{margin:0;color:#58738b}
.pl-real-count{color:#28657e;font-weight:800}
.pl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}
.pl-card{display:flex;flex-direction:column;border:1px solid var(--p-border);background:#fff;overflow:hidden;border-radius:17px;box-shadow:0 5px 14px #2148630c;min-width:0}
.pl-cover{height:122px;display:flex;justify-content:space-between;align-items:center;padding:22px 25px;color:#fff;background:linear-gradient(120deg,#0f4b70,#0d9292);position:relative;isolation:isolate}
.pl-cover::after{content:'';position:absolute;inset:20px 10px auto auto;width:120px;height:120px;border:2px solid #ffffff42;border-radius:30%;transform:rotate(28deg);z-index:-1}
.pl-cover .pl-symbol{font-size:45px;font-weight:900;letter-spacing:-.08em}
.pl-cover .pl-type{font-size:12px;font-weight:800;align-self:start;border:1px solid #fff9;padding:5px 9px;border-radius:30px;background:#ffffff22}
.pl-cover.category-ai{background:linear-gradient(110deg,#174b7b,#215eab)}
.pl-cover.category-government{background:linear-gradient(110deg,#13506a,#0f7b85)}
.pl-cover.category-workflows{background:linear-gradient(110deg,#286381,#007d83)}
.pl-cover.category-education{background:linear-gradient(110deg,#175b77,#318f8e)}
.pl-cover.category-enterprise{background:linear-gradient(110deg,#125078,#177d99)}
.pl-card-content{padding:20px 22px 23px;display:flex;flex:1;flex-direction:column;gap:10px}
.pl-status{align-self:flex-start;border-radius:30px;background:#e0f5ef;color:#047765;font-size:12px;padding:4px 12px;font-weight:800}
.pl-card h3{font-size:22px;line-height:1.3;color:#12385e;margin:0;overflow-wrap:anywhere}
.pl-card p{font-size:15px;line-height:1.6;color:#405d76;margin:0}
.pl-meta{margin-top:auto;padding-top:12px;border-top:1px solid #e2edf1;font-size:13px;color:#4e6a7d}
.pl-more{display:inline-flex;align-items:center;color:#008979!important;font-weight:900;min-height:44px;margin-top:5px}
.pl-empty{padding:30px;background:#fff;border:1px solid var(--p-border);border-radius:16px}
.pl-empty a{color:#007d72;font-weight:800;text-decoration:underline}
.pl-pagination{display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap;padding:30px 0}
.pl-pagination a,.pl-pagination span{padding:10px 16px;border:1px solid #c8dee8;background:#fff;border-radius:10px;min-height:44px}
.pl-pagination a{font-weight:800;color:#007e77}
.pl-footer{margin-top:26px;background:#e0f6f2;border-radius:19px;padding:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}
.pl-footer strong{font-size:21px}.pl-footer p{margin:5px 0 0;color:#3c657c}
@media(max-width:1060px){.pl-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pl-nav .pl-container{flex-wrap:wrap;padding-block:14px}.pl-links{order:3;flex:1 1 100%}.pl-contact{margin-inline-start:auto}}
@media(max-width:720px){.pl-hero-grid{grid-template-columns:1fr}.pl-art{display:none}.pl-grid{grid-template-columns:1fr}.pl-hero{padding-block:30px}.pl-search{display:grid;grid-template-columns:1fr}.pl-count{margin-inline-start:0}}
@media(prefers-reduced-motion:reduce){.portfolio-live *{scroll-behavior:auto!important;transition:none!important}}
</style>
<div class="portfolio-live" lang="{{ $locale }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
    <header class="pl-nav">
      <div class="pl-container">
        <a class="pl-brand" href="{{ route('home', ['lang'=>$locale]) }}"><span>WA</span>{{ $isArabic ? 'وليد عطية' : 'Waleed Atiya' }}</a>
        <nav class="pl-links" aria-label="{{ $isArabic ? 'التنقل الرئيسي' : 'Main navigation' }}">
          <a href="{{ route('home', ['lang'=>$locale]) }}">{{ $isArabic ? 'الرئيسية' : 'Home' }}</a>
          <a href="{{ route('public.about', ['lang'=>$locale]) }}">{{ $isArabic ? 'نبذة' : 'About' }}</a>
          <a href="{{ route('public.expertise', ['lang'=>$locale]) }}">{{ $isArabic ? 'الخبرات' : 'Expertise' }}</a>
          <a href="{{ route('public.projects', ['lang'=>$locale]) }}" aria-current="page">{{ $isArabic ? 'المشاريع' : 'Projects' }}</a>
          <a href="{{ route('public.ai-engineering', ['lang'=>$locale]) }}">{{ $isArabic ? 'مختبر AI' : 'AI Lab' }}</a>
          <a href="{{ route('public.blog', ['lang'=>$locale]) }}">{{ $isArabic ? 'المدونة' : 'Blog' }}</a>
          <a href="{{ route('public.github', ['lang'=>$locale]) }}">GitHub</a>
          <a href="{{ route('public.resume', ['lang'=>$locale]) }}">{{ $isArabic ? 'السيرة' : 'Resume' }}</a>
        </nav>
        <a class="pl-contact" href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $isArabic ? 'تواصل معي' : 'Get in touch' }}</a>
      </div>
    </header>
    <section class="pl-hero" aria-labelledby="portfolio-live-title">
      <div class="pl-container pl-hero-grid">
        <div>
          <span class="pl-eyebrow">{{ $isArabic ? 'الأعمال والمشاريع الموثقة' : 'REAL PROJECT PORTFOLIO' }}</span>
          <h1 id="portfolio-live-title">{{ $isArabic ? 'مشاريعي' : 'Projects Portfolio' }}</h1>
          <p>{{ $isArabic
              ? 'مشاريع حقيقية في المؤسسات الحكومية والتعليمية وحلول المؤسسات والذكاء الاصطناعي. تظهر فقط الأعمال المصرح بنشرها والمدعومة بدليل عام صالح.'
              : 'Real engineering work across government, education, enterprise, AI and software delivery. Only approved projects backed by safe public evidence are listed.' }}</p>
          <div class="pl-hero-actions">
            <a href="#portfolio-live-catalog" class="pl-btn primary">{{ $isArabic ? 'استكشف المشاريع' : 'Explore projects' }}</a>
            <a href="{{ route('public.github', ['lang'=>$locale]) }}" class="pl-btn secondary">{{ $isArabic ? 'الأدلة والمستودعات' : 'GitHub & evidence' }}</a>
            <a href="{{ route('public.projects', array_merge(['lang'=>$isArabic ? 'en' : 'ar','category'=>$portfolioCategory], $search!==''?['q'=>$search]:[], $status!==''?['status'=>$status]:[])) }}"
               class="pl-btn secondary" lang="{{ $isArabic ? 'en' : 'ar' }}">{{ $isArabic ? 'English' : 'العربية' }}</a>
          </div>
        </div>
        <div class="pl-art" aria-hidden="true">
          <div class="pl-laptop">&lt;/&gt;</div>
          <span class="pl-icon one">DB</span><span class="pl-icon two">AI</span>
          <span class="pl-icon three">API</span><span class="pl-icon four">✓</span>
        </div>
      </div>
    </section>
    <main id="portfolio-live-catalog" class="pl-container pl-controls">
      <nav class="pl-tab-nav" aria-label="{{ $isArabic ? 'أقسام المشاريع' : 'Project categories' }}">
        @foreach($navGroups as $key=>$labels)
          <a href="{{ route('public.projects', array_merge($filters, ['category'=>$key])) }}"
             @if($portfolioCategory===$key) aria-current="page" @endif>{{ $labels[$locale] }}</a>
        @endforeach
        <span class="pl-count">{{ $portfolioIndex }} / 6</span>
      </nav>
      <form class="pl-search" role="search" method="get" action="{{ route('public.projects') }}">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <input type="hidden" name="category" value="{{ $portfolioCategory }}">
        <div class="pl-field"><label for="pl-query">{{ $isArabic ? 'البحث في المشاريع المنشورة' : 'Search published projects' }}</label>
          <input id="pl-query" type="search" name="q" value="{{ $search }}" maxlength="80" placeholder="{{ $isArabic ? 'اسم المشروع أو التقنيات' : 'Project title or description' }}"></div>
        <div><label for="pl-status">{{ $isArabic ? 'الحالة' : 'Status' }}</label>
          <select id="pl-status" name="status">
            <option value="">{{ $isArabic ? 'كل الحالات' : 'All statuses' }}</option>
            @foreach($statusLabels as $code=>$label)
              <option value="{{ $code }}" @selected($status===$code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <button type="submit">{{ $isArabic ? 'بحث' : 'Search' }}</button>
      </form>
      <div class="pl-section-head">
        <div>
          <h2>{{ $navGroups[$portfolioCategory][$locale] }}</h2>
          <p>{{ $isArabic ? 'بطاقات حقيقية مرتبطة بصفحات تفاصيل من قاعدة البيانات.' : 'Live records, real case-study routes and current publication state.' }}</p>
        </div>
        <span class="pl-real-count">{{ $paginated->total() }} {{ $isArabic ? 'مشروع منشور مطابق' : 'published matching projects' }}</span>
      </div>
      @if($projects->isEmpty())
        <div class="pl-empty" role="status">
          <p>{{ $isArabic ? 'لا توجد مشاريع معتمدة للنشر في هذا القسم حاليًا. تظل مشاريع السيرة الذاتية غير المراجعة في لوحة الإدارة.' : 'No publication-approved projects match here yet. Unreviewed CV-only projects remain private in the admin console.' }}</p>
          <a href="{{ route('public.projects', ['lang'=>$locale]) }}">{{ $isArabic ? 'عودة لكل المشاريع' : 'View all approved projects' }}</a>
        </div>
      @else
        <div class="pl-grid">
          @foreach($projects as $item)
            <article class="pl-card">
              <div class="pl-cover category-{{ $item['category'] ?? 'enterprise' }}" aria-hidden="true">
                <span class="pl-symbol">{{ ['ai'=>'AI','government'=>'◇','education'=>'ED','workflows'=>'↗','enterprise'=>'⌘'][$item['category'] ?? 'enterprise'] ?? '⌘' }}</span>
                <span class="pl-type">{{ $navGroups[$item['category'] ?? 'enterprise'][$locale] ?? ($isArabic?'مشروع':'Project') }}</span>
              </div>
              <div class="pl-card-content">
                <span class="pl-status">{{ $statusLabels[$item['status']] ?? ($isArabic?'غير محدد':'Unspecified') }}</span>
                <h3>{{ $item['name'] }}</h3>
                <p>{{ \Illuminate\Support\Str::limit($item['summary'] ?? '', 190) }}</p>
                <div class="pl-meta">
                  <strong>{{ $isArabic ? 'دوري' : 'My role' }}:</strong> {{ $item['role'] }}
                  @if(!empty($item['source_label']))<p>{{ $isArabic ? 'المصدر' : 'Source' }}: {{ $item['source_label'] }}</p>@endif
                </div>
                <a class="pl-more" href="{{ route('public.project', array_merge(['slug'=>$item['slug']], $filters,
                    $portfolioCategory!=='featured'?['category'=>$portfolioCategory]:[],
                    $paginated->currentPage()>1?['page'=>$paginated->currentPage()]:[])) }}">
                  {{ $isArabic ? 'عرض تفاصيل المشروع والأدلة' : 'View project details & evidence' }} &rsaquo;
                </a>
              </div>
            </article>
          @endforeach
        </div>
      @endif
      @if($paginated->hasPages())
      <nav class="pl-pagination" aria-label="{{ $isArabic ? 'ترقيم صفحات المشاريع' : 'Project pagination' }}">
        @if($paginated->currentPage()>1)
          <a href="{{ route('public.projects', array_merge($filters, ['category'=>$portfolioCategory,'page'=>$paginated->currentPage()-1])) }}">{{ $isArabic ? 'السابق' : 'Previous' }}</a>
        @endif
        <span>{{ $isArabic ? 'صفحة' : 'Page' }} {{ $paginated->currentPage() }} / {{ $paginated->lastPage() }}</span>
        @if($paginated->hasMorePages())
          <a href="{{ route('public.projects', array_merge($filters, ['category'=>$portfolioCategory,'page'=>$paginated->currentPage()+1])) }}">{{ $isArabic ? 'التالي' : 'Next' }}</a>
        @endif
      </nav>
      @endif
      <div class="pl-footer">
        <div><strong>{{ $isArabic ? 'مشروع حكومي أو عمل سابق لا يظهر؟' : 'Looking for an earlier government or enterprise project?' }}</strong>
          <p>{{ $isArabic ? 'الملفات التي لم تُعتمد أدلتها للنشر تظل محفوظة في لوحة الإدارة حتى مراجعتها.' : 'Projects awaiting public evidence review remain in the owner dashboard until approved.' }}</p></div>
        <a class="pl-btn primary" href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $isArabic ? 'تواصل للاستفسار' : 'Ask about my work' }}</a>
      </div>
    </main>
</div>
