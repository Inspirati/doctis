# Doctis Git User Manual

**Audience:** developers who work on Doctis-hosted repositories from their own
machines, and the document controllers and managers who register and approve
what those repositories hold.

**Companion to:** [MANUAL.md](MANUAL.md), the general Doctis user manual. This
manual covers everything to do with the git repositories behind Doctis; the
general manual covers documents, reviews and issues.

---

## Contents

1. [What this is](#1-what-this-is)
2. [Concepts](#2-concepts)
3. [Document control and source repositories](#3-document-control-and-source-repositories)
4. [Getting a clone](#4-getting-a-clone)
5. [Working in a clone](#5-working-in-a-clone)
6. [Bringing an existing repository into Doctis](#6-bringing-an-existing-repository-into-doctis)
7. [Registering a repository as a document](#7-registering-a-repository-as-a-document)
8. [Registering individual files you pushed](#8-registering-individual-files-you-pushed)
9. [Documents inside a source repository](#9-documents-inside-a-source-repository)
10. [Large binary files (git LFS)](#10-large-binary-files-git-lfs)
11. [For administrators](#11-for-administrators)
12. [Quick reference](#12-quick-reference)

---

## 1. What this is

Every document Doctis controls is stored in git. Each project's files live in
a git repository on the Doctis server, and every revision of every document is
a commit in it. Doctis records which commit of each document is **On Record**,
the approved version, and runs the review and approval around it.

Those repositories are not hidden. You can clone one onto your own computer
with ordinary git and work in it as you would any repository: edit, commit,
branch, push. Doctis is the server you push to.

That gives you three things:

- **Developers work with their own tools.** No uploading files through a web
  page one at a time; edit locally, commit, push.
- **Approval stays in Doctis.** A push never changes what is approved. It
  becomes a **Draft**, and the approved version stays exactly where it was
  until somebody with authority promotes the Draft inside Doctis.
- **Whole source trees can be controlled records.** A Doctis document can
  stand for an entire repository at a commit, not just one file. That is how
  firmware and software releases sit in the same project list as the plans,
  procedures and drawings around them, each with its own approval cycle.

The guiding rule throughout: **the repository is where work happens; Doctis is
where approval happens.**

---

## 2. Concepts

### 2.1 Repositories and projects

A top-level project and all its sub-projects share **one repository** by
default. A sub-project can be given a repository of its own (see §11), and
then becomes the starting point for its own sub-projects in turn.

A repository is named `<project-name>-r<N>`, for example `gimbal-source-r44`.
The number never changes; the name part follows the owning project if it is
renamed, and old addresses keep working. Its address is:

```
https://<your-doctis-server>/git/<project-name>-r<N>.git
```

### 2.2 On Record and Draft

| | Meaning |
|---|---|
| **On Record** | The approved version of a document: one specific commit. This is what Doctis serves when someone downloads the document |
| **Draft** | A newer version of the document that exists in the repository but has not been promoted. It appears automatically when someone pushes a change |

Promoting a Draft (**Promote Draft** on the document's page) makes it the new
On Record version. Until then, downloading the document gives the approved
version, and **Download Draft Version** gives the new one, so a reviewer can
compare the two.

### 2.3 Approved-version pins

Every time a version goes On Record, Doctis creates a permanent git reference
for it:

```
refs/doctis/approved/<document id>/<n>
```

`<n>` counts the approved versions of that document: 1, 2, 3 and so on. These
references are managed by the server. **Nobody can push to them**, under any
repository policy. Because a git commit's content can never change once made,
a pinned approved version is permanent: it stays retrievable even if the
branch it came from is later rewritten or deleted.

You can see them from a clone with `git ls-remote origin 'refs/doctis/*'`.

---

## 3. Document control and source repositories

Every repository has a **push policy**. It decides which git operations the
server accepts.

| Operation | Document control | Source repository |
|---|---|---|
| clone, fetch, pull | yes | yes |
| commit and push | yes | yes |
| create a branch or tag | yes | yes |
| rebase and force-push | **refused** | yes |
| delete a branch or tag | **refused** | yes |
| move an existing tag | **refused** | yes |
| push to `refs/doctis/*` | **refused** | **refused** |

**Document control** is the default. It is append-only: you can add to
history but never replace it. **Source repository** allows normal software
development, including rebasing and tidying branches.

Neither policy stops you committing a change to a document's file, or even
deleting it. Either way your push becomes a Draft, and nothing changes in
Doctis until somebody promotes it.

### 3.1 Why the difference matters

ISO 9001:2015 clause 7.5, *Documented information*, sets out what a quality
management system must do with the documents and records it controls. The
parts relevant here, paraphrased (consult the standard for its exact text):

- **7.5.2, Creating and updating:** documented information gets appropriate
  review and approval for suitability and adequacy.
- **7.5.3.1, Control of documented information:** it must be adequately
  protected, including against *"loss of integrity"*.
- **7.5.3.2:** the organisation must address, among other things, the
  *"control of changes (e.g. version control)"* and the retention of
  documented information. Documented information retained as evidence of
  conformity must be *"protected from unintended alterations"*.

Here is how each policy measures up:

| What a controlled document needs | Document control | Source repository |
|---|---|---|
| The approved version can never change (7.5.3.1, 7.5.3.2) | **yes**, by the approved-version pins | **yes**, by the same pins |
| A permanent record of every change between approved versions (7.5.3.2, control of changes) | **yes**: history can only grow | **no**: commits between approvals can be rewritten or erased |
| Evidence of exactly what was reviewed before approval (7.5.2) | **yes**: a Draft that was reviewed stays in history | **no**: a Draft under review can be force-pushed away and replaced |
| The branch a document follows stays in place | **yes** | **no**: branches can be deleted |

So the approved versions themselves are equally safe under both policies. What
a source repository gives up is the **audit trail around them**: how one
approved version became the next, and proof of what the reviewers saw. That
trail is what makes a document *controlled* rather than merely *stored*.

**The rule:** official controlled documentation belongs in a **document
control** repository. A source repository is for source code, and for
registering releases of it (§7).

### 3.2 One repository, one policy

The policy belongs to the repository, not to a project or a file, so one
repository cannot be append-only for documents and rewritable for development
at once. That is why a source project normally has a repository of its own,
separate from the document-control repository its parent project uses. For
example:

```
Gimbal                 → document control repository  (plans, ICDs, procedures)
└─ Gimbal Source       → its own source repository     (firmware source tree)
```

Each project's repository and policy are shown on Manage → Projects, in the
Repository column, and on Manage Project → **Repository**.

---

## 4. Getting a clone

### 4.1 The Clone button (recommended)

Use **Clone this repository**, found on Manage Project → **Repository** and in
the Primary Document panel of any document whose file is in git. It:

1. creates an **API token** for you. Git never uses your Doctis password; the
   token takes its place;
2. shows a ready-made `git clone` command with the token included, which you
   can copy with one click;
3. offers a small **clone script** (`.sh` for Linux and macOS, `.cmd` for
   Windows). Save it wherever you want to work, then run it. It asks which
   directory to clone into, or takes one as an argument:
   `sh clone-myrepo.sh ~/work/myrepo`.

A web page cannot run git on your computer or choose a folder for you, which
is why the last step is a script rather than a dialog.

**You are shown the token once.** Doctis stores only a scrambled form of it
and cannot show it again. If you lose it, press the button again. That
**adds** a new token rather than replacing the old one, so anything already
using the old one keeps working. A tick-box lets you revoke the old ones if
you want to, and says what that will break. You can see and revoke all your
tokens under My Account → **API Tokens**.

**Treat a token as a password.** It is your full Doctis credential, not
something limited to one repository. If one is exposed, pasted into a chat or
a ticket for example, revoke it straight away.

### 4.2 Cloning by hand

1. Under My Account → **API Tokens**, create a token and copy it.
2. Run, using the same scheme (`http` or `https`) as the address you use for
   Doctis in your browser:
   ```bash
   git clone https://<your-username>@<server>/git/<project>-r<N>.git
   ```
   When git asks for a password, paste the **token**. Your account password
   will not work.

To avoid the prompt you can put the token in the address
(`https://<your-username>:<token>@<server>/git/…`). That works, but the token
then sits in your shell history and in the clone's `.git/config`.

### 4.3 Your token stopped working

A clone keeps the token it was made with. If that token is revoked, fetching
and pushing start failing with:

```
remote: Authentication required: use your Doctis username and an API token as the password.
fatal: Authentication failed for 'https://<server>/git/<repository>.git/'
```

You do not need to clone again. Get a new token (§4.1), then inside your
existing clone run:

```bash
git remote set-url origin https://<your-username>:<new-token>@<server>/git/<repository>.git
```

Or take the credentials out of the address and let git ask each time:

```bash
git remote set-url origin https://<server>/git/<repository>.git
```

### 4.4 If the clone fails

| Symptom | Cause and remedy |
|---------|------------------|
| `Failed to connect to <server> port 80` after a long wait | The server accepts only HTTPS. Use `https://`. Git never reached Doctis, so it could not ask for a password; this is the usual cause of "git never asked for my token" |
| `HTTP 401` or `Authentication failed` | Wrong username, or you gave your account password. Use your Doctis username and an API token, and check the token has not been revoked |
| `HTTP 403` | Your account lacks the access level needed on the project that owns the repository. Reading and pushing have separate thresholds (§11). Ask a project manager |
| `HTTP 404 Repository not found` | The repository name is wrong. It is `<project>-r<N>`, shown on Manage Project → Repository |
| `404` on every repository | Git access is switched off on the server, or its proxy does not pass `/git/` through. Ask an administrator |
| `HTTP 413` when pushing | The server's upload limit is smaller than your push. Ask an administrator (§11) |
| Git fails with `could not read Password` and never prompts | Prompting is disabled on your computer: `GIT_TERMINAL_PROMPT=0` is set, there is no terminal, or a credential helper is answering for you. Unset it, or put the token in the address |

To test an address without credentials:

```bash
curl -sI "https://<server>/git/<project>-r<N>.git/info/refs?service=git-upload-pack"
```

A `401` with `WWW-Authenticate: Basic realm="Doctis Git"` means the address is
right and git will ask for credentials. A timeout means the server is not
reachable on that scheme.

---

## 5. Working in a clone

From a developer's side there is nothing special about a Doctis repository:
`git commit`, `git push`, `git fetch`, branching and merging all behave
normally. The differences are on the server.

**A push moves the Draft, never the approved version.** If your push changes a
registered document's file, that document gets a Draft. On Record stays where
it was until somebody promotes the Draft in Doctis. Downloading the document
still gives everyone the approved version.

**A refused push tells you why.** In a document-control repository:

```
remote: doctis: non-fast-forward update of refs/heads/my-branch rejected
        (force-push / history rewrite is not permitted)
remote: doctis: deleting refs/heads/my-branch is not permitted
```

**Pushing needs a higher access level than cloning.** By default, cloning
needs *developer* access to the project that owns the repository, and pushing
needs *manager* access (§11).

---

## 6. Bringing an existing repository into Doctis

If the project already exists as a git repository somewhere else, on GitHub
or on your own machine, you can move it into Doctis with its full history,
every branch and every tag.

1. In Doctis, create the project (and sub-project, if the code should have a
   repository of its own, see §3.2).
2. An administrator creates the repository on Manage Project → **Repository**,
   and sets its push policy. Choose **source repository** for code.
3. In your existing clone, point it at Doctis and push everything:
   ```bash
   git remote set-url origin https://<your-username>:<token>@<server>/git/<project>-r<N>.git
   git push --all
   git push --tags
   ```
   If the clone has no `origin` yet, use `git remote add origin <address>`
   instead of `set-url`.

Your history, branches and tags arrive unchanged. A working clone only pushes
the branches it has locally, though. To bring a GitHub repository across with
*all* its branches, start from a bare clone, which holds every branch:

```bash
git clone --bare https://github.com/<owner>/<repo>.git
cd <repo>.git
git remote add doctis https://<your-username>:<token>@<server>/git/<project>-r<N>.git
git push doctis --all
git push doctis --tags
```

Avoid `git clone --mirror` with `git push --mirror` for this. A mirror push
also copies GitHub's internal pull-request references, and deletes any
reference on the Doctis server that your copy does not have.

Do not use GitHub's **Download ZIP** for this. A ZIP is a snapshot of one
branch with no history at all.

The repository now exists in Doctis, but nothing in it is a document yet. §7
and §8 cover registering what it holds.

---

## 7. Registering a repository as a document

A **source record** is a Doctis document that stands for a whole repository,
or one directory of it, at a commit. It is the entry people see in the
project's document list, and it gives them the repository: as a downloadable
archive, or with instructions to clone it.

### 7.1 Lines and releases

A source record is one of two kinds:

| | What it is | Behaviour |
|---|---|---|
| **Tracking line** | A branch that goes on: `main`, a maintenance branch, a per-customer branch | Follows its branch. Every push to that branch offers the new commit as a Draft |
| **Frozen release** | A version that has been issued, such as `v1.2` | Pinned at one commit. Gets no further Drafts |

A line is the living record of a branch. A release is a snapshot of it with
its own revision, status, owner, approval cycle and issues. If you cut a
release document for each version, the project's document list shows every
release ever issued, each with the commit it was approved at.

You do not have to use both. A single line, promoted each time a version is
approved, is a perfectly good record if you do not need a row per release.

### 7.2 Before you start

These need to be in place, normally by an administrator (§11):

- the project has its **own repository**, with the **source repository**
  policy;
- **Source releases** is enabled for the project, on Manage Project →
  **Repository**;
- the code has been pushed (§6).

### 7.3 Step by step

**1. Create the document, with no file.** Switch to the source project and use
Create Document. Give it a clear title, for example *Gimbal Firmware Source*.
**Leave the Primary Document file empty.** The registration button only
appears while a document has no file; if you upload one here, it disappears.

**2. Open the document's page.** The Primary Document panel shows a **Source
Release** row with a **Register Source Release** button.

**3. Fill in the form:**

| Field | What to enter |
|---|---|
| **Record as** | **Tracking line** for the living record of a branch, or **Frozen release** for an issued version |
| **Release from branch** | The branch, normally `main` or `master` |
| **Commit** | Blank for the tip of the branch, or a specific commit |
| **Subtree** | Blank for the whole repository, or a directory such as `firmware` to record only part of it |
| **Release note** | Optional |

**4. Check the result.** The Primary Document panel now offers:

- **Download**: the recorded commit as a `zip` archive of the whole tree, in a
  single folder named after the repository and commit, for example
  `gimbal-source-d9896116/`. File permissions and symbolic links are kept. For
  a tarball instead, add `&format=tar.gz` to the download address;
- **Clone this repository** (§4.1).

### 7.4 After a push

When someone pushes to a tracking line's branch:

1. The document shows a **Draft** at the new commit, with **Promote Draft**.
2. **Download Draft Version** gives the new tree, so the change can be
   reviewed against the approved one.
3. **Promote Draft** makes it the new On Record version and pins it as the
   next approved version (`refs/doctis/approved/<id>/2`, then `/3`, …). Every
   earlier approved version stays pinned and downloadable.

Instead of promoting, **Cut Release** creates a *separate* frozen release
document at that commit, and leaves the line tracking. Use it when each
version should have its own document, its own approval and its own issues.

### 7.5 Freezing

**Freeze** stops a record from offering new Drafts; **Unfreeze** reverses it.
Both are recorded in the document's history. A frozen source record is a
release; an unfrozen one is a line.

Freeze is not only for source records. Any document whose file is in git can
be frozen when its issued revision is final.

The document list's **Kind** column and filter show which state each record is
in: document, frozen document, source line or source release.

---

## 8. Registering individual files you pushed

A file you add in a clone and push is in the repository, but it is not a
Doctis document until somebody registers it.

**Repository Files**, in the sidebar, lists the files in the current project's
repository that are not yet documents. You see it if you can clone the
repository and create documents in the project, which by default means
developer access. It is also linked from the Create Document form, under the
file upload field.

Each file shows its size, when it last changed and by whom, and a
**Register** button. Register opens the normal Create Document form with the
repository path already filled in. There is nothing to upload, because the
content is already there.

The document is pinned to the commit that **last changed that file**, not to
the repository's latest commit. It records the change that actually produced
it, even if other people have pushed since.

**Any file type works.** A STEP model, a PDF or a drawing registers exactly as
a Markdown file does. What makes something a document is its record in
Doctis, not the format of the file.

**Markdown with a frontmatter block fills the form in for you.** A block like
this at the top of the file:

```markdown
---
doc_id: PROC-ENG-012
title: Firmware Release Procedure
revision: A
status: Draft
author: Engineering Lead
---
```

supplies the title, number (from `doc_id`), revision, status and author. Such
files are marked *frontmatter* in the list. Where several carry one,
**Register all N with frontmatter** creates them all in one action. Files
without frontmatter are registered one at a time, because only a person can
say what they are called.

**Files inside a registered repository record** (§7) are not counted as
unregistered, because they are already part of that record. They are hidden
by default; select the *inside a registered repository record* count to list
them. They can still be registered as documents of their own, one at a time,
with the caveat in §9. **Register all** never includes them.

Administrators can do the same from Manage → **Git Store**, for any
repository on the server.

---

## 9. Documents inside a source repository

You *can* register an individual file that lives in a source repository, such
as an interface control document kept beside the code. It works, but **that
document is not under full document control**, for the reasons in §3.1: its
approved versions are pinned and permanent, but the history of changes between
them can be rewritten, and a Draft under review can be replaced.

Doctis tells you so wherever it matters. A notice reading *"Source
repository: not under full document control"* appears:

- on the document's page;
- when registering a file from a source repository;
- when promoting its Draft;
- when changing its status to an approved status.

It informs rather than refuses, because the approved versions really are
safe. Whether the missing change history matters is a judgement about the
document.

### 9.1 When a document must be officially controlled

If the document is part of the project's official controlled documentation,
**move it into a document-control project**:

1. Promote or discard any Draft first. A document with a Draft cannot be
   moved.
2. Use **Move** on the document's page, and choose a project whose repository
   uses the document-control policy.

The move commits the document's On Record version into the destination
repository, makes it On Record there, and **removes the file from the source
repository** in a commit of its own. The source repository's history still
shows the file up to that point.

### 9.2 Closing it out in the source repository

The removal is one commit in a repository where history can be rewritten and
old branches merged back. Unless the developers know the document has moved,
it can quietly come back, and an uncontrolled copy then drifts away from the
controlled one. After a move:

- **Tell the developers.** Anyone with an older branch still has the file;
  merging that branch, or reverting the removal, brings it back.
- **Leave a pointer if the path is referenced.** If build scripts, code
  comments or other files refer to the old path, replace the file with a
  one-line stub saying where the controlled document now lives, for example:
  ```markdown
  Moved to Doctis document ICD-HCR571-001 (project Gimbal). Do not edit here.
  ```
- **Never edit the old copy again.** The controlled document has been
  promoted to official project documentation; changes to it now go through
  its own Draft and approval in the document-control project.

---

## 10. Large binary files (git LFS)

Some repositories are mostly large binaries: CAD models, firmware images,
manufacturing output. Plain git keeps every version of every file forever,
and every clone downloads all of it. **Git LFS** keeps a small pointer in the
commit and stores the file itself separately, downloading only the versions
you actually check out.

Doctis acts as the LFS server for its own repositories, with no extra service
and no second password. In a clone:

```bash
git lfs install
git lfs track "*.step" "*.bin"      # your large file types
git add .gitattributes
git commit -m "Track large files with LFS"
git push
```

From then on those files transfer through Doctis automatically, using the
same token as the clone. Documents registered against them behave normally:
the document page shows the real file size, and downloading gives you the
file, not the pointer.

Two limits:

- **Publishing is refused** for an LFS-tracked document, since publishing
  renders Markdown to a web page.
- **A repository archive contains pointers, not files.** Downloading a source
  record of a repository that uses LFS gives you the pointer text for the
  LFS-tracked files. Clone the repository if you need their content.

---

## 11. For administrators

### 11.1 Setting up a project

Everything is on Manage Project → **Repository**:

- which repository the project uses, and whether it is the project's own or
  **inherited** from a parent;
- **Give this project its own repository**: needed before its push policy can
  differ from its parent's. It is refused once the project already stores
  documents in the inherited repository, so decide this *before* anything is
  uploaded to a new source sub-project;
- the **push policy**: administrators only, and only on the project that owns
  the repository;
- **Source releases**: whether documents in this project may record a
  repository (§7).

Manage → **Projects** shows the repository and policy of every project and
sub-project in its Repository column.

### 11.2 Access levels

| Action | Setting | Default |
|---|---|---|
| Clone and fetch | `$g_git_http_read_threshold` | developer |
| Push | `$g_git_http_write_threshold` | manager |
| Promote a Draft | | manager |
| Register a source record | `$g_update_dwg_threshold` | developer |

Access is checked against the project that **owns** the repository.

### 11.3 Git Store

Manage → **Git Store** is the repository-centric view of the whole server:
files, every registered document and its state, commits, references and the
approved-version pins. It stays administrator-only; developers register
files from **Repository Files** instead (§8).

A repository whose owner project has been deleted is shown as **unowned**.
Deleting a project does not delete its repository, because the repository is
then the only remaining record of that project's documents. An unowned
repository can be removed there with **Remove repository**, which deletes it,
its working copy and its LFS objects permanently.

### 11.4 Server requirements

- Remote git access must be enabled (`$g_git_http_enabled`).
- The web server's upload limit must be lifted on the `/git/` route. A push
  sends everything as one request, so the usual limit rejects any real
  repository with `HTTP 413`.
- For LFS: `$g_git_lfs_enabled` and a writable `$g_git_lfs_root` on persistent
  storage. See [git/LFS_DEPLOYMENT.md](git/LFS_DEPLOYMENT.md).
- **Backup Git Store** includes the LFS objects automatically. A backup of the
  repositories alone would restore every commit and lose every large file.

---

## 12. Quick reference

| I want to… | Do this |
|---|---|
| Clone a project's repository | **Clone this repository** on Manage Project → Repository, or on a document's page (§4.1) |
| Fix a clone whose token was revoked | Get a new token, then `git remote set-url origin …` in the clone (§4.3) |
| Move a GitHub repository into Doctis | Create the project and repository, then push `--all` and `--tags` from a bare clone (§6) |
| Register a whole repository as a document | Create a document with **no file**, then **Register Source Release** (§7.3) |
| Accept a pushed change to a registered repository | **Promote Draft** on its document (§7.4) |
| Give each version its own document | **Cut Release** on the tracking line (§7.4) |
| Register a file I pushed | **Repository Files** in the sidebar → **Register** (§8) |
| Make a document in a source repository official | **Move** it to a document-control project, then close out the source copy (§9) |
| Store large binaries | `git lfs track` in your clone (§10) |
