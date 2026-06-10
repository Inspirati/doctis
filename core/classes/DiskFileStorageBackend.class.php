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

use Mantis\Exceptions\ClientException;
use Mantis\Exceptions\ServiceException;

/**
 * DISK storage backend.
 *
 * Stores uploaded files on the server filesystem under the project upload
 * directory.  {dwg_file}.diskfile holds the generated unique filename;
 * {dwg_file}.folder holds the absolute directory path.
 */
class DiskFileStorageBackend implements FileStorageBackendInterface {

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
		file_dwg_ensure_valid_upload_path( $p_file_path );

		$t_disk_file_name = $p_file_path . $p_unique_name;

		if( file_exists( $t_disk_file_name ) ) {
			throw new ClientException( 'Duplicate file', ERROR_FILE_DUPLICATE );
		}

		if( $p_browser_upload ) {
			if( !move_uploaded_file( $p_tmp_file, $t_disk_file_name ) ) {
				throw new ServiceException(
					'Unable to move uploaded file',
					ERROR_FILE_MOVE_FAILED
				);
			}
		} else {
			if( !copy( $p_tmp_file, $t_disk_file_name ) || !unlink( $p_tmp_file ) ) {
				throw new ServiceException(
					'Unable to move uploaded file',
					ERROR_FILE_MOVE_FAILED
				);
			}
		}

		chmod( $t_disk_file_name, config_get( 'attachments_file_permissions' ) );

		return array(
			'diskfile' => $p_unique_name,
			'folder'   => $p_file_path,
			'content'  => '',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function retrieve( array $p_row, int $p_project_id ) {
		$t_local_disk_file = file_dwg_normalize_attachment_path( $p_row['diskfile'], $p_project_id );

		if( !file_exists( $t_local_disk_file ) ) {
			return false;
		}

		$t_content_type = $p_row['file_type'];
		$t_detected = file_dwg_get_mime_type( $t_local_disk_file );
		if( $t_detected !== false ) {
			$t_content_type = $t_detected;
		}

		return array(
			'type'    => $t_content_type,
			'content' => file_dwg_get_contents( $t_local_disk_file ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function delete( string $p_diskfile, int $p_project_id, array $p_metadata = [] ): void {
		$t_local_disk_file = file_dwg_normalize_attachment_path( $p_diskfile, $p_project_id );
		if( file_exists( $t_local_disk_file ) ) {
			file_dwg_delete_local( $t_local_disk_file );
		}
	}
}
