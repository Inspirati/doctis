# SOAP API testing and diagnostics

Recipes for exercising and debugging the Doctis SOAP API by hand — raw curl
envelopes, the base64 double-encoding rule, and how to chase a
*Procedure not present* fault. Split out of CLAUDE.md on 2026-10-30, where
it was 194 lines that are needed only when working on SOAP.

The SOAP API is the primary programmatic interface for document *file*
operations. See also [DOCTIS-SOAP.md](DOCTIS-SOAP.md) for the operation
reference, [REST-AND-SOAP-API.md](REST-AND-SOAP-API.md) for what each API
covers, and [../TESTING.md](../TESTING.md) §3 for the scripted smoke test.

---

The Doctis SOAP API is the primary programmatic interface for document
operations.  Use raw `curl` SOAP calls to exercise and diagnose individual
endpoints without requiring a browser or a configured SoapClient.

## Smoke test — all Doctis SOAP endpoints in one command

```bash
bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.109/doctis manager '' <dwg_id>
#  host                    user    pw  test document
```

Runs 13 tests covering every Doctis-specific SOAP endpoint (enum, document
fetch, primary file lifecycle, attachment lifecycle).  Non-fatal — all
steps run even if earlier ones fail.  Exit code 0 = all pass.  Full
description in [admin/tools/README.md](../../admin/tools/README.md).

The document id is required (the script refuses to run without one); host,
user and password default to the values shown. **Point it only at a throwaway
document**, created for the test in a scratch project: it deletes the target's
primary file at the start and again in step 7, and on the VM every document
holds imported content. Turn email notifications off while it runs, and
remove the scratch project and its repository afterwards
([doc/TESTING.md](../../doc/TESTING.md) §3).

## SOAP endpoint and WSDL

```
SOAP endpoint : http://10.0.0.109/doctis/api/soap/mantisconnect.php
WSDL          : http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl
```

List all available operations (useful to confirm new endpoints appear after
a WSDL change):

```bash
curl -s "http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -oP 'operation name="\K[^"]+' | sort
```

Look up parameter names for a specific method:

```bash
curl -s "http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -A8 '"mc_dwg_primary_uploadRequest"'
```

## Raw curl SOAP call template

Every SOAP call needs two headers and an XML envelope:

```bash
curl -s \
  -H "Content-Type: text/xml; charset=utf-8" \
  -H 'SOAPAction: ""' \
  --data '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:OPERATION_NAME>
      <username>manager</username>
      <password></password>
      PARAMETERS
    </man:OPERATION_NAME>
  </soapenv:Body>
</soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php"
```

## Useful diagnostic one-liners

**Connectivity / version check:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_version><username>manager</username><password></password></man:mc_version></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" | grep -oP '<return[^>]*>\K[^<]+'
```

**List all document status values:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_enum_dwg_status><username>manager</username><password></password></man:mc_enum_dwg_status></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" \
  | grep -oP '(?<=<name xsi:type="xsd:string">)[^<]+'
```

**Fetch a document by id (note: parameter is `issue_id`):**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_get><username>manager</username><password></password><issue_id>2</issue_id></man:mc_dwg_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" \
  | grep -oP '<title[^>]*>\K[^<]+'
```

**Check primary file metadata for a document:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_primary_get><username>manager</username><password></password><dwg_id>2</dwg_id></man:mc_dwg_primary_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php"
# Returns empty PrimaryFileData if no file; filename/filesize/file_type/download_url if present
```

## base64Binary encoding for file upload/download

PHP's SoapServer **pre-decodes** `xsd:base64Binary` input parameters before
passing them to the PHP handler.  PHP's SoapClient compensates by
double-encoding.  Raw `curl` callers must therefore also double-encode:

```bash
# Upload: double-encode the content
CONTENT="file bytes here"
B64=$(printf '%s' "$CONTENT" | base64 -w 0 | base64 -w 0)
# Send <content>${B64}</content> in the SOAP body

# Download (mc_dwg_attachment_get): double-decode the returned value
RETURNED_B64="... value from <return> element ..."
printf '%s' "$RETURNED_B64" | base64 -d | base64 -d
```

This does **not** affect PHP SoapClient or PHPUnit tests — the SoapClient
handles the double-encoding transparently.  It only matters for raw curl
diagnostic calls against base64Binary parameters/returns.

## Verifying git commit attribution after a file operation

After any upload or delete via SOAP (or the web UI), confirm the commit is
attributed to the correct Doctis user:

```bash
git --git-dir=/var/git/doctis/<slug>-r<N>.git log --format='%h %an <%ae> %s' -5
# (repo basename is <slug>-r<repository_id> — ls /var/git/doctis/ to find it)
# Should show the Doctis user's realname (or username) and email, not the
# server identity "Doctis <doctis@vaio.local>" from /var/www/.gitconfig
```

## Diagnosing "Procedure not present" SOAP errors

This error means the SoapServer received a call for an operation it has no
registered PHP function for.  Work through in order:

1. **Is the operation in the WSDL?**
   ```bash
   grep 'mc_my_function' /var/www/html/doctis/api/soap/mantisconnect.wsdl
   # Must appear in <message>, <portType>, and <binding> sections
   ```

2. **Clear the WSDL cache** (PHP caches parsed WSDL in `/tmp/wsdl-*`;
   PHP-FPM has no private `/tmp` on the VM):
   ```bash
   sudo rm -f /tmp/wsdl-*
   ```

3. **Force OPcache recompilation of `mc_core.php`** — the most common cause
   after adding new `require_once` lines.  OPcache caches compiled bytecode
   keyed on file mtime; touching the file forces recompilation on the next
   request:
   ```bash
   touch /var/www/html/doctis/api/soap/mc_core.php
   ```
   If that is not enough, `sudo systemctl reload php8.4-fpm` clears OPcache.

4. **Confirm the PHP function is defined** by loading the SOAP stack from
   CLI as the correct user:
   ```bash
   # Write a check script:
   cat << 'EOF' | sudo -u www-data php
   <?php
   # Bootstrap MantisBT, then load the SOAP stack
   $t_mantis_dir = '/var/www/html/doctis/';
   require_once $t_mantis_dir . 'core.php';
   require_once $t_mantis_dir . 'api/soap/mc_core.php';
   $fns = array_filter(get_defined_functions()['user'],
       fn($f) => strpos($f, 'mc_dwg') === 0 || $f === 'mc_enum_dwg_status');
   sort($fns);
   echo implode("\n", $fns) . "\n";
   EOF
   # If the function is absent, the require_once chain has a gap
   ```

5. **Check the PHP error log** for include-time PHP errors that silently abort
   the SOAP bootstrap:
   ```bash
   tail -30 /var/log/doctis/php_errors.log
   ```

---
