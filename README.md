# LabMan Homelab Manager

**LabMan Homelab Manager** (LabMan for short) is a self-hostable **heads-up display and manager for a home lab**. A dashboard to land on, two lists behind it:

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

**LabMan is the source of truth.** With "Update NPM automatically" on (the default once NPM is connected), a change to a service, to the address of the server it runs on, or to the certificate chosen for a kind of domain is sent to NPM straight away, after the page has been saved: the matching proxy host is updated (domain, forward target, websockets, certificate), or created when it does not exist yet. Only what LabMan owns is written, an automatic update never deletes anything in NPM, and a failure is shown on the NPM page and as a notice (the change in LabMan is kept). Switch it off and the guided sync and the push preview still work by hand. Your domains and their certificates live on this page too, under Domains and certificates; picking a different certificate for local or public domains moves every proxy host of that kind to it.

Connect LabMan to your Nginx Proxy Manager (NPM) under **Admin > Nginx Proxy Manager** (tested against NPM 2.17). Paste any page of NPM's web UI (the address is cut back to host and port), enter an NPM user's e-mail and password, and LabMan logs in to prove it works. NPM has an API but no permanent API keys: it issues short-lived tokens in exchange for an account's e-mail and password, which is why LabMan has to keep the login (encrypted). Create a dedicated NPM user for it with permission to manage proxy hosts only. Use a dedicated NPM user; accounts with two-factor authentication are not supported. The login is stored **encrypted with the application key** in `/data`, and is never displayed again, exported to YAML, or returned by an MCP tool.

Everything is **preview first, then apply what you tick**:

- **Local and public domains**: if you use one domain at home and another from the internet, list them on the Nginx Proxy Manager page under Domains and certificates (or `local_domains` / `public_domains` in the YAML; several each, nothing is assumed when empty) and pick the NPM certificate for each. A service then has a `domain` (the primary, usually local one, which clicking opens) and an optional `public_domain`, each with its own proxy host and link. LabMan shows both, health-checks both (`local` and `public` checks: up if any passes, degraded if the backend is fine but a name fails), tells imported proxy hosts apart by which list their name falls under, files `wiki.<public>` under the same service as `wiki.<local>`, and gives a proxy host it creates the certificate you chose for that kind of domain (with SSL forced).
- **Several names, add / remove**: the domain lists in Settings, and the local and public name lists on a service (and the name list on a server), are add / remove lists. In Settings one entry per list is the **default**; on a service or server, **Add** pre-fills `<name as a host label>.<default domain>` (Jellyfin -> `jellyfin.home.example.com`) and the first entry is the primary name (what clicking opens, what NPM links to). Further names are saved as `extra_domains`, shown everywhere, and health-checked (`local 2`, `public 2`, ...); NPM sync only links the primary names.
- **Suggested local names (opt-in, off by default)**: with a default local domain set, the NPM wizard has a switch ("Also suggest local domain names...") that adds one row per service and server that has no local name, built from its name; ticked rows are saved in LabMan only, and a later sync can then create their proxy hosts. The YAML export has the same switch (Settings > Import and export, `labman:export --suggest-domains`, or `suggest_domains` on the MCP `export_yaml` tool), which only affects the file. A suggestion is never one that another item already has.
- **Guided sync (the wizard)**: connecting for the first time takes you straight to *Match up with NPM* (also reachable any time from the NPM page, and per service from its edit page). It compares both sides once and groups the results: proxy hosts with no service, services that match by domain but are not linked, linked pairs whose settings differ, and services with a domain but no proxy host. Each row has a default already chosen: add new hosts as services, link matches, take NPM's values for differences (it is what really routes traffic) and leave "in LabMan only" services alone. The defaults change only LabMan; writing to NPM is always an explicit choice per row. It applies through the same fingerprint check as the previews below, so a change made anywhere since the page was shown refuses the whole apply.
- **Import (NPM → LabMan)**: lists every proxy host and what would happen to your services: *new service*, *update*, *in sync* or *skipped* (wildcard domains, unusable forward targets). Only fields NPM owns are touched on an existing service (scheme, port, backend, websockets, domain, the link). A forward host that equals a server's address links the service to that server. NPM is never written to.
- **Push (LabMan → NPM)**: for each service with a domain, shows a diff against its proxy host (`forward_scheme`, `forward_host`, `forward_port`, websocket upgrade, and adding the domain name) and offers to create proxy hosts that do not exist yet (unticked by default; created without SSL, add a certificate in NPM). Updates are partial: SSL, certificates, access lists and everything else in NPM are left alone.
- A service is linked to its proxy host by NPM host id (stored on first import/push) or by its domain.
- **Safety:** each previewed item carries a fingerprint. Apply recomputes the plan and refuses any item whose fingerprint changed (an edit in LabMan *or* in NPM since you looked), applying nothing. **LabMan deletes in NPM only a proxy host it created itself** (it keeps a list), only once nothing asks for it any more, only as a previewed item you tick (never ticked for you, never by an automatic update), and only if the host is still in NPM under the name LabMan gave it.

