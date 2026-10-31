<?php
# AI conversation retention tests. Disposable users; no model calls or emails.
namespace Mantis\tests\Mantis;

require_once __DIR__ . '/MantisCoreBase.php';
require_api( 'ai_session_api.php' );
require_once dirname( __DIR__, 2 ) . '/ai_assist_anthropic_api.php';

class AiSessionTest extends MantisCoreBase {
	private $user_id;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->user_id = (int)user_get_id_by_cookie( user_create( 'aisession' . rand(), 'password' ) );
	}

	protected function tearDown(): void {
		foreach( array( 'ai_request', 'ai_conversation', 'ai_sessions' ) as $t_table ) {
			db_param_push();
			db_query( 'DELETE FROM {' . $t_table . '} WHERE user_id=' . db_param(), array( $this->user_id ) );
		}
		user_delete( $this->user_id );
		parent::tearDown();
	}

	private function request( array $p_conversation, string $p_text = 'Plan a meeting' ): int {
		$t_history = json_decode( $p_conversation['history'], true );
		$t_history[] = array( 'role' => 'user', 'content' => $p_text );
		return ai_session_request_begin( $p_conversation, $t_history );
	}

	public function testClearRetainsTranscriptRawReplyAndRequestEvidence() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$t_id = $this->request( $t_conversation );
		$t_raw = '<<<MEETING_DOCUMENT>>>Full document 📝<<<END_MEETING_DOCUMENT>>>';
		ai_session_request_prepare( $t_id, array( 'system' => 'Prompt', 'candidates' => array( array( 'id' => 12, 'username' => 'phil' ) ), 'model' => 'test-model' ) );
		ai_session_save( (int)$t_conversation['id'], $t_id, array(
			array( 'role' => 'user', 'content' => 'Plan a meeting' ),
			array( 'role' => 'assistant', 'content' => 'Agenda saved' ),
		), 'MIN-ENG-TEST', 0 );
		ai_session_request_record( $t_id, 'success', array( 'raw_reply' => $t_raw, 'displayed_reply' => 'Agenda saved' ), true );
		$t_new = ai_session_archive( ai_session_get( (int)$t_conversation['id'] ) );
		$this->assertNotSame( $t_conversation['id'], $t_new['id'] );
		$this->assertSame( '[]', $t_new['history'] );
		$this->assertGreaterThan( 0, (int)ai_session_get( (int)$t_conversation['id'] )['archived'] );
		$this->assertStringContainsString( 'Agenda saved', ai_session_get( (int)$t_conversation['id'] )['history'] );
		$t_requests = ai_session_requests( (int)$t_conversation['id'] );
		$this->assertSame( $t_raw, json_decode( $t_requests[0]['response_data'], true )['raw_reply'] );
		$this->assertSame( 'test-model', json_decode( $t_requests[0]['request_data'], true )['model'] );
	}

	public function testSeparateMeetingsAndModesKeepTheirOwnConversations() {
		$t_one = ai_session_current( $this->user_id, 'meeting', 'MIN-A' );
		$t_two = ai_session_current( $this->user_id, 'meeting', 'MIN-B' );
		$t_help = ai_session_current( $this->user_id, 'help' );
		$this->assertNotSame( $t_one['id'], $t_two['id'] );
		$this->assertNotSame( $t_one['id'], $t_help['id'] );
		$this->assertSame( $t_one['id'], ai_session_current( $this->user_id, 'meeting', 'MIN-A' )['id'] );
	}

	public function testImportsLegacyHistoryWithoutDeletingItOrInventingDiagnostics() {
		$t_history = ai_session_json( array( array( 'role' => 'user', 'content' => 'Earlier message' ) ) );
		db_param_push();
		db_query( "INSERT INTO {ai_sessions} (user_id, mode, created, updated, history, doc_id) VALUES (" . db_param() . ", 'meeting', 123, 456, " . db_param() . ", 'MIN-OLD')", array( $this->user_id, $t_history ) );
		$t_conversation = ai_session_current( $this->user_id, 'meeting', 'MIN-OLD' );
		$this->assertSame( $t_history, $t_conversation['history'] );
		$this->assertSame( 123, (int)$t_conversation['created'] );
		$this->assertSame( 456, (int)$t_conversation['updated'] );
		$this->assertSame( array(), ai_session_requests( (int)$t_conversation['id'] ) );
		ai_session_archive( $t_conversation );
		db_param_push();
		$this->assertSame( 1, (int)db_result( db_query( 'SELECT COUNT(*) FROM {ai_sessions} WHERE user_id=' . db_param(), array( $this->user_id ) ) ) );
	}

	public function testFailureRetainsTheAttemptAndAllowsRetryWithoutPhantomTurns() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$t_id = $this->request( $t_conversation, 'Failed attempt' );
		ai_session_request_record( $t_id, 'failed', array( 'error' => 'Provider timeout' ), true );
		$t_after = ai_session_get( (int)$t_conversation['id'] );
		$this->assertSame( '[]', $t_after['history'] );
		$this->assertSame( 0, (int)$t_after['pending_request_id'] );
		$this->assertSame( 'failed', ai_session_requests( (int)$t_conversation['id'] )[0]['status'] );
		$this->assertStringContainsString( 'Failed attempt', ai_session_requests( (int)$t_conversation['id'] )[0]['request_data'] );
		$this->assertGreaterThan( $t_id, $this->request( $t_after, 'Retry' ) );
	}

	public function testSchemaBackfillCopiesLegacyRowsAndIsIdempotent() {
		for( $t_index = 0; $t_index < 2; $t_index++ ) {
			db_param_push();
			db_query( "INSERT INTO {ai_sessions} (user_id, mode, created, updated, history, doc_id) VALUES (" . db_param()
				. ", 'meeting', 123, 456, " . db_param() . ', ' . db_param() . ')',
				array( $this->user_id, ai_session_json( array( array( 'role' => 'user', 'content' => 'Legacy ' . $t_index ) ) ), 'MIN-OLD-' . $t_index ) );
		}
		# Build the installer's definitions; execute only the additive transcript-copy step.
		require dirname( __DIR__, 2 ) . '/admin/schema.php';
		for( $t_run = 0; $t_run < 2; $t_run++ ) {
			db_query( $g_upgrade[80][1] );
			db_param_push();
			$t_result = db_query( 'SELECT COUNT(*) FROM {ai_conversation} c JOIN {ai_sessions} s ON c.user_id=s.user_id'
				. ' AND c.mode=s.mode AND c.doc_id=s.doc_id AND c.history=s.history AND c.created=s.created AND c.updated=s.updated'
				. ' WHERE c.user_id=' . db_param(), array( $this->user_id ) );
			$this->assertSame( 2, (int)db_result( $t_result ) );
		}
	}

	public function testBusyConversationCannotBeCleared() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$this->request( $t_conversation );
		$this->expectException( \RuntimeException::class );
		ai_session_archive( $t_conversation );
	}

	public function testBusyConversationRejectsASecondRequestAndRecordsTheRejection() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$this->request( $t_conversation );
		try {
			$this->request( $t_conversation );
			$this->fail( 'Concurrent request should be refused' );
		} catch( \RuntimeException $t_error ) {
			$this->assertSame( 'rejected', ai_session_requests( (int)$t_conversation['id'] )[1]['status'] );
		}
	}

	public function testStaleBrowserHistoryCannotOverwriteTheConversation() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$this->expectException( \RuntimeException::class );
		ai_session_request_begin( $t_conversation, array(
			array( 'role' => 'assistant', 'content' => 'Invented previous reply' ),
			array( 'role' => 'user', 'content' => 'Continue' ),
		) );
	}

	public function testExpiredWorkerIsRecordedAndCannotOverwriteItsReplacement() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$t_old_id = $this->request( $t_conversation );
		db_param_push();
		db_query( 'UPDATE {ai_conversation} SET busy_until=1 WHERE id=' . db_param(), array( $t_conversation['id'] ) );
		$t_new_id = $this->request( ai_session_get( (int)$t_conversation['id'] ) );
		$this->assertGreaterThan( $t_old_id, $t_new_id );
		$this->assertSame( 'interrupted', ai_session_requests( (int)$t_conversation['id'] )[0]['status'] );
		$this->expectException( \RuntimeException::class );
		ai_session_request_renew( (int)$t_conversation['id'], $t_old_id );
	}

	public function testResponseCanRenewItsLeaseBeforeDocumentProcessing() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$t_id = $this->request( $t_conversation );
		ai_session_request_renew( (int)$t_conversation['id'], $t_id );
		$this->assertSame( $t_id, (int)ai_session_get( (int)$t_conversation['id'] )['pending_request_id'] );
	}

	public function testUnconfiguredProviderReturnsDiagnosticMetadataWithoutANetworkCall() {
		$t_key = config_get_global( 'anthropic_api_key' );
		try {
			# CLI process memory only; restore immediately and never write configuration.
			config_set_global( 'anthropic_api_key', '' );
			$t_answer = ai_assist_anthropic_request( 'Prompt', array(), 100 );
			$this->assertNotNull( $t_answer['error'] );
			$this->assertSame( 0, $t_answer['diagnostics']['http_status'] );
			$this->assertSame( config_get_global( 'ai_model' ), $t_answer['diagnostics']['requested_model'] );
		} finally {
			config_set_global( 'anthropic_api_key', $t_key );
		}
	}

	public function testArchivedIdsCannotBeReused() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		ai_session_archive( $t_conversation );
		$this->expectException( \RuntimeException::class );
		ai_session_current( $this->user_id, 'meeting', '', (int)$t_conversation['id'] );
	}

	public function testAnotherUserCannotContinueOrSeeTheConversation() {
		$t_conversation = ai_session_current( $this->user_id, 'meeting' );
		$t_other_id = (int)user_get_id_by_cookie( user_create( 'aisession' . rand(), 'password' ) );
		try {
			$this->assertFalse( ai_session_can_view( $t_conversation, $t_other_id ) );
			$this->assertTrue( ai_session_can_view( $t_conversation, $this->user_id ) );
			$this->assertTrue( ai_session_can_view( $t_conversation, auth_get_current_user_id() ) );
			$this->expectException( \RuntimeException::class );
			ai_session_current( $t_other_id, 'meeting', '', (int)$t_conversation['id'] );
		} finally {
			user_delete( $t_other_id );
		}
	}
}
