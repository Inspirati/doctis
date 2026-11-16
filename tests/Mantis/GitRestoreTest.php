<?php
namespace Mantis\tests\Mantis;

require_api( 'git_restore_api.php' );

class GitRestoreTest extends MantisCoreBase {
	private $root;
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/restore-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root, 0700 );
		foreach( array( 'git_restore_max_archive_size', 'git_restore_max_unpacked_size', 'git_storage_root', 'git_lfs_root', 'git_worktree_root' ) as $t_key ) {
			$this->saved[$t_key] = config_get_global( $t_key );
		}
	}

	protected function tearDown(): void {
		foreach( $this->saved as $t_key => $t_value ) { config_set_global( $t_key, $t_value ); }
		git_restore_remove( $this->root );
		parent::tearDown();
	}

	private function command( array $p_args ): string {
		$t_out = tmpfile();
		$t_process = proc_open( $p_args, array( 0 => array( 'pipe', 'r' ), 1 => $t_out, 2 => $t_out ), $t_pipes );
		fclose( $t_pipes[0] );
		$t_rc = proc_close( $t_process );
		rewind( $t_out );
		$t_text = stream_get_contents( $t_out );
		fclose( $t_out );
		$this->assertSame( 0, $t_rc, $t_text );
		return trim( $t_text );
	}

	private function backup( bool $p_lfs = true ): string {
		$t_source = $this->root . '/source';
		mkdir( $t_source );
		$t_work = $this->root . '/work';
		$this->command( array( 'git', 'init', '-q', '-b', 'main', $t_work ) );
		$this->command( array( 'git', '-C', $t_work, 'config', 'user.name', 'Restore test' ) );
		$this->command( array( 'git', '-C', $t_work, 'config', 'user.email', 'restore@example.test' ) );
		$t_data = 'large file content';
		$t_hash = hash( 'sha256', $t_data );
		file_put_contents( $t_work . "/a ' [1].md", $p_lfs
			? "version https://git-lfs.github.com/spec/v1\noid sha256:$t_hash\nsize " . strlen( $t_data ) . "\n"
			: 'plain text' );
		# Exercise GNU long-name archive records and path-as-data handling.
		mkdir( $t_work . '/' . str_repeat( 'long', 30 ) );
		file_put_contents( $t_work . '/' . str_repeat( 'long', 30 ) . '/name.txt', 'long name' );
		$this->command( array( 'git', '-C', $t_work, 'add', '.' ) );
		$this->command( array( 'git', '-C', $t_work, 'commit', '-qm', 'Initial' ) );
		$t_repos = $p_lfs ? $t_source . '/repositories' : $t_source;
		if( $p_lfs ) { mkdir( $t_repos ); }
		$this->command( array( 'git', 'clone', '-q', '--bare', $t_work, $t_repos . '/example-r1.git' ) );
		git_restore_git( $t_repos . '/example-r1.git', array( 'config', 'doctis.pushpolicy', 'source' ) );
		# A long path inside a bare repo also forces a GNU long-name tar entry.
		file_put_contents( $t_repos . '/example-r1.git/' . str_repeat( 'x', 110 ), 'metadata' );
		if( $p_lfs ) {
			$t_object = $t_source . '/lfs/example-r1/' . substr( $t_hash, 0, 2 ) . '/' . substr( $t_hash, 2, 2 );
			mkdir( $t_object, 0700, true );
			file_put_contents( $t_object . '/' . $t_hash, $t_data );
		}
		file_put_contents( $t_repos . '/.doctis-lock', '' );
		$t_archive = $this->root . '/backup.tar.gz';
		$this->command( array( 'tar', 'czf', $t_archive, '-C', $t_source, '.' ) );
		return $t_archive;
	}

	public function testRoundTripChecksLfsHistoryPolicyAndLegacyLayout(): void {
		$t_archive = $this->backup();
		$t_report = git_restore_extract( $t_archive, $this->root . '/out' );
		$this->assertTrue( $t_report['includes_lfs'] );
		$t_report = git_restore_validate_store( $this->root . '/out', array( 1 => 'example-r1.git' ) );
		$this->assertSame( 1, $t_report['lfs_objects'] );
		$this->assertSame( 1, $t_report['lfs_pointers'] );
		$this->assertSame( 'source', $t_report['push_policies']['example-r1.git'] );
		$this->assertFileExists( $this->root . '/out/repositories/example-r1.git/hooks/pre-receive' );
		$this->assertFileDoesNotExist( $this->root . '/out/repositories/.doctis-lock' );
	}

	public function testLegacyGitOnlyArchive(): void {
		$t_report = git_restore_extract( $this->backup( false ), $this->root . '/out' );
		$this->assertFalse( $t_report['includes_lfs'] );
		$t_report = git_restore_validate_store( $this->root . '/out', array( 1 => 'example-r1.git' ) );
		$this->assertSame( 0, $t_report['lfs_objects'] );
	}

	public function testMissingLfsDoesNotValidate(): void {
		git_restore_extract( $this->backup(), $this->root . '/out' );
		git_restore_remove( $this->root . '/out/lfs' );
		mkdir( $this->root . '/out/lfs' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no matching object' );
		git_restore_validate_store( $this->root . '/out', array( 1 => 'example-r1.git' ) );
	}

	public function testMissingDatabaseRepositoryDoesNotValidate(): void {
		git_restore_extract( $this->backup(), $this->root . '/out' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'lacks repositories' );
		git_restore_validate_store( $this->root . '/out', array( 2 => 'missing-r2.git' ) );
	}

	public function testCorruptLfsDoesNotValidate(): void {
		git_restore_extract( $this->backup(), $this->root . '/out' );
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root . '/out/lfs', \FilesystemIterator::SKIP_DOTS ) ) as $t_file ) {
			file_put_contents( $t_file->getPathname(), 'corrupt' );
		}
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'corrupt LFS' );
		git_restore_validate_store( $this->root . '/out', array( 1 => 'example-r1.git' ) );
	}

	/** @dataProvider unsafeMembers */
	public function testUnsafeArchiveMembersAreRefused( string $p_name, string $p_type ): void {
		$t_header = str_pad( $p_name, 100, "\0" ) . sprintf( "%07o\0%07o\0%07o\0%011o\0%011o\0", 0600, 0, 0, 0, 0 )
			. '        ' . $p_type . str_repeat( "\0", 100 ) . "ustar\00000" . str_repeat( "\0", 247 );
		$t_header = substr_replace( $t_header, sprintf( "%06o\0 ", array_sum( unpack( 'C*', $t_header ) ) ), 148, 8 );
		file_put_contents( $this->root . '/bad.tar.gz', gzencode( $t_header . str_repeat( "\0", 1024 ) ) );
		$this->expectException( \RuntimeException::class );
		git_restore_extract( $this->root . '/bad.tar.gz', $this->root . '/out' );
	}

	public static function unsafeMembers(): array {
		return array( array( '../escape', '0' ), array( '/tmp/escape', '0' ),
			array( 'repositories/example-r1.git/linked', '2' ), array( 'repositories/example-r1.git/linked', '1' ),
			array( 'repositories/example-r1.git/device', '3' ), array( 'unexpected/file', '0' ) );
	}

	public function testUnpackedSizeAndTruncationAreBounded(): void {
		$t_archive = $this->backup();
		config_set_global( 'git_restore_max_unpacked_size', 1024 );
		try {
			git_restore_extract( $t_archive, $this->root . '/small' );
			$this->fail( 'Unpacked limit was ignored' );
		} catch( \RuntimeException $t_error ) {
			$this->assertStringContainsString( 'restore limit', $t_error->getMessage() );
		}
		config_set_global( 'git_restore_max_unpacked_size', $this->saved['git_restore_max_unpacked_size'] );
		file_put_contents( $this->root . '/truncated.tar.gz', substr( file_get_contents( $t_archive ), 0, 100 ) );
		$this->expectException( \RuntimeException::class );
		git_restore_extract( $this->root . '/truncated.tar.gz', $this->root . '/truncated' );
	}

	public function testRetryCannotAppendTheSameChunkTwice(): void {
		$t_state = git_restore_start( 'test.tar.gz', 6, '', 1 );
		$t_dir = git_restore_ticket_path( $t_state['ticket'] );
		try {
			file_put_contents( $this->root . '/chunk', 'abc' );
			$this->assertSame( 3, git_restore_append( $t_dir, $t_state, 0, $this->root . '/chunk' ) );
			try {
				git_restore_append( $t_dir, $t_state, 0, $this->root . '/chunk' );
				$this->fail( 'Duplicate chunk was accepted' );
			} catch( \RuntimeException $t_error ) { $this->assertStringContainsString( 'offset', $t_error->getMessage() ); }
			$this->assertSame( 6, git_restore_append( $t_dir, $t_state, 3, $this->root . '/chunk' ) );
			$this->assertSame( 'abcabc', file_get_contents( $t_dir . '/archive.tar.gz' ) );
		} finally { git_restore_remove( $t_dir ); }
	}

	public function testBadTarChecksumIsRefused(): void {
		$t_archive = $this->backup();
		$t_tar = gzdecode( file_get_contents( $t_archive ) );
		$t_tar[0] = 'X';
		file_put_contents( $t_archive, gzencode( $t_tar ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'header checksum' );
		git_restore_extract( $t_archive, $this->root . '/out' );
	}

	public function testOverlappingRootsAreRefused(): void {
		mkdir( $this->root . '/nested' );
		mkdir( $this->root . '/other' );
		config_set_global( 'git_storage_root', $this->root );
		config_set_global( 'git_lfs_root', $this->root . '/nested' );
		config_set_global( 'git_worktree_root', $this->root . '/other' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'must not overlap' );
		git_restore_roots();
	}

	public function testRestoreRefusesAnotherActiveRequest(): void {
		global $g_git_restore_request_lock;
		$t_saved = $g_git_restore_request_lock;
		$t_path = $this->root . '/request-lock';
		file_put_contents( $t_path, '' );
		$t_code = '$f=fopen($argv[1],"r");flock($f,LOCK_SH);echo "ready\\n";fgets(STDIN);';
		$t_process = proc_open( array( PHP_BINARY, '-r', $t_code, $t_path ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $t_pipes );
		try {
			$this->assertSame( "ready\n", fgets( $t_pipes[1] ) );
			$g_git_restore_request_lock = fopen( $t_path, 'r' );
			flock( $g_git_restore_request_lock, LOCK_SH );
			try {
				git_restore_exclusive();
				$this->fail( 'Restore bypassed another active request' );
			} catch( \RuntimeException $t_error ) {
				$this->assertStringContainsString( 'requests are active', $t_error->getMessage() );
			}
		} finally {
			fclose( $g_git_restore_request_lock );
			$g_git_restore_request_lock = $t_saved;
			foreach( $t_pipes as $t_pipe ) { fclose( $t_pipe ); }
			proc_close( $t_process );
		}
	}

	public function testFailedMoveReportsTheFilesystemReasonAndKeepsTheSource(): void {
		file_put_contents( $this->root . '/original', 'original' );
		mkdir( $this->root . '/destination' );
		try {
			git_restore_move( $this->root . '/original', $this->root . '/destination' );
			$this->fail( 'Conflicting destination was accepted' );
		} catch( \RuntimeException $t_error ) {
			$this->assertStringContainsString( 'Could not move', $t_error->getMessage() );
			$this->assertStringContainsString( '/original', $t_error->getMessage() );
			$this->assertStringContainsString( 'rename(', $t_error->getMessage() );
			$this->assertStringNotContainsString( 'filesystem rename failed', $t_error->getMessage() );
			$this->assertSame( 'original', file_get_contents( $this->root . '/original' ) );
		}
	}

	public function testInterruptedReplacementRollsBackAllThreeRoots(): void {
		$t_state = array( 'ticket' => str_repeat( 'a', 32 ), 'phase' => 'applying', 'installation' => array() );
		foreach( array( 'repositories', 'lfs', 'worktrees' ) as $t_kind ) {
			$t_root = $this->root . '/' . $t_kind;
			mkdir( $t_root );
			mkdir( $t_root . '/.doctis-previous-' . $t_state['ticket'] );
			file_put_contents( $t_root . '/.doctis-previous-' . $t_state['ticket'] . '/old', 'original' );
			file_put_contents( $t_root . '/old', 'replacement' );
			file_put_contents( $t_root . '/new', 'new entry' );
			$t_state['installation'][$t_kind] = array( 'root' => $t_root, 'old' => array( 'old' ), 'new' => array( 'old', 'new' ) );
		}
		git_restore_rollback( $this->root, $t_state );
		foreach( $t_state['installation'] as $t_install ) {
			$this->assertSame( 'original', file_get_contents( $t_install['root'] . '/old' ) );
			$this->assertFileDoesNotExist( $t_install['root'] . '/new' );
		}
		$this->assertSame( 'ready', git_restore_state( $this->root )['phase'] );
	}
}
