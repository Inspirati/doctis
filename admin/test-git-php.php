<?php
/**
 * czproject/git-php integration test
 *
 * Self-contained: creates a temporary bare repo and working tree, runs the
 * full store/retrieve/delete cycle, then tears everything down.  Leaves no
 * residue on success or failure.
 *
 * Run on vaio as www-data:
 *   sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php
 */

require '/var/www/html/doctis/vendor/autoload.php';

use CzProject\GitPhp\Git;
use CzProject\GitPhp\GitException;

// ── Config ────────────────────────────────────────────────────────────────────

define( 'TEST_SLUG',    'phptest-' . getmypid() );
define( 'BARE_ROOT',    '/var/git/doctis' );
define( 'WORKTREE_ROOT','/var/www/doctis/worktrees' );
define( 'BARE_REPO',    BARE_ROOT    . '/' . TEST_SLUG . '.git' );
define( 'WORKTREE',     WORKTREE_ROOT . '/' . TEST_SLUG );
define( 'DOC_REF',      'TEST-DOC-001' );
define( 'REL_PATH',     DOC_REF . '/source.md' );
define( 'ABS_DIR',      WORKTREE . '/' . DOC_REF );
define( 'ABS_FILE',     WORKTREE . '/' . REL_PATH );

// ── Helpers ───────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function ok( string $label ) : void {
    global $pass;
    $pass++;
    echo "  $label ... OK\n";
}

function fail( string $label, string $detail = '' ) : void {
    global $fail;
    $fail++;
    echo "  $label ... FAILED" . ( $detail !== '' ? ": $detail" : '' ) . "\n";
}

function exec_cmd( string $cmd ) : array {
    exec( $cmd . ' 2>&1', $out, $rc );
    return [ $rc, implode( "\n", $out ) ];
}

