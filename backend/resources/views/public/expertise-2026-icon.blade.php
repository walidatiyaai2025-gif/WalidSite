{{-- Decorative vector icons scoped to WEB-03 only. --}}
<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
@if($symbol === 'target')
    <circle cx="24" cy="24" r="18"/><circle cx="24" cy="24" r="10"/><circle cx="24" cy="24" r="3"/><path d="M24 24l14-14m-5 0h5v5"/>
@elseif($symbol === 'database')
    <ellipse cx="24" cy="10" rx="17" ry="7"/><path d="M7 10v27c0 9 34 9 34 0V10M7 20c0 9 34 9 34 0M7 30c0 9 34 9 34 0"/>
@elseif($symbol === 'chip')
    <rect x="12" y="12" width="24" height="24" rx="3"/><path d="M17 7v5m8-5v5m8-5v5M17 36v5m8-5v5m8-5v5M7 18h5m-5 8h5m-5 8h5m24-16h5m-5 8h5m-5 8h5"/><text x="24" y="29" text-anchor="middle" font-family="Arial,sans-serif" font-size="12" font-weight="900" fill="currentColor" stroke="none">AI</text>
@elseif($symbol === 'code')
    <path d="M16 12L4 24l12 12m16-24 12 12-12 12m-10 5 6-34"/>
@elseif($symbol === 'phone')
    <rect x="13" y="4" width="22" height="40" rx="5"/><path d="M20 9h8M22 38h4"/>
@elseif($symbol === 'server')
    <rect x="7" y="6" width="34" height="16" rx="3"/><rect x="7" y="26" width="34" height="16" rx="3"/><path d="M14 14h1m5 0h1m-7 20h1m5 0h1m6-20h9m-9 20h9"/>
@elseif($symbol === 'layers')
    <path d="M24 4L44 15 24 26 4 15 24 4Zm-20 20 20 11 20-11m-40 9 20 11 20-11"/>
@elseif($symbol === 'landmark')
    <path d="M4 17L24 5l20 12H4Zm5 4h30M12 21v15m8-15v15m8-15v15m8-15v15M6 41h36M4 44h40"/>
@elseif($symbol === 'refresh')
    <path d="M40 19a17 17 0 0 0-30-5l-4 5m0-11v11h11M8 29a17 17 0 0 0 30 5l4-5m0 11V29H31"/>
@elseif($symbol === 'list')
    <path d="M6 9l3 3 5-6M20 10h23M6 25l3 3 5-6M20 26h23M6 40l3 3 5-6M20 41h23"/>
@elseif($symbol === 'check')
    <rect x="7" y="7" width="34" height="34" rx="8"/><path d="M14 25l7 7 14-16"/>
@elseif($symbol === 'users')
    <circle cx="17" cy="15" r="7"/><circle cx="33" cy="18" r="6"/><path d="M4 41v-7c0-9 9-13 16-13s16 4 16 13v7M31 26c8 0 13 5 13 13v2"/>
@elseif($symbol === 'rocket')
    <path d="M16 30c-5-5-5-12 0-17 6-6 19-8 27-8 0 8-2 21-8 27-5 5-12 5-17 0L16 30Zm10-9 4 4M15 34l-7 8m1-16L4 31m23 9-5 5"/>
@elseif($symbol === 'network')
    <rect x="18" y="4" width="12" height="10" rx="2"/><rect x="3" y="35" width="12" height="10" rx="2"/><rect x="33" y="35" width="12" height="10" rx="2"/><path d="M24 14v10m-15 0h30M9 24v11m30-11v11"/>
@elseif($symbol === 'search')
    <circle cx="20" cy="20" r="13"/><path d="m30 30 14 14M10 20h5l4-7 6 14 4-7h4"/>
@else
    <circle cx="24" cy="24" r="18"/><path d="M15 24h18"/>
@endif
</svg>
