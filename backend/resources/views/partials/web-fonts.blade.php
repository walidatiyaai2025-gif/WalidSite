{{-- Shared public + admin typography. Inter covers Latin; Tajawal supplies Arabic glyphs. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Tajawal:wght@400;500;700;800&amp;display=swap">
<style>
    :root { --wa-font-ui: "Inter", "Tajawal", Arial, sans-serif; }
    /* Latin uses Inter; Arabic characters fall through to Tajawal even in mixed text. */
    html body, html:lang(ar) body, html[lang="ar"] body,
    input, select, textarea, button { font-family: var(--wa-font-ui); }
    /* Preserve legibility of source code and commands in technical articles. */
    pre, code, kbd, samp {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
</style>
