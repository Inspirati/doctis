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

/**
 * Doctis file storage backend interface.
 *
 * Each storage method (DISK, DATABASE, GIT, …) implements this interface.
 * file_dwg_add(), file_dwg_get_content(), and file_dwg_delete() delegate
 * all method-specific I/O through a backend obtained from
 * file_dwg_get_storage_backend().
 */
interface FileStorageBackendInterface {

	/**
	 * Store a file from a temporary upload location.
	 *
	 * Moves or reads the file at $p_tmp_file into the backing store and
	 * returns the three {dwg_file} column values that identify the stored
	 * content.
	 *
	 * @param string $p_tmp_file       Absolute path to the uploaded temp file.
	 * @param int    $p_file_size      Byte length of the file.
	 * @param string $p_unique_name    Generated unique storage name (MD5 hash).
	 * @param string $p_file_path      Project upload directory (DISK) or empty.
	 * @param bool   $p_browser_upload True when the file came from a browser
	 *                                 multipart upload; false for an API upload.
	 * @param array  $p_metadata       Optional context for backends that need
	 *                                 more than the file itself.  Keys used by
	 *                                 GitFileStorageBackend:
	 *                                   project_id (int)
	 *                                   dwg_id     (int)
	 *                                   filename   (string)
	 *                                   user_id    (int)
	 *
	 * @return array{diskfile: string, folder: string, content: string}
	 *   diskfile — opaque storage identifier stored in {dwg_file}.diskfile
	 *   folder   — storage location stored in {dwg_file}.folder (empty string
	 *              when the backend does not use a directory path)
	 *   content  — binary content for {dwg_file}.content (empty string when
	 *              the backend does not store content in the database)
	 *
	 * @throws ClientException
	 * @throws ServiceException
	 */
	public function store(
		string $p_tmp_file,
		int    $p_file_size,
		string $p_unique_name,
		string $p_file_path,
		bool   $p_browser_upload,
		array  $p_metadata = []
	): array;

	/**
	 * Retrieve file content from the backing store.
	 *
	 * @param array $p_row        Full {dwg_file} row as returned by db_fetch_array().
	 * @param int   $p_project_id Project identifier (used by DISK to resolve paths).
	 *
	 * @return array{type: string, content: string}|false
	 *   type    — detected MIME type string
	 *   content — raw file bytes
	 *   Returns false if the file cannot be found.
	 */
	public function retrieve( array $p_row, int $p_project_id );

	/**
	 * Delete a stored file.
	 *
	 * Backends that store content in the database row (DATABASE) are no-ops
	 * here — the caller deletes the row.  Backends that write to external
	 * storage (DISK, GIT) must remove the stored artefact.
	 *
	 * @param string $p_diskfile    Value of {dwg_file}.diskfile for this file.
	 * @param int    $p_project_id  Project identifier.
	 * @param array  $p_metadata    Optional context.  Keys used by
	 *                              GitFileStorageBackend:
	 *                                dwg_id   (int)
	 *                                filename (string)
	 *                                user_id  (int)
	 *
	 * @return void
	 */
	public function delete( string $p_diskfile, int $p_project_id, array $p_metadata = [] ): void;
}
