# Doctis Git Server Operations

*Reference for the git storage backend feature — documents every git operation
the PHP backend must perform, with the equivalent SSH command for manual
verification and testing from the development machine.*

---

## Environment

| Item | Value |
|------|-------|
| Server | vaio (`10.0.0.10`) |
| Apache webroot | `/var/www/html/doctis/` |
| Git process user | `www-data` (same user Apache/PHP runs as) |
| Bare repo root | `/var/git/doctis/` |
| Working tree root | `/var/www/doctis/worktrees/` |
| SSH user | `hcr` |

All git operations that Doctis PHP code performs run on vaio as `www-data`.
When verifying or setting up infrastructure manually from the dev machine,
prefix every command with `ssh hcr@vaio "..."`.

---

## One-Time Server Setup

Run once to establish the directory roots and global git identity for `www-data`.
These are prerequisites before any PHP code can be tested.

### Create storage root directories

```bash
ssh hcr@vaio "
  sudo mkdir -p /var/git/doctis &&
  sudo mkdir -p /var/www/doctis/worktrees &&
  sudo chown www-data:www-data /var/git/doctis /var/www/doctis/worktrees &&
  sudo chmod 2770 /var/git/doctis /var/www/doctis/worktrees
"
```

The `2770` mode (setgid) ensures files and subdirectories created inside
inherit the `www-data` group, preventing permission drift as PHP creates
per-project repositories over time.

### Set global git identity for www-data

PHP commits will fail silently or with an error if git has no author identity.
Set it globally for the `www-data` user once:

```bash
ssh hcr@vaio "
  sudo -u www-data git config --global user.email 'doctis@vaio.local' &&
  sudo -u www-data git config --global user.name 'Doctis' &&
  sudo -u www-data git config --global init.defaultBranch main
"
```

Setting `init.defaultBranch main` ensures all new repos use `main` rather
than `master`, which is the convention used throughout this codebase.

Verify:

```bash
ssh hcr@vaio "sudo -u www-data git config --global --list"
```

---

## Operation 1 — Project Creation

**When it runs:** when a new Doctis project is created via the admin UI.

**What PHP must do:** initialise a bare repo and clone a working tree from it.

### 1a. Initialise bare repository

```bash
ssh hcr@vaio "sudo -u www-data git init --bare /var/git/doctis/<project-slug>.git"
```

The bare repo is the authoritative, durable store. It holds the full object
database and history but has no working files. Only `git push` writes to it;
never write files into it directly.

### 1b. Clone working tree from bare repo

```bash
ssh hcr@vaio "sudo -u www-data git clone /var/git/doctis/<project-slug>.git /var/www/doctis/worktrees/<project-slug>"
```

The working tree is where PHP writes source files before committing. It is
disposable — if it is deleted or corrupted it can be re-cloned from the bare
repo at any time with no data loss.

### Verify project setup

```bash
ssh hcr@vaio "ls -la /var/git/doctis/ && ls -la /var/www/doctis/worktrees/"
```

---

## Operation 2 — Document Upload (store)

**When it runs:** when a document source file is uploaded via `file_dwg_add()`.

**What PHP must do:** write the file into the working tree, stage it, commit,
then push to the bare repo.

### The three-step sequence (add → commit → push)

```bash
ssh hcr@vaio "sudo -u www-data bash -c '
  cd /var/www/doctis/worktrees/<project-slug> &&
  cp /tmp/uploaded-file.md <dwg_reference>/source.md &&
  git add <dwg_reference>/source.md &&
  git commit -m \"dwg_id=<id> rev=<revision> by <username>\" &&
  git push origin main
'"
```

> **The push is mandatory.** `git commit` only writes to the working tree's
> local history. The bare repo — the durable store — does not receive the
> commit until `git push` is called. If push is omitted, the bare repo and
> working tree diverge; retrieval against the bare repo will return stale
> or absent content. The `store()` method in `GitStorageBackend` must
> always call push as its final step.

### What to store in `{dwg_file}` after a successful push

| Column | Value to store |
|--------|---------------|
| `diskfile` | Commit SHA returned by `git rev-parse HEAD` after push |
| `folder` | Absolute path to the bare repo: `/var/git/doctis/<project-slug>.git` |
| `content_hash` | Git blob SHA: `git hash-object <file>` (optional integrity column) |

---

## Operation 3 — Document Retrieval (retrieve)

**When it runs:** when `file_dwg_get_content()` is called to serve a file download.

**What PHP must do:** extract the file content from the bare repo using the
stored commit SHA.

### Retrieve current version by commit SHA

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git show <commit-sha>:<dwg_reference>/source.md"
```

Reading directly from the bare repo (not the working tree) is the correct
approach for retrieval — the bare repo is authoritative and the working tree
may be in a transitional state during a concurrent upload.

### Retrieve latest version (HEAD)

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git show HEAD:<dwg_reference>/source.md"
```

