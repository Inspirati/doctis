<?php
# Doctis - Document Issue Tracking System
#
# git_http.php — Smart HTTP gateway entry point for remote git access.
#
# Apache routes /git/<slug>.git/<service> here (see git-serve.conf).  All logic
# lives in core/git_http_api.php.  Authenticates the caller via a Doctis API
# token (HTTP Basic password) and authorises project-level access before
# proxying to git-http-backend.  Read-only (clone/fetch) in this phase.
#
# This endpoint must not emit any output before the proxied response.

require_once( 'core.php' );
require_api( 'git_http_api.php' );

git_http_handle_request();
