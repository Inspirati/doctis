# Implementation Plan — Remote Git Access (PROJECT_REPOS.md Part 2, Option 1)

**Goal:** Allow an advanced user to `git clone` a Doctis project's document
repository from a remote workstation, authenticated by a **Doctis API token**,
with **no Linux account** — via Smart HTTP(S) through `git-http-backend` behind a
Doctis PHP auth/authz gateway. First milestone is **read-only clone**; push and
the related concerns are tracked as later phases.

This plan tracks steps and progress. Design rationale lives in
[PROJECT_REPOS.md](PROJECT_REPOS.md) Part 2. Status keys: ☐ todo · ◐ in progress
· ☑ done · ⊘ deferred.

---

## Phase 0 — Investigation & decisions ☑

- ☑ API token infrastructure already exists (`core/api_token_api.php`,
  `api_tokens_page.php`, `api_token_create.php`, `api_token_revoke.php`,
  `{api_token}` table). `api_token_get_user($plain)` reverse-maps a token to a
  user by SHA-256 hash. **Leverage it — do not rebuild token provisioning.**
- ☑ REST `AuthMiddleware` already authenticates via `api_token_get_user()` from
  the `Authorization` header — same primitive the gateway will use.
- ☑ vaio has `git-http-backend` at `/usr/lib/git-core/git-http-backend`; Apache
  has `cgi`, `alias`, `env`, `rewrite`, `setenvif`; PHP is mod_php8.1 (gateway
  runs as `www-data`, which owns `/var/git/doctis`); `proc_open` enabled.
- ☑ An old, **insecure** `git-serve.conf` exists (anonymous export of all of
  `/var/www/html`). It will be replaced by the authenticated gateway.
- ☑ **Architecture chosen: PHP gateway proxying to `git-http-backend`** (keeps
  auth via API token + project authz in PHP; no extra Apache auth modules).

## Phase 1 — Read-only clone over HTTP via API token  ☑  (verified 2026-06-30)

**Result:** `git clone http://<user>:<API_TOKEN>@10.0.0.10/git/<slug>.git` works
from a remote workstation with no Linux account. Verified: 401 without token,
200 + clone with a valid token (DEVELOPER+), 403 for a low-privilege user
(`viewer`), 404 for unknown repo, 403 for push (`git-receive-pack`). Feature is
gated by `$g_git_http_enabled` (set ON in `config_inc.php` on vaio).

Files: `config_defaults_inc.php` (4 keys), `core/git_http_api.php`,
`git_http.php`, `admin/tools/git-serve.conf` (installed + enabled on vaio).


- ☑ Config keys (`config_defaults_inc.php`): `$g_git_http_enabled`,
  `$g_git_http_backend`, `$g_git_http_read_threshold`,
  `$g_git_http_write_threshold`.
- ☑ `core/git_http_api.php`: slug↔project mapping, token extraction, request
  parsing/validation (allowlist of smart-HTTP endpoints, path-traversal guard),
  access check, and the `git-http-backend` proxy (`proc_open`, CGI header
  parsing).
- ☑ `git_http.php`: thin entry point (bootstrap + `git_http_handle_request()`).
- ☑ Apache `git-serve.conf`: route `/git/...` → gateway; enable, reload.
- ☑ Functional test: create an API token; `git clone http://…/git/<slug>.git`
  from a remote-style client; verify content; verify auth is enforced (401
  without token) and authz (403/404 for no-access / unknown project).
- ☑ Confirm **push is rejected** (`git-receive-pack` → 403) in this phase.

## Phase 2 — Push (draft write) support  ⊘ deferred

Enables the actual requirement: advanced users push draft updates. Safe under
the On-Record/Draft model (`{dwg_primary_file}.git_sha` vs `HEAD`), but requires
(see PROJECT_REPOS.md §P2.2.1):

- ⊘ Gateway: permit `git-receive-pack` for users at the **write** threshold;
  enable `http.receivepack` per repo.
- ⊘ **Reject force-push / non-fast-forward** on the served branch.
- ⊘ **Worktree sync**: `GitFileStorageBackend::store()` must `fetch` +
  fast-forward/rebase to `origin/HEAD` before its own commit, or the first
  external push breaks UI-driven primary-document uploads.
- ⊘ **Pin approved SHAs** with a managed ref (e.g.
  `refs/doctis/approved/<dwg_id>/<n>`) so a draft history rewrite can never
  orphan/GC an On-Record version.
- ⊘ Surface the "approve current draft" action (`file_dwg_primary_sync_head()`)
  as the promotion step in the UI.

## Phase 3 — Repository naming stability  ⊘ deferred (prerequisite for durable URLs)

- ⊘ Adopt Part 1 **Option C** (`<slug>-<id>.git`, persisted) so a project rename
  cannot break users' configured git remotes. De-duplicate the slug helper
  (currently 6 copies). Until then, Phase 1 uses the live `<slug>.git` scheme
  with a reverse lookup, and rename is a known hazard.

## Phase 4 — Repository contents & integrity  ◐

- ☑ Attachments excluded from the repo (Part 1 §8 — done; only the primary
  registered document is versioned).
- ⊘ Worktree sync + approved-SHA pinning (shared with Phase 2).

## Phase 5 — UX, security hardening & docs  ⊘ deferred

- ⊘ Surface clone URL + token guidance in the UI (replace the server-local
  `/tmp/<slug>` text on `dwg_primary_head_warn.php` with the real remote URL).
- ⊘ **TLS**: require HTTPS in production (tokens travel as HTTP Basic). Today's
  vhost is plain HTTP on the LAN — acceptable for dev only.
- ⊘ Rate-limiting / auth logging / lockout integration.
- ⊘ Document the project-level (not per-document) nature of git access and that
  per-document `view_dwg_threshold` / license gating is **not** enforced over
  git. Restrict the read/write entitlements accordingly.
- ⊘ Update CLAUDE.md with a "Remote Git Access" section once stable.

---

## Open concerns / decisions log

- **Authz granularity:** git serves whole repos; per-document ACL/license gating
  cannot be enforced. Phase 1 gates on a project-level **read** threshold
  (`$g_git_http_read_threshold`, default DEVELOPER).
- **Buffering:** Phase-1 proxy buffers the backend response in memory (fine for
  small document repos). Streaming is a later optimisation for large repos.
- **Feature flag:** `$g_git_http_enabled` defaults OFF; the gateway 404s unless
  explicitly enabled in `config_inc.php`.
