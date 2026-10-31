<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Waleed Atiya's engineering portfolio platform, in development.">
    <title>Waleed Atiya — Engineering Portfolio</title>
    <style>
        :root { color-scheme:light; --brand:#163B66; --accent:#00A999; --ink:#142E4D; --body:#315471; --surface:#F5F9FC; }
        *, *::before, *::after { box-sizing:border-box; }
        body { margin:0; background:var(--surface); color:var(--ink); font:16px/1.6 Inter,Arial,sans-serif; }
        main { min-height:100vh; display:grid; place-items:center; padding:32px 20px; }
        .panel { width:min(100%,760px); padding:clamp(24px,6vw,64px); background:#fff; border:1px solid #D3E3ED; border-radius:26px; box-shadow:0 16px 46px rgba(22,59,102,.07); }
        .eyebrow { color:var(--brand); font-weight:700; letter-spacing:.08em; text-transform:uppercase; font-size:13px; }
        h1 { margin:12px 0; font-size:clamp(36px,6vw,52px); line-height:1.15; }
        p { margin:0; max-width:60ch; color:var(--body); }
        .status { display:inline-flex; gap:10px; align-items:center; margin-top:28px; padding:9px 14px; border-radius:999px; background:#EAF5FB; color:var(--brand); font-weight:600; }
        .status::before { content:""; width:9px; height:9px; border-radius:50%; background:var(--accent); }
        @media(prefers-reduced-motion:reduce) { *,*::before,*::after { scroll-behavior:auto!important; } }
    </style>
</head>
<body>
<main><section class="panel" aria-labelledby="page-title">
    <span class="eyebrow">Engineering portfolio</span>
    <h1 id="page-title">Waleed Atiya</h1>
    <p>This website is being built using verified technical projects and approved source materials. Portfolio content, administration and AI features will appear after implementation and validation.</p>
    <div class="status" role="status">Platform foundation in development</div>
</section></main>
</body>
</html>
