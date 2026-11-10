import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:url_launcher/url_launcher.dart';
import 'theme/waleed_theme.dart';
import 'runbooks/offline_runbooks.dart';
import 'runbooks/verified_online_runbooks.dart';
import 'articles/verified_articles.dart';
import 'learning/verified_learning_paths.dart';
import 'interview/interview_practice.dart';
import 'github/verified_github_portfolio.dart';
import 'solutions/offline_solution_finder.dart';
import 'ai/ai_troubleshooter.dart';
import 'account/account_journey.dart';
import 'preferences/mobile_preferences.dart';
import 'home/mobile_home_feed.dart';
import 'bootstrap/mobile_bootstrap.dart';

Future<String> _loginForArticleSave(String email, String password) async {
  // No durable bearer cache: this token is used for one explicit Save action.
  final session = await const MobileAiClient().login(email, password);
  if (session.expiresAt != null &&
      !session.expiresAt!.isAfter(DateTime.now().toUtc())) {
    throw const FormatException('Expired article-save session');
  }
  return session.token;
}

/// Continue an acknowledged article Save into the private Saved account
/// without requesting a second password or persisting a bearer token.
Future<void> _openSavedAfterArticleSave(
  BuildContext context, String bearer, bool arabic,
) async {
  if (bearer.isEmpty || bearer.length > 8192) return;
  await Navigator.of(context).push<void>(MaterialPageRoute<void>(
    builder: (_) => MobileAccountJourney(
      arabic: arabic,
      initialSection: MobileAccountSection.saved,
      initialSession: MobileAiSession(token: bearer, expiresAt: null),
    ),
  ));
}

/// Projects are opened through a canonical HTTPS public detail route only.
Future<void> _openVerifiedProject(
  BuildContext context, String slug, bool arabic,
) async {
  final target = MobileHomeProject.publicDetailUrl(slug, arabic: arabic);
  if (target != null) {
    try {
      if (await launchUrl(target, mode: LaunchMode.externalApplication)) return;
    } catch (_) {
      // Platform/browser failure is not proof that the project opened.
    }
  }
  if (!context.mounted) return;
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(arabic
        ? 'تعذر فتح صفحة المشروع الموثق حاليًا.'
        : 'Could not open the verified project page.'),
  ));
}

void main() => runApp(const WaleedTechApp());

/// Real bilingual navigation shell; no fabricated projects, AI output or metrics.
class WaleedTechApp extends StatefulWidget {
  const WaleedTechApp({
    this.initiallyStarted = false,
    this.bootstrapFetch,
    super.key,
  });

  /// Explicit test/integration fixture hook. Production main() always uses
  /// the default false value so MOBILE-01 is the real application entry.
  final bool initiallyStarted;

  /// Injected only by UI tests; production always uses the real API reader.
  final MobileBootstrapFetch? bootstrapFetch;

  @override
  State<WaleedTechApp> createState() => _WaleedTechAppState();
}

class _WaleedTechAppState extends State<WaleedTechApp> {
  bool _arabic = false;
  late bool _started;

  @override
  void initState() {
    super.initState();
    _started = widget.initiallyStarted;
    // Respect the device's primary locale at app startup.
    // The user can still switch languages explicitly during this session.
    _arabic =
        WidgetsBinding.instance.platformDispatcher.locale.languageCode == 'ar';
  }

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
      home: _started
          ? CompanionHome(
              arabic: _arabic,
              onToggleLanguage: () => setState(() => _arabic = !_arabic),
              onSetLanguage: (value) => setState(() => _arabic = value),
            )
          : CompanionWelcome(
              arabic: _arabic,
              onToggleLanguage: () => setState(() => _arabic = !_arabic),
              onStart: () => setState(() => _started = true),
              bootstrapFetch: widget.bootstrapFetch,
            ),
    );
  }
}

class CompanionWelcome extends StatelessWidget {
  const CompanionWelcome({
    required this.arabic,
    required this.onToggleLanguage,
    required this.onStart,
    this.bootstrapFetch,
    super.key,
  });

