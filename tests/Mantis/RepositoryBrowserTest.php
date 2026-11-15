<?php
namespace Mantis\tests\Mantis;

require_api( 'repository_browser_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'print_dwg_api.php' );

/** Real Git fixtures live only under a temporary directory. DB fixtures are removed. */
class RepositoryBrowserTest extends MantisCoreBase {
	private $root;
	private $repo;
	private $saved = array();
	private $projects = array();
	private $users = array();
	private $repositories = array();
	private $first;
	private $second;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->root = sys_get_temp_dir() . '/doctis-browser-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root, 0700 );
		foreach( array( 'git_storage_root', 'git_http_enabled', 'git_http_read_threshold',
			'git_http_write_threshold', 'enable_email_notification', 'send_reset_password' ) as $t_key ) {
			$this->saved[$t_key] = config_get_global( $t_key );
		}
		config_set_global( 'git_storage_root', $this->root );
		config_set_global( 'git_http_enabled', ON );
		config_set_global( 'git_http_read_threshold', DEVELOPER );
		config_set_global( 'git_http_write_threshold', DEVELOPER );
		config_set_global( 'enable_email_notification', OFF );
		config_set_global( 'send_reset_password', OFF );
		$this->repo = array( 'bare' => $this->root . '/work', 'bare_exists' => true );
		mkdir( $this->repo['bare'] );
		$this->git( 'init -q -b main' );
		$this->git( 'config user.name ' . escapeshellarg( 'Browser <test>' ) );
		$this->git( 'config user.email browser@example.test' );
		mkdir( $this->repo['bare'] . '/folder' );
		file_put_contents( $this->repo['bare'] . '/folder/a[1].txt', 'first' );
		file_put_contents( $this->repo['bare'] . '/folder/a1.txt', 'other' );
		file_put_contents( $this->repo['bare'] . "/हिंदी\n'&.txt", 'unicode' );
		$this->git( 'add .' );
		$this->git( 'commit -q -m first' );
		$this->first = $this->git( 'rev-parse HEAD' );
		file_put_contents( $this->repo['bare'] . '/folder/a[1].txt', 'second' );
		$this->git( 'add .' );
		$this->git( 'commit -q -m ' . escapeshellarg( '<script>second</script>' ) );
		$this->second = $this->git( 'rev-parse HEAD' );
		# Read helpers work against both bare repos and .git directories.
		$this->repo['bare'] .= '/.git';
	}

	protected function tearDown(): void {
		foreach( array_reverse( $this->projects ) as $t_id ) {
			config_delete( 'git_http_write_threshold', ALL_USERS, $t_id );
			project_delete( $t_id );
		}
		foreach( $this->repositories as $t_id ) {
			db_query( 'DELETE FROM {project_repository} WHERE repository_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {repository} WHERE id=' . db_param(), array( $t_id ) );
			unset( $GLOBALS['g_cache_repository'][$t_id] );
		}
		foreach( $this->users as $t_id ) {
			user_delete( $t_id );
		}
		foreach( $this->saved as $t_key => $t_value ) {
			config_set_global( $t_key, $t_value );
		}
		if( $this->root && is_dir( $this->root ) ) {
			$t_iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST );
			foreach( $t_iterator as $t_file ) {
				$t_file->isDir() && !$t_file->isLink() ? rmdir( $t_file->getPathname() ) : unlink( $t_file->getPathname() );
			}
			rmdir( $this->root );
		}
		parent::tearDown();
	}

	private function git( string $p_args, bool $p_success = true ): string {
		$t_output = git_store_run( $this->root . '/work', false, $p_args, $t_rc );
		if( $p_success ) {
			$this->assertSame( 0, $t_rc, $p_args );
		} else {
			$this->assertNotSame( 0, $t_rc, $p_args );
		}
		return trim( $t_output );
	}

	public function testOnlyHeadAndFullCommitIdsAreAccepted(): void {
		$this->assertSame( $this->second, repository_browser_commit( $this->repo ) );
		$this->assertSame( $this->first, repository_browser_commit( $this->repo, $this->first ) );
		foreach( array( 'HEAD^', '--all', 'main', substr( $this->first, 0, 8 ), str_repeat( '0', 40 ),
			$this->git( 'rev-parse HEAD:folder/a1.txt' ), "HEAD\0" ) as $t_invalid ) {
			$this->assertSame( '', repository_browser_commit( $this->repo, $t_invalid ) );
		}
	}

	public function testDirectoriesAndHistoricalTreesPreserveLiteralPaths(): void {
		$this->git( 'rm -q -- ' . escapeshellarg( 'folder/a[1].txt' ) );
		$this->git( 'commit -q -m deleted' );
		$t_tree = git_store_tree( $this->repo );
		$this->assertArrayNotHasKey( 'folder/a[1].txt', $t_tree );
		$t_old = git_store_tree( $this->repo, $this->first );
		$this->assertArrayHasKey( 'folder/a[1].txt', $t_old );
		$t_entries = repository_browser_entries( $t_old, '' );
		$this->assertSame( 'folder', array_key_first( $t_entries ) );
		$this->assertSame( 'tree', $t_entries['folder']['type'] );
		$this->assertArrayHasKey( "हिंदी\n'&.txt", $t_entries );
		$this->assertCount( 2, repository_browser_entries( $t_old, 'folder' ) );
		$this->assertSame( array(), repository_browser_entries( $t_old, 'fold' ) );
		$this->assertSame( array(), repository_browser_entries( $t_old, '../folder' ) );
	}

	public function testHistoryUsesLiteralPathsAndKeepsSubjectsAsData(): void {
		$t_history = repository_browser_history( $this->repo, $this->second, 'folder/a[1].txt' );
		$this->assertCount( 2, $t_history );
		$this->assertSame( '<script>second</script>', $t_history[0]['subject'] );
		$this->assertSame( $this->second, $t_history[0]['sha'] );
		$this->assertCount( 1, repository_browser_history( $this->repo, $this->second, 'folder/a1.txt' ) );
		$this->assertCount( 1, repository_browser_history( $this->repo, $this->second, "हिंदी\n'&.txt" ) );
		$this->assertSame( array(), repository_browser_history( $this->repo, $this->second, ':(glob)**' ) );
		$this->assertSame( array(), repository_browser_history( $this->repo, $this->second, "folder\0" ) );
	}

	public function testHistoryPagesDoNotOverlapAndIncludeDeletedPaths(): void {
		$t_first = repository_browser_history( $this->repo, $this->second, '', 0, 1 );
		$t_second = repository_browser_history( $this->repo, $this->second, '', 1, 1 );
		$this->assertSame( $this->second, $t_first[0]['sha'] );
		$this->assertSame( $this->first, $t_second[0]['sha'] );
		$this->assertSame( array(), repository_browser_history( $this->repo, $this->second, '', 2 ) );
		$this->git( 'rm -q folder/a1.txt' );
		$this->git( 'commit -q -m deleted' );
		$t_head = repository_browser_commit( $this->repo );
		$this->assertCount( 2, repository_browser_history( $this->repo, $t_head, 'folder/a1.txt' ) );
	}

	public function testLongHistoryHasBoundedStablePages(): void {
		for( $i = 0; $i < 53; $i++ ) {
			$this->git( 'commit -q --allow-empty -m page' . $i );
		}
		$t_head = repository_browser_commit( $this->repo );
		$t_first = repository_browser_history( $this->repo, $t_head );
		$t_second = repository_browser_history( $this->repo, $t_head, '', 50 );
		$this->assertCount( 51, $t_first ); # One look-ahead row for the Next link.
		$this->assertCount( 5, $t_second );
		$this->assertSame( $t_first[50]['sha'], $t_second[0]['sha'] );
		$this->assertSame( array(), array_intersect( array_column( array_slice( $t_first, 0, 50 ), 'sha' ),
			array_column( $t_second, 'sha' ) ) );
	}

	public function testDeveloperPushAccessUsesOwnerProjectAndHonoursRestrictions(): void {
		$t_parent = (int)project_create( 'browser-parent-' . rand(), '', 10, VS_PRIVATE );
		$this->projects[] = $t_parent;
		$t_child = (int)project_create( 'browser-child-' . rand(), '', 10, VS_PRIVATE );
		$this->projects[] = $t_child;
		project_hierarchy_add( $t_child, $t_parent );
		$t_repo_id = repository_create( 'Browser test', $t_parent );
		$this->repositories[] = $t_repo_id;
		repository_link_project( $t_parent, $t_repo_id );
		mkdir( repository_bare_path( $t_repo_id ) );
		$t_user = (int)user_get_id_by_cookie( user_create( 'rbtest' . rand(), 'testpass', '' ) );
		$this->users[] = $t_user;
		project_add_user( $t_child, $t_user, DEVELOPER );
		$this->assertFalse( repository_user_can_clone( $t_child, $t_user ) );
		$this->assertFalse( repository_user_can_push( $t_child, $t_user ) );
		project_add_user( $t_parent, $t_user, DEVELOPER );
		$GLOBALS['g_cache_access_matrix_user_ids'] = array();
		$this->assertTrue( repository_user_can_clone( $t_child, $t_user ) );
		$this->assertTrue( repository_user_can_push( $t_child, $t_user ) );
		config_set( 'git_http_write_threshold', MANAGER, ALL_USERS, $t_parent );
		$this->assertTrue( repository_user_can_clone( $t_child, $t_user ) );
		$this->assertFalse( repository_user_can_push( $t_child, $t_user ) );
		project_add_user( $t_parent, $t_user, MANAGER );
		$GLOBALS['g_cache_access_matrix_user_ids'] = array();
		$this->assertTrue( repository_user_can_push( $t_child, $t_user ) );
	}

	public function testPushPoliciesStillProtectControlledHistoryAndManagedRefs(): void {
		$t_bare = $this->root . '/controlled.git';
		$this->git( 'init --bare -q ' . escapeshellarg( $t_bare ) );
		repository_configure_bare( $t_bare );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' HEAD:refs/heads/main' );
		$this->git( 'push --force ' . escapeshellarg( $t_bare ) . ' ' . $this->first . ':refs/heads/main', false );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' HEAD:refs/heads/topic' );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' :refs/heads/topic', false );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' HEAD:refs/doctis/approved/test/1', false );
		repository_set_push_policy( $t_bare, DWG_PUSH_POLICY_SOURCE );
		$this->git( 'push --force ' . escapeshellarg( $t_bare ) . ' ' . $this->first . ':refs/heads/main' );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' :refs/heads/topic' );
		$this->git( 'push ' . escapeshellarg( $t_bare ) . ' HEAD:refs/doctis/approved/test/1', false );
	}
}