The same two-step flow is available to MCP clients (`npm_import_preview` / `npm_import_apply`, `npm_push_preview` / `npm_push_apply`), plus `npm_get_connection`, `npm_set_connection`, `npm_test_connection` and `npm_remove_connection`. Apply tools take the `key` and `fingerprint` copied from the preview.

**Zones over MCP.** All of these are admin tools, validated by the same code as the screens, and none returns a password or token. `list_zones`, `create_zone`, `update_zone` (only the settings you give change; certificate policy is merged), `delete_zone` (the default zone and a zone with servers are refused), `assign_server_zone`, `zone_for_address` (which zone a network block suggests), `set_service_zone` (a service own zone, certificate choice and "also publish in" list). Every `npm_*` tool above also takes an optional `zone` (name or id): without one it acts on the default zone and, when there are several zones, says so in a `hint`. `npm_preview_all` is one grouped preview (a section per proxy, each item with a key, fingerprint, a plain-language summary, warnings and errors; an unreachable proxy shows its own error), and `npm_apply_all` applies the approved items to their own zones (one proxy failing does not stop the others; an item that changed since the preview, or has an error, is refused). Certificates: `npm_list_certificates` (a proxy certificates with days left, plus where each host name stands) and `npm_match_certificate` (which certificate a name would get and why; nothing is requested). Mirrors: `list_mirrors`, `mirror_preview` (apply with `npm_push_apply`), `forget_mirror` (stops tracking an orphan; nothing is deleted in NPM; a delete goes through the push preview and apply). DNS: `dns_plan` (records and conflicts as JSON, or as a readable plan, hosts list or AdGuard rewrites) and `dns_set_owner` (settle a domain or name two zones both want). Every result ends with a short `next` saying what to do from there, and applies made over MCP appear in the zone "What changed" log as "MCP (token name)".

## Inventory sync (Ansible and Semaphore)

If Semaphore (or plain Ansible) reads its inventory from a git repository, LabMan can keep that inventory and its own servers lined up. LabMan reads the inventory file, compares it with your servers and shows, in one screen, what matches and what does not. You decide, item by item, what to change on either side.

Connect under **Admin > Inventory (Ansible)** with just two things: a **link to your inventory file** and a **personal access token** with read and write access to the repository contents. Paste the address from your browser, for example `https://github.com/your-org/your-repo/blob/main/ansible/inventory/hosts.ini`, and LabMan works out the repository, branch and file path as you paste (GitHub, GitLab, Gitea, Forgejo, Codeberg and Bitbucket links work, and so do plain repository addresses and `git@host:org/repo.git` clone addresses). A branch name that itself contains a slash (`release/1.0`) cannot be told apart from a folder in a web link: enter that one under Details. You do not need to know the git user name either: LabMan uses the host's convention (`x-access-token` for GitHub, `oauth2` for GitLab, `x-token-auth` for Bitbucket) and, for self-hosted hosts, asks the host's API who owns the token; it tells you which name it ended up using. LabMan clones the repository, finds the file and reads it before saving, then takes you straight to the comparison of its hosts with your servers. The token is stored encrypted, passed to git only through the environment of a single process (never in a URL, a command line, the git config or a file), and is never shown, exported or sent to an MCP client.

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

