<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

use Mantis\Exceptions\ServiceException;

/**
 * DATABASE storage backend.
 *
 * Stores uploaded file content as a BLOB in {dwg_file}.content.
 * {dwg_file}.diskfile holds the generated unique name (used as a key for
 * Oracle BLOB updates); {dwg_file}.folder is always empty.
 */
class DatabaseFileStorageBackend implements FileStorageBackendInterface {

	/**
	 * @inheritDoc
	 */
	public function store(
		string $p_tmp_file,
		int    $p_file_size,
		string $p_unique_name,
		string $p_file_path,
		bool   $p_browser_upload,
		array  $p_metadata = []
	): array {
		$t_content = db_prepare_binary_string(
			fread( fopen( $p_tmp_file, 'rb' ), $p_file_size )
		);

		return array(
			'diskfile' => $p_unique_name,
			'folder'   => '',
			'content'  => $t_content,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function retrieve( array $p_row, int $p_project_id ) {
		$t_content_type = $p_row['file_type'];
		$t_detected = file_dwg_get_mime_type_for_content( $p_row['content'] );
		if( $t_detected !== false ) {
			$t_content_type = $t_detected;
		}

		return array(
			'type'    => $t_content_type,
			'content' => $p_row['content'],
		);
	}

	/**
	 * @inheritDoc
	 *
	 * No-op: content lives in the {dwg_file} row; the caller deletes the row.
	 */
	public function delete( string $p_diskfile, int $p_project_id, array $p_metadata = [] ): void {
	}
}
