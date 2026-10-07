# Doctis Document and License API Reference

**Covers:** REST and SOAP interfaces for the `documents` and `licenses` domains.
**Date:** 2026-06-29

---

## Contents

1. [Data model — what a Doctis document actually is](#1-data-model)
2. [Scope of each API operation](#2-scope-of-each-api-operation)
3. [Authentication](#3-authentication)
4. [REST API — documents](#4-rest-api--documents)
5. [REST API — licenses](#5-rest-api--licenses)
6. [SOAP API — documents](#6-soap-api--documents)
7. [SOAP API — licenses](#7-soap-api--licenses)
8. [Web server setup — Apache](#8-web-server-setup--apache)
9. [Web server setup — nginx](#9-web-server-setup--nginx)

---

## 1. Data model

A Doctis document is **not** a single database row. It is a set of rows spread across three tables, created atomically when a document is registered:

### `documents` — bibliographic registration record

Stores the document's immutable-at-creation identity.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int unsigned PK | Internal surrogate key; referenced as `document_id` in `dwg` |
| `title` | varchar(255) NOT NULL | Document title |
| `author` | varchar(255) | Primary author |
| `publisher` | varchar(255) | Publishing organisation |
| `reference` | varchar(64) | Formal reference number (indexed) |
| `number` | varchar(64) | Document number (indexed) |
| `edition` | varchar(64) | Edition label |
| `revision` | varchar(64) | Revision label |
| `link_url` | varchar(2048) | Optional hyperlink |
| `classification` | varchar(64) | Security classification |
| `revision_date` | int unsigned | Unix timestamp; 1 = not set |
| `release_date` | int unsigned | Unix timestamp; 1 = not set |

### `dwg` — workflow tracking record

The living record. This is what the API's `id` refers to. Changes as the document moves through review.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int unsigned PK | **The document ID used in all API calls** |
| `document_id` | int unsigned | FK → `documents.id` |
| `dwg_text_id` | int unsigned | FK → `dwg_text.id` |
| `project_id` | int unsigned | Project the document belongs to |
| `creator_id` | int unsigned | User who registered it |
| `handler_id` | int unsigned | Currently assigned reviewer/handler |
| `category_id` | int unsigned | Document category |
| `status` | smallint | Workflow status (110=pending … 195=archived) |
| `priority` | smallint | Priority (30=normal default) |
| `view_state` | smallint | 10=public, 50=private |
| `version` | varchar(64) | Version string |
| `discipline` | varchar(64) | Engineering discipline |
| `classification` | varchar(64) | See §2 note on shadowing |
| `date_submitted` | int unsigned | Unix timestamp |
| `last_updated` | int unsigned | Unix timestamp |
| `due_date` | int unsigned | Unix timestamp; 1 = not set |

### `dwg_text` — long-form text

| Column | Type |
|--------|------|
| `id` | int unsigned PK |
| `description` | longtext |
| `steps_to_reproduce` | longtext |
| `additional_information` | longtext |

> **Note on `classification` shadowing:** Both `documents.classification` and `dwg.classification` exist. The `documents` value is written at creation time and is not subsequently updated. The `dwg` value is what the API reads on GET. See `DEVELOPER_ISSUES_AND_HOTSPOTS.md` §2a for the full discussion.

---

## 2. Scope of each API operation

This is the critical section. The API operates on the composite document (all three tables), but with important limitations on UPDATE and DELETE.

### CREATE — all three tables written atomically

`POST /documents` (REST) and `mc_dwg_add()` (SOAP) both call `DwgData::create()`, which:

1. Inserts a row into `dwg_text` with the description fields.
2. Inserts a row into `documents` with the bibliographic fields.
3. Inserts a row into `dwg` with project/workflow fields, linking both.
4. Returns the `dwg.id` as the document ID for all future API calls.

All three inserts happen in a single request. The API call creates a **complete Doctis document entry** — not just a raw entry in the `documents` table.

### READ — joins all three tables

`GET /documents/{id}` (REST) and `mc_dwg_get()` (SOAP) join all three tables via `dwg_get()` / `dwg_get_extended_row()` and return a unified document object including bibliographic fields, workflow state, and description text.

### UPDATE — `dwg` and `dwg_text` only; `documents` table NOT updated

`PATCH /documents/{id}` (REST) and `mc_dwg_update()` (SOAP) can update:

| Updatable via API | Table |
|-------------------|-------|
| status, priority, handler, view_state, category | `dwg` |
| version, due_date | `dwg` |
| description, steps_to_reproduce, additional_information | `dwg_text` |
| Notes (dwgnotes) | `dwgnote` / `dwgnote_text` |

**Not updatable via API:**

| Field | Table | Workaround |
|-------|-------|------------|
| title, author, publisher | `documents` | None — requires direct DB edit |
| reference, number, edition, revision | `documents` | None — requires direct DB edit |
| link_url, classification (doc registration value) | `documents` | None |

The `documents` table has no `UPDATE` statement anywhere in the PHP layer. The bibliographic registration is treated as immutable after creation. If these fields need to be corrected, update `doctis.documents` directly in the database.

### DELETE — `dwg` and `dwg_text` removed; `documents` row NOT deleted

`DELETE /documents/{id}` (REST) and `mc_dwg_delete()` (SOAP) call `dwg_delete()`, which removes:

- All dwgnotes and dwgnote text
- All file attachments (and their git/disk/DB storage)
- All relationships and sponsorships
- All custom field values
- The history and revision log
- The `dwg_text` row
- The `dwg` row

It does **not** delete the `documents` table row. That row becomes an orphan. This is a known architectural gap — the `documents` table grows monotonically and is never pruned by the API. Manual cleanup: `DELETE FROM doctis.documents WHERE id NOT IN (SELECT document_id FROM doctis.dwg);`

---

## 3. Authentication

### REST — API token

The REST API does **not** accept HTTP Basic auth (username + password). It requires an API token passed as a plain Bearer token in the `Authorization` header:

```
Authorization: 4o6jhtFGS-HVEmkgHZA3pe95Cja9lsGu
```

No `Bearer ` prefix is required — the raw token string is used directly. Tokens are stored as SHA-256 hashes in `{api_token}`.

**Creating a token via web UI:**
Log in → click your username (top-right) → My Account → API Tokens tab → enter a name → Create Token. Copy the token immediately — it is shown only once.

**Creating a token via PHP (for scripted setup on a new server):**

```bash
ssh hcr@vaio "php -r \"
define('MANTIS_CORE', true);
chdir('/var/www/html/doctis');
require_once 'core.php';
\\\$user_id = user_get_id_by_name('manager');
\\\$token = api_token_create('rest-test', \\\$user_id);
echo \\\$token . PHP_EOL;
\""
```

### SOAP — username and password

Every SOAP call includes credentials in the request body:

```xml
<username>manager</username>
<password></password>
```

The default `manager` account has a blank password in the sample dataset.

---

## 4. REST API — documents

**Base URL:** `http://<host>/doctis/api/rest`
**Content-Type:** `application/json`

There is no `/v1/` prefix in the URL path. Slim 3 strips the script directory (`/doctis/api/rest`) from the request URI, so routes are registered and matched as `/documents[/{id}]`.

### 4.1 GET /documents/{id} — fetch one document

Returns full document detail including description, workflow state, and history.

```bash
TOKEN="your-api-token-here"
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/documents/2' | python3 -m json.tool
```

**Response 200:**
```json
{
  "documents": [
    {
      "id": 2,
      "title": "example Doc 1",
      "author": "Stress Author",
      "publisher": "Stress Corp",
      "number": "000001",
      "reference": "STR-000001",
      "edition": "Ed 1",
      "revision": "Rev A",
      "classification": "UNCLASSIFIED",
      "link_url": "",
      "revision_date": 1782621346,
      "release_date": 1782621346,
      "summary": "",
      "description": "Description of example Doc 1.",
      "project": { "id": 1, "name": "example" },
      "category": { "id": 1, "name": "General" },
      "status": { "id": 110, "name": "pending", "label": "pending" },
      "priority": { "id": 30, "name": "normal", "label": "normal" },
      "view_state": { "id": 10, "name": "public", "label": "public" },
      "creator": { "id": 7, "name": "manager", "email": "..." },
      "created_at": "2026-06-28T10:00:00+10:00",
      "updated_at": "2026-06-28T10:00:00+10:00",
      "history": [ ... ]
    }
  ]
}
```

**Response 404:** `404 Document #2 not found`

### 4.2 GET /documents — list documents

Returns paginated list with workflow state for each document. All fields shown in §4.1 are included per document.

```bash
# All documents in project 1, page 1, 50 per page (default)
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/documents?project_id=1'

# Paginate
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/documents?project_id=1&page=2&page_size=10'

# All projects
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/documents'
```

**Response 200:**
```json
{
  "documents": [ ... ],
  "total_count": 3,
  "page": 1,
  "page_size": 50
}
```

### 4.3 POST /documents — create a document

Creates a complete document entry across all three tables (see §2). Returns the full document object including the assigned `id`.

**Required fields:** `project`, `title`
**Useful fields:** `author`, `publisher`, `number`, `reference`, `edition`, `revision`, `classification`, `description`, `category`
**All other string fields** default to `''` if omitted.

```bash
curl -s -H "Authorization: $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
  "project":        { "id": 1 },
  "title":          "Safety Case Report",
  "author":         "J. Smith",
  "publisher":      "Engineering Division",
  "number":         "SC-2026-001",
  "edition":        "Ed 1",
  "revision":       "Rev A",
  "reference":      "SC-001",
  "classification": "UNCLASSIFIED",
  "description":    "Safety case for release 2.0.",
  "category":       { "id": 1 }
}' \
  'http://10.0.0.10/doctis/api/rest/documents'
```

**Response 201:** Document object under `"document"` key (singular).

**Optional workflow fields:**
```json
{
  "handler":    { "id": 7 },
  "priority":   { "id": 30, "name": "normal" },
  "view_state": { "id": 10, "name": "public" },
  "status":     { "id": 110, "name": "pending" }
}
```

### 4.4 PATCH /documents/{id} — partial update

Only fields present in the body are updated. All other fields retain current values. Updates `dwg` and `dwg_text` only — see §2 for what cannot be changed.

```bash
# Change status to 'received' (120)
curl -s -X PATCH \
     -H "Authorization: $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{ "status": { "id": 120, "name": "received" } }' \
  'http://10.0.0.10/doctis/api/rest/documents/5'

# Assign a handler and update description
curl -s -X PATCH \
     -H "Authorization: $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
  "handler":     { "id": 7 },
  "description": "Updated scope — now covers subsystem B."
}' \
  'http://10.0.0.10/doctis/api/rest/documents/5'
```

**Response 200:** Updated document under `"documents"` key (array with one entry).

### 4.5 DELETE /documents/{id} — delete a document

Removes the `dwg` and `dwg_text` rows, all notes, attachments, history, and relationships. The `documents` table row is NOT removed (see §2).

```bash
curl -s -X DELETE -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/documents/5' -w '%{http_code}\n'
# → 204
```

**Response 204:** No body.
**Response 404:** If the document id does not exist.

---

## 5. REST API — licenses

A Doctis license is a skill, security clearance, or professional qualification registered against a user account. It is unrelated to software licensing.

**Base URL:** `http://<host>/doctis/api/rest/licenses`

### 5.1 GET /licenses — list all (brief)

Returns id and name only for all licenses. No pagination — the full list is returned.

```bash
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/licenses'
```

**Response 200:**
```json
{
  "licenses": [
    { "id": 1, "name": "DDG - 0283-11/2063-08" },
    { "id": 2, "name": "DDG - 0296-11/1129-09" }
  ]
}
```

### 5.2 GET /licenses/{id} — fetch one (full detail)

```bash
curl -s -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/licenses/1'
```

**Response 200:**
```json
{
  "licenses": [
    {
      "id": 1,
      "name": "DDG - 0283-11/2063-08",
      "description": "",
      "enabled": true,
      "status":     { "id": 10, "name": "active",  "label": "active"  },
      "view_state": { "id": 10, "name": "public",  "label": "public"  },
      "access_level": { "id": 70, "name": "manager", "label": "manager" }
    }
  ]
}
```

**Response 404:** `404 License '1' not found`

### 5.3 POST /licenses — create

```bash
curl -s -H "Authorization: $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
  "name":        "ISO 9001 Lead Auditor",
  "description": "External audit qualification",
  "enabled":     true
}' \
  'http://10.0.0.10/doctis/api/rest/licenses'
```

**Response 201:** Full license detail under `"license"` key (singular).

### 5.4 PATCH /licenses/{id} — partial update

```bash
# Disable a license
curl -s -X PATCH \
     -H "Authorization: $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{ "enabled": false }' \
  'http://10.0.0.10/doctis/api/rest/licenses/27'
```

**Response 200:** Full license detail under `"license"` key.

### 5.5 DELETE /licenses/{id}

```bash
curl -s -X DELETE -H "Authorization: $TOKEN" \
  'http://10.0.0.10/doctis/api/rest/licenses/27' -w '%{http_code}\n'
# → 204
```

---

## 6. SOAP API — documents

The SOAP endpoint for all operations:

```
http://<host>/doctis/api/soap/mantisconnect.php
```

Full WSDL: append `?wsdl` to the endpoint URL.

```bash
# List all available operations
curl -s "http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -oP 'operation name="\K[^"]+' | sort
```

### SOAP envelope template

```bash
SOAP_URL="http://10.0.0.10/doctis/api/soap/mantisconnect.php"
SOAP() {
  curl -s \
    -H "Content-Type: text/xml; charset=utf-8" \
    -H 'SOAPAction: ""' \
    --data "$1" \
    "$SOAP_URL"
}
```

### 6.1 mc_dwg_get — fetch a document

> **Note on parameter naming:** The SOAP parameter is `issue_id` even though it refers to a document. This is intentional — see CLAUDE.md on `dwg_*` naming conventions.

```bash
SOAP '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:mc_dwg_get>
      <username>manager</username>
      <password></password>
      <issue_id>2</issue_id>
    </man:mc_dwg_get>
  </soapenv:Body>
</soapenv:Envelope>'
```

Returns a `DwgData` structure with all fields including description (via extended row fetch).

### 6.2 mc_dwg_add — create a document

Creates the complete three-table entry (same as `POST /documents`).

```bash
SOAP '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:mc_dwg_add>
      <username>manager</username>
      <password></password>
      <issue>
        <project><id>1</id></project>
        <title>Safety Case Report</title>
        <author>J. Smith</author>
        <publisher>Engineering Division</publisher>
        <number>SC-2026-001</number>
        <edition>Ed 1</edition>
        <revision>Rev A</revision>
        <reference>SC-001</reference>
        <classification>UNCLASSIFIED</classification>
        <summary>Safety Case Report</summary>
        <description>Safety case for release 2.0.</description>
        <category><name>General</name></category>
        <priority><name>normal</name></priority>
        <view_state><name>public</name></view_state>
      </issue>
    </man:mc_dwg_add>
  </soapenv:Body>
</soapenv:Envelope>'
```

Returns the new `dwg.id` as a plain integer.

### 6.3 mc_dwg_update — update workflow state

Updates `dwg` and `dwg_text` fields. Does not update the `documents` table (see §2).

Both `summary` and `description` are required fields in `mc_dwg_update()` — even if you only want to change the status, you must include them. Set them to the current values from a prior `mc_dwg_get` call.

```bash
SOAP '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:mc_dwg_update>
      <username>manager</username>
      <password></password>
      <issue_id>5</issue_id>
      <issue>
        <summary>Safety Case Report</summary>
        <description>Safety case for release 2.0.</description>
        <status><name>received</name></status>
        <handler><name>manager</name></handler>
      </issue>
    </man:mc_dwg_update>
  </soapenv:Body>
</soapenv:Envelope>'
```

Returns `true` on success.

### 6.4 mc_dwg_delete — delete a document

```bash
SOAP '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:mc_dwg_delete>
      <username>manager</username>
      <password></password>
      <issue_id>5</issue_id>
    </man:mc_dwg_delete>
  </soapenv:Body>
</soapenv:Envelope>'
```

Returns `true`. The `documents` table row is not removed (see §2).

### 6.5 Other SOAP document operations

| Function | Purpose |
|----------|---------|
| `mc_dwg_exists` | Returns true/false |
| `mc_dwg_get_history` | Full history log entries |
| `mc_dwg_get_biggest_id` | Highest document id in a project |
| `mc_dwg_get_id_from_title` | Lookup id by title |
| `mc_dwg_note_add` | Add a dwgnote (review comment) |
| `mc_dwg_note_delete` | Delete a dwgnote |
| `mc_dwg_note_update` | Update a dwgnote |
| `mc_dwg_relationship_add` | Link two documents |
| `mc_dwg_relationship_delete` | Remove a link |
| `mc_dwg_primary_get` | Get primary file metadata |
| `mc_dwg_primary_upload` | Upload/replace primary file |
| `mc_dwg_primary_delete` | Remove primary file |
| `mc_dwg_attachment_add` | Add an attachment |
| `mc_dwg_attachment_get` | Download an attachment |
| `mc_dwg_attachment_delete` | Remove an attachment |
| `mc_enum_dwg_status` | List all document status values |
| `mc_dwgs_get` | Batch fetch multiple documents |

The `mc_dwg_primary_*` and `mc_dwg_attachment_*` families use `xsd:base64Binary` for file content. When calling from raw curl, the content must be **double base64-encoded** (PHP's SoapServer pre-decodes one layer). PHP SoapClient handles this transparently. See CLAUDE.md for full curl examples with double-encoding.

### 6.6 mc_enum_dwg_status — list document status values

```bash
SOAP '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:mc_enum_dwg_status>
      <username>manager</username>
      <password></password>
    </man:mc_enum_dwg_status>
  </soapenv:Body>
</soapenv:Envelope>' | grep -oP '(?<=<name xsi:type="xsd:string">)[^<]+'
```

Output (current defaults):
```
pending
received
assigned
in-review
reviewed
approved
reserved
rejected
archived
```

Configured via `$g_dwg_status_enum_string` in `config_defaults_inc.php`.

---

## 7. SOAP API — licenses

Licenses do not have dedicated SOAP CRUD endpoints equivalent to the REST API. They are accessible via account-level helpers used internally by the SOAP layer.

The REST API (`/api/rest/licenses`) is the primary programmatic interface for license management. There is no `mc_license_add` / `mc_license_update` / `mc_license_delete` SOAP equivalent.

Licenses associated with a user account are visible in the `AccountData` returned by `mc_login` and user-fetch operations via `mci_license_get_array_by_id()`.

---

## 8. Web server setup — Apache

The REST API requires two Apache features that are off by default on Ubuntu/Debian installs:

1. **`mod_rewrite`** — routes all non-file requests to `index.php` via the `.htaccess` in `api/rest/`
2. **`AllowOverride FileInfo AuthConfig`** — allows `.htaccess` to use `RewriteEngine` and `CGIPassAuth On`

The `CGIPassAuth On` directive is required so that Apache forwards the `Authorization` header to PHP. Without it, the REST token is invisible to `AuthMiddleware`.

### Step 1 — enable mod_rewrite

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Verify: `apache2ctl -M | grep rewrite` → should show `rewrite_module`.

### Step 2 — add the Directory block to the site config

Edit the active virtual host config. On Ubuntu/Debian this is typically `/etc/apache2/sites-enabled/000-default.conf`. Add the following **outside** the `<VirtualHost>` block (at the end of the file, or inside if preferred):

```apache
# Doctis REST API: allow .htaccess rewrite rules and CGIPassAuth
<Directory /var/www/html/doctis/api/rest>
    AllowOverride FileInfo AuthConfig
    Options FollowSymLinks
    Require all granted
</Directory>
```

Adjust the path if the Doctis webroot is not `/var/www/html/doctis`.

```bash
sudo systemctl reload apache2
```

### Step 3 — verify

```bash
# Should return JSON, not a 404 or 500
curl -sv 'http://<host>/doctis/api/rest/documents/1' 2>&1 | grep '< HTTP'
# Expect: 401 (no token) — proves routing works
# With a token: 200 or 404 depending on whether document 1 exists
```

If you still get a 404 from Apache (not from Slim), the `.htaccess` rules are not being processed — recheck `AllowOverride`. If you get Slim's HTML "Page Not Found" page, the rewrite is working but the URL path is wrong.

### Diagnosing errors

```bash
# Check Apache accepted the config change
sudo apache2ctl configtest

# Confirm mod_rewrite is active
apache2ctl -M | grep rewrite

# Check the .htaccess is being read (AllowOverride working)
# A 500 error with mod_rewrite not loaded produces: "Invalid command 'RewriteEngine'"
sudo tail -20 /var/log/apache2/error.log | grep -v Xdebug

# Confirm CGIPassAuth is being processed
# Without it, the Authorization header is missing and you get: 401 Valid API token required
curl -sv -H "Authorization: mytoken" 'http://<host>/doctis/api/rest/documents' 2>&1 | grep '< HTTP'
```

---

## 9. Web server setup — nginx

nginx does not use `.htaccess`. The rewrite and header-passing behaviour must be configured directly in the server block.

### nginx configuration block

Add the following `location` block inside your `server {}` block for Doctis:

```nginx
server {
    listen 80;
    server_name your-doctis-host;
    root /var/www/html/doctis;
    index index.php;

    # ... other location blocks for the main Doctis app ...

    # REST API — route all requests through index.php
    location /doctis/api/rest/ {
        # Pass the Authorization header to PHP-FPM
        # (nginx strips it by default when using fastcgi_pass)
        fastcgi_pass_header Authorization;

        # Route non-file requests to index.php (equivalent to the .htaccess RewriteRule)
        try_files $uri $uri/ /doctis/api/rest/index.php$is_args$args;
    }

    location ~ ^/doctis/api/rest/.*\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.x-fpm.sock;  # adjust PHP version

        # Forward the Authorization header to PHP
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    # PHP handler for the rest of Doctis
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.x-fpm.sock;
    }
}
```

### Key differences from Apache

| Concern | Apache | nginx |
|---------|--------|-------|
| URL rewriting | `.htaccess` with `RewriteEngine On` | `try_files` in server block |
| Enable rewriting | `a2enmod rewrite` + `AllowOverride` | Built into nginx — no module needed |
| Forward `Authorization` header | `CGIPassAuth On` in `<Directory>` | `fastcgi_pass_header Authorization` + `fastcgi_param HTTP_AUTHORIZATION` |
| Config reload | `systemctl reload apache2` | `nginx -t && systemctl reload nginx` |

### Why `Authorization` header forwarding matters

nginx (and Apache without `CGIPassAuth`) strips the `Authorization` header before it reaches PHP-FPM, as a security measure inherited from CGI conventions. The Doctis REST `AuthMiddleware` reads this header to find the API token. If it is stripped, every request returns `401 Valid API token required` regardless of whether a valid token was sent.

The `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` directive in the `location` block re-injects the header into the FastCGI environment as `HTTP_AUTHORIZATION`, which PHP exposes as `$_SERVER['HTTP_AUTHORIZATION']`.

### Verify nginx config

```bash
sudo nginx -t            # syntax check
sudo systemctl reload nginx

# Test: should return 401 (no token) not 404 or 502
curl -sv 'http://<host>/doctis/api/rest/documents' 2>&1 | grep '< HTTP'

# With a valid token:
curl -s -H "Authorization: your-token" \
  'http://<host>/doctis/api/rest/documents?project_id=1'
```

### nginx on a subdirectory vs subdomain

If Doctis is not at the root of the server (e.g. `http://host/doctis/` rather than `http://host/`), adjust the `location` prefixes accordingly, and ensure the PHP `$_SERVER['SCRIPT_NAME']` value reflects the correct path so Slim 3 computes its base path correctly. Slim 3 derives its base path from `SCRIPT_NAME` — if that is wrong, all routes return 404.

---

*See also: `doc/DOCTIS-SOAP.md` for the full SOAP API audit; `doc/GIT_STORAGE_BACKEND.md` for primary file storage; `admin/tools/doctis-soap-test.sh` for a runnable SOAP smoke test.*
