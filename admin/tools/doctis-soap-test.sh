#!/bin/bash
#
# doctis-soap-test.sh
#
# Exercises the Doctis-specific SOAP endpoints:
#
#   Priority 1 — mc_enum_dwg_status (correctness fix)
#   Priority 2 — mc_dwg_get (basic document read)
#   Priority 3 — mc_dwg_primary_get / mc_dwg_primary_upload / mc_dwg_primary_delete
#   Priority 4 — mc_dwg_attachment_add / mc_dwg_attachment_get / mc_dwg_attachment_delete
#
# Each test is run in sequence.  Failures are non-fatal — the script
# continues and prints a full summary at the end so you can see all
# breakage at once rather than stopping on the first failure.
#
# Prerequisites:
#   • curl, grep, sed, base64 (all standard on Debian/Ubuntu)
#   • A running Doctis instance with at least one document in the database.
#   • The test account must have at least Manager-level access to the project.
#
# Usage:
#   bash admin/tools/doctis-soap-test.sh [host] [username] [password] [dwg_id]
#
# Parameters (all optional — defaults shown):
#   host       http://10.0.0.10/doctis     Base URL of the Doctis instance
#   username   manager                      SOAP / web login username
#   password   (blank)                      Password (blank for default test users)
#   dwg_id     2                            Document id to use as test target
#
# Example:
#   bash admin/tools/doctis-soap-test.sh http://10.0.0.10/doctis manager "" 2
#
# Remote execution via SSH (from the dev machine):
#   ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh"
#

HOST="${1:-http://10.0.0.10/doctis}"
USERNAME="${2:-manager}"
PASSWORD="${3:-}"
DWG_ID="${4:-2}"

SOAP_URL="${HOST}/api/soap/mantisconnect.php"
SOAP_NS="http://futureware.biz/mantisconnect"
COOKIE_JAR="/tmp/doctis_soap_test_cookies_$$.txt"

# ── colour codes (matches admin/tools convention) ─────────────────────────────
OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"
BOLD="\033[1m"

PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0

# ── output helpers ─────────────────────────────────────────────────────────────
pass() { echo -e "  ${GREEN}PASS${OFF}  $1"; (( PASS_COUNT++ )); }
fail() { echo -e "  ${RED}FAIL${OFF}  $1"; (( FAIL_COUNT++ )); }
skip() { echo -e "  ${YELLOW}SKIP${OFF}  $1"; (( SKIP_COUNT++ )); }
info() { echo -e "        ${CYAN}$1${OFF}"; }
step() { echo -e "\n${BOLD}$1${OFF}"; }

# ── SOAP helper ───────────────────────────────────────────────────────────────
#
# soap_call <operation> <inner_xml>
#   Wraps <inner_xml> in a SOAP Envelope addressed to <operation> and posts
#   it to the configured SOAP URL.  Prints the raw response to stdout.
#
soap_call() {
    local op="$1"
    local body="$2"
    local envelope
    envelope="<?xml version=\"1.0\" encoding=\"utf-8\"?>"$'\n'
    envelope+="<soapenv:Envelope"$'\n'
    envelope+="    xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\""$'\n'
    envelope+="    xmlns:man=\"${SOAP_NS}\">"$'\n'
    envelope+="  <soapenv:Body>"$'\n'
    envelope+="    <man:${op}>${body}</man:${op}>"$'\n'
    envelope+="  </soapenv:Body>"$'\n'
    envelope+="</soapenv:Envelope>"

    curl -s \
        -H "Content-Type: text/xml; charset=utf-8" \
        -H 'SOAPAction: ""' \
        --data "${envelope}" \
        "${SOAP_URL}"
}

# extract_element <tag> <xml>
#   Returns the text content of the first occurrence of <tag>...</tag>.
#   Works even when the opening tag has attributes (e.g. xsi:type="xsd:string").
extract_element() {
    local tag="$1" xml="$2"
    echo "$xml" | grep -oP "<${tag}(\s[^>]*)?>.*?</${tag}>" \
        | sed "s|<${tag}[^>]*>||;s|</${tag}>||" \
        | head -1
}

