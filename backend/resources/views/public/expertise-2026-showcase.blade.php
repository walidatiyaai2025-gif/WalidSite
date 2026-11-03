{{-- Owner-directed four-screen WEB-03 visual preview, 2026-11-03.
     The copy is owner-supplied layout copy, not a source of fabricated statistics.
     Evidence CTAs go to real publication-gated destinations. --}}
@php
$w3Ar = $locale === 'ar';
$w3L = $w3Ar ? 'ar' : 'en';
$w3Panels = [
    'overview' => [
        'name' => ['en'=>'Overview','ar'=>'نظرة عامة'],
        'count'=>'1 / 4',
        'description'=>[
            'en'=>'Enterprise technology leader focused on program delivery, database reliability, backend platforms, AI integration, and production operations. Practical experience across government initiatives, FOODEX program delivery, release governance, and end-to-end execution.',
            'ar'=>'قيادة تقنية المؤسسات مع التركيز على تسليم البرامج وموثوقية قواعد البيانات ومنصات البرمجيات وتكامل الذكاء الاصطناعي والعمليات التشغيلية. خبرة عملية في المبادرات الحكومية وبرنامج FOODEX وحوكمة الإصدارات والتسليم المتكامل.',
        ],
        'explore'=>['en'=>'Explore my work','ar'=>'استكشف أعمالي'],
        'exploreTo'=>'public.projects',
        'tagTitle'=>['en'=>'Core stack / Tools','ar'=>'الأدوات والتقنيات الأساسية'],
        'tags'=>['Laravel','PHP','MariaDB','MySQL','SQL Server','Flutter','REST APIs','GitHub','CI/CD','QA','IIS','cPanel','Windows Server','Release Management','Backup & Recovery'],
        'cards'=>[
            ['target','Technical Program & Project Delivery','End-to-end delivery of enterprise and government technology initiatives, including planning, execution, release coordination, issue tracking, and cross-team alignment.','البرامج التقنية وإدارة المشاريع','تسليم المبادرات التقنية للمؤسسات والجهات الحكومية من التخطيط والتنفيذ إلى تنسيق الإصدارات ومتابعة المشكلات والفرق.','public.hire',['track'=>'delivery']],
            ['database','Database Administration & Reliability','Hands-on administration of MariaDB, MySQL, and SQL Server with focus on stability, backup/recovery readiness, performance tuning, and operational continuity.','إدارة قواعد البيانات والموثوقية','إدارة MariaDB وMySQL وSQL Server مع التركيز على الاستقرار والنسخ الاحتياطي والاستعادة وتحسين الأداء واستمرارية التشغيل.','public.expertise',['category'=>'database']],
            ['chip','AI Engineering & Automation','Applied AI integration for diagnostics, content workflows, and operational automation, with practical implementation across web and mobile products.','هندسة الذكاء الاصطناعي والأتمتة','تكامل عملي للذكاء الاصطناعي في التشخيص وسير عمل المحتوى والأتمتة التشغيلية عبر الويب والموبايل.','public.ai-engineering',[]],
            ['code','Web & Backend Architecture','Design and delivery of Laravel/PHP platforms, APIs, admin dashboards, setup flows, and production-ready backend services.','هندسة الويب والخدمات الخلفية','تصميم وتسليم منصات Laravel وPHP وواجهات API ولوحات الإدارة ومسارات الإعداد وخدمات الإنتاج.','public.projects',[]],
            ['phone','Mobile Product Delivery','Flutter-based product delivery with Android/iOS release readiness, testing support, and coordinated rollout workflows.','تسليم تطبيقات الموبايل','تسليم المنتجات باستخدام Flutter مع الاستعداد لإصدارات Android وiOS والاختبارات والتنسيق للنشر.','public.projects',[]],
            ['server','Infrastructure, Deployment & Support','Operational ownership across IIS, cPanel, Windows Server, deployment setup, troubleshooting, and production support.','البنية التحتية والنشر والدعم','مسؤوليات تشغيلية عبر IIS وcPanel وWindows Server والإعداد والنشر واستكشاف الأعطال ودعم الإنتاج.','public.expertise',['category'=>'security']],
        ],
        'highlightTitle'=>['en'=>'Professional highlights','ar'=>'أبرز الخبرات المهنية'],
        'highlights'=>[
            ['landmark','Government digital delivery','IT / DBA professional contributing to key government technology initiatives in Kuwait.','التسليم الحكومي الرقمي','عمل تقني في قواعد البيانات ومبادرات تقنية حكومية في الكويت.'],
            ['layers','FOODEX program leadership','Program leadership and enterprise delivery across FOODEX platform tracks and releases.','قيادة برنامج FOODEX','تنسيق البرنامج والتسليم المؤسسي لمسارات وإصدارات منصة FOODEX.'],
            ['check','Delivery ownership','Strong ownership across planning, execution, QA readiness, and operational follow-through.','مسؤولية التسليم','المتابعة من التخطيط والتنفيذ والاستعداد للجودة إلى التشغيل.'],
            ['users','Cross-functional execution','Bridging product, engineering, infrastructure, and release operations in one delivery profile.','التنفيذ متعدد التخصصات','الربط بين المنتج والهندسة والبنية التحتية وعمليات الإصدارات.'],
        ],
        'art'=>['DB','AI','</>','☁','⚙','▣'],
    ],
    'delivery'=>[
        'name'=>['en'=>'Delivery','ar'=>'التسليم'],
        'count'=>'2 / 4',
        'description'=>[
            'en'=>'Delivery leadership across enterprise and government technology work, combining planning discipline, release control, issue-driven execution, and strong cross-functional coordination. Proven ownership from roadmap to rollout.',
            'ar'=>'قيادة التسليم في الأعمال التقنية للمؤسسات والجهات الحكومية بالجمع بين التخطيط المنضبط والتحكم في الإصدارات والتنفيذ المبني على متابعة المشكلات والتنسيق بين الفرق من خارطة الطريق إلى الإطلاق.',
        ],
        'explore'=>['en'=>'Explore delivery','ar'=>'استكشف التسليم'],
        'exploreTo'=>'public.hire',
        'tagTitle'=>['en'=>'Delivery focus','ar'=>'محاور التسليم'],
        'tags'=>['Program Leadership','Project Delivery','Release Governance','QA Coordination','Issue Tracking','Stakeholder Alignment','Cross-team Execution','Production Readiness','Operational Follow-through','Enterprise Delivery'],
        'cards'=>[
            ['layers','Enterprise Program Leadership','Leading multi-track initiatives with clear priorities, ownership boundaries, and delivery cadence across product, engineering, and operations.','قيادة البرامج المؤسسية','إدارة المبادرات متعددة المسارات بأولويات واضحة ومسؤوليات محددة وجدول تسليم بين فرق المنتج والهندسة والتشغيل.','public.hire',['track'=>'delivery']],
            ['landmark','Government Technology Delivery','Experience supporting structured government environments with accountability, reliability, and controlled execution standards.','تسليم التقنية الحكومية','العمل في بيئات حكومية منظمة مع التركيز على المساءلة والموثوقية وضبط التنفيذ.','public.hire',['track'=>'delivery']],
            ['refresh','Release & CI Governance','Hands-on coordination of release flow, branch discipline, CI validation, defect closure, and production-readiness checks.','حوكمة الإصدارات والتكامل المستمر','تنسيق مسارات الإصدارات والفروع والتحقق من CI وإغلاق العيوب والاستعداد للإنتاج.','public.github',[]],
            ['list','Issue-to-Closure Execution','Driving work from issue definition through implementation, testing, acceptance, merge, and final operational follow-up.','التنفيذ حتى إغلاق المهمة','متابعة العمل من تعريف المهمة إلى التنفيذ والاختبارات والقبول والدمج والتشغيل.','public.github',[]],
            ['code','Product & Technical Coordination','Bridging business goals with engineering decisions, QA feedback, and execution details to keep delivery moving.','التنسيق التقني والمنتج','ربط أهداف الأعمال بالقرارات الهندسية وملاحظات الاختبارات وتفاصيل التنفيذ.','public.projects',[]],
            ['layers','FOODEX Delivery Leadership','Program-level delivery involvement across FOODEX tracks, releases, operational workflows, and implementation follow-through.','القيادة في تسليم FOODEX','المشاركة في تنسيق تسليم مسارات FOODEX وإصداراتها وعملياتها ومتابعة تنفيذها.','public.projects',[]],
        ],
        'highlightTitle'=>['en'=>'Delivery highlights','ar'=>'أبرز محاور التسليم'],
        'highlights'=>[
            ['target','End-to-end ownership','From planning and issue framing to release and live follow-up.','المسؤولية المتكاملة','من التخطيط وتحديد المشكلات إلى الإصدار والمتابعة.'],
            ['users','Multi-team orchestration','Aligning developers, QA, ops, and product stakeholders.','تنسيق الفرق','مواءمة المطورين والجودة والتشغيل وأصحاب المصلحة.'],
            ['check','Delivery discipline','Structured execution with clear gates, checkpoints, and acceptance.','انضباط التسليم','تنفيذ منظم ببوابات واضحة ونقاط تحقق وقبول.'],
            ['rocket','Real-world rollout','Built around working systems, not slideware.','النشر الفعلي','التركيز على أنظمة عملية قابلة للتشغيل.'],
        ],
        'art'=>['✓','□','☑','♧','◇','◎'],
    ],
    'technology'=>[
        'name'=>['en'=>'Technology','ar'=>'التقنية'],
        'count'=>'3 / 4',
        'description'=>[
            'en'=>'A practical technology stack centered on backend platforms, database operations, mobile delivery, APIs, and production infrastructure. Built through hands-on work across implementation, stability, and support.',
            'ar'=>'منظومة تقنية عملية تتمحور حول منصات الخدمات الخلفية وإدارة قواعد البيانات وتطبيقات الموبايل وواجهات API والبنية التحتية للإنتاج، مع التركيز على التنفيذ والاستقرار والدعم.',
        ],
        'explore'=>['en'=>'Explore technology','ar'=>'استكشف التقنية'],
        'exploreTo'=>'public.projects',
        'tagTitle'=>['en'=>'Technology stack','ar'=>'حزمة التقنيات'],
        'tags'=>['Laravel','PHP','MariaDB','MySQL','SQL Server','Flutter','REST APIs','GitHub','IIS','cPanel','Windows Server','Setup Flows','Database Backup','Performance Tuning'],
        'cards'=>[
            ['code','Laravel / PHP Platforms','Designing and implementing web platforms, admin dashboards, business workflows, and setup experiences with Laravel and PHP.','منصات Laravel وPHP','تصميم وتطوير منصات الويب ولوحات الإدارة وسير العمل وإعداد الأنظمة باستخدام Laravel وPHP.','public.projects',[]],
            ['database','Database Operations','Managing MariaDB, MySQL, and SQL Server environments with attention to integrity, availability, tuning, and operational continuity.','عمليات قواعد البيانات','إدارة MariaDB وMySQL وSQL Server مع الاهتمام بالسلامة والتوافر وضبط الأداء والاستمرارية.','public.expertise',['category'=>'database']],
            ['chip','API & Integration Design','Building and integrating REST APIs, service flows, system connections, and backend-driven product capabilities.','تصميم API والتكامل','تطوير وتكامل REST APIs وتدفقات الخدمات والربط بين الأنظمة ووظائف المنتجات المدعومة بخدمات خلفية.','public.expertise',['category'=>'integration']],
            ['phone','Flutter Mobile Delivery','Contributing to Flutter-based delivery with release readiness, issue resolution, UI follow-through, and deployment coordination.','تسليم تطبيقات Flutter','تطوير وتسليم تطبيقات Flutter مع الاستعداد للإصدار وحل المشكلات ومتابعة الواجهات والتنسيق للنشر.','public.projects',[]],
            ['server','Infrastructure & Hosting','Working across IIS, cPanel, Windows Server, environment setup, deployment preparation, and live operational troubleshooting.','البنية التحتية والاستضافة','العمل مع IIS وcPanel وWindows Server وإعداد البيئات والتحضير للنشر واستكشاف أعطال التشغيل.','public.expertise',['category'=>'security']],
            ['check','Reliability & Recovery','Supporting backup thinking, resilience, controlled changes, and recovery-minded operations for production systems.','الموثوقية والاستعادة','دعم النسخ الاحتياطي والمرونة وإدارة التغييرات والاستعادة في بيئات الإنتاج.','public.expertise',['category'=>'database']],
        ],
        'highlightTitle'=>['en'=>'Technical highlights','ar'=>'أبرز الخبرات التقنية'],
        'highlights'=>[
            ['layers','Full-stack delivery','Backend, database, infrastructure, and mobile coordination in one execution profile.','تسليم متكامل','تنسيق الخدمات الخلفية وقواعد البيانات والبنية التحتية والموبايل.'],
            ['database','Database strength','Strong grounding in administration, performance, and stability.','قوة قواعد البيانات','التركيز على الإدارة والأداء والاستقرار.'],
            ['rocket','Production mindset','Designed for deployment, support, and real usage.','عقلية الإنتاج','التصميم للنشر والدعم والاستخدام الفعلي.'],
            ['server','Platform versatility','Comfortable across web, APIs, admin systems, and hosting layers.','تنوع المنصات','العمل عبر الويب وAPI ولوحات الإدارة والاستضافة.'],
        ],
        'art'=>['DB','API','</>','▣','▤','⚙'],
    ],
    'ai'=>[
        'name'=>['en'=>'AI & Operations','ar'=>'الذكاء الاصطناعي والتشغيل'],
        'count'=>'4 / 4',
        'description'=>[
            'en'=>'Applying AI pragmatically inside real delivery environments, while keeping strong operational ownership across diagnostics, workflows, support, and production execution. A blend of modern automation and dependable systems thinking.',
            'ar'=>'تطبيق الذكاء الاصطناعي بصورة عملية في بيئات التسليم الحقيقية مع المحافظة على مسؤولية تشغيلية قوية في التشخيص وسير العمل والدعم والتنفيذ الإنتاجي، والجمع بين الأتمتة الحديثة والتفكير المنظومي الموثوق.',
        ],
        'explore'=>['en'=>'Explore AI & ops','ar'=>'استكشف AI والتشغيل'],
        'exploreTo'=>'public.ai-engineering',
        'tagTitle'=>['en'=>'AI & operations','ar'=>'الذكاء الاصطناعي والعمليات'],
        'tags'=>['AI Integration','Workflow Automation','Diagnostics','Content Flows','Production Support','Operations','QA Feedback','Release Support','Troubleshooting','Ownership','Continuous Improvement'],
        'cards'=>[
            ['chip','AI Product Integration','Integrating AI-assisted capabilities into products and workflows with focus on usefulness, guardrails, and operational fit.','تكامل AI في المنتجات','إضافة قدرات الذكاء الاصطناعي إلى المنتجات وسير العمل مع التركيز على الفائدة والضوابط والملاءمة التشغيلية.','public.ai-engineering',[]],
            ['network','Automation & Content Workflows','Using automation to streamline repetitive tasks, content handling, and assisted operational processes.','الأتمتة وسير عمل المحتوى','توظيف الأتمتة لتبسيط المهام المتكررة ومعالجة المحتوى ودعم الإجراءات التشغيلية.','public.lab',[]],
            ['search','Diagnostics & Troubleshooting','A practical problem-solver for system issues, runtime failures, release blockers, and production behavior.','التشخيص وحل الأعطال','حل عملي لمشكلات الأنظمة وأعطال التشغيل وعوائق الإصدارات وسلوك الإنتاج.','public.lab',[]],
            ['check','QA, Validation & Readiness','Supporting acceptance thinking, validation loops, release confidence, and evidence-based improvement.','الجودة والتحقق والجاهزية','دعم اختبارات القبول ودورات التحقق والثقة في الإصدارات والتحسين المبني على الأدلة.','public.projects',[]],
            ['server','Production Operations','Comfortable owning live-system concerns, follow-up actions, support flow, and continuous operational refinement.','عمليات الإنتاج','متابعة أنظمة التشغيل المباشرة وإجراءات الدعم والتحسين المستمر للعمليات.','public.projects',[]],
            ['users','Professional Delivery Profile','A balanced profile spanning technical depth, execution ownership, communication clarity, and dependable follow-through.','ملف مهني متكامل','مزيج بين العمق التقني والمسؤولية التنفيذية والتواصل الواضح والمتابعة الموثوقة.','public.hire',['track'=>'all']],
        ],
        'highlightTitle'=>['en'=>'Professional strengths','ar'=>'نقاط القوة المهنية'],
        'highlights'=>[
            ['chip','AI with practicality','Focused on useful implementation, not hype.','ذكاء اصطناعي عملي','التركيز على التنفيذ المفيد لا الدعاية.'],
            ['layers','Operations mindset','Strong follow-through in live environments.','عقلية تشغيلية','متابعة مستمرة في البيئات التشغيلية.'],
            ['check','Clear ownership','Comfortable taking work to a finished outcome.','مسؤولية واضحة','تسليم العمل إلى نتيجة نهائية.'],
            ['network','Broad adaptability','Able to connect technical, delivery, and operational perspectives.','مرونة واسعة','الربط بين الرؤى التقنية والتنفيذية والتشغيلية.'],
        ],
        'art'=>['AI','↗','▤','⚙','◉','⌁'],
    ],
];
$w3Requested = request()->query()['section'] ?? 'overview';
$w3Focus = is_string($w3Requested) && array_key_exists($w3Requested, $w3Panels) ? $w3Requested : 'overview';
$w3View = $w3Panels[$w3Focus];
$w3ActiveCategory = $expertiseCategory ?? '';
$w3Text = static fn (array $pair) => $pair[$w3L] ?? $pair['en'];
$w3Dest = static fn (string $route, array $args = []) => route($route, array_merge(['lang' => $locale], $args));
@endphp
@push('styles')
<style>
/* WEB-03 standalone navigation skin: other professional screens keep their own T007 CSS. */
body:has(.expertise-screen) .t007-nav-shell{height:60px;min-height:60px;display:flex;align-items:center;gap:30px;padding:0 max(5%,32px);background:#17436e;color:#fff;box-shadow:0 2px 9px #173f652b}
body:has(.expertise-screen) .t007-brand{display:inline-flex;align-items:center;text-decoration:none;white-space:nowrap;gap:6px;margin-inline-end:30px}
body:has(.expertise-screen) .t007-brand span{font-size:29px;font-weight:900;line-height:1;letter-spacing:-1px;color:#00b8a7}
body:has(.expertise-screen) .t007-brand strong{font-size:21px;font-weight:850;color:#fff}
body:has(.expertise-screen) .t007-nav-links{display:flex;align-items:stretch;justify-content:space-between;gap:clamp(14px,2.3vw,42px);flex:1;min-width:0;height:100%}
body:has(.expertise-screen) .t007-nav-links a{color:#fff;text-decoration:none;display:flex;align-items:center;border-bottom:3px solid transparent;font-size:15px;padding:0 3px;white-space:nowrap}
body:has(.expertise-screen) .t007-nav-links a.active{color:#14d4b7;border-color:#00c7ae;font-weight:850}
body:has(.expertise-screen) .t007-contact{background:linear-gradient(95deg,#08d1b4,#00b9a7);border-radius:13px;color:#022550;min-width:137px;height:39px;padding:6px 14px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;white-space:nowrap;font-size:15px;font-weight:850;margin-inline-start:18px}
body:has(.expertise-screen) .t007-sr-only{position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important}
body:has(.expertise-screen) .t007-sr-only:focus-visible{position:fixed!important;top:10px!important;inset-inline-start:10px!important;width:auto!important;height:auto!important;padding:9px 13px!important;overflow:visible!important;clip:auto!important;background:#fff;color:#072858;z-index:9999}
body:has(.expertise-screen) .t007-nav-shell a:focus-visible{outline:3px solid #a6fff2;outline-offset:-2px}
@media(max-width:1100px){body:has(.expertise-screen) .t007-nav-shell{padding:0 20px;gap:15px}body:has(.expertise-screen) .t007-brand{margin-inline-end:0}body:has(.expertise-screen) .t007-nav-links{gap:14px;overflow-x:auto}body:has(.expertise-screen) .t007-nav-links a{font-size:13px}body:has(.expertise-screen) .t007-contact{margin-inline-start:0}}
@media(max-width:700px){body:has(.expertise-screen) .t007-nav-shell{flex-wrap:wrap;height:auto;min-height:64px;padding:10px 15px;gap:8px}body:has(.expertise-screen) .t007-nav-links{order:3;flex:0 0 100%;overflow-x:auto;min-height:38px}body:has(.expertise-screen) .t007-brand strong{font-size:18px}body:has(.expertise-screen) .t007-contact{margin-inline-start:auto;min-width:110px;font-size:13px}body:has(.expertise-screen) .t007-nav-links a{padding:0 6px}}
/* Owner supplied 1648×927 four-state reference, scoped to WEB-03 only. */
body:has(.expertise-screen){background:#eaf6ff;overflow-x:clip;color:#081f52}
body:has(.expertise-screen) .site-main{min-height:0}
body:has(.expertise-screen) .site-wrap.t007-functional{width:min(calc(100% - 64px),1560px);max-width:none}
body:has(.expertise-screen) .site-footer{display:none}
body:has(.expertise-screen) .t007-nav-shell{box-shadow:none}
/* English defaults to left-to-right. Arabic direction is applied below only
   for elements under the Arabic document language; selector lists must not
   accidentally apply direction:rtl to English grid/card containers. */
.expertise-screen{direction:ltr;text-align:left}
:lang(ar) .expertise-screen{direction:rtl;text-align:right}
.expertise-screen{--w3-ink:#071e50;--w3-dark:#153c67;--w3-green:#008e77;--w3-teal:#00bea9;--w3-line:#d3e4f3;font-family:Inter,Arial,sans-serif;padding:0 0 14px}
.expertise-screen a:focus-visible,.expertise-screen button:focus-visible,.expertise-screen select:focus-visible,.expertise-screen summary:focus-visible{outline:3px solid #007ff0;outline-offset:3px}
.expertise-showcase{max-width:1560px;margin:auto}
.expertise-hero{display:grid;grid-template-columns:minmax(0,50%) minmax(0,50%);gap:0;min-height:308px;padding:20px 22px 8px;background:radial-gradient(ellipse at 70% 38%,#d5f5fb 0%,#e8f5ff 47%,#f0f8ff 100%);position:relative}
.expertise-intro{min-width:0;position:relative;z-index:1}
.expertise-hero-top{display:flex;flex-wrap:nowrap;gap:10px;align-items:center;margin:5px 0 10px}
.expertise-kicker{flex:0 0 193px;display:inline-flex;align-items:center;border-radius:15px;background:linear-gradient(90deg,#d9f9f1,#d4f4ec);color:#008a6b;font-size:16px;font-weight:850;padding:7px 17px;line-height:1.2}
.expertise-tab-nav{display:flex;align-items:center;gap:7px;white-space:nowrap;margin-inline-start:auto}
.expertise-tab-counter{border-radius:99px;background:#fff;padding:6px 18px;color:#163b66;font-weight:800;font-size:14px}
.expertise-tab-nav a{border:1px solid #cee1f2;border-radius:100px;padding:7px 14px;color:var(--w3-ink);text-decoration:none;background:#f7fbff;font-size:13px;line-height:1.25;transition:background .15s ease;box-shadow:0 1px 1px #e1eff5}
.expertise-tab-nav a[aria-current="page"]{background:linear-gradient(135deg,#005a85,#00b99e);color:white;border-color:transparent;font-weight:800}
.expertise-tab-nav a:hover{background:#d4f5f0;color:#06365b}
.expertise-tab-nav a[aria-current="page"]:hover{color:white}
.expertise-hero h1{font-size:clamp(36px,3.1vw,50px);line-height:1.07;letter-spacing:-.045em;margin:8px 0 3px;color:#143b69;font-weight:850}
.expertise-description{font-size:clamp(15px,1.16vw,19px);font-weight:500;line-height:1.25;min-height:96px;margin:0 0 19px;color:#17385d;max-width:760px}
.expertise-hero-actions{display:flex;gap:22px;align-items:center}
.expertise-hero-actions a{height:49px;min-width:218px;padding:9px 24px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-weight:800;font-size:17px}
.expertise-hero-actions a:first-child{background:linear-gradient(110deg,#008e76,#00a788);color:#fff;box-shadow:0 3px 0 #087e77}
.expertise-hero-actions a:last-child{border:2px solid #008b93;background:#fbfdff;color:#10365b}
.expertise-art{height:296px;min-width:0;display:flex;justify-content:center;align-items:center;margin:-6px -8px -4px 0;filter:drop-shadow(0 14px 13px #5aaed54a)}
.expertise-art svg{width:100%;height:100%;max-width:710px;overflow:visible}
.expertise-tags{display:flex;align-items:center;gap:18px;min-height:60px;background:#f9fcff;border:1px solid #dbeafa;border-radius:19px;padding:12px 26px;margin:3px 22px 14px;box-shadow:0 9px 19px #91bcd229}
.expertise-tags strong{flex:0 0 auto;font-size:17px;font-weight:820;color:#051d4c}
.expertise-tag-pills{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.expertise-tag-pills span{white-space:nowrap;font-size:12px;background:linear-gradient(#e9fbfa,#d8f1ee);border-radius:999px;padding:7px 12px;color:#182b42;line-height:1}
.expertise-feature-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 15px;margin:0 22px 22px}
.expertise-feature{background:linear-gradient(145deg,#fff,#f8fbfe);border:1px solid #d1e4f3;border-radius:17px;min-height:157px;box-shadow:0 8px 16px #679ac320;display:grid;grid-template-columns:70px minmax(0,1fr);gap:14px;padding:15px 19px}
.expertise-feature-icon,.expertise-highlight-icon{background:linear-gradient(140deg,#e2faf6,#ebf5f8);border-radius:16px;color:#009678;display:grid;place-items:center}
.expertise-feature-icon{width:68px;height:69px;align-self:start}
.expertise-feature-icon svg,.expertise-highlight-icon svg{width:37px;height:37px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.expertise-feature-text{display:flex;flex-direction:column;min-width:0}
.expertise-feature h3{font-size:17px;line-height:1.22;margin:0 0 5px;font-weight:850;color:#051b4b;letter-spacing:-.015em}
.expertise-feature p{font-size:13.1px;line-height:1.34;margin:0 0 8px;color:#19365b}
.expertise-feature a{display:flex;align-items:center;min-height:26px;margin-top:auto;border-top:1px solid #d2e2ef;padding-top:6px;color:#007957;text-decoration:none;font-size:15px;font-weight:800}
.expertise-feature a:hover{text-decoration:underline}
.expertise-highlight-bar{margin:0 0 0;background:linear-gradient(100deg,#e0f7f6,#e8fbf9 50%,#def5f1);border-radius:22px;min-height:114px;display:grid;grid-template-columns:202px repeat(4,minmax(0,1fr));align-items:stretch;padding:15px 14px;gap:0}
.expertise-highlight-bar h2{margin:0;align-self:center;padding:0 22px;font-size:21px;font-weight:850;line-height:1.2;color:var(--w3-ink)}
.expertise-highlight{display:grid;grid-template-columns:58px minmax(0,1fr);gap:9px;align-items:start;padding:7px 15px;border-inline-start:1px solid #b3d4e4;min-width:0}
.expertise-highlight-icon{width:52px;height:53px}
.expertise-highlight-icon svg{width:31px;height:31px}
.expertise-highlight strong{display:block;color:#031848;font-size:15px;line-height:1.25;font-weight:850}
.expertise-highlight p{margin:4px 0 0;color:#173759;font-size:12.8px;line-height:1.26}
.expertise-summary{margin:18px 0 0;background:linear-gradient(90deg,#048f89,#0a747f);border-radius:17px;color:#fff;text-align:center;font-size:18px;font-weight:800;font-style:italic;line-height:1.2;padding:12px 16px}
.expertise-proven-panel{margin:28px 22px 0;border:1px solid #cde6f1;border-radius:16px;padding:16px 20px;background:#fff}
.expertise-proven-panel summary{cursor:pointer;color:#143c67;font-size:17px;font-weight:800}
.expertise-body{padding-top:15px}
.expertise-section-heading{color:#163b66;font-size:20px;margin:0 0 12px}
.expertise-filter{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin-bottom:14px}
.expertise-filter label{display:block;font-weight:700;font-size:13px;color:#163b66}
.expertise-filter select{min-width:200px;padding:8px;border:1px solid #9dbbd4;border-radius:10px;color:#092744;background:white;min-height:42px;font:inherit}
.expertise-filter button,.expertise-filter a,.expertise-empty a{min-height:42px;border-radius:9px;padding:8px 16px;background:#009b83;color:#fff;text-decoration:none;border:1px solid #009b83;font:inherit;cursor:pointer;display:inline-flex;align-items:center}
.expertise-filter a,.expertise-empty a{background:white;color:#006f67}
.expertise-filter-status{padding:10px 13px;border-inline-start:3px solid #00aa92;background:#e8f8f6;color:#17385d;margin:0 0 12px}
.expertise-empty{background:#f6fbff;padding:14px;border:1px dashed #b1d6e7;border-radius:11px;color:#17385d}
.expertise-empty p{margin:0 0 8px}
.expertise-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.expertise-card{padding:16px;border:1px solid #d5e7f3;border-radius:12px;min-width:0}
.expertise-symbol{font-size:20px;color:#008d7d}
.expertise-skills{padding:0;list-style:none;color:#2b4767}
.expertise-card-footer{display:flex;flex-wrap:wrap;gap:12px}
.expertise-card a{color:#007966;font-weight:750}
.expertise-proof-links{display:flex;flex-wrap:wrap;gap:12px;margin:14px 0}
.expertise-proof-links a{color:#007866;text-decoration:underline}
.expertise-visually-hidden{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important}
:lang(ar) .expertise-screen{font-family:Tajawal,Arial,sans-serif}
:lang(ar) .expertise-hero,:lang(ar) .expertise-feature-grid,:lang(ar) .expertise-highlight-bar,:lang(ar) .expertise-tags{direction:rtl}
:lang(ar) .expertise-tab-nav{margin-inline-start:auto}
:lang(ar) .expertise-feature h3{font-size:17px}
@media(max-width:1450px){
 .expertise-hero-top{flex-wrap:wrap}
 .expertise-tab-nav{margin-inline-start:0}
 .expertise-feature{grid-template-columns:57px minmax(0,1fr);gap:10px}
 .expertise-feature-icon{width:54px;height:57px}
 .expertise-feature p{font-size:12.7px}
 .expertise-highlight-bar{grid-template-columns:160px repeat(4,minmax(0,1fr))}
 .expertise-highlight{grid-template-columns:43px minmax(0,1fr);padding:7px}
 .expertise-highlight-icon{width:40px;height:43px}
}
@media(max-width:1100px){
 .expertise-hero{grid-template-columns:minmax(0,55%) minmax(0,45%)}
 .expertise-art{height:255px}
 .expertise-tab-nav{width:100%;overflow-x:auto;padding-bottom:4px}
 .expertise-hero-actions a{min-width:0;flex:1}
 .expertise-feature-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
 .expertise-highlight-bar{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
 .expertise-highlight-bar h2{grid-column:1 / -1}
 .expertise-highlight:nth-child(2n){border:0}
}
@media(max-width:700px){
 body:has(.expertise-screen) .site-wrap.t007-functional{width:calc(100% - 24px)}
 .expertise-hero{grid-template-columns:1fr;padding:15px 10px 0}
 .expertise-hero h1{font-size:39px}
 .expertise-description{min-height:0;font-size:16px;line-height:1.5}
 .expertise-hero-actions{gap:9px}
 .expertise-hero-actions a{padding:9px 12px;font-size:14px}
 .expertise-art{height:auto;aspect-ratio:700/320;min-height:175px;margin:0}
 .expertise-kicker{flex-basis:auto;min-width:155px}
 .expertise-tab-nav{gap:6px}
 .expertise-tab-nav a{font-size:12px;padding:8px 10px}
 .expertise-tags{margin:8px 0 12px;align-items:start;flex-direction:column;padding:12px}
 .expertise-feature-grid{grid-template-columns:1fr;margin:0 0 12px}
 .expertise-feature{min-height:120px}
 .expertise-feature p{font-size:14px}
 .expertise-highlight-bar{grid-template-columns:1fr;padding:12px}
 .expertise-highlight-bar h2{padding:7px 4px}
 .expertise-highlight{border:0;border-top:1px solid #bddbdc!important}
 .expertise-summary{font-size:15px}
 .expertise-proven-panel{margin:14px 0 0}
 .expertise-cards{grid-template-columns:1fr}
}
@media(prefers-reduced-motion:reduce){.expertise-screen *{scroll-behavior:auto;transition:none}}
</style>
@endpush
<a class="t007-sr-only" href="#t007-primary-content">{{ $w3Ar ? 'انتقل إلى الخبرات' : 'Skip to expertise' }}</a>
<header class="expertise-hero" aria-labelledby="expertise-title" id="t007-primary-content" tabindex="-1">
    <div class="expertise-intro">
        <div class="expertise-hero-top">
            <span class="expertise-kicker">{{ $w3Ar ? 'القدرات المهنية' : 'CAPABILITIES' }}</span>
            <nav class="expertise-tab-nav" aria-label="{{ $w3Ar ? 'أقسام الخبرات' : 'Expertise sections' }}">
                <span class="expertise-tab-counter" aria-label="{{ $w3Ar ? 'القسم '.$w3View['count'] : 'Section '.$w3View['count'] }}">{{ $w3View['count'] }}</span>
                @foreach($w3Panels as $key => $panel)
                    <a href="{{ $w3Dest('public.expertise', ['section'=>$key]) }}" @if($w3Focus === $key) aria-current="step" @endif>{{ $w3Text($panel['name']) }}</a>
                @endforeach
            </nav>
        </div>
        <h1 id="expertise-title">{{ $w3Ar ? 'الخبرات' : 'Expertise' }}</h1>
        <p class="expertise-description">{{ $w3Text($w3View['description']) }}</p>
        <nav class="expertise-hero-actions" aria-label="{{ $w3Ar ? 'استكشاف المجالات' : 'Explore expertise' }}">
            <a href="{{ $w3Dest($w3View['exploreTo'], $w3Focus === 'delivery' ? ['track'=>'delivery'] : []) }}">{{ $w3Text($w3View['explore']) }}</a>
            <a href="#expertise-catalog">{{ $w3Ar ? 'عرض الأدلة' : 'View evidence' }}</a>
        </nav>
    </div>
    <figure class="expertise-art" aria-label="{{ $w3Ar ? 'رسم توضيحي لحاسوب وتقنيات متصلة' : 'Illustration of a connected laptop and technology modules' }}" role="img">
        <svg viewBox="0 0 700 340" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="w3-tile" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#0bbcad"/><stop offset=".55" stop-color="#027f92"/><stop offset="1" stop-color="#063b72"/></linearGradient>
                <linearGradient id="w3-screen" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#091e44"/><stop offset=".6" stop-color="#00374a"/><stop offset="1" stop-color="#12548a"/></linearGradient>
                <linearGradient id="w3-metal" x1="0" x2=".7" y1="0" y2="1"><stop stop-color="#edfbff"/><stop offset=".5" stop-color="#93cbe7"/><stop offset="1" stop-color="#1b83bc"/></linearGradient>
                <filter id="w3-glow"><feGaussianBlur stdDeviation="6"/></filter>
            </defs>
            <ellipse cx="348" cy="308" rx="253" ry="27" fill="#05e5d0" opacity=".24" filter="url(#w3-glow)"/>
            <g stroke="#5dfcf1" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity=".95">
                <path d="M340 207L183 118L111 118"/>
                <path d="M355 203L220 201L128 201"/>
                <path d="M344 280L214 298L112 261"/>
                <path d="M503 174L597 118L631 118"/>
                <path d="M503 219L602 211L645 211"/>
                <path d="M505 267L595 289L642 268"/>
            </g>
            <g fill="#00ead6" opacity=".5" filter="url(#w3-glow)"><path d="M340 207L183 118L111 118M344 280L214 298L112 261M503 174L597 118L631 118" stroke="#22dfff" stroke-width="11" fill="none"/></g>
            @php($w3TilePositions = [
                ['95','28'], ['9','126'], ['3','235'], ['564','27'], ['603','132'], ['568','240']
            ])
            @foreach($w3TilePositions as $tileIndex => $coords)
            <g transform="translate({{ $coords[0] }},{{ $coords[1] }})">
                <path d="M0 25L55 0L111 24L112 56L54 84L0 57Z" fill="#0c6382" opacity=".6" transform="translate(0 8)"/>
                <path d="M0 25L55 0L111 24L111 49L55 75L0 50Z" fill="url(#w3-tile)" stroke="#25d9df" stroke-width="2"/>
                <path d="M0 49L55 74L111 49" stroke="#00f4d7" stroke-width="3" fill="none"/>
                <text x="55" y="42" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-weight="850" font-size="{{ $w3Focus === 'ai' ? '29' : '26' }}" fill="#e9ffff">{{ $w3View['art'][$tileIndex] }}</text>
            </g>
            @endforeach
            <g transform="translate(195,28)">
                <path d="M98 231L282 231L344 279L158 301L1 270Z" fill="#00edca" opacity=".55" filter="url(#w3-glow)"/>
                <path d="M123 30L292 57L276 221L108 190Z" fill="#103455" stroke="#69d6f7" stroke-width="7" stroke-linejoin="round"/>
                <path d="M132 44L280 69L269 202L119 176Z" fill="url(#w3-screen)" stroke="#03c6e3" stroke-width="2"/>
                <path d="M119 176L269 202L308 249L139 222Z" fill="#1b87b9"/>
                <path d="M108 189L277 220L345 270L163 243L1 259Z" fill="url(#w3-metal)" stroke="#c1f7ff" stroke-width="2"/>
                <path d="M1 259L163 243L345 270L279 287L134 276Z" fill="#66acd3" stroke="#ebfaff" stroke-width="2"/>
                <path d="M129 234L274 255L298 270L149 251Z" fill="#084763" opacity=".73"/>
                <path d="M154 256L221 263L236 275L169 266Z" fill="#b6e8f7"/>
                @for($i=0;$i<9;$i++)
                    <path d="M{{ 134+$i*10 }} {{ 222+$i*1.8 }}l6 1" stroke="#d7f7fd" stroke-width="2"/>
                @endfor
                <g fill="none" stroke="#08d9bb" stroke-width="2.8">
                    @for($i=0;$i<6;$i++)
                        <path d="M{{ 140+$i*2 }} {{ 58+$i*15 }}l{{ 40+($i%3)*12 }} 6"/>
                    @endfor
                </g>
                @if($w3Focus === 'ai')
                    <rect x="183" y="93" width="70" height="67" rx="10" stroke="#00e0c5" stroke-width="2" fill="#03536d"/>
                    <text x="218" y="136" text-anchor="middle" font-family="Inter,Arial" font-size="31" font-weight="900" fill="#fff">AI</text>
                @elseif($w3Focus === 'delivery')
                    <path d="M173 112L205 118M177 129L233 138M182 146L215 151" stroke="#08f7c4" stroke-width="7" stroke-linecap="round"/>
                    <circle cx="243" cy="155" r="12" fill="none" stroke="#0bedc4" stroke-width="3"/>
                    <path d="M237 155l4 5 10-12" fill="none" stroke="#0bedc4" stroke-width="3"/>
                @else
                    <path d="M202 128l-16 14 16 18m27-25 16 14-16 13" fill="none" stroke="#c7ffff" stroke-width="6" stroke-linejoin="round"/>
                @endif
            </g>
        </svg>
    </figure>
</header>
<div class="expertise-tags">
    <strong>{{ $w3Text($w3View['tagTitle']) }}</strong>
    <div class="expertise-tag-pills" aria-label="{{ $w3Ar ? 'مجالات وأدوات التخصص' : 'Focus tags and technology stack' }}">
        @foreach($w3View['tags'] as $tag)
            <span>{{ $tag }}</span>
        @endforeach
    </div>
</div>
<section class="expertise-feature-grid" aria-label="{{ $w3Ar ? 'مجموعات الخبرات' : 'Expertise capability cards' }}">
    @foreach($w3View['cards'] as $card)
        <article class="expertise-feature">
            <div class="expertise-feature-icon" aria-hidden="true">
                @include('public.expertise-2026-icon', ['symbol'=>$card[0]])
            </div>
            <div class="expertise-feature-text">
                <h3>{{ $w3Ar ? $card[3] : $card[1] }}</h3>
                <p>{{ $w3Ar ? $card[4] : $card[2] }}</p>
                <a href="{{ $w3Dest($card[5], $card[6]) }}">{{ $w3Ar ? 'عرض الأدلة' : 'View evidence' }} <span aria-hidden="true">&nbsp; ❯</span></a>
            </div>
        </article>
    @endforeach
</section>
<section class="expertise-highlight-bar" aria-label="{{ $w3Text($w3View['highlightTitle']) }}">
    <h2>{{ $w3Text($w3View['highlightTitle']) }}</h2>
    @foreach($w3View['highlights'] as $highlight)
        <div class="expertise-highlight">
            <div class="expertise-highlight-icon" aria-hidden="true">@include('public.expertise-2026-icon', ['symbol'=>$highlight[0]])</div>
            <div><strong>{{ $w3Ar ? $highlight[3] : $highlight[1] }}</strong><p>{{ $w3Ar ? $highlight[4] : $highlight[2] }}</p></div>
        </div>
    @endforeach
</section>
@if($w3Focus === 'ai')
    <p class="expertise-summary">{{ $w3Ar ? 'خبرة مدعومة بالأدلة في التسليم وقواعد البيانات والمنصات والذكاء الاصطناعي والعمليات.' : 'Evidence-backed expertise across delivery, databases, platforms, AI, and operations.' }}</p>
@endif
