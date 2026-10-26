# GitList evaluation on the native VM

Installed and checked **2026-10-26** on `10.0.0.109`. This is a local,
read-only repository browser evaluation. Doctis continues to own repository
storage, clone/push authorization and release approval.

## Access and lifecycle

Open **<http://127.0.0.1:8091/> in a browser on the VM**. Suggested starting
points:

- [Source Trial](http://127.0.0.1:8091/source-trial-r2.git): hardware source tree.
- [Source Trial Large](http://127.0.0.1:8091/source-trial-large-r3.git): Doctis
  source, including file history, blame and diffs.
- [Firmware trial](http://127.0.0.1:8091/split-trial-firmware-r16.git): several
  branches and approved revision pins.

From another computer, forward the port over SSH, then use the same URL:

```bash
ssh -N -L 8091:127.0.0.1:8091 robert@10.0.0.109
```

All installation files are under `/home/robert/Documents/gitlist-eval`, outside
both Doctis checkouts. The service is transient: it remains running after this
installation session, but **does not start automatically after reboot**. Files
persist across reboots. Run these commands as `robert` on the VM:

```bash
# Stop the evaluation.
/home/robert/Documents/gitlist-eval/stop.sh

# Start it again after stopping, or after a reboot.
/home/robert/Documents/gitlist-eval/start.sh

# Check its status.
systemctl status doctis-gitlist-eval.service
```

Stopping it does not stop or reload any Doctis service. After stopping,
removing `/home/robert/Documents/gitlist-eval` removes the installation; no
nginx site, system service file, cron job, account or package needs undoing.

## Package and local adjustments

The installed official release asset is
[gitlist-3.0.0-beta3.zip](https://github.com/klaussilveira/gitlist/releases/tag/3.0.0-beta3),
published 2026-10-23. It is a **beta**, rather than a production recommendation.
The package includes its PHP dependencies and compiled browser assets; no
Composer install, package install or upgrade was performed.

The downloaded archive is retained in the evaluation directory. Its SHA-256
matched the digest in GitHub's release metadata:

```text
4aa764d3b793987d0c9f0343e170361f57cd70bbfec1efd13755f2d0ae45f318
```

Only the evaluation copy was configured/customized:

- `app/config/config.yml`: evaluation title, repository root `/var/git/doctis`,
  and clone links pointing to Doctis's existing HTTP Git gateway.
- `app/config/framework.yml`: separate session cookie name and removal of the
  syslog handler. Application logs stay in the evaluation directory.
- `app/assets/themes/default/templates/Repository/show.html.twig`: clone widget
  offers the Doctis HTTP URL, without advertising an unconfigured SSH endpoint.
- `runtime.env`: private production-mode environment and generated app secret.
  No `.env` is placed in the application: the packaged release omits the Dotenv
  component its optional `.env` loading path requires.
- `php.ini` and `router.php`: private CLI PHP settings, public asset routing,
  host checks, and rejection of directory traversal. No upstream PHP application
  source was changed.

## Isolation

`start.sh` creates only an independent transient systemd service under `/run`,
running PHP's development HTTP server on **127.0.0.1:8091**. It is suitable for
this evaluation, not a production web serving arrangement.

- Existing nginx and PHP-FPM configuration, sockets and processes are untouched.
  The CLI process reads its own `php.ini` and the existing CLI extension list.
- The service has a read-only filesystem view, including the authoritative Git
  store. Only its private `var` directory and private temporary space are writable.
  It does not clone, import, move, chown or install hooks into Doctis repositories.
- The process cannot access `/var/www`, the database data directory, or the
  database/PHP-FPM sockets. Home directories are hidden except the evaluation
  installation. It runs without privilege escalation capabilities.
- A startup check verifies that repository configuration and object directories
  are not writable, its cache is writable, and Doctis configuration is unreadable.
- CPU is limited to half of one core, memory to 512 MiB and tasks to 32, with
  low CPU/IO scheduling priority. PHP's memory limit is 192 MiB. These limits
  apply only to the evaluation process; the VM's existing resources remain shared.
- It listens only on loopback; an HTTP connection to `10.0.0.109:8091` was
  refused. Service IP filtering additionally permits only localhost.

**This browser does not enforce Doctis user/project permissions.** Anyone with
access to the local evaluation endpoint can browse its configured repositories,
including raw files and archives. Keep it local or use the SSH tunnel. Clone/push
still uses the Doctis gateway and its API tokens. LAN publication would require
an authorization design covering every browser endpoint.

## Verified behavior and findings

The index discovers eight repositories. Six populated repositories render their
root trees. The existing `lifecycle-trial-r36.git` and `split-trial-tools-r17.git`
have no refs; opening their root pages returns HTTP 500 because this release
tries to resolve the unborn `main` branch. Their repositories were left unchanged.

Verified on populated repositories:

- Branch/tag pages, commit listing and individual commit diffs, graph and
  comparison pages, metadata, file views, raw downloads, blame and file history.
- Stylesheets, scripts and editor assets return successfully; a Firefox headless
  screenshot confirmed the rendered repository page. The screenshot remains in
  the private evaluation directory.
- A raw README download matches `git show` byte for byte. A small ZIP download
  is a valid archive with two entries.
- An existing approved revision can be browsed by commit SHA. GitList does not
  offer Doctis's `refs/doctis/approved/*` as release records in its branch/tag UI;
  the inspected revision parser recognizes branches, tags and SHAs. Linking
  approved SHA views from Doctis remains future integration work.
- Private configuration paths and traversal requests are denied. Unrecognized
  Host headers are rejected.
- Before/after hashes, ownership and file modes match for **549 files** covering
  `/etc/nginx`, `/etc/php`, Doctis PHP configuration, and all files within the
  eight bare repositories. No files were added within that recorded scope.
- nginx, PHP-FPM and MariaDB retain the same PIDs and activation timestamps.
  Doctis login renders; unauthenticated Git discovery still returns HTTP 401.

No database operation, email test or repository write/push was performed. No
Doctis application deployment or schema upgrade was needed.

Local evidence: `baseline.json`, `var/http-checks.json`,
`var/verification.json`, `preview.png` and logs under `var/log`. Run `python3
/home/robert/Documents/gitlist-eval/verify.py` on the VM to repeat the read-only
verification. Its original baseline is a point-in-time record: later intentional
Doctis configuration or repository changes will naturally produce differences.
