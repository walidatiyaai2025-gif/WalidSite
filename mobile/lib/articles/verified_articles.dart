import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

/// Reads ONLY published, evidence-checked articles from the existing public API.
/// No AI diagnosis, authentication or remote caching is implied by this screen.
class VerifiedArticle {
  const VerifiedArticle({
    required this.slug,
    required this.title,
    required this.body,
    required this.citations,
  });

  final String slug;
  final String title;
  final String body;
  final List<String> citations;

  static List<VerifiedArticle> parseList(String json) {
    final value = jsonDecode(json);
    if (value is! Map<String, dynamic> || value['data'] is! List) {
      throw const FormatException('Invalid article response');
    }

    final raw = value['data'] as List<dynamic>;
    if (raw.length > 12) {
      throw const FormatException('Unbounded article response');
    }

    final articles = <VerifiedArticle>[];
    final seen = <String>{};
    for (final item in raw) {
      if (item is! Map<String, dynamic>) {
        throw const FormatException('Invalid article');
      }
      final slug = item['slug'];
      final title = item['title'];
      final body = item['body_markdown'];
      final citations = item['citations'];
      if (slug is! String ||
          !RegExp(r'^[a-zA-Z0-9-]{1,180}$').hasMatch(slug) ||
          !seen.add(slug) ||
          title is! String ||
          title.trim().isEmpty ||
          title.length > 250 ||
          body is! String ||
          body.trim().isEmpty ||
          body.length > 60000 ||
          item['tested_status'] != 'documented' ||
          item['published_at'] is! String ||
          DateTime.tryParse(item['published_at'] as String) == null ||
          citations is! List ||
          citations.isEmpty ||
          citations.length > 20) {
        throw const FormatException('Unverified article in response');
      }
      final validatedCitations = <String>[];
      for (final citation in citations) {
        if (citation is! String || citation.length > 2048) {
          throw const FormatException('Unsafe citation');
        }
        // Treat API citations as untrusted input even when upstream says
        // the article was verified. Query strings may carry signed links,
        // API credentials or private redirects; never expose them in UI.
        final url = Uri.tryParse(citation);
        final host = url?.host.toLowerCase() ?? '';
        const privateSuffixes = [
          'localhost', 'local', 'internal', 'test', 'invalid', 'onion',
        ];
        if (url == null ||
            url.scheme != 'https' ||
            url.userInfo.isNotEmpty ||
            url.hasQuery ||
            url.hasFragment ||
            (url.hasPort && url.port != 443) ||
            !RegExp(
              r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}
        validatedCitations.add(citation);
      }
      articles.add(VerifiedArticle(
        slug: slug,
        title: title,
        body: body,
        citations: List.unmodifiable(validatedCitations),
      ));
    }
    return List.unmodifiable(articles);
  }
}

class VerifiedArticleReader {
  const VerifiedArticleReader();

  // Explicit build-time deployment configuration, never an invented endpoint.
  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const _limit = 2 * 1024 * 1024;

  static Uri? configuredOrigin() {
    final uri = Uri.tryParse(apiOrigin);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        (uri.path.isNotEmpty && uri.path != '/')) {
      return null;
    }
    return uri;
  }

  Future<List<VerifiedArticle>> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );

    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Article service unavailable');
      }

      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > _limit) {
          throw const FormatException('Article response exceeds size limit');
        }
        buffer.add(chunk);
      }
      return VerifiedArticle.parseList(utf8.decode(buffer.takeBytes()));
    } finally {
      client.close(force: true);
    }
  }
}

class VerifiedArticleLibrary extends StatefulWidget {
  const VerifiedArticleLibrary({required this.arabic, this.loader, super.key});

  final bool arabic;
  final Future<List<VerifiedArticle>> Function()? loader;

  @override
  State<VerifiedArticleLibrary> createState() => _VerifiedArticleLibraryState();
}

class _VerifiedArticleLibraryState extends State<VerifiedArticleLibrary> {
  late Future<List<VerifiedArticle>> _articles;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  void _refresh() {
    final loader = widget.loader;
    _articles = loader != null
        ? loader()
        : VerifiedArticleReader.configuredOrigin() == null
            ? Future<List<VerifiedArticle>>.value(const <VerifiedArticle>[])
            : const VerifiedArticleReader().load(widget.arabic);
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    final configured = widget.loader != null ||
        VerifiedArticleReader.configuredOrigin() != null;
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'المقالات التقنية' : 'Technical articles')),
      body: !configured
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar
                      ? 'خادم المقالات غير مهيأ في هذا الإصدار. لا توجد بيانات محلية بديلة.'
                      : 'Article server is not configured for this build. No offline articles are available.',
                  textAlign: TextAlign.center,
                ),
              ),
            )
          : FutureBuilder<List<VerifiedArticle>>(
              future: _articles,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar
                            ? 'تعذّر تحميل المقالات الموثقة. تحقق من الاتصال.'
                            : 'Verified articles are unavailable. Check your connection.'),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: () => setState(_refresh),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ),
                  );
                }
                final articles = snapshot.data ?? const <VerifiedArticle>[];
                if (articles.isEmpty) {
                  return Center(child: Text(ar
                      ? 'لا توجد مقالات منشورة وموثقة حاليًا.'
                      : 'No source-verified articles have been published yet.'));
                }
                return ListView.builder(
                  itemCount: articles.length,
                  padding: const EdgeInsets.all(16),
                  itemBuilder: (context, index) {
                    final article = articles[index];
                    return Card(
                      child: ListTile(
                        key: Key('article-' + article.slug),
                        title: Text(article.title),
                        subtitle: Text(ar
                            ? 'موثق بمراجع منشورة — لا يُثبت الاختبار العملي'
                            : 'Published citations — not a claim of hands-on testing'),
                        trailing: Icon(ar ? Icons.chevron_left : Icons.chevron_right),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => VerifiedArticleDetail(
                              article: article, arabic: ar,
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
    );
  }
}

class VerifiedArticleDetail extends StatelessWidget {
  const VerifiedArticleDetail({
    required this.article,
    required this.arabic,
    super.key,
  });

  final VerifiedArticle article;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(article.title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            arabic
                ? 'محتوى منشور موثق بمراجع؛ ليس تقرير اختبار عملي.'
                : 'Published source-verified reading, not a tested execution report.',
          ),
          const SizedBox(height: 16),
          SelectableText(article.body, key: const Key('verified-article-body')),
          const SizedBox(height: 24),
          Text(arabic ? 'المراجع' : 'Citations',
              style: Theme.of(context).textTheme.titleLarge),
          for (final citation in article.citations)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: SelectableText(citation),
            ),
        ],
      ),
    );
  }
}
,
            ).hasMatch(host) ||
            privateSuffixes.any(
              (suffix) => host == suffix || host.endsWith('.$suffix'),
            ) ||
            InternetAddress.tryParse(host) != null) {
          throw const FormatException('Unsafe citation');
        }
        validatedCitations.add(citation);
      }
      articles.add(VerifiedArticle(
        slug: slug,
        title: title,
        body: body,
        citations: List.unmodifiable(validatedCitations),
      ));
    }
    return List.unmodifiable(articles);
  }
}