function teardown() : void {
    echo "\n── Teardown ─────────────────────────────────────────────────────────────\n";
    foreach ( [ WORKTREE, BARE_REPO ] as $path ) {
        if ( is_dir( $path ) ) {
            exec_cmd( 'rm -rf ' . escapeshellarg( $path ) );
            echo "  Removed: $path\n";
        }
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────

echo "=== czproject/git-php integration test ===\n";
echo "    slug: " . TEST_SLUG . "\n\n";

try {

    // ── STEP 1: Init bare repo ─────────────────────────────────────────────────

    echo "── Setup ────────────────────────────────────────────────────────────────\n";

    echo "  1. git init --bare   ... ";
    [ $rc, $out ] = exec_cmd( 'git init --bare ' . escapeshellarg( BARE_REPO ) );
    if ( $rc !== 0 ) {
        fail( '1. git init --bare', $out );
    } else {
        ok( '1. git init --bare' );
    }

    // ── STEP 2: Clone working tree ─────────────────────────────────────────────

    echo "  2. git clone         ... ";
    [ $rc, $out ] = exec_cmd(
        'git clone ' . escapeshellarg( BARE_REPO ) . ' ' . escapeshellarg( WORKTREE )
    );
    if ( $rc !== 0 ) {
        fail( '2. git clone', $out );
    } else {
        ok( '2. git clone' );
    }

    // ── STEP 3: Open via czproject ─────────────────────────────────────────────

    echo "\n── Store cycle ──────────────────────────────────────────────────────────\n";

    echo "  3. czproject open    ... ";
    $git  = new Git;
    $repo = $git->open( WORKTREE );
    ok( '3. czproject open' );

    // ── STEP 4: Write source file ──────────────────────────────────────────────

    echo "  4. write source file ... ";
    if ( !is_dir( ABS_DIR ) ) {
        mkdir( ABS_DIR, 0775, true );
    }
    $content =
        "---\n" .
        "doc_id: " . DOC_REF . "\n" .
        "title:  czproject/git-php integration test\n" .
        "---\n\n" .
        "Written by PHP at: " . date( 'Y-m-d H:i:s' ) . "\n";
    file_put_contents( ABS_FILE, $content );
    ok( '4. write source file' );

    // ── STEP 5: git add ────────────────────────────────────────────────────────

    echo "  5. git add           ... ";
    $repo->addFile( REL_PATH );
    ok( '5. git add' );

    // ── STEP 6: git commit ─────────────────────────────────────────────────────

    $repo->commit( 'dwg_id=1 rev=A czproject library test' );
    $sha = (string) $repo->getLastCommitId();
    echo "  6. git commit        ... OK (SHA: $sha)\n";
    $pass++;

    // ── STEP 7: git push ───────────────────────────────────────────────────────

    echo "  7. git push          ... ";
    $repo->push( [ 'origin', $repo->getCurrentBranchName() ] );
    ok( '7. git push' );

    // ── STEP 8: Retrieve by SHA ────────────────────────────────────────────────

    echo "\n── Retrieval ────────────────────────────────────────────────────────────\n";

    echo "  8. retrieve by SHA   ... ";
    $retrieved = shell_exec(
        'git --git-dir=' . escapeshellarg( BARE_REPO ) .
        ' show ' . escapeshellarg( $sha . ':' . REL_PATH ) . ' 2>&1'
    );
    if ( strpos( $retrieved, 'czproject/git-php integration test' ) !== false ) {
        ok( '8. retrieve by SHA' );
    } else {
        fail( '8. retrieve by SHA', $retrieved );
    }

    // ── STEP 9: Retrieve via HEAD ──────────────────────────────────────────────

    echo "  9. retrieve via HEAD ... ";
    $head_content = shell_exec(
        'git --git-dir=' . escapeshellarg( BARE_REPO ) .
        ' show HEAD:' . escapeshellarg( REL_PATH ) . ' 2>&1'
    );
    if ( strpos( $head_content, 'czproject/git-php integration test' ) !== false ) {
        ok( '9. retrieve via HEAD' );
    } else {
        fail( '9. retrieve via HEAD', $head_content );
    }

    // ── STEP 10: SHA256 integrity ──────────────────────────────────────────────

    echo "  10. SHA256 integrity ... ";
    $hash_bare = trim( shell_exec(
        'git --git-dir=' . escapeshellarg( BARE_REPO ) .
        ' show ' . escapeshellarg( $sha . ':' . REL_PATH ) . ' | sha256sum 2>&1'
    ) );
    $hash_disk = trim( shell_exec( 'sha256sum ' . escapeshellarg( ABS_FILE ) . ' 2>&1' ) );
    // sha256sum output: "<hash>  <filename>" — compare just the hash portion
    $bare_hash = explode( ' ', $hash_bare )[0];
    $disk_hash = explode( ' ', $hash_disk )[0];
    if ( $bare_hash === $disk_hash && strlen( $bare_hash ) === 64 ) {
        ok( '10. SHA256 integrity' );
    } else {
        fail( '10. SHA256 integrity', "bare=$bare_hash disk=$disk_hash" );
    }

    // ── STEP 11: Soft delete (git rm + commit + push) ─────────────────────────

    echo "\n── Delete cycle ─────────────────────────────────────────────────────────\n";

    echo "  11. git rm           ... ";
    $repo->removeFile( REL_PATH );
    ok( '11. git rm' );

    $repo->commit( 'dwg_id=1 FILE_DELETED by phptest' );
    $del_sha = (string) $repo->getLastCommitId();
    echo "  12. commit delete    ... OK (SHA: $del_sha)\n";
    $pass++;

    echo "  13. push delete      ... ";
    $repo->push( [ 'origin', $repo->getCurrentBranchName() ] );
    ok( '13. push delete' );

    // ── STEP 14: Verify file absent from HEAD ──────────────────────────────────

    echo "\n── History integrity ────────────────────────────────────────────────────\n";

    echo "  14. absent from HEAD ... ";
    $absent = shell_exec(
        'git --git-dir=' . escapeshellarg( BARE_REPO ) .
        ' show HEAD:' . escapeshellarg( REL_PATH ) . ' 2>&1'
    );
    if ( strpos( $absent, 'exists on disk' ) !== false
      || strpos( $absent, 'does not exist' ) !== false
      || strpos( $absent, 'fatal' ) !== false ) {
        ok( '14. absent from HEAD' );
    } else {
        fail( '14. absent from HEAD', 'file still visible at HEAD' );
    }

    // ── STEP 15: Both commits present in log ───────────────────────────────────

    echo "  15. history retained ... ";
    $log = shell_exec(
        'git --git-dir=' . escapeshellarg( BARE_REPO ) . ' log --oneline 2>&1'
    );
    $sha_short     = substr( $sha,     0, 7 );
    $del_sha_short = substr( $del_sha, 0, 7 );
    if ( strpos( $log, $sha_short ) !== false && strpos( $log, $del_sha_short ) !== false ) {
        ok( '15. history retained' );
    } else {
        fail( '15. history retained', "log:\n$log" );
    }

} catch ( GitException $e ) {
    $fail++;
    echo "EXCEPTION: GitException — " . $e->getMessage() . "\n";
} finally {
    teardown();
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n── Result ───────────────────────────────────────────────────────────────\n";
$total = $pass + $fail;
echo "  $pass / $total tests passed\n";
if ( $fail > 0 ) {
    echo "  $fail FAILED\n";
    exit( 1 );
}
echo "  All tests passed.\n";
exit( 0 );
