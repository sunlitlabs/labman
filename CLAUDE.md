# LabMan

Public, self-hostable home-lab manager (Laravel 12 + FrankenPHP). These rules come first; the shared baseline follows.

- **Public repository**: placeholders only (`example.com`/`example.org`/`example.dev`, RFC 5737 addresses). Nothing about the developer's own lab: no real hostnames, IPs, domains or names, in code, tests, docs or commits.
- **Minimal dependencies**: `laravel/framework`, `symfony/yaml` and `sunlitlabs/foundation` only. MCP, Nginx Proxy Manager, Semaphore, AdGuard and git are implemented in the app. Ask before adding a package.
- **Tests run in Docker**: `docker build --target test -t labman-test .`, then from PowerShell in the repo `docker run --rm -v "${PWD}:/app" -w /app labman-test vendor/bin/phpunit`. Git Bash mangles `-w`. Run the app with `docker compose up -d --build`, then open http://localhost:8080.
- **Delete rule**: LabMan deletes only what it created and recorded (managed hosts, mirrors, AdGuard rewrites), and only as a ticked, previewed item.
- Branch: work on `feature/…` branches and fast-forward `master` once the suite passes.

<!-- sunlitlabs/foundation:start (managed by `php artisan foundation:sync`; edit outside this block) -->
# Sunlit Labs baseline (from sunlitlabs/foundation)

This app is built on **sunlitlabs/foundation**, the shared baseline for Sunlit Labs Laravel apps. This block is synced from the package on every `composer update`; put app-specific rules outside it. Where the Laravel Boost guidelines conflict with this block, this block wins.

## Use the foundation before writing shared code

Before building any of these, use the package version (namespace `Sunlit\Foundation`). If it almost fits, improve the package instead of forking it in the app (see the `sunlit-foundation` skill):

- **Site admins**: `Admins` (`config('foundation.admin.column')`, or `Admins::resolveUsing()`), the `admin` gate, the `admin` route middleware (404 for non-admins), and the `HasAdminFlag` trait.
- **First-run setup**: `SetupState` + the `foundation.setup` middleware. "Complete" is cached forever once true and never cached while false.
- **Install-wide settings**: `SettingsStore` (key/value table, JSON or raw values, config defaults). Use it for anything an admin changes at runtime, because hosted platforms cannot rewrite `.env`.
- **Extensions**: `Extensions\HookRegistry`, `ExtensionHooks`, `SettingDefinition` for per-tenant modules and plugins.
- **QR codes**: `Support\QrCode::svg()`. **Registration modes**: `Registration\RegistrationMode`.
- **Security headers**: the `foundation.headers` middleware. **Proxies**: `Foundation::trustProxies($middleware)` in `bootstrap/app.php`, reading `TRUSTED_PROXIES`.
- **Health**: `php artisan foundation:doctor`. Apps add checks with `app(Doctor::class)->check(...)`.
- **Docker**: the entrypoint sources `vendor/sunlitlabs/foundation/docker/lib.sh`. The Caddyfile and base php.ini are copied from the package.

## Workflow

- Work on a named branch per chunk (`feature/`, `fix/`, `chore/`, `refactor/`), merge into `dev`, and delete the branch. When a chunk is stable, merge `dev` into `main` (and `master` where it exists) and push. Do this without being asked.
- Commit and push as the last step of every task. Commit only when the test suite passes. Never commit `.env`, keys or tokens.
- Ask whether a new repository should live under the `sunlitlabs` organization or a personal account before running `gh repo create`.
- Write and update documentation without being asked: README, `DEPLOYMENT.md`, `docs/ARCHITECTURE.md` and guides for module authors. Update docs in the same change as the code. This replaces the Boost rule "only create documentation files if explicitly requested".
- Track feature work in the feature-tracker artifact when the app has one (see the `feature-tracker` skill). Record progress there as you go.

## Code conventions

- Laravel 12/13 and PHPUnit (not Pest). Use Artisan generators, give each model a factory, and use `#[Fillable]`/`#[Hidden]` attributes on models.
- Every feature ships with tests. Fake outside HTTP services (`Http::fake`), and never call real services from tests.
- Make new capabilities opt-in: per-tenant modules or plugins enabled in settings, so nobody gets features they didn't ask for.
- Placeholders only: use `example.test`/`example.com` and the RFC 5737 addresses (192.0.2.x, 198.51.100.x, 203.0.113.x). Never put a real person's address, hostnames, IPs or names in code, tests, seeders or docs.
- Demo data comes from a `DemoSeeder` with `@example.test` users and the password `password`.

## Design

- Mobile-first: phones are a primary device (DMs, homestead chores, couch audiobooks). Use real tap targets and no hover-only controls.
- Tailwind with a small token set per app: one brand color and a neutral (stone/gray). Use components (`x-…`) over repeated class soup. Give empty states a next action.
- Give the UI some character, but clarity comes first. Use page titles, plain-language labels, toasts for saved/failed, and undo where deletes are cheap.
- Check UI changes in a real browser before calling them done (headless Chrome via Playwright works on this machine). PHP tests do not catch JS errors.

## MCP

- The Laravel Boost MCP server (`php artisan boost:mcp`) is configured in `.mcp.json` when Boost is installed. Use its docs search and tinker/database tools before guessing at APIs.
- Apps that expose their own MCP server put it at `POST /mcp`, use bearer tokens with read and write/admin scopes, and only offer an extension's tools where that extension is enabled.

## Environment (the developer's Windows machine)

- PHP and Composer come from Herd, so run them from PowerShell, not Git Bash. Docker Desktop is available. Apps without local PHP run tests in their Docker `test` target.
- PowerShell gotchas:
  - `Set-Content -Encoding utf8` writes a BOM that breaks PHP. Use the Write tool or `[IO.File]::WriteAllText` with `UTF8Encoding($false)`.
  - `>` inside `tinker --execute` becomes a redirect. Put tinker code in a scratch file instead.
  - `git commit -F -` with a here-string fails. Write the message to a file and pass `-F <path>`.
- Heredocs with apostrophes and `sed` edits that contain PHP namespaces fail silently. Use the Edit/Write tools for code.
- Don't use the connected Chrome (Claude in Chrome) unless asked, because it is the developer's work browser. Use headless Playwright for browser checks.
<!-- sunlitlabs/foundation:end -->
