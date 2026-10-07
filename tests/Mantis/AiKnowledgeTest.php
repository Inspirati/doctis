<?php
# Doctis AI Assistant knowledge base tests (core/ai_knowledge_api.php and
# ai_assist_knowledge_api.php). Uses the live database; created users,
# projects and entries are removed in tearDown. No AI calls are made.

namespace Mantis\tests\Mantis;

# Load the base (which boots MantisBT core) first: this file sorts before it.
require_once __DIR__ . '/MantisCoreBase.php';
require_api( 'ai_knowledge_api.php' );
require_once dirname( __DIR__, 2 ) . '/ai_assist_knowledge_api.php';
require_once dirname( __DIR__, 2 ) . '/ai_assist_help_api.php';

use Mantis\Exceptions\ClientException;

class AiKnowledgeTest extends MantisCoreBase {
	private $entry_ids = array();
	private $user_ids = array();
	private $project_ids = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();
	}

	protected function tearDown(): void {
		foreach( $this->entry_ids as $t_id ) {
			db_query( 'DELETE FROM {ai_knowledge} WHERE id=' . db_param(), array( $t_id ) );
		}
		foreach( $this->user_ids as $t_id ) {
			db_query( 'DELETE FROM {ai_knowledge} WHERE created_by=' . db_param(), array( $t_id ) );
			user_delete( $t_id );
		}
		foreach( $this->project_ids as $t_id ) {
			project_delete( $t_id );
		}
		parent::tearDown();
	}

	private function makeUser( int $p_level ): int {
		$t_name = 'KbTest' . rand();
		$t_id = (int)user_get_id_by_cookie( user_create( $t_name, 'password', strtolower( $t_name ) . '@example.test' ) );
		user_set_field( $t_id, 'access_level', $p_level );
		$this->user_ids[] = $t_id;
		return $t_id;
	}

	private function add( array $p_fields, int $p_user ): int {
		$t_id = ai_knowledge_add( $p_fields, $p_user );
		$this->entry_ids[] = $t_id;
		return $t_id;
	}

	public function testAddCleansFieldsAndChecksPage() {
		$t_user = $this->makeUser( REPORTER );
		$t_id = $this->add( array(
			'question' => "  Where is the   corporate directory? <<<KNOWLEDGE_ENTRY>>>  ",
			'answer'   => "My View → Organisational Chart.\nIt lists everyone.",
			'keywords' => 'directory, phone list',
			'page'     => 'http://10.0.0.94/doctis/my_view_org_page.php',
			'source'   => 'assistant',
		), $t_user );
		$t_e = ai_knowledge_get( $t_id );
		$this->assertSame( 'Where is the corporate directory? KNOWLEDGE_ENTRY', $t_e['question'] );
		$this->assertSame( "My View → Organisational Chart.\nIt lists everyone.", $t_e['answer'] );
		$this->assertSame( 'my_view_org_page.php', $t_e['page'] );
		$this->assertSame( AI_KNOWLEDGE_UNVERIFIED, (int)$t_e['status'] );

		$this->assertSame( '', ai_knowledge_clean_page( 'no_such_page.php' ) );
		$this->assertSame( '', ai_knowledge_clean_page( '../config/config_inc.php' ) );
		$this->assertSame( 'view_all_bug_page.php?filter=1', ai_knowledge_clean_page( 'view_all_bug_page.php?filter=1' ) );

		$this->expectException( ClientException::class );
		$this->add( array( 'question' => 'Empty answer', 'answer' => '  ' ), $t_user );
	}

	public function testReviewerAddsPublishedOthersUnverified() {
		$t_manager = $this->makeUser( MANAGER );
		$t_reporter = $this->makeUser( REPORTER );
		$this->assertTrue( ai_knowledge_user_can_review( $t_manager ) );
		$this->assertFalse( ai_knowledge_user_can_review( $t_reporter ) );

		$t_m = ai_knowledge_get( $this->add( array( 'question' => 'Q', 'answer' => 'A', 'source' => 'manual' ), $t_manager ) );
		$this->assertSame( AI_KNOWLEDGE_PUBLISHED, (int)$t_m['status'] );
		$t_a = ai_knowledge_get( $this->add( array( 'question' => 'Q', 'answer' => 'A', 'source' => 'assistant' ), $t_manager ) );
		$this->assertSame( AI_KNOWLEDGE_UNVERIFIED, (int)$t_a['status'] );   # taught entries always start unverified
	}

	public function testReviewPermissionsAndCorrections() {
		$t_manager = $this->makeUser( MANAGER );
		$t_reporter = $this->makeUser( REPORTER );
		$t_old = $this->add( array( 'question' => 'Old', 'answer' => 'Wrong' ), $t_reporter );
		$t_new = $this->add( array( 'question' => 'New', 'answer' => 'Right', 'supersedes' => $t_old ), $t_reporter );

		try {
			ai_knowledge_review( $t_new, 'publish', array(), $t_reporter );
			$this->fail( 'non-reviewer published' );
		} catch( ClientException $e ) {
			$this->assertSame( AI_KNOWLEDGE_UNVERIFIED, (int)ai_knowledge_get( $t_new )['status'] );
		}
		ai_knowledge_review( $t_new, 'publish', array(), $t_manager );
		$this->assertSame( AI_KNOWLEDGE_PUBLISHED, (int)ai_knowledge_get( $t_new )['status'] );
		$this->assertSame( AI_KNOWLEDGE_RETIRED, (int)ai_knowledge_get( $t_old )['status'] );   # correction retires the original

		ai_knowledge_review( $t_new, 'edit', array( 'answer' => 'Right, edited', 'page' => 'nope.php' ), $t_manager );
		$t_e = ai_knowledge_get( $t_new );
		$this->assertSame( 'Right, edited', $t_e['answer'] );
		$this->assertSame( '', $t_e['page'] );
		$this->assertSame( AI_KNOWLEDGE_PUBLISHED, (int)$t_e['status'] );   # editing keeps the status

		# Authors may delete their own unverified entries only.
		$t_own = $this->add( array( 'question' => 'Mine', 'answer' => 'x' ), $t_reporter );
		ai_knowledge_delete( $t_own, $t_reporter );
		$this->assertNull( ai_knowledge_get( $t_own ) );
		$this->expectException( ClientException::class );
		ai_knowledge_delete( $t_new, $t_reporter );
	}

	public function testProjectEntriesVisibleOnlyWithAccess() {
		$t_project = (int)project_create( 'KbTestProject' . rand(), '', 10, VS_PRIVATE );
		$this->project_ids[] = $t_project;
		$t_member = $this->makeUser( REPORTER );
		$t_outsider = $this->makeUser( REPORTER );
		project_add_user( $t_project, $t_member, REPORTER );

		$t_id = $this->add( array( 'question' => 'Secret', 'answer' => 'Only members', 'project_id' => $t_project ), $t_member );
		$this->assertContains( $t_id, array_map( 'intval', array_column( ai_knowledge_list( $t_member ), 'id' ) ) );
		$this->assertNotContains( $t_id, array_map( 'intval', array_column( ai_knowledge_list( $t_outsider ), 'id' ) ) );

		$this->expectException( ClientException::class );
		$this->add( array( 'question' => 'X', 'answer' => 'Y', 'project_id' => $t_project ), $t_outsider );
	}

	public function testDailyLimitForTaughtEntries() {
		$t_user = $this->makeUser( REPORTER );
		$t_saved = config_get_global( 'ai_knowledge_daily_limit' );
		config_set_global( 'ai_knowledge_daily_limit', 2 );
		try {
			$this->add( array( 'question' => '1', 'answer' => 'a', 'source' => 'assistant' ), $t_user );
			$this->add( array( 'question' => '2', 'answer' => 'b', 'source' => 'assistant' ), $t_user );
			$this->add( array( 'question' => '3', 'answer' => 'c', 'source' => 'manual' ), $t_user );   # manual entries don't count
			$this->expectException( ClientException::class );
			$this->add( array( 'question' => '4', 'answer' => 'd', 'source' => 'assistant' ), $t_user );
		} finally {
			config_set_global( 'ai_knowledge_daily_limit', $t_saved );
		}
	}

	public function testTeachingMarkerSavesUnverifiedEntry() {
		$t_reply = "Saved it.\n<<<KNOWLEDGE_ENTRY question=\"Where is the corporate directory?\" keywords=\"directory\" "
			. "page=\"my_view_org_page.php\" scope=\"global\" supersedes=\"\">>>\nMy View → Organisational Chart.\n"
			. "<<<END_KNOWLEDGE_ENTRY>>>\nAnything else?";
		$t_result = ai_assist_process_knowledge_entry( $t_reply, auth_get_current_user_id() );
		$this->entry_ids[] = (int)$t_result['id'];

		$this->assertNull( $t_result['error'] );
		$this->assertSame( "Saved it.\n\nAnything else?", $t_result['stripped_reply'] );
		$t_e = ai_knowledge_get( (int)$t_result['id'] );
		$this->assertSame( 'assistant', $t_e['source'] );
		$this->assertSame( AI_KNOWLEDGE_UNVERIFIED, (int)$t_e['status'] );
		$this->assertSame( 'my_view_org_page.php', $t_e['page'] );
		$this->assertNull( ai_assist_process_knowledge_entry( 'No entry here.', auth_get_current_user_id() ) );
	}

	public function testNavigationMapAndHelpPromptShape() {
		$t_map = ai_assist_knowledge_nav_map();
		$this->assertStringContainsString( 'Organisational Chart: my_view_org_page.php', $t_map );
		$this->assertStringContainsString( 'My Meetings: my_view_meeting_page.php', $t_map );

		$t_blocks = ai_assist_help_system_prompt( auth_get_current_user_id() );
		$this->assertCount( 2, $t_blocks );
		$this->assertSame( array( 'type' => 'ephemeral' ), $t_blocks[0]['cache_control'] );
		$this->assertStringContainsString( '## DOCTIS USER MANUAL', $t_blocks[0]['text'] );
		$this->assertStringContainsString( 'pending → received', $t_blocks[0]['text'] );    # real arrows, not →
		$this->assertStringNotContainsString( 'PAGES THIS USER CAN OPEN', $t_blocks[0]['text'] );   # per-user part stays out of the cache
		$this->assertStringContainsString( 'my_view_org_page.php', $t_blocks[1]['text'] );
	}
}
