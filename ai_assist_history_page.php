<?php
# Doctis — retained assistant conversations and request diagnostics.
require_once( 'core.php' );
require_api( 'ai_session_api.php' );
require_api( 'gpc_api.php' );
require_api( 'http_api.php' );
require_api( 'layout_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );
http_caching_headers( false );
html_robots_noindex();

$t_user_id = auth_get_current_user_id();
$f_id = gpc_get_int( 'id', 0 );
$f_all = gpc_get_bool( 'all', false );
$f_page = max( 1, gpc_get_int( 'page', 1 ) );
$t_admin = access_has_global_level( ADMINISTRATOR );
if( $f_all ) {
	access_ensure_global_level( ADMINISTRATOR );
	auth_reauthenticate();
}
$t_conversation = $f_id > 0 ? ai_session_get( $f_id ) : null;
if( $f_id > 0 && ( $t_conversation === null || !ai_session_can_view( $t_conversation, $t_user_id ) ) ) {
	access_denied();
}
if( $t_conversation !== null && (int)$t_conversation['user_id'] !== $t_user_id ) {
	auth_reauthenticate();
}

if( $t_conversation !== null ) {
	$t_requests = ai_session_requests( $f_id );
	if( gpc_get_string( 'format', '' ) === 'json' ) {
		foreach( $t_requests as &$t_request ) {
			$t_request['request_data'] = json_decode( $t_request['request_data'], true );
			$t_request['response_data'] = json_decode( $t_request['response_data'], true );
		}
		unset( $t_request );
		$t_conversation['history'] = json_decode( $t_conversation['history'], true );
		header( 'Content-Type: application/json; charset=utf-8' );
		http_content_disposition_header( 'ai-conversation-' . $f_id . '.json' );
		echo ai_session_json( array( 'conversation' => $t_conversation, 'requests' => $t_requests ) );
		exit;
	}
}

layout_page_header( lang_get( 'ai_history_title' ) );
layout_page_begin( 'ai_assist_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<h2><?php echo lang_get( 'ai_history_title' ) ?></h2>
	<p><a href="ai_assist_page.php"><?php echo lang_get( 'ai_history_back' ) ?></a>
		&nbsp; <a href="ai_assist_history_page.php"><?php echo lang_get( 'ai_history_mine' ) ?></a>
		<?php if( $t_admin ) { ?> &nbsp; <a href="ai_assist_history_page.php?all=1"><?php echo lang_get( 'ai_history_all' ) ?></a><?php } ?>
	</p>
<?php if( $t_conversation !== null ) { ?>
	<h3><?php echo '#' . $f_id . ' · ' . string_html_specialchars( $t_conversation['mode'] ) . ' · '
		. string_html_specialchars( user_get_name( (int)$t_conversation['user_id'] ) ) ?></h3>
	<?php if( $t_conversation['doc_id'] !== '' ) { ?><p><?php echo string_html_specialchars( $t_conversation['doc_id'] ) ?></p><?php } ?>
	<p><a class="btn btn-sm btn-primary" href="ai_assist_history_page.php?id=<?php echo $f_id ?>&amp;format=json"><?php echo lang_get( 'ai_history_export' ) ?></a></p>
	<p><?php echo lang_get( 'ai_history_notice' ) ?></p>
	<?php foreach( json_decode( $t_conversation['history'], true ) ?: array() as $t_turn ) { ?>
		<h4><?php echo string_html_specialchars( $t_turn['role'] ) ?></h4>
		<pre style="white-space:pre-wrap"><?php echo string_html_specialchars( $t_turn['content'] ) ?></pre>
	<?php } ?>
	<h3><?php echo lang_get( 'ai_history_requests' ) ?></h3>
	<?php if( empty( $t_requests ) ) { ?><p><?php echo lang_get( 'ai_history_legacy' ) ?></p><?php } ?>
	<?php foreach( $t_requests as $t_request ) { ?>
		<details style="margin-bottom:12px">
			<summary><?php echo '#' . (int)$t_request['id'] . ' · '
				. string_html_specialchars( date( config_get( 'normal_date_format' ), (int)$t_request['started'] ) ) . ' · '
				. string_html_specialchars( $t_request['status'] ) ?></summary>
			<pre style="white-space:pre-wrap"><?php echo string_html_specialchars( json_encode( array(
				'finished' => (int)$t_request['finished'],
				'request' => json_decode( $t_request['request_data'], true ),
				'response' => json_decode( $t_request['response_data'], true ),
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) ) ?></pre>
		</details>
	<?php } ?>
<?php } else {
	# Upgraded installations already imported these; also covers a legacy worker finishing later.
	foreach( array( 'help', 'meeting', 'sop', 'other' ) as $t_mode ) {
		ai_session_import_legacy( $t_user_id, $t_mode );
	}
	db_param_push();
	$t_result = db_query( 'SELECT id, user_id, mode, updated, archived, context_key, doc_id FROM {ai_conversation}'
		. ( $f_all ? '' : ' WHERE user_id=' . db_param() ) . ' ORDER BY updated DESC, id DESC',
		$f_all ? array() : array( $t_user_id ), 100, ( $f_page - 1 ) * 100 );
?>
	<p><?php echo lang_get( 'ai_history_list_notice' ) ?></p>
	<table class="table table-striped table-bordered">
		<thead><tr><th>ID</th><th><?php echo lang_get( 'username' ) ?></th><th><?php echo lang_get( 'ai_history_context' ) ?></th><th><?php echo lang_get( 'last_update' ) ?></th><th><?php echo lang_get( 'status' ) ?></th></tr></thead>
		<tbody>
		<?php while( $t_row = db_fetch_array( $t_result ) ) { ?>
		<tr>
			<td><a href="ai_assist_history_page.php?id=<?php echo (int)$t_row['id'] ?>"><?php echo (int)$t_row['id'] ?></a></td>
			<td><?php echo string_html_specialchars( user_get_name( (int)$t_row['user_id'] ) ) ?></td>
			<td><?php echo string_html_specialchars( $t_row['mode'] . ( ( $t_row['doc_id'] ?: $t_row['context_key'] ) !== '' ? ' · ' . ( $t_row['doc_id'] ?: $t_row['context_key'] ) : '' ) ) ?></td>
			<td><?php echo string_html_specialchars( date( config_get( 'normal_date_format' ), (int)$t_row['updated'] ) ) ?></td>
			<td><?php echo lang_get( (int)$t_row['archived'] > 0 ? 'ai_history_archived' : 'ai_history_active' ) ?></td>
		</tr>
		<?php } ?>
		</tbody>
	</table>
	<p>
		<?php if( $f_page > 1 ) { ?><a href="ai_assist_history_page.php?page=<?php echo $f_page - 1 ?>&amp;all=<?php echo (int)$f_all ?>"><?php echo lang_get( 'ai_history_previous' ) ?></a> &nbsp;<?php } ?>
		<a href="ai_assist_history_page.php?page=<?php echo $f_page + 1 ?>&amp;all=<?php echo (int)$f_all ?>"><?php echo lang_get( 'next' ) ?></a>
	</p>
<?php } ?>
</div>
<?php layout_page_end(); ?>