**Workbooks** (your Semaphore templates) have their own page, **Admin > Workbooks**, with the recent runs. Choose which machines a run aims at from your server list: groups (everything in a group, including machines added later) and individual servers, with a filter. On the **Servers** page, tick servers and use "Run a workbook on the selected servers", or use the **Run** link on a row; the run form opens with them already ticked, and you still review the run before it starts.

**Updating a service.** On a service's edit page, under **Update**, pick the workbook that updates it. An **Update** link then appears on the service list. It opens that workbook's run form already aimed at the service's server (and noted "Update <name>"); you review the run and confirm it like any other, then follow the output live. LabMan does not pull code itself: the workbook does, which keeps credentials, privileges and the exact steps in Semaphore. [`docs/laravel-update.playbook.yml`](docs/laravel-update.playbook.yml) is an example playbook for a Laravel app that runs from a git checkout: `git pull --ff-only`, `composer install --no-dev`, `npm ci && npm run build`, `artisan migrate --force`, the cache commands and `queue:restart`. Copy it into the repository Semaphore uses, adjust the variables, and add a template for it. A service that is not linked to a server opens the form without a target, so you choose where it runs.

## Links

**Links.** For pages that are not services or servers (a network controller, a cloud dashboard), use **Add link** on the dashboard or **Admin > Links**. A link has a name, an http(s) address, an optional group, notes and icon; it appears as a tile on the dashboard, opens in a new tab and is never health-checked.

## Zones

**How zones work.** Servers belong to zones, and a service follows its server. Each zone can have its own proxy (an Nginx Proxy Manager), its own domains (for example `lab.example.com` inside, `example.dev` public) and its own certificate policy. A name belongs to the zone with the longest matching domain, and an exact tie is settled by picking an owner. To let people in one zone reach a host another zone publishes, by the same name, the first zone's proxy mirrors it. A lab with one zone sees no change anywhere. The quickest way in is the setup wizard (**Admin > Zones**). Everything that changes a proxy is previewed first and applied only for the items you approve; a proxy host is deleted only if LabMan created it and you ticked that previewed item; secrets are never shown, exported or returned over MCP; and LabMan lists DNS records but does not write them. The paragraphs below cover each part.

**Zones.** A zone is a trust level that servers belong to (for example management, internal, LAN, external); higher levels are more trusted, and LabMan only ever uses the level to warn. Every install has one **Default** zone holding everything, and with one zone nothing else in LabMan changes. Add more under **Admin > Zones**: each zone has a level, optional network blocks (to suggest a zone for a new server from its address), optional domains, and the address other zones forward to. Once there are two zones, the server form gets a Zone field and the service form an optional override (a service normally follows its server). Zones are in the YAML export and import (`zones:`, and `zone: <name>` on a server or service); a file without zones still imports. A name belongs to the zone with the longest matching domain (so `*.a.example.dev` beats `*.example.dev`).

**One proxy per zone.** Each zone can have its own Nginx Proxy Manager. On **Admin > Nginx Proxy Manager**, pick a zone (the Proxies list shows each one: connected or not, NPM version, host count, and drift) and connect, preview or apply for that zone only; a zone's proxy only ever sees the services of that zone. **Preview push to all proxies** (or import) shows one page with a section per proxy, each with its own switch; an unreachable proxy shows its error in its own section and the others still work. The default zone keeps the original connection and its two certificate choices; other zones create new hosts without a certificate until certificate policies arrive. LabMan still never deletes anything in NPM.