# has_fault <xml> — exits 0 if the response contains a SOAP Fault
has_fault() { echo "$1" | grep -q 'faultstring'; }

# fault_msg <xml> — extracts the human-readable fault string
fault_msg() { echo "$1" | grep -oP '(?<=<faultstring>)[^<]+'; }

# creds — credential fragment reused in every SOAP call body
CREDS="<username>${USERNAME}</username><password>${PASSWORD}</password>"

# ── HTTP login (for authenticated file downloads) ──────────────────────────────
http_login() {
    # Step 1: submit username to get the password page
    curl -s -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
        -X POST "${HOST}/login_password_page.php" \
        -d "username=${USERNAME}&return=index.php" -o /dev/null
    # Step 2: submit password
    curl -s -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
        -X POST "${HOST}/login.php" \
        -d "username=${USERNAME}&password=${PASSWORD}&return=index.php&secure_session=0" \
        -o /dev/null
}

http_get_body() {
    curl -s -L -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "$1"
}

cleanup() { rm -f "${COOKIE_JAR}"; }
trap cleanup EXIT

# ─────────────────────────────────────────────────────────────────────────────

echo -e "\n${BOLD}Doctis SOAP Test Suite${OFF}"
echo -e "${CYAN}SOAP endpoint : ${SOAP_URL}${OFF}"
echo -e "${CYAN}Username      : ${USERNAME}${OFF}"
echo -e "${CYAN}Test document : dwg_id=${DWG_ID}${OFF}"

# ── Sanity: endpoint reachable? ───────────────────────────────────────────────
step "0. Connectivity — SOAP endpoint reachable"

PROBE=$(soap_call mc_version "${CREDS}" 2>/dev/null)
if echo "$PROBE" | grep -q 'mc_versionResponse\|return'; then
    VERSION=$(extract_element 'return' "$PROBE")
    pass "Endpoint reachable — Doctis/MantisBT version: ${VERSION}"
else
    fail "Cannot reach SOAP endpoint at ${SOAP_URL} — aborting"
    echo ""
    echo -e "${RED}${BOLD}ABORTED — endpoint not reachable${OFF}"
    exit 1
fi

# ── 1. mc_enum_dwg_status ─────────────────────────────────────────────────────
step "1. mc_enum_dwg_status — document status enum"

RESP=$(soap_call mc_enum_dwg_status "${CREDS}")
if has_fault "$RESP"; then
    fail "Returned fault: $(fault_msg "$RESP")"
elif echo "$RESP" | grep -q 'pending'; then
    N=$(echo "$RESP" | grep -o '<name' | wc -l | tr -d ' ')
    pass "Returns status list including 'pending' (${N} statuses total)"
    info "Statuses: $(echo "$RESP" | grep -oP '(?<=<name xsi:type="xsd:string">)[^<]+' | tr '\n' ' ')"
else
    fail "Response does not contain expected status names"
    info "Raw (first 300): ${RESP:0:300}"
fi

# ── 2. mc_dwg_get ─────────────────────────────────────────────────────────────
step "2. mc_dwg_get — fetch document dwg_id=${DWG_ID}"

RESP=$(soap_call mc_dwg_get "${CREDS}<issue_id>${DWG_ID}</issue_id>")
if has_fault "$RESP"; then
    fail "Returned fault: $(fault_msg "$RESP")"
else
    TITLE=$(extract_element 'title' "$RESP")
    DOC_ID=$(extract_element 'id' "$RESP")
    if [ -n "$TITLE" ] && [ -n "$DOC_ID" ]; then
        pass "Document retrieved — id=${DOC_ID}, title='${TITLE}'"
    elif echo "$RESP" | grep -q 'DwgData'; then
        pass "DwgData structure returned (title element uses different tag name)"
    else
        fail "Response parsed but no recognisable document data found"
        info "Raw (first 300): ${RESP:0:300}"
    fi
