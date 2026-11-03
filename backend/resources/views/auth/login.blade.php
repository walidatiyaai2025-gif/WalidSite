@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($installationComplete = in_array(session('status'), [
    'Installation complete. Sign in with your owner email.',
    'اكتمل التثبيت. سجّل الدخول باستخدام بريد المالك.',
], true))
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $locale === 'ar' ? 'دخول إدارة وليد سايت' : 'WalidSite admin sign in' }}</title>
    <style>
        :root{color-scheme:light;--ink:#142e4d;--link:#126de1;--border:#d3e3ed}
        *{box-sizing:border-box}body{background:#f5f9fc;color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif;margin:0}
        :lang(ar) body{font-family:Tajawal,Arial,sans-serif}
        main{min-height:100vh;display:grid;place-items:center;padding:24px}
        .sign-in{width:min(100%,480px);padding:clamp(22px,5vw,36px);border:1px solid var(--border);border-radius:18px;background:#fff}
        h1{font-size:clamp(26px,5vw,35px);line-height:1.25;margin:0 0 12px}
        .top-links{display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:24px}
        a{color:var(--link);display:inline-flex;align-items:center;min-height:44px;font-weight:700}
        label{display:block;font-weight:700;margin:14px 0 6px}
        input{width:100%;min-height:46px;padding:12px;font:inherit;border-radius:8px;border:1px solid #97b4c8}
        button{width:100%;min-height:46px;margin-top:24px;padding:12px;border:0;background:var(--link);color:#fff;font:inherit;font-weight:700;border-radius:8px;cursor:pointer}
        a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #00877c;outline-offset:3px}
        .error{color:#a01826}.notice{background:#e8f6ed;color:#145b3a;border:1px solid #82ba95;border-radius:12px;padding:14px 16px;margin:16px 0}
        .notice p{margin:0}.notice a{color:#145b3a}p{margin:12px 0 0}
    </style>
    @include('partials.web-fonts')
</head>
<body><main>
<form class="sign-in" action="{{ route('admin.login.submit', ['lang' => $locale]) }}" method="post" aria-labelledby="admin-login-title">
    @csrf
    <nav class="top-links" aria-label="{{ $locale === 'ar' ? 'روابط صفحة الدخول' : 'Sign-in navigation' }}">
        <a href="{{ route('home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض الموقع العام' : 'View public website' }}</a>
        <a href="{{ route('login', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
    </nav>
    <h1 id="admin-login-title">{{ $locale === 'ar' ? 'تسجيل دخول الإدارة' : 'Admin sign in' }}</h1>
    <p>{{ $locale === 'ar' ? 'مخصص للحسابات المخوّلة فقط.' : 'Authorized accounts only.' }}</p>
    @if($installationComplete)
        <div class="notice" role="status">
            <p>{{ $locale === 'ar'
                ? 'اكتمل تثبيت الموقع وتفعيل حساب المالك. سجّل الدخول باستخدام بريده المعتمد.'
                : 'Installation complete. Sign in using the approved owner email.' }}</p>
            <a href="{{ route('home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'فتح الموقع المنشور' : 'Open public website' }}</a>
        </div>
    @elseif(session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    @error('email')<p role="alert" class="error">{{ $message }}</p>@enderror
    @error('password')<p role="alert" class="error">{{ $message }}</p>@enderror
    <label for="email">{{ $locale === 'ar' ? 'البريد الإلكتروني' : 'Email' }}</label>
    <input id="email" type="email" name="email" autocomplete="username" required maxlength="254" value="{{ old('email') }}">
    <label for="password">{{ $locale === 'ar' ? 'كلمة المرور' : 'Password' }}</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">{{ $locale === 'ar' ? 'تسجيل الدخول' : 'Sign in' }}</button>
</form>
</main></body>
</html>