**Certificates.** Each zone has a certificate policy (**Admin > Zones > edit**): what to do for a host with no certificate (use one that already covers the name, request one from Let's Encrypt with the HTTP or DNS challenge, or none), pins that say "always use certificate N for this domain", and whether the proxy sits behind a CDN or tunnel that terminates TLS publicly (an "edge-managed" zone: its certificates are origin certificates, never requested from Let's Encrypt, and never reused on a proxy that browsers connect to directly). A certificate that already covers a name is always chosen before a new one is requested; a wildcard covers exactly one label (`*.sub.example.dev` covers `a.sub.example.dev`, not `a.b.sub.example.dev`). A service can override the choice on its own form. The NPM page shows each host name as covered, expiring (under 21 days), missing or not covered, with a preview of the fixes. Nothing is requested while previewing: a request is made only when you apply a previewed change, once per set of names, and automatic updates never request one (they only attach an existing covering certificate to a host that has none). For the DNS challenge LabMan borrows the provider settings of an existing DNS-challenge certificate in NPM, sends them to NPM in that one request, and never stores the token.

**Mirrors.** A mirror is an entry in one zone's proxy that forwards to another zone's proxy for a name that other zone owns, so people on the inner network can use a publicly published name without going out and back. It is off by default: tick **Mirror the hosts of** another zone on a zone's page, or tick zones under **Also publish in** on a service (the service's setting wins). The mirror forwards to the other zone's **proxy address** (an IP or a stable internal name, never the public name, so it cannot loop through DNS) and keeps the original Host header, and SNI over HTTPS. The hop is HTTP, HTTPS with verification, or HTTPS without verification (for an origin certificate); automatic looks at how the other proxy serves the name and picks the safest that works, and the preview says why. A mirror needs its own browser-trusted certificate on the mirroring proxy (a wildcard it already has, or one the zone is set to request); without one it is not created and the preview says so. When the source changes (a new proxy address, websockets, a renamed service) the next preview proposes an update. LabMan deletes in NPM only a proxy host it created itself, never automatically: a mirror nothing asks for any more is listed as orphaned (**NPM > Mirrors**), and shows up in the push preview as a delete you can tick. If its host was edited in NPM so it is no longer the mirror LabMan made, it is not offered for deletion: remove it by hand, then forget it here. Proxy hosts LabMan created for services are handled the same way once the service no longer uses them. A lower zone reaching into a higher one is allowed but warned about, and so is a host that forwards to a server in a more trusted zone.

**DNS plan.** The zones create DNS records, so **Admin > DNS plan** lists them per resolver (read-only unless you connect AdGuard Home, below): the **inside** resolver on your network and the **public** provider. Each zone domain gets a wildcard pointing at the zone proxy (inside) or at the zone public address (public, set on the zone page); with split-horizon on, a zone public names are also answered inside by its proxy; each server gets a record at its own address (a suggested name when it has no domain); a service name a wildcard does not cover gets its own record; and each mirror name is answered inside by the mirroring zone proxy. Export it as a readable plan, a hosts-style list, AdGuard Home rewrites, or JSON (records with no answer yet are left out of the machine formats). Collisions are never guessed: when two zones list the same domain or use the same name, the page shows it, no record is made until you pick the owning zone, and then the other zone can be set to mirror the owner hosts. A more specific sub-domain zone always beats its parent by the longest matching suffix, with nothing to pick. A server record that hides a proxied name, a name stranded below a record of its own, a zone with no proxy address, and a mirror whose upstream name would resolve back to its own proxy are flagged. **AdGuard Home.** Under **DNS plan > AdGuard Home** you can connect your AdGuard Home (address and, if it has one, a login; stored encrypted and never shown again) and write the plan's **inside** records to it as DNS rewrites. It works like the NPM push: a preview lists every rewrite to add or to change (a name that already answers something else shows the old and new answer), you tick what to write, and anything that changed since the preview is refused. A change edits the existing rewrite in place (AdGuard Home's own update call, sent as a PUT, naming the rewrite exactly as AdGuard holds it, for example `*.example.me` from `192.0.2.5` to `192.0.2.10`). An AdGuard Home version too old to have that call is not worked around by deleting and re-adding: that item is reported as not applied, with the rewrite to edit by hand, and the other items are still written. LabMan keeps a list of the rewrites it wrote itself, and the preview offers a delete only for one of those that the plan no longer asks for (never ticked for you). It leaves every other rewrite alone (it only counts them), and rewrites written before this list existed are not on it. A name with several rewrites of the same type is skipped with the reason rather than guessed. The MCP tools are `adguard_get_connection`, `adguard_set_connection`, `adguard_test_connection`, `adguard_remove_connection`, `adguard_preview` and `adguard_apply`. Pushing the public records to a DNS provider is a later feature; a `DnsProvider` interface marks where one plugs in.

