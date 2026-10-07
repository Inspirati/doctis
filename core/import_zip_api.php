<?php
# Doctis — stage a browser-uploaded source archive for the existing Git importer.
# This file deliberately has no application bootstrap so ZIP validation can be
# exercised independently of the database.

/** Run an argument-vector command without a shell and capture its output. */
function doctis_import_zip_command( array $p_command, ?string $p_cwd = null ): array {
	$t_process = proc_open( $p_command, array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'redirect', 1 ),
	), $t_pipes, $p_cwd );
	if( !is_resource( $t_process ) ) {
		throw new RuntimeException( 'Could not start the import command.' );
	}
	fclose( $t_pipes[0] );
	$t_output = stream_get_contents( $t_pipes[1] );
	fclose( $t_pipes[1] );
	return array( proc_close( $t_process ), (string)$t_output );
}

/** Remove only a private staging directory created by this importer. */
function doctis_import_zip_cleanup( string $p_directory ): void {
	if( !preg_match( '#^' . preg_quote( sys_get_temp_dir(), '#' ) . '/doctis-import-[0-9a-f]{32}$#', $p_directory )
		|| !is_dir( $p_directory ) || is_link( $p_directory ) ) {
		return;
	}
	$t_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $p_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach( $t_iterator as $t_entry ) {
		$t_entry->isDir() && !$t_entry->isLink()
			? rmdir( $t_entry->getPathname() )
			: unlink( $t_entry->getPathname() );
	}
	rmdir( $p_directory );
}

/**
 * Extract only selected top-level document roots from a GitHub-style ZIP.
 * Returns the private source directory and counts. No archive path is trusted.
 */
function doctis_import_zip_stage( string $p_zip_path, array $p_roots, array $p_patterns = array( '*.md' ) ): array {
	if( !class_exists( 'ZipArchive' ) ) {
		throw new RuntimeException( 'The PHP ZIP extension is required.' );
	}
	if( !is_file( $p_zip_path ) || filesize( $p_zip_path ) > 64 * 1024 * 1024 ) {
		throw new RuntimeException( 'The uploaded ZIP is missing or exceeds 64 MiB.' );
	}
	if( empty( $p_roots ) ) {
		throw new RuntimeException( 'Select at least one top-level directory.' );
	}
	foreach( $p_roots as $t_root ) {
		if( !preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $t_root ) ) {
			throw new RuntimeException( 'Directory names must be simple top-level names.' );
		}
	}
	if( empty( $p_patterns ) ) {
		throw new RuntimeException( 'Select at least one document pattern.' );
	}
	$t_zip = new ZipArchive();
	if( $t_zip->open( $p_zip_path ) !== true ) {
		throw new RuntimeException( 'The uploaded file is not a readable ZIP archive.' );
	}
	$t_stage = '';
	try {
		if( $t_zip->numFiles < 1 || $t_zip->numFiles > 10000 ) {
			throw new RuntimeException( 'The ZIP must contain 1 to 10000 entries.' );
		}
		$t_names = array();
		$t_first_components = array();
		foreach( range( 0, $t_zip->numFiles - 1 ) as $t_index ) {
			$t_name = $t_zip->getNameIndex( $t_index );
			if( !is_string( $t_name ) || strlen( $t_name ) > 1024 || strpos( $t_name, '\\' ) !== false
				|| strpos( $t_name, "\0" ) !== false || str_starts_with( $t_name, '/' ) ) {
				throw new RuntimeException( 'The ZIP contains an unsafe path.' );
			}
			$t_parts = explode( '/', trim( $t_name, '/' ) );
			if( in_array( '..', $t_parts, true ) || in_array( '.', $t_parts, true )
				|| in_array( '', $t_parts, true ) || in_array( '.git', $t_parts, true ) ) {
				throw new RuntimeException( 'The ZIP contains an unsafe path.' );
			}
			$t_names[$t_index] = $t_name;
			$t_first_components[$t_parts[0]] = true;
		}
		$t_prefix = '';
		if( count( $t_first_components ) === 1 ) {
			$t_first = array_key_first( $t_first_components );
			if( !in_array( $t_first, $p_roots, true ) ) {
				$t_prefix = $t_first . '/';
			}
		}
		$t_stage = rtrim( sys_get_temp_dir(), '/' ) . '/doctis-import-' . bin2hex( random_bytes( 16 ) );
		if( !mkdir( $t_stage, 0700 ) ) {
			throw new RuntimeException( 'Could not create a private import directory.' );
		}
		$t_file_count = 0;
		$t_document_count = 0;
		$t_bytes = 0;
		foreach( $t_names as $t_index => $t_name ) {
			if( $t_prefix !== '' ) {
				if( !str_starts_with( $t_name, $t_prefix ) ) {
					continue;
				}
				$t_name = substr( $t_name, strlen( $t_prefix ) );
			}
			if( $t_name === '' ) {
				continue;
			}
			$t_root = explode( '/', $t_name, 2 )[0];
			if( !in_array( $t_root, $p_roots, true ) ) {
				continue;
			}
			$t_is_directory = str_ends_with( $t_name, '/' );
			if( $t_zip->getExternalAttributesIndex( $t_index, $t_os, $t_attributes )
				&& $t_os === ZipArchive::OPSYS_UNIX ) {
				$t_type = ( $t_attributes >> 16 ) & 0170000;
				if( $t_type !== 0 && $t_type !== ( $t_is_directory ? 0040000 : 0100000 ) ) {
					throw new RuntimeException( 'The ZIP contains a link or special file.' );
				}
			}
			$t_destination = $t_stage . '/' . rtrim( $t_name, '/' );
			$t_parent = $t_is_directory ? $t_destination : dirname( $t_destination );
			if( !is_dir( $t_parent ) && !mkdir( $t_parent, 0700, true ) ) {
				throw new RuntimeException( 'Could not create an import directory.' );
			}
			if( $t_is_directory ) {
				continue;
			}
			$t_stat = $t_zip->statIndex( $t_index );
			if( !$t_stat || $t_stat['size'] > 64 * 1024 * 1024
				|| $t_bytes + $t_stat['size'] > 256 * 1024 * 1024 ) {
				throw new RuntimeException( 'The ZIP exceeds the extracted size limit.' );
			}
			$t_input = $t_zip->getStreamIndex( $t_index );
			$t_output = @fopen( $t_destination, 'x' );
			if( !$t_input || !$t_output ) {
				throw new RuntimeException( 'Could not extract a ZIP entry.' );
			}
			$t_copied = stream_copy_to_stream( $t_input, $t_output, 64 * 1024 * 1024 + 1 );
			fclose( $t_input );
			fclose( $t_output );
			if( $t_copied === false || $t_copied !== $t_stat['size'] ) {
				throw new RuntimeException( 'A ZIP entry is incomplete or exceeds its declared size.' );
			}
			$t_bytes += $t_copied;
			$t_file_count++;
			foreach( $p_patterns as $t_pattern ) {
				if( fnmatch( strtolower( $t_pattern ), strtolower( basename( $t_name ) ) ) ) {
					$t_document_count++;
					break;
				}
			}
		}
		if( $t_document_count === 0 ) {
			throw new RuntimeException( 'No documents matched the selected patterns and directories.' );
		}
		return array( 'path' => $t_stage, 'files' => $t_file_count,
			'documents' => $t_document_count, 'bytes' => $t_bytes );
	} catch( Throwable $t_error ) {
		if( $t_stage !== '' ) {
			doctis_import_zip_cleanup( $t_stage );
		}
		throw $t_error;
	} finally {
		$t_zip->close();
	}
}
