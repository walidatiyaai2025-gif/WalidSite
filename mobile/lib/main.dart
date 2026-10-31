import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'theme/waleed_theme.dart';

void main() => runApp(const WaleedTechApp());

/// Real bilingual navigation shell; no fabricated projects, AI output or metrics.
class WaleedTechApp extends StatefulWidget {
  const WaleedTechApp({super.key});

  @override
  State<WaleedTechApp> createState() => _WaleedTechAppState();
}

class _WaleedTechAppState extends State<WaleedTechApp> {
  bool _arabic = false;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Waleed Tech Companion',
      debugShowCheckedModeBanner: false,
      locale: Locale(_arabic ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: WaleedTheme.light(),
      home: CompanionHome(
        arabic: _arabic,
        onToggleLanguage: () => setState(() => _arabic = !_arabic),
      ),
    );
  }
}

class CompanionHome extends StatelessWidget {
  const CompanionHome({
    required this.arabic,
    required this.onToggleLanguage,
    super.key,
  });

  final bool arabic;
  final VoidCallback onToggleLanguage;

  static const List<_Topic> _topics = [
    _Topic(
      titleEn: 'Database Engineering',
      titleAr: 'هندسة قواعد البيانات',
      subtitleEn: 'SQL Server, reliability and performance',
      subtitleAr: 'SQL Server والاعتمادية والأداء',
      icon: Icons.storage_outlined,
    ),
    _Topic(
      titleEn: 'Software Engineering',
      titleAr: 'هندسة البرمجيات',
      subtitleEn: 'Laravel, APIs and mobile architecture',
      subtitleAr: 'Laravel وواجهات API وتطوير التطبيقات',
      icon: Icons.code_outlined,
    ),
    _Topic(
      titleEn: 'AI Engineering',
      titleAr: 'هندسة الذكاء الاصطناعي',
      subtitleEn: 'Provider routing and reliable automation',
      subtitleAr: 'توجيه مزودي الذكاء الاصطناعي والأتمتة الموثوقة',
      icon: Icons.auto_awesome_outlined,
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          arabic ? 'رفيق وليد التقني' : 'Waleed Tech Companion',
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
        ),
        actions: [
          IconButton(
            tooltip: arabic ? 'Switch to English' : 'Switch to Arabic',
            onPressed: onToggleLanguage,
            icon: const Icon(Icons.language),
          ),
        ],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
          children: [
            _IntroPanel(arabic: arabic),
            const SizedBox(height: 28),
            Text(
              arabic ? 'استكشف المجالات التقنية' : 'Explore engineering topics',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
            ..._topics.map(
              (topic) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Card(
                  child: ListTile(
                    contentPadding: const EdgeInsets.symmetric(
                      vertical: 12,
                      horizontal: 16,
                    ),
                    leading: Icon(topic.icon, size: 30),
                    title: Text(
                      topic.title(arabic),
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    subtitle: Text(topic.subtitle(arabic)),
                    trailing: Icon(
                      arabic ? Icons.chevron_left : Icons.chevron_right,
                    ),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => TopicOverview(
                          topic: topic,
                          arabic: arabic,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _IntroPanel extends StatelessWidget {
  const _IntroPanel({required this.arabic});

  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: const LinearGradient(
          colors: [Color(0xFFFFFFFF), Color(0xFFEAF5FB)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: WaleedPalette.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'W',
            style: TextStyle(
              color: WaleedPalette.accent,
              fontSize: 42,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 16),
          Text(
            arabic ? 'أدوات عملية للمطورين' : 'Practical tools for developers',
            style: const TextStyle(
              color: WaleedPalette.primary,
              fontWeight: FontWeight.w800,
              fontSize: 25,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            arabic
                ? 'مساحة للمعرفة التقنية والأدوات المساعدة. ستتاح الوظائف الجديدة بعد تنفيذها والتحقق منها.'
                : 'A trustworthy knowledge hub and AI-assisted toolbox. '
                    'New tools will appear only after implementation and verification.',
            style: const TextStyle(
              color: WaleedPalette.body,
              height: 1.45,
            ),
          ),
        ],
      ),
    );
  }
}

class _Topic {
  const _Topic({
    required this.titleEn,
    required this.titleAr,
    required this.subtitleEn,
    required this.subtitleAr,
    required this.icon,
  });

  final String titleEn;
  final String titleAr;
  final String subtitleEn;
  final String subtitleAr;
  final IconData icon;

  String title(bool arabic) => arabic ? titleAr : titleEn;
  String subtitle(bool arabic) => arabic ? subtitleAr : subtitleEn;
}

class TopicOverview extends StatelessWidget {
  const TopicOverview({
    required this.topic,
    required this.arabic,
    super.key,
  });

  final _Topic topic;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(topic.title(arabic))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Icon(topic.icon, size: 42, color: WaleedPalette.accent),
            const SizedBox(height: 16),
            Text(
              topic.subtitle(arabic),
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 20),
            Text(
              arabic
                  ? 'ستُنشر الأدلة الموثّقة والأدوات التفاعلية عند اكتمال تنفيذها والتحقق منها.'
                  : 'Verified guides and interactive tools for this topic '
                      'will be published as their implementation is completed.',
            ),
          ],
        ),
      ),
    );
  }
}