**Zones overview and setup wizard.** **Admin > Zones** shows one card per zone, most trusted first: its proxy status (connected, unreachable or none, remembered from the last check so the page opens at once; **Refresh proxy status** asks the proxies again), its domains, how many servers and services it holds, certificate coverage and drift, and any issue with its fix beside it (connect a proxy, set a domain, see the certificates, preview the push). A zone's own page lists its servers (with a move control and a suggestion when a network block matches the address), its services, the issues, the option to let another zone mirror it, and **What changed**: every apply to that zone's proxy with the time, who applied it (or "automatic update"), and what happened. The **setup wizard** (Zones > Set up zones) has five steps (name the zones from a template or by hand, place the servers with network-block suggestions, connect each proxy, set each zone's domains and certificate choice, review); each step saves straight away, can be skipped, and can be revisited in any order. The review screen for pending proxy changes reads each item as a sentence ("Add wiki.example.dev to the LAN proxy, forwarding to ...") with the raw changes behind a toggle; warnings sit next to the line they affect, and a new host that would be created broken (for example a pinned certificate the proxy does not have) is shown as an error and cannot be applied. On the service form, the resulting address, the zone and the other zones it will also be published in update as you type.

**Host list.** Under **Admin > Semaphore** choose the Semaphore inventory that holds your machines (a static one, kept inside Semaphore; an inventory that points at a git file is handled by the Inventory page above). Then:

- The Servers page shows, per server, the host name Semaphore knows it by, or "Not in Semaphore", and the run form flags servers Semaphore does not have.
- An optional switch ("Update this inventory whenever my server list changes", off by default) writes your servers into that inventory after every change: new servers are added, address, user, port and group follow LabMan, and a deleted server's host is removed. Hosts that only exist in Semaphore are never removed. A static INI inventory becomes YAML the first time LabMan writes to it. A failed update is shown on the Semaphore page and as a notice. **Update Semaphore now** does the same on demand.
- About every ten minutes LabMan reads the host list again. When hosts appear that LabMan does not have, every admin gets a dismissible notice; **Review them** lets you add each to your server list or ignore it.

Under **Admin > Semaphore** LabMan connects to your [Semaphore](https://semaphoreui.com) (paste any page of its web UI; the address is cut back to host and port) with an **API token** (stored encrypted, never shown) and can list projects and templates (playbooks), run a template, follow the run live and stop it. It never creates or edits Semaphore's own configuration (keys, templates, repositories); the only thing it writes is the host list of the one inventory you choose, and only if you let it.

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

Done: Nginx Proxy Manager import and push, an MCP server (v0.2); inventory sync and Semaphore runs (v0.3); dashboard links, per-service updates, and zones with per-zone proxies, certificate matching, mirrors, a DNS plan, a setup wizard and MCP tools.

1. **Inventory**: several inventory files, group-level variables, and creating the inventory file from scratch.
2. **More NPM coverage**: redirection hosts, streams and 404 hosts, and optional deletion with a separate explicit confirmation.
3. **MCP**: finer-grained token scopes (per tool group), and resources/prompts for common workflows.
4. **DNS**: push the public records to a DNS provider (a `DnsProvider` interface marks where it plugs in; AdGuard Home is done for the inside records), and an inside record for a lower zone to reach a higher zone's hosts directly.
5. Later ideas: websocket-aware checks (the `websockets` flag is informational today), notifications on state changes, history graphs.

## License

[MIT](LICENSE)