class VerifiedArticleReader {
  const VerifiedArticleReader();

  // Explicit build-time deployment configuration, never an invented endpoint.
  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const _limit = 2 * 1024 * 1024;

  static Uri? configuredOrigin() {
    final uri = Uri.tryParse(apiOrigin);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        (uri.path.isNotEmpty && uri.path != '/')) {
      return null;
    }
    return uri;
  }

  Future<List<VerifiedArticle>> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );

    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Article service unavailable');
      }

      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > _limit) {
          throw const FormatException('Article response exceeds size limit');
        }
        buffer.add(chunk);
      }
      return VerifiedArticle.parseList(utf8.decode(buffer.takeBytes()));
    } finally {
      client.close(force: true);
    }
  }
}

class VerifiedArticleLibrary extends StatefulWidget {
  const VerifiedArticleLibrary({required this.arabic, this.loader, super.key});

  final bool arabic;
  final Future<List<VerifiedArticle>> Function()? loader;

  @override
  State<VerifiedArticleLibrary> createState() => _VerifiedArticleLibraryState();
}

class _VerifiedArticleLibraryState extends State<VerifiedArticleLibrary> {
  late Future<List<VerifiedArticle>> _articles;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  void _refresh() {
    final loader = widget.loader;
    _articles = loader != null
        ? loader()
        : VerifiedArticleReader.configuredOrigin() == null
            ? Future<List<VerifiedArticle>>.value(const <VerifiedArticle>[])
            : const VerifiedArticleReader().load(widget.arabic);
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    final configured = widget.loader != null ||
        VerifiedArticleReader.configuredOrigin() != null;
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'المقالات التقنية' : 'Technical articles')),
      body: !configured
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar
                      ? 'خادم المقالات غير مهيأ في هذا الإصدار. لا توجد بيانات محلية بديلة.'
                      : 'Article server is not configured for this build. No offline articles are available.',
                  textAlign: TextAlign.center,
                ),
              ),
            )
          : FutureBuilder<List<VerifiedArticle>>(
              future: _articles,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar
                            ? 'تعذّر تحميل المقالات الموثقة. تحقق من الاتصال.'
                            : 'Verified articles are unavailable. Check your connection.'),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: () => setState(_refresh),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ),
                  );
                }
                final articles = snapshot.data ?? const <VerifiedArticle>[];
                if (articles.isEmpty) {
                  return Center(child: Text(ar
                      ? 'لا توجد مقالات منشورة وموثقة حاليًا.'
                      : 'No source-verified articles have been published yet.'));
                }
                return ListView.builder(
                  itemCount: articles.length,
                  padding: const EdgeInsets.all(16),
                  itemBuilder: (context, index) {
                    final article = articles[index];
                    return Card(
                      child: ListTile(
                        key: Key('article-' + article.slug),
                        title: Text(article.title),
                        subtitle: Text(ar
                            ? 'موثق بمراجع منشورة — لا يُثبت الاختبار العملي'
                            : 'Published citations — not a claim of hands-on testing'),
                        trailing: Icon(ar ? Icons.chevron_left : Icons.chevron_right),
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => VerifiedArticleDetail(
                              article: article, arabic: ar,
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
    );
  }
}

class VerifiedArticleDetail extends StatelessWidget {
  const VerifiedArticleDetail({
    required this.article,
    required this.arabic,
    super.key,
  });

  final VerifiedArticle article;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(article.title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            arabic
                ? 'محتوى منشور موثق بمراجع؛ ليس تقرير اختبار عملي.'
                : 'Published source-verified reading, not a tested execution report.',
          ),
          const SizedBox(height: 16),
          SelectableText(article.body, key: const Key('verified-article-body')),
          const SizedBox(height: 24),
          Text(arabic ? 'المراجع' : 'Citations',
              style: Theme.of(context).textTheme.titleLarge),
          for (final citation in article.citations)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: SelectableText(citation),
            ),
        ],
      ),
    );
  }
}
