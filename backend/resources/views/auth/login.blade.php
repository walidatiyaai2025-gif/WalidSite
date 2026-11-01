<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in · Waleed Site</title>
    <style>
        body { background:#F5F9FC; color:#142E4D; font:16px/1.6 Arial,sans-serif; margin:0; }
        main { min-height:100vh; display:grid; place-items:center; padding:24px; }
        form { width:min(100%,420px); padding:32px; border:1px solid #D3E3ED; border-radius:18px; background:#fff; }
        label { display:block; font-weight:600; margin:14px 0 6px; }
        input { width:100%; padding:12px; font:inherit; border-radius:8px; border:1px solid #97B4C8; }
        button { width:100%; margin-top:24px; padding:12px; border:0; background:#126DE1; color:#fff; font:inherit; font-weight:600; border-radius:8px; cursor:pointer; }
        a:focus-visible,button:focus-visible,input:focus-visible {outline:3px solid #00A999;outline-offset:2px;}
        .error { color:#A72330; } p { margin:12px 0 0; }
    </style>
    @include('partials.web-fonts')
</head>
<body><main><form action="{{ route('admin.login.submit') }}" method="post">
    @csrf
    <h1>Admin sign in</h1>
    <p>Authorized account required.</p>
    @error('email')<p role="alert" class="error">{{ $message }}</p>@enderror
    <label for="email">Email</label>
    <input id="email" type="email" name="email" autocomplete="username" required maxlength="254" value="{{ old('email') }}">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
</form></main></body>
</html>
