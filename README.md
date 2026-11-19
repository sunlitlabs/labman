# LabMan

A self-hostable **heads-up display for a home lab**. A dashboard to land on, two lists behind it:

- **Dashboard** (the home page): an overall "all systems operational / 2 items need attention" banner and a tile per service and server, each with an icon matched from its name. Click a service tile to open it, a server tile to copy its SSH command. Updates live.
- **Services**: name, domain, backend address and live health. Click one to open it (public URL, else backend URL).
- **Servers**: name, domain, address and live health. Click one to **copy its SSH command** (`ssh admin@192.0.2.5`).

**Icons** come from the public [Dashboard Icons](https://github.com/homarr-labs/dashboard-icons) library (Apache-2.0; the logos belong to their respective owners). Nothing is bundled: LabMan matches the service or server name to an icon (or you type an icon name), downloads it once through its own server and caches it, so your browser never contacts anyone else. A letter avatar shows when there is no match. Switch icons off under Settings > General, set `LABMAN_ICONS_FETCH=false` to stop all downloads, or give one item `icon: none`.

Plus a filter box (press `/`), a status summary, light/dark theme that follows your OS, and a layout that works down to phone width. Laravel 12, plain Blade, no JavaScript framework, no build step.

> Status: v0.3. Configure it in the admin UI or a YAML file, let an AI assistant manage it over [MCP](#mcp-model-context-protocol), sync with [Nginx Proxy Manager](#nginx-proxy-manager), keep your Ansible [inventory](#inventory-sync-ansible-and-semaphore) in git lined up with your servers, and run playbooks through [Semaphore](#semaphore) (always with a preview or a confirmation).

## The admin panel

Everything that configures LabMan lives in one **Admin** area, apart from the pages you look at every day (Services and Servers). The sidebar groups it:

| Group | Pages |
|---|---|
| Site | **Overview** (status of everything, accounts waiting for approval), **General** (title, health-check timing, Copy SSH defaults), **Access** (registration and who may see each page), **Users** |
| Integrations | **Inventory (Ansible)**, **Semaphore**, **Nginx Proxy Manager**, each with a connected / not connected dot |
| Tools | **AI access (MCP)** (API tokens), **Import and export** (YAML) |

Step-by-step workflows (comparing the inventory, reviewing a run, reviewing an NPM push) hide the sidebar and show a breadcrumb so they get the full width. People who are allowed to *see* the Settings or Users pages (see [Roles and visibility](#roles-and-visibility)) get a read-only version of those pages; everything else in the panel is admin-only.

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
| `LABMAN_MCP_ENABLED` | `true` | Set to `false` to switch the `/mcp` endpoint off (it then returns 404). |
| `LABMAN_MCP_ALLOWED_ORIGINS` | unset | Comma separated browser origins allowed to call `/mcp` besides LabMan's own. Non-browser clients send no `Origin` and are unaffected. |
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

The same import/export is available under **Admin > Import and export** and in the setup wizard. A complete, commented example with placeholder data is in [`labman.example.yml`](labman.example.yml):

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
    expect: 200-499      # healthy statuses: 200, 200-399,401 ... (default 200-499)
    health_path: /health # optional: request this instead of path for the health check
    icon: wikijs         # optional icon name; omit to guess from the name, "none" for no icon
    monitor: true        # false hides the status ("not monitored")
    check_tcp: true      # an open port counts as up even if the web check fails
    verify_tls: true     # applies to the public (domain) check only
    public_check: true
    websockets: false    # informational for now
    notes: ""
```

Messages number entries from 1 (`servers[1]: 'name' is required`) and name the entry once it has a name (`server 'nas': port 99999 is outside 1-65535`).

## MCP (Model Context Protocol)

LabMan is an MCP server, so an AI assistant (Claude Code, Claude Desktop, any MCP client) can do what an administrator can do in the UI. The endpoint is `POST /mcp` (Streamable HTTP, stateless, JSON responses) and is authenticated with a **bearer API token** that an admin creates under **Settings > MCP access**. The token is shown once and only its SHA-256 hash is stored.

```sh
claude mcp add --transport http labman https://labman.example.com/mcp --header "Authorization: Bearer lm_xxxxxxxx"
```

Two token scopes:

| Scope | Tools |
|---|---|
| `read` | `list_services`, `list_servers`, `get_status`, `get_settings`, `export_yaml` |
| `admin` | everything above plus `run_health_check`; `create/update/delete_server`; `create/update/delete_service`; `update_settings` (including registration mode and per-screen visibility); `import_yaml` (with `dry_run` and `replace`); `list_users`, `create_user`, `update_user` (role, status, password reset), `approve_user`; and the Nginx Proxy Manager tools below |

- Admin tools use exactly the same validation as the UI (shared code), so an AI cannot store an address, path or SSH user the forms would reject.
- Tool annotations mark what is read-only and what is destructive, so clients can ask you before deleting, replacing via import, or writing to NPM. The server instructions also tell the assistant to confirm those.
- **Tokens cannot manage tokens**: there is deliberately no tool for it, so a leaked token cannot mint more.
- An admin token is only as strong as its owner: if the admin who created it is disabled or demoted it stops working. The "cannot demote or disable yourself / the last admin" guards apply to the token's owner.
- Rate limited (120 calls a minute per token). Browsers' `Origin` header is checked against LabMan's own origin (DNS-rebinding protection). A signed-in browser session is **not** a credential for `/mcp`.

## Nginx Proxy Manager

Connect LabMan to your Nginx Proxy Manager (NPM) under **Admin > Nginx Proxy Manager** (tested against NPM 2.17). Paste any page of NPM's web UI (the address is cut back to host and port), enter an NPM user's e-mail and password, and LabMan logs in to prove it works. NPM has an API but no permanent API keys: it issues short-lived tokens in exchange for an account's e-mail and password, which is why LabMan has to keep the login (encrypted). Create a dedicated NPM user for it with permission to manage proxy hosts only. Use a dedicated NPM user; accounts with two-factor authentication are not supported. The login is stored **encrypted with the application key** in `/data`, and is never displayed again, exported to YAML, or returned by an MCP tool.

Everything is **preview first, then apply what you tick**:

- **Import (NPM → LabMan)**: lists every proxy host and what would happen to your services: *new service*, *update*, *in sync* or *skipped* (wildcard domains, unusable forward targets). Only fields NPM owns are touched on an existing service (scheme, port, backend, websockets, domain, the link). A forward host that equals a server's address links the service to that server. NPM is never written to.
- **Push (LabMan → NPM)**: for each service with a domain, shows a diff against its proxy host (`forward_scheme`, `forward_host`, `forward_port`, websocket upgrade, and adding the domain name) and offers to create proxy hosts that do not exist yet (unticked by default; created without SSL, add a certificate in NPM). Updates are partial: SSL, certificates, access lists and everything else in NPM are left alone.
- A service is linked to its proxy host by NPM host id (stored on first import/push) or by its domain.
- **Safety:** each previewed item carries a fingerprint. Apply recomputes the plan and refuses any item whose fingerprint changed (an edit in LabMan *or* in NPM since you looked), applying nothing. **LabMan never deletes anything in NPM**; the client has no delete call.

The same two-step flow is available to MCP clients (`npm_import_preview` / `npm_import_apply`, `npm_push_preview` / `npm_push_apply`), plus `npm_get_connection`, `npm_set_connection`, `npm_test_connection` and `npm_remove_connection`. Apply tools take the `key` and `fingerprint` copied from the preview.

## Inventory sync (Ansible and Semaphore)

If Semaphore (or plain Ansible) reads its inventory from a git repository, LabMan can keep that inventory and its own servers lined up. LabMan reads the inventory file, compares it with your servers and shows, in one screen, what matches and what does not. You decide, item by item, what to change on either side.

Connect under **Admin > Inventory (Ansible)** with just two things: a **link to your inventory file** and a **personal access token** with read and write access to the repository contents. Paste the address from your browser, for example `https://github.com/your-org/your-repo/blob/main/ansible/inventory/hosts.ini`, and LabMan works out the repository, branch and file path as you paste (GitHub, GitLab, Gitea, Forgejo, Codeberg and Bitbucket links work, and so do plain repository addresses and `git@host:org/repo.git` clone addresses). A branch name that itself contains a slash (`release/1.0`) cannot be told apart from a folder in a web link: enter that one under Details. You do not need to know the git user name either: LabMan uses the host's convention (`x-access-token` for GitHub, `oauth2` for GitLab, `x-token-auth` for Bitbucket) and, for self-hosted hosts, asks the host's API who owns the token; it tells you which name it ended up using. LabMan clones the repository, finds the file and reads it before saving. The token is stored encrypted, passed to git only through the environment of a single process (never in a URL, a command line, the git config or a file), and is never shown, exported or sent to an MCP client.

**Compare** pulls the latest commit and sorts everything into four groups:

| Group | What it means | Choices |
|---|---|---|
| In sync | the server and the inventory host agree | none needed |
| Different | matched, but address, SSH user, SSH port or group disagree | per field: use LabMan's value (written into the inventory), use the inventory's value (copied into LabMan), or skip |
| Only in LabMan | no matching inventory host | add it to the inventory, delete it from LabMan, or leave it |
| Only in the inventory | no matching LabMan server | **set it up** in LabMan (name, address, group, user and port are prefilled and editable), remove it from the inventory, or leave it |

Servers and hosts are matched by the saved link (set automatically), then by name (exactly, or as an Ansible-safe name: "Docker Host" matches `Docker-Host`), then, only when exactly one host qualifies, by address. Range patterns such as `web[01:05]` and hosts whose address is an Ansible template are left alone.

**Review** shows the exact result before anything is written: the git diff that will be pushed, the LabMan changes, and the commit message. **Apply** re-runs the review and refuses, writing nothing, if the repository or LabMan changed since you looked. The inventory commit is pushed first; LabMan is only updated if the push succeeds. LabMan never rewrites history and never force-pushes.

**YAML vs INI.** LabMan reads both. It always writes YAML, because YAML is typed, supports nested groups and is safe for tools to edit, while INI has quoting and typing quirks. If your inventory is an INI file, nothing is converted until you apply a change to the inventory; then LabMan writes the same hosts (with every variable it does not manage carried over) to a `.yml` file next to it and switches to that file. The old INI file is kept unless you tick "delete it in the same commit"; Semaphore keeps using the old path until you change its inventory to the new file. Comments and formatting in the inventory are not preserved when LabMan rewrites it, which is why every write is previewed as a diff.

Limits: one inventory file; LabMan creates groups for LabMan groups (named like the group with unsafe characters replaced by `_`) but never removes group memberships from the inventory.

## Semaphore

Under **Admin > Semaphore** LabMan connects to your [Semaphore](https://semaphoreui.com) (paste any page of its web UI; the address is cut back to host and port) with an **API token** (stored encrypted, never shown) and can list projects and templates (playbooks), run a template, follow the run live and stop it. It never creates or edits Semaphore's own configuration (keys, inventories, templates, repositories).

Running is always three steps, in the UI and over MCP:

1. **Choose**: pick the hosts or groups to limit the run to (LabMan lists your servers and groups using their inventory names), check mode (dry run), diffs, optional extra variables (a JSON object) and a note.
2. **Confirm**: LabMan shows exactly what will run: the template and playbook, the hosts, the mode, the variables. A run without a limit, or a real run, is called out. Nothing is sent to Semaphore yet.
3. **Run**: only after you tick the confirmation. The task page then follows the status and output live.

**Variables.** Recent Semaphore versions only accept extra variables that the template declares as *survey variables* (anything else is refused with a bare HTTP 400). LabMan reads those declarations and shows them as proper fields (text, whole number, or a choice list; required ones are enforced), checks any extra variables against them before sending, and lists them for MCP clients. Secret survey variables are not passed by LabMan: set those in Semaphore. Command-line colour codes in the run output are stripped.

Everything sent to Semaphore is validated: host limits may only contain letters, digits and `_ . - * ! & : [ ]` and cannot start with `-`; variable names are checked; the note is one line. Over MCP, `semaphore_run_template` requires `confirm: true`, which the assistant must only set after the user approved that exact run, and it is annotated as destructive so clients ask first.

The Semaphore token acts as its Semaphore user, so use a dedicated user that can run only the templates you want available here.
## How health checks work

Checks run in the background, never when a page loads. `php artisan labman:monitor` is a resident loop started by the container entrypoint (and restarted if it exits); it re-reads the settings every round, so changing the interval needs no restart. Results are stored in the database; the pages render what is stored and the browser polls a small JSON endpoint (`/status`) every interval.

- **Server**: a TCP connect to the SSH port (default 22, switch off with `check_ssh: false`) plus each extra port. `up` = all connect, `degraded` = some, `down` = none, `unknown` = not checked yet.
- **Service**: it is **up when any check passes**:
  - the web check: an HTTP(S) GET to `scheme://address:port/health_path` (`health_path` falls back to `path`), healthy when the status is inside `expect` (default `200-499`, i.e. anything but a server error: login pages answering 401 and API-only apps answering 404 on `/` are running). `expect` takes a list, e.g. `200-399,401`. Backend TLS is **not** verified and redirects are **not** followed. For a service with a domain the request is also sent with `Host: <domain>`, which is what name-based reverse proxies and apps such as Nextcloud need;
  - an **open backend port** (`check_tcp`, on by default): an app that answers oddly but is plainly listening counts as up;
  - the **public address** (`public_check`, `https://<domain><path>` with certificate verification unless `verify_tls: false`): if LabMan cannot reach the backend (another VLAN, a firewall) but the public name answers, the service is up.
  - `degraded` only when the backend is up but the public check fails (proxy or DNS broken); `down` only when nothing passes. A service with no address is `down` ("no address").
- **Hide a status you cannot get right**: untick *Monitor* on a service or server (`monitor: false`). It stays listed as "not monitored", costs no checks and is not counted in the totals.
- **Diagnose** (admin, on a service's edit page, or the `diagnose_service` MCP tool) probes one service several ways at once: the port, http and https, with and without the domain as `Host`, a set of likely health paths (plus the known one for common apps such as Jellyfin, Plex, Sonarr, Grafana, Home Assistant, Proxmox), and the other common web ports on the same address. It then says what is wrong ("connection refused: nothing is listening on that port", "timed out: usually a firewall or VLAN rule", "answers on :8443 over https") and offers settings that would make the check pass; nothing changes until you press Apply.
- Each check records a label (`backend`, `public`, `ssh :22`, `tcp :445`), pass/fail, milliseconds and a detail such as `HTTP 200`, `HTTP 500 (expected 200-499)`, `timed out`, `connection refused`, `certificate: ...`.
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
- **Passwords** may contain any characters (symbols, spaces, unicode) up to 255 characters and are never trimmed or altered, so a password-manager-generated password works. They are hashed with **argon2id** (bcrypt silently ignores everything past 72 bytes); accounts created with an older bcrypt hash keep working and are upgraded the next time they sign in.

## Security notes

- **Claim risk on a fresh install.** Until the setup wizard has been completed, *anyone who can reach the port can create the administrator*. Finish setup before exposing the service, or set `LABMAN_SETUP_TOKEN` so the wizard needs a secret only you know. After the first admin exists, `/setup` is gone (404) permanently; the lock is re-checked inside the transaction that creates the admin so two simultaneous submissions cannot both win.
- No default credentials, no secrets in the image or repository. The application key is generated into the data volume (keep it in your backups: it protects sessions and cookies).
- Passwords are hashed with Laravel's hasher; sessions and CSRF protection are Laravel's; login attempts are throttled per email + IP (5 per minute); every Blade output is escaped (a service named `<script>` is shown as text; there is a test for it).
- A strict `Content-Security-Policy` (no inline scripts or styles), `X-Frame-Options: DENY`, `nosniff` and `same-origin` referrer policy are sent on every page.
- Everything an admin types is validated: hosts/IPs (IPv6 allowed), ports 1-65535, paths start with `/`, domains are host names, and the SSH user may only contain letters, digits, `_`, `.` and `-` and must not start with `-`. The SSH command is built only from those stored fields, so a pasted command cannot smuggle options or shell syntax.
- **Admins can make the server connect to any address they enter.** Health checks connect only to addresses stored by admins, but that includes internal ones, and failure details (such as `connection refused`) are shown to everyone who may see the page. Treat the admin role as trusted, and consider who you give the Services/Servers screens to.
- **MCP tokens are powerful.** An `admin` token can change servers, services, settings (including who can see what), users and your NPM proxy hosts. Treat it like an admin password: create one per client, prefer `read` scope when that is enough, revoke it under Settings when done, and put LabMan behind HTTPS before using tokens over a network (a bearer token sent over plain HTTP can be sniffed). Set `LABMAN_MCP_ENABLED=false` if you do not want the endpoint at all.
- **Playbook runs are the most powerful thing here.** An admin (or an admin MCP token) can start Semaphore runs on your real hosts. Every run needs an explicit confirmation, host limits and variables are validated, and ead tokens cannot run anything, but you are still handing out the ability to run your automation: use a dedicated, limited Semaphore user and keep admin MCP tokens few.
- **The inventory repository token** can push to your repository. Give it the minimum scope (contents read/write on that one repository), keep branch protection if you want a human gate, and note LabMan only ever makes fast-forward pushes to the configured branch. Only https repository addresses are accepted (no ile://, ssh:// or credentials in the URL), the inventory path must stay inside the repository and cannot be a symbolic link, and git runs with hooks disabled and an empty environment apart from the token.
- The NPM login in LabMan is encrypted at rest with the application key. Anyone who can read both the database and `/data/app.key` can decrypt it, so protect and back up the volume accordingly, and give LabMan its own NPM user.
- The container runs as an unprivileged user (uid 10001); the only writable location is `/data`.

## Architecture

| Piece | Where |
|---|---|
| Health engine | `app/Monitoring`: `TcpProbe`, `HealthChecker`, `Verdict` (pure state logic), `StatusView` |
| YAML config | `app/Config/YamlConfig.php` |
| Admin panel | `app/Support/AdminNav.php` (sidebar model), `AdminController` (overview), `resources/views/layouts/admin.blade.php` |
| Access rules | `app/Support/Access.php`, middleware `screen:` and `admin`, gates `view-screen` / `admin` |
| Setup wizard | `app/Http/Controllers/SetupController.php`, middleware `RedirectToSetup` |
| Shared validation | `app/Domain` (`ServerInput`, `ServiceInput`, `SettingsInput`, `UserAdmin`): used by the forms **and** the MCP tools |
| MCP server | `app/Mcp` (protocol, tools), `app/Http/Controllers/McpController.php`, `routes/mcp.php`, `AuthenticateApiToken` |
| Nginx Proxy Manager | `app/Npm`: `NpmClient`, `Connection` (encrypted login), `NpmSync` (plan / fingerprint / apply) |
| Inventory sync | `app/Inventory`: `Inventory` (INI/YAML model), `GitRepo` (git CLI, token via askpass), `InventorySync` (compare / review / apply), `RepoConfig` |
| Semaphore | `app/Semaphore`: `SemaphoreClient`, `Connection` (encrypted token), `RunOptions` (validation) |
| Commands | `labman:monitor`, `labman:check`, `labman:import`, `labman:export` |
| Frontend | `resources/views` (Blade), `public/css/labman.css`, `public/js/labman.js` (no build step) |

The image is built on **FrankenPHP** (Caddy + PHP in one binary): a single small process serves the app with compression and good defaults, instead of an nginx + php-fpm + supervisor stack. It is a multi-stage `Dockerfile` (default target `runtime`, plus a `test` target) on the Alpine variant, roughly 400 MB.

### Dependencies

Runtime: `laravel/framework` (the application framework; its bundled HTTP client does the HTTP checks and talks to NPM) and `symfony/yaml` (reading and writing the YAML config). The MCP server is implemented in the app itself (it is a small JSON-RPC protocol), so it needs no extra package. Development only: `phpunit/phpunit` (tests) and `mockery/mockery` (needed by Laravel's console test helpers). No front-end packages, no starter kit, no admin framework.

## Development and tests

PHP is not needed on the host; everything runs in Docker.

```sh
docker build --target test -t labman-test .
docker run --rm labman-test
```

The suite (PHPUnit) covers the setup wizard and its lock-out, the four screens x three roles (including the JSON endpoint), registration modes and approval, user administration guards, YAML validation messages, health-state logic against real local endpoints (PHP's built-in server and TCP listeners: redirect = answered, `expect` ranges, degraded via a failing public name, dead hosts not blocking), input validation, and HTML escaping. For MCP it covers token authentication and scopes, the protocol (initialize, batches, errors), every tool, and the token UI. For inventory sync it uses real git repositories created per test (a bare remote that LabMan clones, commits to and pushes to) and covers parsing INI and YAML, matching, every choice, the diff, conversion to YAML, stale-review refusal and rejected pushes. For Semaphore it covers the connection, the run flow (choose, confirm, start, follow, stop), option validation and the MCP tools, against an in-memory fake Semaphore. For NPM it covers connection handling and encryption, import and push plans, apply, stale-preview refusal and the guarantee that nothing is ever deleted, against an in-memory fake NPM whose responses were recorded from a real NPM 2.17 container.

Useful one-offs against a running container:

```sh
docker compose exec labman php artisan labman:check     # one round now, printed as a table
docker compose exec labman php artisan labman:monitor --once
```

## Roadmap

Done: Nginx Proxy Manager import and push, an MCP server (v0.2); inventory sync and Semaphore runs (v0.3).

1. **Inventory**: several inventory files, group-level variables, and creating the inventory file from scratch.
2. **More NPM coverage**: redirection hosts, streams and 404 hosts, certificate/SSL awareness in previews, and optional deletion with a separate explicit confirmation.
3. **MCP**: finer-grained token scopes (per tool group), and resources/prompts for common workflows.
4. Later ideas: websocket-aware checks (the `websockets` flag is informational today), notifications on state changes, history graphs.

## License

[MIT](LICENSE)
