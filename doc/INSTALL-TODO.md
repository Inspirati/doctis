# Install Scripts — Headless Support Audit (`admin/tools/`)

## Design intent

The scripts are structured as independent modules that can be run at any layer
of an installation.  Environments that are already partially provisioned — a
Docker container with LAMP pre-installed, an existing server that only needs
Doctis/MantisBT/phpMyAdmin deployed — do not need to re-run earlier stages.

In particular, `install-target.sh` (Layer 2) is the primary deployment script:
it clones the repos, generates `config_inc.php`, publishes the webroot, and
runs the MantisBT database installer.  It can be run standalone on any server
or container where Apache + PHP + MariaDB are already present, without touching
`install-system.sh` (Layer 1).

The intended layer structure is:

```
install.sh / install-lan.sh         — entry point convenience wrappers
  └─ install-option.sh              — orchestrator: fetch + source sub-scripts
        ├─ install-system.sh        — Layer 1: LAMP + system tools (needs sudo)
        └─ install-target.sh        — Layer 2: clone, configure, publish, DB install
              └─ doctis-git-setup.sh — Layer 3: git document storage

doctis-drop-and-create-new-database.sh  — standalone: reset DB + reload example data
check.sh                                — standalone: virtualisation detection
install-check.sh                        — standalone: virt-detect + platform dispatch stubs
```

A Docker deployment skips Layer 1 entirely and runs only `install-target.sh`
(and optionally `doctis-git-setup.sh`).

---

## Module-by-module headless assessment

### `install-system.sh` — Layer 1 — headless-safe

`set_headless()` is defined locally for standalone use.
`install_tools()` correctly gates `install_tools_gui()` (meld, VSCodium) on
`HEADLESS=false`. No interactive prompts. No browser/GUI launch calls.
On a Docker target this module is simply not run at all — LAMP is pre-installed.

### `install-target.sh` — Layer 2 — headless-safe ✔ (fixed)

`set_headless()` is defined locally for standalone use.
`setup_target()` calls `set_headless()` first, then correctly gates
`configure_vscode()`. `install_doctis()` correctly gates `launch_target()`
(firefox, xdg-open).

`prompt_delete_dir()` (line 48) now checks `$HEADLESS` and the Docker sentinel
(`/.dockerenv`) before issuing a `read` prompt.  When either condition is true
it auto-confirms deletion and logs the action.  The blocking-on-headless-rerun
issue documented in the original audit has been resolved.

### `doctis-git-setup.sh` — Layer 3 / standalone — fully headless-safe

No GUI operations. No interactive prompts. Explicitly documented as idempotent
(safe to re-run). Colour output is suppressed when stdout is not a terminal,
which is good practice for pipe/log use. Requires root, correctly checked at
entry.

### `doctis-drop-and-create-new-database.sh` — standalone — headless-safe in Docker, blocks elsewhere

Has a Docker bypass (`/.dockerenv` check) that skips the interactive `read`.
When sourced from another script rather than invoked directly, `main()` also
runs without prompting. On a headless non-Docker system invoked directly, the
`read` blocks.  For unattended non-Docker use, pipe `echo 'yes'` as in the
CLAUDE.md reset procedure.

### `install-option.sh` — orchestrator — headless behavior delegated to sub-scripts

No interactive prompts of its own. Correctly passes arguments through. The
`DOCTIS_SCRIPT_URL` environment variable override (used by `install-lan.sh`)
is a clean design that allows LAN installs to pull scripts from vaio instead
of GitHub without modifying the orchestrator.

### `install-lan.sh` — entry point wrapper — correctly handles pipe invocation

The `exec </dev/tty` is intentional and correct: when the script is fetched and
piped directly to bash (`curl URL | bash`), bash's stdin is the pipe rather than
the terminal. Reconnecting stdin to `/dev/tty` before proceeding allows sudo to
prompt for a password.

### `check.sh` — standalone virtualisation detection — fine as-is

No interactive prompts. No GUI operations. All four virt-specific install
handlers are stubs with TODO comments. These are where platform-specific
pre-install steps would go; they are not part of the main install chain.

### `install-check.sh` — standalone virtualisation dispatch — functional

Contains `detect_virtualization()` (using `systemd-detect-virt` with DMI and
metadata fallbacks), platform-specific install stubs (`install_vbox`,
`install_qemu`, `install_droplet`, `install_baremetal`), and `chkinst_virt()`
dispatcher. Runs the dispatcher unconditionally on execution. The stubs are
intentional placeholders for platform-specific pre-install steps.

---

## Issues remaining

### 1. `install_vbox()` defined twice in `install-system.sh` — stub overrides real implementation

Lines 259–329: complete, working implementation (interactive/non-interactive
modes, CD-ROM mounting, kernel module detection). Lines 337–341: a stub
identical to the one in `install-check.sh`, with a TODO comment.

The stub was copied from `install-check.sh` (where environment-specific
dispatch stubs belong) without removing the real implementation above it.
Because bash processes the file top-to-bottom, the stub wins and the real
implementation becomes dead code.

**Fix:** remove lines 337–341 from `install-system.sh`. The dispatch stubs
belong only in `install-check.sh`.

### 2. `set_headless()` is duplicated across `install-system.sh` and `install-target.sh`

Given the standalone module design this is partly intentional — each module
must be self-contained when run independently. The two copies are currently
identical, so any future change must be made in both places.

**Fix (optional):** extract into a shared `install-utils.sh` sourced by both,
or accept the duplication as the cost of standalone capability and add a comment
noting that both copies must be kept in sync.

### 3. `doctis-drop-and-create-new-database.sh` blocks on headless non-Docker direct invocation

The interactive `read` is only bypassed when `/.dockerenv` is present or the
script is sourced. A headless bare-metal or VM re-run invoked directly will
block.

**Fix:** honour a `DOCTIS_YES=1` (or `HEADLESS=true`) env var in the same way
that `install-target.sh` now does in `prompt_delete_dir()`.
