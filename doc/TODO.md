# TODO

## Install Scripts — Headless Support Audit (`admin/tools/`)

### Design intent

The scripts are structured as independent modules that can be run at any layer
of an installation, so that environments already partially set up (e.g. a
Docker container with LAMP pre-installed) do not need to re-run earlier steps.

The intended layer structure is:

```
install.sh / install-lan.sh         — entry point convenience wrappers
  └─ install-option.sh              — orchestrator: fetch + source sub-scripts
        ├─ install-system.sh        — Layer 1: LAMP + system tools (needs sudo)
        └─ install-target.sh        — Layer 2: clone, configure, publish, DB install
              └─ doctis-git-setup.sh — Layer 3: git document storage

doctis-drop-and-create-new-database.sh  — standalone: reset DB + reload example data
check.sh                                — standalone: virtualisation detection
install-check.sh                        — standalone: WIP scratch/diagnostic
```

A Docker deployment skips Layer 1 entirely and runs only `install-target.sh`
(and optionally `doctis-git-setup.sh`). This changes the assessment of several
issues from the first audit pass.

---

### Module-by-module headless assessment

**`install-system.sh` — Layer 1 — headless-safe**

`set_headless()` is defined locally (line 72) for standalone use.
`install_tools()` correctly gates `install_tools_gui()` (meld, VSCodium) on
`HEADLESS=false`. No interactive prompts. No browser/GUI launch calls.
On a Docker target this module is simply not run at all — LAMP is pre-installed.

**`install-target.sh` — Layer 2 — mostly headless-safe, one blocking gap**

`set_headless()` is defined locally (line 102) for standalone use.
`setup_target()` calls `set_headless()` first, then correctly gates
`configure_vscode()`. `install_doctis()` correctly gates `launch_target()`
(firefox, xdg-open). These are all right.

The gap: `prompt_delete_dir()` (line 65) issues `read -rp "Type 'yes' to
delete it:"` with no bypass for headless or unattended runs. It is called from
`publish_target`, `install_phpmyadmin`, and `install_dokuwiki`. On a headless
server that already has a `doctis` directory in the webroot the script will
hang indefinitely. The `$HEADLESS` flag is set before `prompt_delete_dir()` is
reached, but the prompt takes no notice of it.

This is the most important gap for Docker/headless deployment: a re-run of
`install-target.sh` against an already-installed server will stall.

**`doctis-git-setup.sh` — Layer 3 / standalone — fully headless-safe**

No GUI operations. No interactive prompts. Explicitly documented as idempotent
(safe to re-run). Colour output is suppressed when stdout is not a terminal
(line 45–48), which is good practice for pipe/log use. Requires root, correctly
checked at entry. Well-designed for standalone use.

**`doctis-drop-and-create-new-database.sh` — standalone — headless-safe in Docker,
blocks elsewhere**

Has a Docker bypass (`/.dockerenv` check at line 197) that skips the
interactive `read`. When the script is sourced from another script rather than
invoked directly, `main()` also runs without prompting. On a headless non-Docker
system invoked directly, the `read` blocks.

**`install-option.sh` — orchestrator — headless behavior delegated to sub-scripts**

No interactive prompts of its own. Correctly passes arguments through. The
`DOCTIS_SCRIPT_URL` environment variable override (used by `install-lan.sh`)
is a clean design that allows LAN installs to pull scripts from vaio instead
of GitHub without modifying the orchestrator.

**`install-lan.sh` — entry point wrapper — correctly handles pipe invocation**

The `exec </dev/tty` at line 25 is intentional and correct: when the script is
fetched and piped directly to bash (`curl URL | bash`), bash's stdin is the
pipe rather than the terminal. Reconnecting stdin to `/dev/tty` before
proceeding allows sudo to prompt for a password. This is the right solution
for that invocation pattern.

**`check.sh` — standalone virtualisation detection — fine as-is**

No interactive prompts. No GUI operations. All four virt-specific install
handlers (`install_vbox`, `install_qemu`, `install_droplet`, `install_baremetal`)
are stubs with TODO comments. These stubs are where platform-specific
pre-install steps would go; they are not part of the main install chain.

**`install-check.sh` — standalone WIP/scratch — incomplete**

`run_mantis_install()` only echoes the URL; it does nothing. `main()` calls it
and returns. The interactive `read` guard at line 49 is present for direct
invocation but the script does no actual work. Appears to be an early draft or
scratch file retained for reference.

---

### Issues remaining

**1. `prompt_delete_dir()` blocks on headless non-Docker re-runs**

`admin/tools/install-target.sh` line 65. The `$HEADLESS` flag is available at
the point `prompt_delete_dir()` is called, but the function ignores it.

Fix: check `$HEADLESS` inside `prompt_delete_dir()` and auto-confirm (or
auto-skip) when true. Alternatively, honour a `--yes` / `DOCTIS_YES=1` env var
threaded from the entry point, matching the Docker bypass pattern already used
in `doctis-drop-and-create-new-database.sh`.

**2. `install_vbox()` defined twice in `install-system.sh` — stub overrides real implementation**

Lines 253–322: complete, working implementation (interactive/non-interactive
modes, CD-ROM mounting, kernel module detection). Lines 331–335: a stub
identical to the one in `install-check.sh`, with a TODO comment.

The stub was copied from `install-check.sh` (where environment-specific
dispatch stubs belong) into `install-system.sh` without removing the real
implementation above it. Because bash processes the file top-to-bottom the
stub wins, leaving the real implementation as dead code.

Fix: remove lines 331–335 from `install-system.sh`. The stubs belong only in
`install-check.sh` / `check.sh`.

**3. `set_headless()` is duplicated across `install-system.sh` and `install-target.sh`**

Given the standalone module design this is partly intentional — each module
must be self-contained when run independently. However the two copies are
currently identical, so any future change must be made in both places.

Fix (optional): extract into a shared `install-utils.sh` sourced by both, or
accept the duplication as the cost of standalone capability and add a comment
noting that both copies must be kept in sync.

**4. `install-check.sh` appears to be an incomplete draft**

The script defines infrastructure (`run_mantis_install`, a `main()`, guard
logic) but `run_mantis_install()` only prints a URL — it performs no checks.
Either complete it as a pre-install diagnostic or remove it to avoid confusion
with the working `check.sh`.