### Verify a stored commit SHA matches expected content

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git show <commit-sha>:<path> | sha256sum"
```

---

## Operation 4 — Document Deletion (soft delete)

**When it runs:** when `file_dwg_delete()` is called.

**What PHP must do:** remove the file from the working tree, commit the
removal, and push. The file disappears from `HEAD` but the full history —
including every previous version — is permanently retained in the bare repo.

```bash
ssh hcr@vaio "sudo -u www-data bash -c '
  cd /var/www/doctis/worktrees/<project-slug> &&
  git rm <dwg_reference>/source.md &&
  git commit -m \"dwg_id=<id> FILE_DELETED by <username>\" &&
  git push origin main
'"
```

> Never rewrite git history (no `git filter-repo`, no force-push) to remove
> a deleted document. The git history is the audit trail. Deletion in Doctis
> is always a soft-delete commit.

---

## Operation 5 — Revision History

**When it runs:** when the document revision view page requests the file's
change history.

### List all commits touching a document file

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git log --oneline -- <dwg_reference>/source.md"
```

### Show full metadata for a specific commit

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git show --stat <commit-sha>"
```

---

## Operation 6 — Integrity Verification

**When it runs:** optionally on download, or as an admin diagnostic.

### Verify a file's content hash against the stored blob SHA

```bash
ssh hcr@vaio "sudo -u www-data git --git-dir=/var/git/doctis/<project-slug>.git hash-object -w --stdin < <(git --git-dir=/var/git/doctis/<project-slug>.git show <commit-sha>:<path>)"
```

Simpler equivalent for a file on disk:

```bash
ssh hcr@vaio "sudo -u www-data git hash-object /var/www/doctis/worktrees/<project-slug>/<dwg_reference>/source.md"
```

The SHA returned must match the value stored in `{dwg_file}.content_hash`.

---

## Operation 7 — Working Tree Recovery

**When it runs:** administrative recovery if the working tree is lost or
corrupted. The bare repo is never affected.

```bash
ssh hcr@vaio "
  sudo rm -rf /var/www/doctis/worktrees/<project-slug> &&
  sudo -u www-data git clone /var/git/doctis/<project-slug>.git /var/www/doctis/worktrees/<project-slug>
"
```

---

## PHP Implementation Notes

### Config keys (to add to `config_defaults_inc.php`)

```php
$g_git_storage_root   = '';   // abs path to bare repo root, e.g. /var/git/doctis
$g_git_worktree_root  = '';   // abs path to working tree root, e.g. /var/www/doctis/worktrees
$g_git_remote_url     = '';   // optional remote to push to (empty = local bare repo only)
$g_git_lfs_enabled    = OFF;  // Git LFS for large binaries (future)
```

Add to `config/config_inc.php` on vaio to activate during development:

```php
$g_file_upload_method = GIT;                             // new constant = 3
$g_git_storage_root   = '/var/git/doctis';
$g_git_worktree_root  = '/var/www/doctis/worktrees';
```

### Project slug derivation

The directory name used for both the bare repo and working tree is derived
from the Doctis project name. It must be filesystem-safe:

```php
function git_project_slug( $p_project_name ) {
    return preg_replace( '/[^a-z0-9\-]/', '-', strtolower( trim( $p_project_name ) ) );
}
// "My Project" → "my-project"
// bare repo:    /var/git/doctis/my-project.git
// working tree: /var/www/doctis/worktrees/my-project
```

### Commit message format

```
dwg_id=<id> rev=<revision> by <username>
```

Examples:
```
dwg_id=42 rev=B by jsmith
dwg_id=42 FILE_DELETED by jsmith
```

### Branch name

All repos use `main` (enforced by the `init.defaultBranch main` global config
set during server setup). Push target is always `origin main`.

### czproject/git-php call sequence for upload

```php
$git  = new CzProject\GitPhp\Git;
$repo = $git->open( $t_worktree_path );
// write $t_content to $t_file_path inside the worktree first
$repo->addFile( $t_file_path );
$repo->commit( $t_commit_message );
$repo->push( 'origin', ['main'] );   // ← must not be omitted
$t_sha = (string) $repo->getLastCommitId();
```

---

## Quick Reference

| Operation | SSH command skeleton |
|-----------|---------------------|
| Create bare repo | `sudo -u www-data git init --bare /var/git/doctis/<slug>.git` |
| Create working tree | `sudo -u www-data git clone /var/git/doctis/<slug>.git /var/www/doctis/worktrees/<slug>` |
| Store file | `git add <file> && git commit -m "..." && git push origin main` |
| Retrieve by SHA | `git --git-dir=<bare> show <sha>:<path>` |
| Retrieve HEAD | `git --git-dir=<bare> show HEAD:<path>` |
| Soft delete | `git rm <file> && git commit -m "..." && git push origin main` |
| List history | `git --git-dir=<bare> log --oneline -- <path>` |
| Recover worktree | `rm -rf <worktree> && git clone <bare> <worktree>` |
| Check www-data config | `sudo -u www-data git config --global --list` |
