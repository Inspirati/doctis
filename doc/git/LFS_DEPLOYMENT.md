# Deploying Git LFS on a Doctis Server

Audience: the server administrator of a Doctis deployment. Written against the
live Docker deployment at `qms.hcrobo.com`, but the requirements are the same
on any host.

What this covers: the five changes needed before a Doctis server can host
git-LFS repositories, and the one ceiling that no configuration can raise.
For what LFS is and how Doctis implements it, see [GIT_LFS.md](GIT_LFS.md);
for users, `doc/MANUAL.md` §13.6.

**Nothing here is needed to run the current release.** With LFS left off,
every other feature works as deployed, the endpoints answer 501, and ordinary
clone and push are unaffected.

## 1. Settings to add

Two keys. Compared key by key against a working installation, nothing else
the release needs is absent.

| Setting | Value | Notes |
|---------|-------|-------|
| `$g_git_lfs_enabled` | `ON` | Off by default. While off the LFS endpoints answer 501 |
| `$g_git_lfs_root` | `/var/git/doctis-lfs` | Must be writable by the web-server user, and must **not** sit inside `$g_git_storage_root` |

The live config reads most settings from the environment, so in house style
that is `GIT_LFS_ENABLED` and `GIT_LFS_ROOT`, alongside the existing
`GIT_HTTP_ENABLED` and `DWG_UPLOAD_METHOD`.

The config file can be changed through Manage → System Operations → Config
File without a container rebuild. The volume in §2 cannot, so make both
changes together.

## 2. Persistent storage for the LFS objects

**This is the change that loses data if it is missed.**

`/var/git/doctis-lfs` must be a named volume or bind mount with the same
persistence as `/var/git/doctis` — never a directory in the container's own
writable layer.

Git LFS keeps a short text pointer in the commit and the file's bytes outside
the repository. If the object store lives in the container layer, every
redeploy destroys every large file while the commits referencing them survive.
The repository then looks healthy and its files cannot be retrieved — worse
than an obvious failure, because backups taken afterwards preserve the damage.

Required:

- Mount path `/var/git/doctis-lfs`, owned by the web-server user, mode `2770`,
  matching the existing `/var/git/doctis` mount.
- Backed up on the same schedule and retention as the git store.
- Not nested inside `/var/git/doctis`, so an LFS object can never be swept
  into a packfile or a repository clone.

The directory grows to roughly the total size of every version of every large
file ever pushed. Nothing prunes it automatically.

## 3. Request body size limits

Three independent ceilings, enforced in different places. Raising one does not
raise the others.

| Ceiling | Applies to | Needed for LFS |
|---------|-----------|----------------|
| Front proxy `client_max_body_size` | Every request, including `/git/` | **Yes** — set to `0`/unlimited on the `/git/` route |
| PHP `post_max_size` | POST only | **Yes** for `git push`, which is a POST |
| PHP `upload_max_filesize` | Browser file uploads | Import Data only |

An LFS upload is an HTTP **PUT**, and PHP does not apply `post_max_size` to
PUT — verified by pushing a 100 MB object through the gateway, which returned
HTTP 200. LFS transfers therefore pass PHP untouched and are limited only by
the front proxy.

A `git push` **is** a POST and **is** capped by `post_max_size`. A first push
of a large history fails once the packfile exceeds it, with
`RPC failed; HTTP 413` or a PHP warning in the error log. Suggested:
`post_max_size = 512M` or higher, and no limit on the proxy's `/git/` route.

LFS reduces the exposure — once large files are tracked the packfile stays
small — but the initial push of existing history is still one large POST.

## 4. Importing a large repository

**No container setting will let an oversized repository through Import Data.**
`core/import_zip_api.php` enforces its own limits before PHP or the proxy are
involved, so raising `post_max_size`, `upload_max_filesize` and the proxy
limit together changes nothing. There are five, with two different purposes:

| Limit | Value | Purpose |
|-------|-------|---------|
| Compressed archive | 64 MiB | Resource policy; mirrors the usual `upload_max_filesize` |
| Entry count | 10,000 | Resource policy |
| Per-entry extracted | 64 MiB | Decompression-bomb defence |
| Total extracted | 256 MiB | Decompression-bomb defence |
| Copy cap per entry | 64 MiB + 1 | Catches an archive that under-declares an entry's size |

The last three are not arbitrary. The staging directory is
`sys_get_temp_dir()`, which on a typical container and on the development VM
is **tmpfs — RAM, not disk**. An unbounded extract is an out-of-memory event
for the whole host, not merely a full filesystem, and a declared entry size
comes from the archive, so it cannot be trusted on its own.

The first two are ordinary policy and could reasonably become configurable.
Raising them alone still achieves nothing unless `upload_max_filesize` and
`post_max_size` rise to match, since the upload is a browser multipart POST.

Size is rarely the binding constraint in practice. `HCR-Hardware-Designs`,
the repository that prompted LFS work, is 314 files and **26.4 MiB
compressed** — comfortably inside every limit above. The reason not to import
it as a ZIP is not size but **history**: Import Data discards it, creating one
synthetic commit, and that repository's 41 MB of `.git` *is* the design
revision record.

Use the ZIP route for a snapshot whose history does not matter. Use the push
route below whenever it does, whatever the size.

The route that works, and the reason §3's proxy limit matters:

1. Create the project and its repository in Doctis first.
2. `git clone --mirror <source url>` locally.
3. `git remote set-url origin https://<user>:<token>@<host>/git/<repo>.git`
4. `git push --all && git push --tags`

Full history and every branch come across. If the source already uses LFS, run
`git lfs fetch --all` locally before the push; LFS-aware *import* is not built
yet (GIT_LFS.md §6, stage 3).

## 5. Backups

Backup Git Store already covers the object store — no action needed — but
**the archive layout changes** once LFS content exists, so any script that
unpacks these archives must handle both shapes.

| Condition | Archive contents | Filename |
|-----------|-----------------|----------|
| No LFS content | Bare repositories at the top level | `doctis_git_backup_<ts>.tar.gz` |
| LFS content present | Two directories, `repositories/` and `lfs/` | `doctis_git_backup_lfs_<ts>.tar.gz` |

An install with no LFS content produces byte-for-byte the archive it always
did; confirmed against the live backup taken on 2026-10-27, which came down in
the old flat layout.

Never separate the two: repositories restored without the object store keep
every commit and lose every large file those commits point at.

## 6. What not to do

**Do not run `git lfs install` for the web-server user.** It writes
`filter.lfs.*` into that user's gitconfig, after which every Doctis worktree
checkout would try to contact an LFS server, and every commit would try to
rewrite files into pointers behind Doctis's back.

The `git-lfs` binary is **not required on the server** — Doctis implements the
protocol in PHP (`core/git_lfs_api.php`). Install it only for manual
maintenance, and leave the filter unconfigured.

## 7. Verification

Inside the container:

```bash
ls -ld /var/git/doctis-lfs                         # exists, web user, mode 2770
php -i | grep -E '^(post_max_size|upload_max_filesize)'
grep -r 'filter.lfs' ~/.gitconfig /var/www/.gitconfig   # must find nothing
```

From outside, with a valid API token:

```bash
curl -u <user>:<token> -X POST \
  -H 'Content-Type: application/vnd.git-lfs+json' \
  https://<host>/git/<repo>.git/info/lfs/objects/batch \
  -d '{"operation":"download","transfers":["basic"],"objects":[]}'
```

Expect a JSON body. `501` means LFS is not enabled; `401` means the token was
rejected but the gateway route is reachable.

End-to-end, from a clone:

```bash
git lfs install && git lfs track "*.bin"
git add .gitattributes && git commit -m "Track large files"
# add a large file, commit, push, then clone fresh and check the file is real
```
