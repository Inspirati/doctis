# Git LFS in Doctis

Status: **in development** on branch `git-lfs` (started 2026-10-26).
Prerequisite reading: [GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md) for the
storage baseline and [SOURCE_REPOSITORIES.md](SOURCE_REPOSITORIES.md) for the
clone/push gateway this builds on.

## 1. Why

Doctis stores every primary registered document in git. That is right for
Markdown and good enough for a PDF, but a repository whose documents *are*
large binaries — the `HCR-Hardware-Designs` case, mostly CAD and manufacturing
output — grows a packfile that every clone must download in full, forever.
Git LFS replaces the blob in the commit with a small text **pointer** and keeps
the bytes in a separate content-addressed store, fetched on demand.

Two things follow, and they are separate pieces of work:

1. **Doctis must be an LFS server**, or a clone of a Doctis-hosted LFS
   repository cannot fetch its files and a push cannot deliver them.
2. **Doctis must read through a pointer itself.** A document whose git blob is
   a pointer would otherwise download as 130 bytes of text, publish as
   nonsense, and appear in Git Store with the wrong size.

## 2. The protocol, and what of it we implement

git-lfs talks to a server over HTTPS at an endpoint derived from the git remote
URL by appending `/info/lfs`. For a Doctis remote
`http://host/git/<slug>-r<id>.git` that is
`http://host/git/<slug>-r<id>.git/info/lfs` — already inside the `/git/` route,
so **no nginx change is needed** and the existing API-token Basic auth applies
unchanged.

| Endpoint | Method | Ours | Purpose |
|----------|--------|------|---------|
| `info/lfs/objects/batch` | POST | yes | The only required call. Client sends `{operation, objects:[{oid,size}]}`; server answers with a per-object `actions.upload` / `actions.download` href, or an `error` |
| `info/lfs/objects/<oid>` | PUT | yes | "basic" transfer upload — the href we hand back |
| `info/lfs/objects/<oid>` | GET | yes | "basic" transfer download |
| `info/lfs/objects/<oid>/verify` | POST | yes | Confirms the object landed at the right size |
| `info/lfs/locks*` | any | no — 501 | File locking. Optional; `git lfs lock` is for exclusive checkout of unmergeable binaries and is not needed to store or fetch them |

Only the **basic** transfer adapter is offered. `multipart` and
`lfs-standalone-file` are optional and add nothing here.

Content types are specific and the client checks them:
`application/vnd.git-lfs+json` for batch and verify, raw bytes for the
transfers.

## 3. Object store

```
$g_git_lfs_root/<slug>-r<id>/<oid[0:2]>/<oid[2:4]>/<oid>
```

- Keyed by the object's **sha256**, which is what `oid` is; the path layout
  mirrors git-lfs's own so the directory is recognisable and auditable.
- **Per repository**, not shared. A shared pool would mean one repository's
  objects were reachable through another's access check, and would make
  deleting a repository unsafe. Duplication across repositories is accepted.
- Outside the bare repository, so `git clone --bare`, `git bundle` and the
  Backup Git Store tarball all behave predictably — and so that **an LFS
  object is never in a packfile**, which is the whole point.
- The `-r<id>` suffix is authoritative here as everywhere; a project rename
  relocates the directory alongside the bare repo.

Consequences that must be handled explicitly rather than discovered later:

- **Backup Git Store must include it**, or a restore loses every large file
  while the commits that reference them survive. Worse than useless.
- **`git bundle --all` does not carry LFS objects.** Any bundle-based import or
  export needs the object store shipped beside it (§6).

## 4. Do not install the LFS filter for `www-data`

`git lfs install` writes `filter.lfs.*` into the user's gitconfig. If that were
done for `www-data`, every Doctis worktree checkout would try to contact an LFS
server to smudge files it has no business fetching, and a commit would try to
*clean* a file into a pointer behind Doctis's back. The server identity
therefore stays filter-free: `/var/www/.gitconfig` gains nothing, and Doctis
handles pointers explicitly in the one place that reads content (§5).

The consequence is that Doctis's worktrees hold **pointers**, which is correct —
the worktree is a staging area for commits, not a place files are read from.

## 5. Reading through a pointer

A pointer blob is three or four short lines:

```
version https://git-lfs.github.com/spec/v1
oid sha256:4d7a...
size 12345
```

`git_lfs_parse_pointer()` recognises exactly that and nothing else, so a real
file that merely starts with the word "version" is never mistaken for one.
Call sites that must read through it:

| Path | Today | With LFS |
|------|-------|----------|
| `GitFileStorageBackend::retrieve()` | `git show <sha>:<path>` into memory | pointer → stream the object from the store |
| `file_download.php` (primary) | echoes `retrieve()`'s content | streams, so a 500 MB file does not have to fit in `memory_limit` |
| `file_dwg_primary_register()` | records `filesize` from the blob | records the pointer's `size`, which is the real file's size |
| Publishing | pandoc reads the file | a pointer is not Markdown; refuse with a clear message |
| `git archive` (source release) | streams the tree | emits pointers. Documented, not fixed: a release tarball of an LFS repo is pointers unless `git archive` is given a smudge filter, which is a later decision |
| Git Store preview | shows the blob | shows the pointer, labelled, with the real size |

## 6. Import

`admin/import-git-repo.php:178` currently aborts when `.gitattributes` names
`filter=lfs`. Lifting that abort is only safe once the objects come across too,
because `git clone --bare` of an LFS remote fetches **no** LFS objects — the
adopted repository would be complete in its history and empty of its content.

The route that works, matching the owner's preference for pre-prepared bundles
over Doctis authenticating to GitHub:

```bash
git clone --mirror <github url> repo.git
git -C repo.git lfs fetch --all          # fills repo.git/lfs/objects
git -C repo.git bundle create ../repo.bundle --all
tar czf ../repo-lfs.tar.gz -C repo.git lfs/objects
```

The importer then adopts the bundle with `clone --bare` **directly** (staging
via a plain clone silently keeps only HEAD's branch — proven) and unpacks the
object store into `$g_git_lfs_root/<slug>-r<id>/`.

## 7. Staging

| Stage | Content |
|-------|---------|
| 1 | LFS server: batch, basic transfer, verify, object store, config, gateway routing. A clone/push of an LFS repository works end to end |
| 2 | Reading through a pointer (§5), and Backup Git Store including the object store |
| 3 | Bundle + LFS import (§6), administrator-only, lifting the `filter=lfs` abort |

## 8. Decisions

- **L1.** Per-repository object store, not a shared pool — access control and
  safe deletion beat deduplication.
- **L2.** Basic transfer only; no multipart, no locking (501).
- **L3.** No LFS filter in the server gitconfig; pointers are handled in PHP.
- **L4.** LFS is per-install, not per-project: a repository holds LFS objects
  if its content does. `$g_git_lfs_enabled` exists to refuse the endpoints
  outright on an install that does not want them.
- **L5.** Import is by pre-prepared bundle plus object tarball, not by Doctis
  authenticating to GitHub (owner, 2026-10-26). Infrequent, expert-only
  operation; restricted to administrators.

## 9. Log

- **2026-10-26** — branch `git-lfs` taken from `source-repos`. `git-lfs 3.6.1`
  installed on the VM for client-side testing. Design recorded.
