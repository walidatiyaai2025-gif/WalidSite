<?php

namespace App\Domain\Content;

/**
 * Canonical first-install copy for owner-provided pages, not invented CV evidence.
 * Professional claims in review-state sections are NOT auto-publication approval.
 * The Expertise copy is sourced verbatim from owner-reviewed visual PR #800.
 */
final class PageContentDefaults
{
    /** @return array<string, array<string, array<string, array{en:string,ar:string}>>> */
    public static function all(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $items = [
            'home' => [
                'hero' => [
                    'tagline' => ['en' => 'Enterprise Technology & AI Solutions Architecture', 'ar' => 'قيادة تقنية المؤسسات وهندسة حلول الذكاء الاصطناعي'],
                    'intro' => ['en' => 'Database & infrastructure, enterprise systems, software engineering, project delivery, and AI-driven development.', 'ar' => 'قواعد البيانات، الأنظمة المؤسسية، هندسة البرمجيات، وتسليم المشاريع وتطوير حلول الذكاء الاصطناعي.'],
                ],
                'skills' => [
                    'project' => ['en' => 'Project Delivery','ar' => 'إدارة المشاريع'],
                    'database' => ['en' => 'SQL Server / DBA','ar' => 'قواعد البيانات'],
                    'engineering' => ['en' => 'Software Engineering','ar' => 'هندسة البرمجيات'],
                    'ai' => ['en' => 'AI Engineering','ar' => 'هندسة الذكاء الاصطناعي'],
                ],
                'laptop' => [
                    'heading' => ['en' => 'Enterprise Technology & AI Solutions','ar' => 'هندسة التقنية وحلول الذكاء الاصطناعي'],
                    'description' => ['en' => 'Verified engineering projects and case studies','ar' => 'المشاريع ودراسات الحالة المتحقق منها'],
                ],
                'ribbon' => [
                    'database_title' => ['en'=>'Database & Infra','ar'=>'قواعد البيانات والبنية'],
                    'database_description' => ['en'=>'SQL Server, HA/DR, Security & Compliance','ar'=>'SQL Server والتوافر والتعافي والأمان'],
                    'software_title' => ['en'=>'Software Engineering','ar'=>'هندسة البرمجيات'],
                    'software_description' => ['en'=>'Laravel, Flutter, APIs & Enterprise Systems','ar'=>'Laravel وFlutter وAPI وأنظمة المؤسسات'],
                    'delivery_title' => ['en'=>'Project Delivery','ar'=>'تسليم المشاريع'],
                    'delivery_description' => ['en'=>'Governance, review, UAT & Rollout','ar'=>'الحوكمة والمراجعة والقبول والنشر'],
                    'ai_title' => ['en'=>'AI Engineering','ar'=>'هندسة الذكاء الاصطناعي'],
                    'ai_description' => ['en'=>'AI Tools, Automation, Content & Code','ar'=>'أدوات AI والأتمتة والمحتوى والكود'],
                    'github_title' => ['en'=>'GitHub & DevOps','ar'=>'GitHub والتطوير'],
                    'github_description' => ['en'=>'Repositories, CI/CD, Versioning','ar'=>'المستودعات والتكامل والإصدارات'],
                    'knowledge_title' => ['en'=>'Knowledge Hub','ar'=>'مركز المعرفة'],
                    'knowledge_description' => ['en'=>'Technical Blog, Solutions, Runbooks & Learning','ar'=>'المدونة التقنية والحلول والأدلة والتعلم'],
                ],
                'mobile' => [
                    'description' => ['en'=>'Enterprise Technology & AI Solutions Architecture','ar'=>'هندسة التقنية المؤسسية وحلول الذكاء الاصطناعي'],
                ],
            ],
            'about' => [
                'intro' => ['heading'=>['en'=>'About','ar'=>'نبذة']],
            ],
            'projects' => [
                'intro' => [
                    'heading'=>['en'=>'Verified projects','ar'=>'المشاريع الموثقة'],
                    'empty'=>['en'=>'No publicly verified projects are available yet.','ar'=>'لا توجد مشاريع عامة موثقة متاحة حاليًا.'],
                ],
            ],
            'expertise' => [],
        ];

        foreach (self::expertise() as $section => $panel) {
            $items['expertise'][$section] = [
                'hero_description' => $panel['description'],
                'hero_action' => $panel['explore'],
                'tag_heading' => $panel['tagTitle'],
            ];
            foreach ($panel['tags'] as $index => $tag) {
                // Technology labels/proper nouns are intentionally identical across locales.
                $items['expertise'][$section]['tag_'.($index+1)] = ['en'=>$tag,'ar'=>$tag];
            }
            foreach ($panel['cards'] as $index => $card) {
                $items['expertise'][$section]['card_'.($index+1).'_title'] = ['en'=>$card[1],'ar'=>$card[3]];
                $items['expertise'][$section]['card_'.($index+1).'_body'] = ['en'=>$card[2],'ar'=>$card[4]];
            }
            foreach ($panel['highlights'] as $index => $highlight) {
                $items['expertise'][$section]['highlight_'.($index+1).'_title'] = ['en'=>$highlight[1],'ar'=>$highlight[3]];
                $items['expertise'][$section]['highlight_'.($index+1).'_body'] = ['en'=>$highlight[2],'ar'=>$highlight[4]];
            }
            $items['expertise'][$section]['highlight_heading'] = $panel['highlightTitle'];
        }

        return $cached = $items;
    }

    /** Exact approved screenshot text definitions; source is open owner-preview PR #800. */
    private static function expertise(): array
    {
        $panels = [
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
        return $panels;
    }

    public static function isAllowed(string $page, string $section, string $field, string $locale): bool
    {
        return in_array($locale, ['en','ar'], true)
            && isset(self::all()[$page][$section][$field][$locale]);
    }
}