fi

# ── Primary file lifecycle ─────────────────────────────────────────────────────
# Before starting, silently delete any leftover primary file from a previous
# interrupted test run so we start from a known-clean state.
soap_call mc_dwg_primary_delete "${CREDS}<dwg_id>${DWG_ID}</dwg_id>" > /dev/null 2>&1

PRIMARY_UPLOADED=0
ATTACH_ID=0

# ── 3. mc_dwg_primary_get — before upload ────────────────────────────────────
step "3. mc_dwg_primary_get — before upload (expect empty)"

RESP=$(soap_call mc_dwg_primary_get "${CREDS}<dwg_id>${DWG_ID}</dwg_id>")
if has_fault "$RESP"; then
    fail "Returned fault: $(fault_msg "$RESP")"
elif echo "$RESP" | grep -q '<filename>'; then
    fail "Expected empty PrimaryFileData but <filename> element is present"
else
    pass "Returns empty PrimaryFileData — no file present yet"
fi

# ── 4. mc_dwg_primary_upload ─────────────────────────────────────────────────
step "4. mc_dwg_primary_upload — upload test file"

# Note on base64 encoding: PHP's SoapServer automatically base64-decodes
# xsd:base64Binary parameters before passing them to the handler function.
# PHP's SoapClient compensates by double-encoding.  Raw curl only encodes once,
# so we must also double-encode here to match the SoapClient behaviour:
#   wire value  = base64(base64(raw_bytes))
#   SoapServer decodes once  → base64(raw_bytes)
#   PHP handler base64_decode()s → raw_bytes  ✓
#
# For xsd:base64Binary return values the SoapServer base64-encodes the PHP
# return value before sending, so raw curl callers must decode twice:
#   PHP handler returns base64_encode(raw_bytes)
#   SoapServer encodes again → base64(base64(raw_bytes)) on wire
#   curl receives → decode twice → raw_bytes  ✓

