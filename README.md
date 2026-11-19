# LabMan

A self-hostable **heads-up display for a home lab**. Two pages, one glance:

- **Services**: name, domain, backend address and live health. Click one to open it (public URL, else backend URL).
- **Servers**: name, domain, address and live health. Click one to **copy its SSH command** (`ssh admin@192.0.2.5`).

Plus a filter box (press `/`), a status summary, light/dark theme that follows your OS, and a layout that works down to phone width. Laravel 12, plain Blade, no JavaScript framework, no build step.

> Status: v0.1. Configuration is done in the admin UI or a YAML file. Nginx Proxy Manager integration is on the [roadmap](#roadmap), not built yet.

## Quick start

You need Docker with the Compose plugin.

```sh
git clone <this repository> labman
cd labman
docker compose up -d --build
```

Open <http://localhost:8080>. A fresh install always redirects to the **setup wizard**, where you create the admin account, set the site title, choose the registration mode and who can see which screen, and optionally import a YAML file. There are no default credentials.

Everything that must survive upgrades lives in the `labman-data` volume (`/data`): the SQLite database, the generated application key, logs and sessions. Rebuild or recreate the container freely.

```sh
docker compose logs -f labman     # health-check rounds are logged here
docker compose down               # stop, keep your data
docker compose down -v            # stop and DELETE all data (back to the wizard)
```

### Plain HTTP and TLS

The container speaks plain HTTP on port 8080 and does not manage certificates. Put a reverse proxy in front for HTTPS and set `LABMAN_TRUSTED_PROXIES` to the proxy's address (or `*` if the container is only reachable through it) so LabMan sees the real scheme and client IP.

The copy button works over plain HTTP too: `navigator.clipboard` only exists in secure contexts, so LabMan falls back to a hidden textarea and `document.execCommand('copy')`.

## Configuration

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `LABMAN_SETUP_TOKEN` | unset | If set, the setup wizard also demands this value. Use it whenever the port is reachable by anyone you do not trust. |
| `LABMAN_TRUSTED_PROXIES` | unset | Comma separated proxy IPs/CIDRs, or `*`. |
| `APP_KEY` | generated | Normally leave unset: the entrypoint creates one in `/data/app.key` on first start (mode 600). Setting it overrides the file. |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql`. |
| `DB_DATABASE` | `/data/database.sqlite` | SQLite file path, or the database name for MySQL/PostgreSQL. |
| `DB_HOST` `DB_PORT` `DB_USERNAME` `DB_PASSWORD` | unset | For MySQL/PostgreSQL. |
| `LARAVEL_STORAGE_PATH` | `/data/storage` | Laravel's storage directory (logs, sessions, compiled views). |
| `LOG_LEVEL` | `warning` | Logs go to stderr (`docker logs`). |

SQLite runs in WAL mode with a 5 s busy timeout so the web process and the monitor can share the file. See `.env.example` for a copy-paste template (placeholders only).

### Settings (admin UI or YAML)

| Setting | Meaning |
|---|---|
| title | Site title. |
| check_interval | Seconds between health-check rounds, minimum 5 (default 30). |
| timeout | Per-check timeout, 0.5 to 60 seconds (default 4). |
| ssh_user | Default user for Copy SSH. |
| ssh_target | `address` or `domain`: which name the SSH command connects to (domain only if the server has one). |

Registration mode (off / open / open with admin approval) and per-screen visibility are admin-UI/setup-wizard settings and deliberately not part of the YAML file, so importing a file can never loosen access.

### YAML import / export

Servers, services and settings can be managed as a file. Import upserts by `name` (add `--replace`, or tick "Replace", to also delete anything not in the file). Validation is all-or-nothing: if anything is wrong, nothing is imported and every problem is listed.

```sh
# put a file in the data volume, or mount one, then:
docker compose exec labman php artisan labman:import /data/labman.yml
docker compose exec labman php artisan labman:import /data/labman.yml --replace
docker compose exec labman php artisan labman:export /data/labman.yml
```

The same import/export is available under **Settings** in the UI and in the setup wizard. A complete, commented example with placeholder data is in [`labman.example.yml`](labman.example.yml):

```yaml
settings:
  title: LabMan          # site title
  check_interval: 30     # seconds, minimum 5
  timeout: 4             # seconds, 0.5-60
  ssh_user: admin        # default user for Copy SSH
  ssh_target: address    # "address" or "domain"
servers:
  - name: nas
    address: 192.0.2.10  # IP or hostname checked
    domain: nas.example.com
    group: Storage
    ports: [445]         # extra TCP ports to check besides SSH
    ssh_user: deploy     # overrides settings.ssh_user
    ssh_port: 22
    notes: ""
services:
  - name: Wiki
    domain: wiki.example.com
    server: docker-1     # a server name, OR use address (exactly one of the two)
    port: 3000           # default 80, or 443 when scheme is https
    scheme: http         # http | https
    path: /              # must start with /
    group: Apps
    expect: 200-399      # healthy status range: 200, 200-499, 401 ...
    verify_tls: true     # applies to the public (domain) check only
    public_check: true
    websockets: false    # informational for now
    notes: ""
```

Messages number entries from 1 (`servers[1]: 'name' is required`) and name the entry once it has a name (`server 'nas': port 99999 is outside 1-65535`).

## How health checks work

Checks run in the background, never when a page loads. `php artisan labman:monitor` is a resident loop started by the container entrypoint (and restarted if it exits); it re-reads the settings every round, so changing the interval needs no restart. Results are stored in the database; the pages render what is stored and the browser polls a small JSON endpoint (`/status`) every interval.

- **Server**: a TCP connect to the SSH port (default 22) plus each extra port. `up` = all connect, `degraded` = some, `down` = none, `unknown` = not checked yet.
- **Service**: an HTTP(S) GET to `scheme://address:port/path` (address = the linked server's address, or the service's own). Backend TLS is **not** verified and redirects are **not** followed, so a 301/302 counts as "it answered". Healthy means the status is inside `expect` (default `200-399`). If the service has a domain and `public_check` is on, a second GET goes to `https://<domain><path>` **with** certificate verification (unless `verify_tls: false`).
  - `down` if the backend fails, `degraded` if the backend is fine but the public check fails, otherwise `up`. A service with no address is `down` ("no address").
- Each check records a label (`backend`, `public`, `ssh :22`, `tcp :445`), pass/fail, milliseconds and a detail such as `HTTP 200`, `HTTP 500 (expected 200-399)`, `timed out`, `connection refused`, `certificate: ...`.
- All checks in a round run concurrently (non-blocking sockets with `stream_select` for TCP, Laravel's `Http::pool` for HTTP), each bounded by the timeout, so a dead or slow host costs one timeout for the whole round, not one per host. A failing round is logged and the loop carries on.
- Limitation: if a host is given by *name*, the DNS lookup happens before the timeout applies and is bounded by your resolver.
- Stored results older than three intervals (minimum 60 s) are shown as `(stale)`, so a stopped monitor is visible.

## Roles and visibility

Three kinds of visitor: **guest**, **user**, **admin**. For each of the four screens (Services, Servers, Settings, Users) an admin chooses who may *see* it: everyone, signed-in users, or admins only. Defaults: Services and Servers = signed-in users; Settings and Users = admins only.

- The rule is enforced on the server by middleware/gate on every route, including the JSON status endpoint (which only includes the sections the viewer may see). Hiding a link is never the only protection.
- Guests are redirected to the login page; signed-in users without access get 403 (JSON callers get 401/403).
- **Editing is always admin-only**, whatever a screen's visibility. A non-admin who may see Settings or Users gets a read-only page.
- Admins can create and disable users, change roles, approve pending accounts and reset passwords. An admin cannot demote or disable themselves, nor the last active administrator. Disabled users are signed out on their next request.
- Registration modes: **off**, **open**, **open with admin approval** (the default; pending accounts cannot sign in).

## Security notes

- **Claim risk on a fresh install.** Until the setup wizard has been completed, *anyone who can reach the port can create the administrator*. Finish setup before exposing the service, or set `LABMAN_SETUP_TOKEN` so the wizard needs a secret only you know. After the first admin exists, `/setup` is gone (404) permanently; the lock is re-checked inside the transaction that creates the admin so two simultaneous submissions cannot both win.
- No default credentials, no secrets in the image or repository. The application key is generated into the data volume (keep it in your backups: it protects sessions and cookies).
- Passwords are hashed with Laravel's hasher; sessions and CSRF protection are Laravel's; login attempts are throttled per email + IP (5 per minute); every Blade output is escaped (a service named `<script>` is shown as text; there is a test for it).
- A strict `Content-Security-Policy` (no inline scripts or styles), `X-Frame-Options: DENY`, `nosniff` and `same-origin` referrer policy are sent on every page.
- Everything an admin types is validated: hosts/IPs (IPv6 allowed), ports 1-65535, paths start with `/`, domains are host names, and the SSH user may only contain letters, digits, `_`, `.` and `-` and must not start with `-`. The SSH command is built only from those stored fields, so a pasted command cannot smuggle options or shell syntax.
- **Admins can make the server connect to any address they enter.** Health checks connect only to addresses stored by admins, but that includes internal ones, and failure details (such as `connection refused`) are shown to everyone who may see the page. Treat the admin role as trusted, and consider who you give the Services/Servers screens to.
- The container runs as an unprivileged user (uid 10001); the only writable location is `/data`.

## Architecture

| Piece | Where |
|---|---|
| Health engine | `app/Monitoring`: `TcpProbe`, `HealthChecker`, `Verdict` (pure state logic), `StatusView` |
| YAML config | `app/Config/YamlConfig.php` |
| Access rules | `app/Support/Access.php`, middleware `screen:` and `admin`, gates `view-screen` / `admin` |
| Setup wizard | `app/Http/Controllers/SetupController.php`, middleware `RedirectToSetup` |
| Commands | `labman:monitor`, `labman:check`, `labman:import`, `labman:export` |
| Frontend | `resources/views` (Blade), `public/css/labman.css`, `public/js/labman.js` (no build step) |

The image is built on **FrankenPHP** (Caddy + PHP in one binary): a single small process serves the app with compression and good defaults, instead of an nginx + php-fpm + supervisor stack. It is a multi-stage `Dockerfile` (default target `runtime`, plus a `test` target) on the Alpine variant, roughly 400 MB.

### Dependencies

Runtime: `laravel/framework` (the application framework; its bundled HTTP client does the HTTP checks) and `symfony/yaml` (reading and writing the YAML config). Development only: `phpunit/phpunit` (tests) and `mockery/mockery` (needed by Laravel's console test helpers). No front-end packages, no starter kit, no admin framework.

## Development and tests

PHP is not needed on the host; everything runs in Docker.

```sh
docker build --target test -t labman-test .
docker run --rm labman-test
```

The suite (PHPUnit) covers the setup wizard and its lock-out, the four screens x three roles (including the JSON endpoint), registration modes and approval, user administration guards, YAML validation messages, health-state logic against real local endpoints (PHP's built-in server and TCP listeners: redirect = answered, `expect` ranges, degraded via a failing public name, dead hosts not blocking), input validation, and HTML escaping.

Useful one-offs against a running container:

```sh
docker compose exec labman php artisan labman:check     # one round now, printed as a table
docker compose exec labman php artisan labman:monitor --once
```

## Roadmap

1. **Nginx Proxy Manager import**: read proxy hosts from NPM and propose services (domain, backend, TLS) to add.
2. **UI editing + NPM sync with a diff preview**: edit services in LabMan and push changes to NPM, always showing exactly what would change before anything is written.
3. Later ideas: websocket-aware checks (the `websockets` flag is informational today), notifications on state changes, history graphs.

## License

[MIT](LICENSE)
