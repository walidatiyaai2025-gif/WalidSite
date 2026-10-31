# Binding implementation choices
- Monorepo: `backend/` Laravel modular monolith, Blade+Livewire+Tailwind (server-rendered SEO public/admin), Laravel REST API; `mobile/` Flutter Android/iOS; `design/` and `assets/` shared source references.
- Laravel owns frontend templates and assets; there is NO independent SPA/web service to invent.
- JS packaging is `backend/package.json` managed with Vite. Unit JS tests use Vitest with `npm test -- --run`; PHP tests use `php artisan test`; Flutter analysis and tests use Flutter SDK.
- PHP/Flutter/Laravel version pins are selected and recorded in task T001 after checking compatible supported releases and hosting, not silently guessed or left to worker preference.
- One domain, one API `/api/v1`, session-auth/CSRF for admin, token auth for mobile; do not use browser-held AI secrets.
- No Redis dependency assumed on cPanel; durable database jobs and short scheduler ticks, optional Redis only after runtime verification.