  final bool arabic;
  final VoidCallback onToggleLanguage;
  final VoidCallback onStart;
  final MobileBootstrapFetch? bootstrapFetch;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('mobile-welcome-screen'),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      'W',
                      style: TextStyle(
                        color: WaleedPalette.accent,
                        fontSize: 52,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  const SizedBox(height: 28),
                  Semantics(
                    // The route marker belongs to the *visible* heading,
                    // not a page-wide Scaffold node Android may report hidden.
                    identifier: 'walidsite.mobile.01',
                    header: true,
                    child: Text(
                      arabic ? 'مرحبًا بك في رفيق وليد التقني' : 'Welcome to Waleed Tech Companion',
                      key: const Key('welcome-title'),
                      style: const TextStyle(
                        color: WaleedPalette.primary,
                        fontSize: 32,
                        height: 1.2,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    arabic
                        ? 'أدوات ومعرفة تقنية موثوقة تعمل بوضوح دون اختلاق نتائج أو بيانات.'
                        : 'Trusted engineering tools and knowledge with clear, evidence-aware states.',
                    key: const Key('welcome-subtitle'),
                    style: const TextStyle(
                      color: WaleedPalette.body,
                      fontSize: 16,
                      height: 1.55,
                    ),
                  ),
                  const SizedBox(height: 24),
                  MobileBootstrapPanel(
                    arabic: arabic,
                    fetch: bootstrapFetch,
                  ),
                  const SizedBox(height: 24),
                  FilledButton.icon(
                    key: const Key('welcome-start'),
                    onPressed: onStart,
                    icon: const Icon(Icons.arrow_forward_rounded),
                    label: Text(arabic ? 'ابدأ' : 'Start'),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    key: const Key('welcome-language'),
                    onPressed: onToggleLanguage,
                    icon: const Icon(Icons.language),
                    label: Text(arabic ? 'English' : 'العربية'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class CompanionHome extends StatefulWidget {
  const CompanionHome({
    required this.arabic,
    required this.onToggleLanguage,
    this.onSetLanguage,
    super.key,
  });

  final bool arabic;
  final VoidCallback onToggleLanguage;
  final ValueChanged<bool>? onSetLanguage;

  @override
  State<CompanionHome> createState() => _CompanionHomeState();

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
}

class _CompanionHomeState extends State<CompanionHome> {
  int _selectedTab = 0;

  @override
  Widget build(BuildContext context) {
    final arabic = widget.arabic;
    final onToggleLanguage = widget.onToggleLanguage;
    final onSetLanguage = widget.onSetLanguage;

    void push(Widget page) {
      Navigator.of(context).push<void>(
        MaterialPageRoute<void>(builder: (_) => page),
      );
    }

    return Scaffold(
      key: const Key('mobile-youtube-home'),
      appBar: _selectedTab == 0 ? AppBar(
        title: Text(
          arabic ? 'رفيق وليد التقني' : 'Waleed Tech Companion',
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
        ),
        actions: [
          IconButton(
            key: const Key('mobile-home-search'),
            tooltip: arabic ? 'المقالات' : 'Search articles',
            onPressed: () => push(VerifiedArticleLibrary(
              arabic: arabic,
              loginAction: _loginForArticleSave,
              saveAction: const MobileAccountClient().saveArticle,
              onViewSaved: (viewContext, bearer) =>
                  _openSavedAfterArticleSave(viewContext, bearer, arabic),
            )),
            icon: const Icon(Icons.search),
          ),
          IconButton(
            key: const Key('mobile-home-language'),
            tooltip: arabic ? 'Switch to English' : 'Switch to Arabic',
            onPressed: onToggleLanguage,
            icon: const Icon(Icons.language),
          ),
          IconButton(
            key: const Key('mobile-home-profile'),
            tooltip: arabic ? 'الملف الشخصي' : 'Your profile',
            onPressed: () => setState(() => _selectedTab = 4),
            icon: const Icon(Icons.account_circle_outlined),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(54),
          child: SizedBox(
            height: 54,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsetsDirectional.fromSTEB(12, 5, 12, 5),
              children: [
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-for-you'),
                  label: arabic ? 'لك' : 'For you',
                  selected: true,
                ),
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-ai'),
                  label: 'AI',
                  onTap: () => setState(() => _selectedTab = 2),
                ),
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-articles'),
                  label: arabic ? 'مقالات' : 'Articles',
                  onTap: () => push(VerifiedArticleLibrary(
                    arabic: arabic,
                    loginAction: _loginForArticleSave,
                    saveAction: const MobileAccountClient().saveArticle,
                    onViewSaved: (viewContext, bearer) =>
                        _openSavedAfterArticleSave(viewContext, bearer, arabic),
                  )),
                ),
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-guides'),
                  label: arabic ? 'أدلة' : 'Guides',
                  onTap: () => push(ReviewedRunbookLibrary(arabic: arabic)),
                ),
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-learn'),
                  label: arabic ? 'تعلّم' : 'Learn',
                  onTap: () => setState(() => _selectedTab = 1),
                ),
                _HomeCategoryChip(
                  key: const Key('mobile-home-filter-github'),
                  label: 'GitHub',
                  onTap: () => push(VerifiedGithubPortfolioScreen(arabic: arabic)),
                ),
              ],
            ),
          ),
        ),
      ) : null,
      bottomNavigationBar: NavigationBar(
        height: 64,
        selectedIndex: _selectedTab,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        onDestinationSelected: (index) {
          if (index != _selectedTab) {
            // Primary tabs share one navigation shell. Detail/category routes
            // remain explicit pushes, but the five primary destinations are
            // always accessible without stacking duplicate paid AI screens.
            setState(() => _selectedTab = index);
          }
        },
        destinations: [
          NavigationDestination(
            key: const Key('mobile-home-nav-home'),
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home_filled),
            label: arabic ? 'الرئيسية' : 'Home',
          ),
          NavigationDestination(
            key: const Key('mobile-home-nav-learn'),
            icon: const Icon(Icons.school_outlined),
            label: arabic ? 'تعلّم' : 'Learn',
          ),
          const NavigationDestination(
            key: const Key('mobile-home-nav-ai'),
            icon: const Icon(Icons.auto_awesome_outlined),
            label: 'AI',
          ),
          NavigationDestination(
            key: const Key('mobile-home-nav-library'),
            icon: const Icon(Icons.video_library_outlined),
            label: arabic ? 'المكتبة' : 'Library',
          ),
          NavigationDestination(
            key: const Key('mobile-home-nav-you'),
            icon: const Icon(Icons.account_circle_outlined),
            label: arabic ? 'أنت' : 'You',
          ),
        ],
      ),
      body: switch (_selectedTab) {
        0 => SafeArea(
        child: ListView(
          key: const Key('mobile-home-scroll-feed'),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
          children: [
            _IntroPanel(arabic: arabic),
            const SizedBox(height: 16),
            MobileHomeFeedPanel(
              arabic: arabic,
              onOpenArticles: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => VerifiedArticleLibrary(
                      arabic: arabic,
                      loginAction: _loginForArticleSave,
                      saveAction: const MobileAccountClient().saveArticle,
                      onViewSaved: (viewContext, bearer) =>
                          _openSavedAfterArticleSave(viewContext, bearer, arabic),
                    ),
                ),
              ),
              onOpenRunbooks: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => ReviewedRunbookLibrary(arabic: arabic),
                ),
              ),
              onOpenArticle: (slug) => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => MobileHomeArticleRoute(
                    arabic: arabic,
                    slug: slug,
                  ),
                ),
              ),
              onOpenProject: (slug) => _openVerifiedProject(context, slug, arabic),
              onOpenRunbook: (id) => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => ReviewedRunbookDetail(id: id, arabic: arabic),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('offline-runbooks-entry'),
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                leading: const Icon(Icons.menu_book_outlined, size: 30),
                title: Text(arabic ? 'الأدلة المتاحة دون إنترنت' : 'Offline runbooks'),
                subtitle: Text(arabic
                    ? 'اقرأ النسخة المرفقة مع التطبيق وتحقق من صلاحيتها'
                    : 'Read bundled guidance and check its verification status'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(builder: (_) => OfflineRunbookLibrary(arabic: arabic)),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('online-runbooks-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.fact_check_outlined, size: 30),
                title: Text(arabic ? 'الأدلة التقنية المنشورة'
                    : 'Verified online runbooks'),
                subtitle: Text(arabic
                    ? 'تصفح الأدلة المعتمدة من الخادم وأعد التحقق منها عند الفتح'
                    : 'Read reviewed server guides, rechecked when opened'),
                trailing: Icon(arabic
                    ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => ReviewedRunbookLibrary(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('ai-troubleshooter-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 12,
                ),
                leading: const Icon(Icons.auto_awesome_outlined, size: 30),
                title: Text(arabic
                    ? 'مساعد تشخيص الأعطال'
                    : 'AI Troubleshooter'),
                subtitle: Text(arabic
                    ? 'تشخيص مسجّل الدخول يوجّهك إلى أدلة منشورة ومراجعة فقط'
                    : 'Signed-in diagnosis that points only to reviewed published guides'),
                trailing: Icon(arabic
                    ? Icons.chevron_left
                    : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => AiTroubleshooterScreen(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('offline-solution-finder-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 12,
                ),
                leading: const Icon(Icons.manage_search_outlined, size: 30),
                title: Text(arabic
                    ? 'البحث عن حلول تقنية محلية'
                    : 'Offline solution finder'),
                subtitle: Text(arabic
                    ? 'بحث فعلي في الأدلة المرفقة دون إرسال بيانات أو ادعاء تشخيص AI'
                    : 'Search bundled guidance; no upload or claimed AI diagnosis'),
                trailing: Icon(arabic
                    ? Icons.chevron_left
                    : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => OfflineSolutionFinder(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('verified-articles-entry'),
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                leading: const Icon(Icons.article_outlined, size: 30),
                title: Text(arabic ? 'المقالات التقنية الموثقة' : 'Verified technical articles'),
                subtitle: Text(arabic
                    ? 'اقرأ المقالات المنشورة فقط، دون ادعاء نتائج AI'
                    : 'Read approved publications; no invented AI output'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => VerifiedArticleLibrary(
                      arabic: arabic,
                      loginAction: _loginForArticleSave,
                      saveAction: const MobileAccountClient().saveArticle,
                      onViewSaved: (viewContext, bearer) =>
                          _openSavedAfterArticleSave(viewContext, bearer, arabic),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('verified-learning-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.school_outlined, size: 30),
                title: Text(arabic ? 'مسارات التعلّم' : 'Learning paths'),
                subtitle: Text(arabic
                    ? 'استعرض الدروس المنشورة من المصدر المعتمد'
                    : 'Explore published lessons from the verified catalog'),
                trailing: Icon(arabic
                    ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => VerifiedLearningLibrary(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('interview-practice-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.record_voice_over_outlined, size: 30),
                title: Text(arabic ? 'تدريب المقابلات' : 'Interview practice'),
                subtitle: Text(arabic
                    ? 'أسئلة تقنية ومراجعة ذاتية خاصة دون تقييم آلي'
                    : 'Private technical answers with server review checklists'),
                trailing: Icon(arabic
                    ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => InterviewPracticeScreen(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('account-saved-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.bookmark_outline, size: 30),
                title: Text(arabic ? 'المحفوظات' : 'Saved resources'),
                subtitle: Text(arabic
                    ? 'سجل الدخول لفتح محفوظاتك الموثقة وإدارتها'
                    : 'Sign in to review and manage your verified saved items'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => MobileAccountJourney(
                      arabic: arabic,
                      initialSection: MobileAccountSection.saved,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('account-profile-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.person_outline, size: 30),
                title: Text(arabic ? 'الملف الشخصي' : 'Personal profile'),
                subtitle: Text(arabic
                    ? 'سجل الدخول لإدارة بريد الحساب والخصوصية والجلسة'
                    : 'Sign in to manage account email, privacy and session controls'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => MobileAccountJourney(
                      arabic: arabic,
                      initialSection: MobileAccountSection.profile,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                key: const Key('mobile-preferences-entry'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16, vertical: 12,
                ),
                leading: const Icon(Icons.tune_outlined, size: 30),
                title: Text(arabic ? 'التفضيلات' : 'Preferences'),
                subtitle: Text(arabic
                    ? 'اللغة والأدلة المحلية وخيارات الخصوصية المدعومة'
                    : 'Language, local guides and available privacy settings'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => MobilePreferencesScreen(
                      arabic: arabic,
                      onLanguageChanged: onSetLanguage ??
                          (_) => onToggleLanguage(),
                    ),
                  ),
                ),
              ),
            ),
            Card(
              child: ListTile(
                key: const Key('verified-github-entry'),
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                leading: const Icon(Icons.code_outlined, size: 30),
                title: Text(arabic ? 'مشاريعي على GitHub' : 'My GitHub'),
                subtitle: Text(arabic
                    ? 'استعرض المستودعات العامة التي تم التحقق منها فقط'
                    : 'Browse independently verified public repositories'),
                trailing: Icon(arabic ? Icons.chevron_left : Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => VerifiedGithubPortfolioScreen(arabic: arabic),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 28),
            Text(
              arabic ? 'استكشف المجالات التقنية' : 'Explore engineering topics',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
            ...CompanionHome._topics.map(
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
        1 => VerifiedLearningLibrary(arabic: arabic),
        2 => AiTroubleshooterScreen(arabic: arabic),
        3 => MobileAccountJourney(
          arabic: arabic,
          initialSection: MobileAccountSection.saved,
        ),
        4 => MobileAccountJourney(
          arabic: arabic,
          initialSection: MobileAccountSection.profile,
        ),
        _ => const SizedBox.shrink(),
      },
    );
  }
}

class _HomeCategoryChip extends StatelessWidget {
  const _HomeCategoryChip({
    required this.label,
    this.selected = false,
    this.onTap,
    super.key,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsetsDirectional.only(end: 8),
    child: ActionChip(
      onPressed: onTap,
      backgroundColor: selected ? const Color(0xff0f0f0f) : const Color(0xfff2f2f2),
      side: BorderSide.none,
      shape: const StadiumBorder(),
      label: Text(
        label,
        style: TextStyle(
          color: selected ? Colors.white : const Color(0xff0f0f0f),
          fontSize: 13,
          fontWeight: FontWeight.w600,
        ),
      ),
    ),
  );
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
