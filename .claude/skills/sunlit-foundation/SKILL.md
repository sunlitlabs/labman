---
name: sunlit-foundation
description: Use when touching admin access, first-run setup, install-wide settings, module/plugin hooks, QR codes, security headers, trusted proxies, Docker images/entrypoints, or health checks in a Sunlit Labs app, and whenever code you are about to write would be useful in more than one app. Explains what sunlitlabs/foundation provides and how to extend the package instead of duplicating it.
---

<!-- Managed by sunlitlabs/foundation (php artisan foundation:sync). Do not edit in the app. -->

# sunlitlabs/foundation

The package lives at https://github.com/sunlitlabs/foundation (public). Locally it is at `C:\Users\xtrao\Development\Laravel\foundation`. Apps require it as `sunlitlabs/foundation: ^1.0` through a `vcs` repository entry in `composer.json`.

## What it provides

| Need | Use |
|---|---|
| Is this user a site admin? | `app(Admins::class)->check($user)`, `Gate::allows('admin')`, `$user->isAdmin()` (`HasAdminFlag`) |
| Admin-only routes | `->middleware('admin')`: returns 404 to non-admins (`foundation.admin.deny_status`) |
| Different admin rule | `app(Admins::class)->resolveUsing(fn ($user) => $user->role === 'admin')` in a provider |
| First-run wizard | `foundation.setup` middleware on the web group, plus your own `/setup` page that creates the first admin. Call `SetupState::markComplete()` after creating it. `completeUsing()` changes the rule. |
| Runtime settings | `app(SettingsStore::class)->get/set/many/forget/flush()`. Configure the table, encoding and defaults in `config/foundation.php`. |
| Modules or plugins per tenant | Extend `Extensions\HookRegistry`/`ExtensionHooks`. Give it `resolveEnabledUsing(fn ($scope, $key) => …)`. Use `SettingDefinition` for per-tenant settings. |
| QR code | `Support\QrCode::svg($url)` (needs `bacon/bacon-qr-code`) |
| Headers / proxies | the `foundation.headers` middleware with `foundation.headers.csp`; `Foundation::trustProxies($middleware)` |
| Health | `php artisan foundation:doctor [--json]`; add checks with `app(Doctor::class)->check('Name', fn () => true\|false\|'warning')` |
| Docker | Source `docker/lib.sh` in `deploy/entrypoint.sh` (`fdn_boot`, `fdn_optimize`, `fdn_queue_worker`, `fdn_scheduler`, `fdn_vapid_keys`, `fdn_serve`). Copy the Caddyfile and php.ini from `vendor/sunlitlabs/foundation/docker/`. |
| Claude files | `php artisan foundation:sync` (runs after `composer update`); `--check` for CI |

## Changing the package

When a change belongs to more than one app:

1. Edit the package checkout (`Laravel\foundation`) on a `feature/…` branch. Add tests under `tests/` and run `vendor/bin/phpunit` there.
2. Keep changes backwards compatible within the major version. Every new behaviour is opt-in or defaulted to what apps already do.
3. Update `README.md` and `CHANGELOG.md`, merge to `main`, then tag the next semver (`git tag v1.x.y && git push --tags`).
4. In each app, run `composer update sunlitlabs/foundation`. Dependabot also opens a PR daily. Then run the app's tests.

To try an unreleased package change in an app, add a `path` repository pointing at `../foundation` above the `vcs` entry, run `composer update sunlitlabs/foundation`, and remove the entry before committing.

Never copy package code into an app to tweak it. Add a hook, config key or resolver to the package instead.
