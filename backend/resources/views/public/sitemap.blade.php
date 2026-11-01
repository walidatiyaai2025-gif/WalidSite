{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach($entries as $entry)
    <url>
        <loc>{{ $entry['url'] }}</loc>
        <xhtml:link rel="alternate" hreflang="en" href="{{ $entry['en'] }}" />
        <xhtml:link rel="alternate" hreflang="ar" href="{{ $entry['ar'] }}" />
    </url>
@endforeach
</urlset>
