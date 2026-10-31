import 'package:flutter/material.dart';

void main() => runApp(const WaleedTechApp());

/// Bootstrap shell, intentionally free of fabricated content or fake actions.
/// Product features are delivered only after their canonical issues pass QA.
class WaleedTechApp extends StatelessWidget {
  const WaleedTechApp({super.key});

  static const Color _ink = Color(0xFF10213E);
  static const Color _accent = Color(0xFF00C3A3);
  static const Color _canvas = Color(0xFFF8FAFC);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Waleed Tech Companion',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        scaffoldBackgroundColor: _canvas,
        colorScheme: ColorScheme.fromSeed(
          seedColor: _accent,
          primary: _ink,
          surface: Colors.white,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: _canvas,
          foregroundColor: _ink,
          centerTitle: false,
        ),
        cardTheme: const CardThemeData(
          color: Colors.white,
          margin: EdgeInsets.zero,
        ),
      ),
      home: const CompanionHome(),
    );
  }
}

class CompanionHome extends StatelessWidget {
  const CompanionHome({super.key});

  static const List<_Topic> _topics = [
    _Topic(
      title: 'Database Engineering',
      subtitle: 'SQL Server, reliability and performance',
      icon: Icons.storage_outlined,
    ),
    _Topic(
      title: 'Software Engineering',
      subtitle: 'Laravel, APIs and mobile architecture',
      icon: Icons.code_outlined,
    ),
    _Topic(
      title: 'AI Engineering',
      subtitle: 'Provider routing and reliable automation',
      icon: Icons.auto_awesome_outlined,
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          'Waleed Tech Companion',
          style: TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
        ),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
          children: [
            const _IntroPanel(),
            const SizedBox(height: 28),
            Text(
              'Explore engineering topics',
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
                      topic.title,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    subtitle: Text(topic.subtitle),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => TopicOverview(topic: topic),
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
  const _IntroPanel();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: const LinearGradient(
          colors: [Color(0xFF10213E), Color(0xFF165D7C)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'W',
            style: TextStyle(
              color: Color(0xFF00C3A3),
              fontSize: 42,
              fontWeight: FontWeight.w900,
            ),
          ),
          SizedBox(height: 16),
          Text(
            'Practical tools for developers',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 25,
            ),
          ),
          SizedBox(height: 8),
          Text(
            'A trustworthy knowledge hub and AI-assisted toolbox. '
            'New tools will appear only after implementation and verification.',
            style: TextStyle(
              color: Color(0xFFE5EFF4),
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
    required this.title,
    required this.subtitle,
    required this.icon,
  });

  final String title;
  final String subtitle;
  final IconData icon;
}

class TopicOverview extends StatelessWidget {
  const TopicOverview({required this.topic, super.key});

  final _Topic topic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(topic.title)),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(topic.icon, size: 42, color: const Color(0xFF008B86)),
              const SizedBox(height: 16),
              Text(
                topic.subtitle,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 20),
              const Text(
                'Verified guides and interactive tools for this topic '
                'will be published as their implementation is completed.',
              ),
            ],
          ),
        ),
      ),
    );
  }
}