TEST_CONTENT="Doctis SOAP test: primary document content (pid=$$, dwg_id=${DWG_ID})"
TEST_B64=$(printf '%s' "$TEST_CONTENT" | base64 -w 0 | base64 -w 0)
TEST_FILENAME="soap-primary-test-$$.txt"
EXPECTED_SIZE=${#TEST_CONTENT}

RESP=$(soap_call mc_dwg_primary_upload \
    "${CREDS}\
<dwg_id>${DWG_ID}</dwg_id>\
<name>${TEST_FILENAME}</name>\
<file_type>text/plain</file_type>\
<content>${TEST_B64}</content>\
<description>Automated SOAP test - safe to delete</description>")

if has_fault "$RESP"; then
    fail "Returned fault: $(fault_msg "$RESP")"
elif echo "$RESP" | grep -qiE '>true<|>1<'; then
    pass "Upload returned true — filename='${TEST_FILENAME}', size=${EXPECTED_SIZE} bytes"
    PRIMARY_UPLOADED=1
else
    fail "Unexpected response (not true, not fault): ${RESP:0:300}"
fi

# ── 5. mc_dwg_primary_get — verify metadata after upload ─────────────────────
step "5. mc_dwg_primary_get — metadata after upload"

if [ "$PRIMARY_UPLOADED" -eq 0 ]; then
    skip "Skipped (upload in step 4 failed)"
else
    RESP=$(soap_call mc_dwg_primary_get "${CREDS}<dwg_id>${DWG_ID}</dwg_id>")
    if has_fault "$RESP"; then
        fail "Returned fault: $(fault_msg "$RESP")"
    else
        GOT_FILENAME=$(extract_element 'filename' "$RESP")
        GOT_SIZE=$(extract_element 'filesize' "$RESP")
        GOT_TYPE=$(extract_element 'file_type' "$RESP")
        GOT_URL=$(extract_element 'download_url' "$RESP")
        GOT_DESC=$(extract_element 'description' "$RESP")

        ERRORS=0
        [ "$GOT_FILENAME" = "$TEST_FILENAME" ] \
            || { fail "filename: expected '${TEST_FILENAME}', got '${GOT_FILENAME}'"; (( ERRORS++ )); }
        [ "$GOT_TYPE" = "text/plain" ] \
            || { fail "file_type: expected 'text/plain', got '${GOT_TYPE}'"; (( ERRORS++ )); }
        [[ "$GOT_SIZE" =~ ^[0-9]+$ ]] && [ "$GOT_SIZE" -eq "$EXPECTED_SIZE" ] \
            || { fail "filesize: expected ${EXPECTED_SIZE}, got '${GOT_SIZE}'"; (( ERRORS++ )); }

        if [ "$ERRORS" -eq 0 ]; then
            pass "All metadata fields correct (filename, filesize, file_type)"
            info "download_url : ${GOT_URL}"
            info "description  : ${GOT_DESC}"
        fi
    fi
fi

# ── 6. Authenticated HTTP download + content integrity ────────────────────────
step "6. Authenticated download — content integrity check"

if [ "$PRIMARY_UPLOADED" -eq 0 ]; then
    skip "Skipped (upload in step 4 failed)"
else
    http_login
    DOWNLOAD_URL="${HOST}/file_download.php?type=dwg_primary&id=${DWG_ID}"
    DOWNLOADED=$(http_get_body "$DOWNLOAD_URL")
    if [ "$DOWNLOADED" = "$TEST_CONTENT" ]; then
        pass "Downloaded content matches uploaded content byte-for-byte"
        info "URL: ${DOWNLOAD_URL}"
    else
        fail "Content mismatch"
        info "Expected : ${TEST_CONTENT}"
        info "Got      : ${DOWNLOADED:0:120}"
    fi
fi

# ── 7. mc_dwg_primary_delete ─────────────────────────────────────────────────
step "7. mc_dwg_primary_delete"

if [ "$PRIMARY_UPLOADED" -eq 0 ]; then
    skip "Skipped (upload in step 4 failed)"
else
    RESP=$(soap_call mc_dwg_primary_delete "${CREDS}<dwg_id>${DWG_ID}</dwg_id>")
    if has_fault "$RESP"; then
        fail "Returned fault: $(fault_msg "$RESP")"
    elif echo "$RESP" | grep -qiE '>true<|>1<'; then
        pass "Delete returned true"
    else
        # No fault and no explicit true/1 — some backends return empty on success
        if ! has_fault "$RESP"; then
            pass "Delete returned no fault (treating as success)"
        else
            fail "Unexpected response: ${RESP:0:200}"
        fi
    fi
fi

# ── 8. mc_dwg_primary_get — verify gone after delete ─────────────────────────
step "8. mc_dwg_primary_get — after delete (expect empty)"

if [ "$PRIMARY_UPLOADED" -eq 0 ]; then
    skip "Skipped (upload in step 4 failed)"
else
    RESP=$(soap_call mc_dwg_primary_get "${CREDS}<dwg_id>${DWG_ID}</dwg_id>")
    if has_fault "$RESP"; then
        fail "Returned fault: $(fault_msg "$RESP")"
    elif echo "$RESP" | grep -q '<filename>'; then
        fail "Primary file still present after delete (<filename> element found)"
    else
        pass "Returns empty PrimaryFileData — file correctly removed"
    fi
fi

# ── Attachment lifecycle ───────────────────────────────────────────────────────

# ── 9. mc_dwg_attachment_add ─────────────────────────────────────────────────
step "9. mc_dwg_attachment_add — upload note attachment"

ATTACH_CONTENT="Doctis SOAP test: note attachment content (pid=$$, dwg_id=${DWG_ID})"
ATTACH_B64=$(printf '%s' "$ATTACH_CONTENT" | base64 -w 0 | base64 -w 0)
ATTACH_FILENAME="soap-attach-test-$$.txt"

RESP=$(soap_call mc_dwg_attachment_add \
    "${CREDS}\
<dwg_id>${DWG_ID}</dwg_id>\
<name>${ATTACH_FILENAME}</name>\
<file_type>text/plain</file_type>\
<content>${ATTACH_B64}</content>")

if has_fault "$RESP"; then
    fail "Returned fault: $(fault_msg "$RESP")"
else
    ATTACH_ID=$(extract_element 'return' "$RESP")
    if [[ "$ATTACH_ID" =~ ^[0-9]+$ ]] && [ "$ATTACH_ID" -gt 0 ]; then
        pass "Attachment added — attachment_id=${ATTACH_ID}"
    else
        fail "Response did not contain a valid integer id — got '${ATTACH_ID}'"
        info "Raw (first 300): ${RESP:0:300}"
        ATTACH_ID=0
    fi
fi

# ── 10. mc_dwg_attachment_get ─────────────────────────────────────────────────
step "10. mc_dwg_attachment_get — retrieve and verify content"

if [ "$ATTACH_ID" -eq 0 ]; then
    skip "Skipped (add in step 9 failed)"
else
    RESP=$(soap_call mc_dwg_attachment_get "${CREDS}<attachment_id>${ATTACH_ID}</attachment_id>")
    if has_fault "$RESP"; then
        fail "Returned fault: $(fault_msg "$RESP")"
    else
        GOT_B64=$(extract_element 'return' "$RESP")
        # Double-decode: SoapServer encodes the PHP return value, PHP already
        # called base64_encode() — so the wire value is double-encoded.
        GOT_CONTENT=$(printf '%s' "$GOT_B64" | base64 -d 2>/dev/null | base64 -d 2>/dev/null)
        if [ "$GOT_CONTENT" = "$ATTACH_CONTENT" ]; then
            pass "Retrieved content matches uploaded content byte-for-byte"
        else
            fail "Content mismatch"
            info "Expected : ${ATTACH_CONTENT}"
            info "Got      : ${GOT_CONTENT:0:120}"
        fi
    fi
fi

# ── 11. mc_dwg_attachment_delete ──────────────────────────────────────────────
step "11. mc_dwg_attachment_delete — delete the attachment"

if [ "$ATTACH_ID" -eq 0 ]; then
    skip "Skipped (add in step 9 failed)"
else
    RESP=$(soap_call mc_dwg_attachment_delete "${CREDS}<attachment_id>${ATTACH_ID}</attachment_id>")
    if has_fault "$RESP"; then
        fail "Returned fault: $(fault_msg "$RESP")"
    else
        pass "Delete returned no fault (success)"
    fi
fi

# ── 12. mc_dwg_attachment_get after delete (expect fault) ────────────────────
step "12. mc_dwg_attachment_get — after delete (expect 'not found' fault)"

if [ "$ATTACH_ID" -eq 0 ]; then
    skip "Skipped (add in step 9 failed)"
else
    RESP=$(soap_call mc_dwg_attachment_get "${CREDS}<attachment_id>${ATTACH_ID}</attachment_id>")
    if has_fault "$RESP"; then
        pass "Correctly returned fault after deletion"
        info "Fault: $(fault_msg "$RESP")"
    else
        fail "Expected 'not found' fault but got a non-fault response"
        info "Raw (first 200): ${RESP:0:200}"
    fi
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}────────────────────────────────────────────${OFF}"
TOTAL=$(( PASS_COUNT + FAIL_COUNT + SKIP_COUNT ))

if [ "$FAIL_COUNT" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}ALL TESTS PASSED${OFF}  (${PASS_COUNT} passed, ${SKIP_COUNT} skipped of ${TOTAL} total)"
    EXIT_CODE=0
else
    echo -e "${RED}${BOLD}FAILURES: ${FAIL_COUNT}${OFF}  (${PASS_COUNT} passed, ${FAIL_COUNT} failed, ${SKIP_COUNT} skipped of ${TOTAL} total)"
    EXIT_CODE=1
fi
echo ""
exit $EXIT_CODE
