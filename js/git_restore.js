'use strict';
document.addEventListener('DOMContentLoaded', function () {
	const form = document.getElementById('git-restore-form');
	if (!form) return;
	const el = id => document.getElementById('restore-' + id);
	const storageKey = 'doctis-git-restore:' + location.pathname;
	let saved;
	try { saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch (_) { saved = null; }
	let ticket = form.dataset.recovery || (saved && saved.ticket) || '';
	let busy = false;
	const message = value => { el('message').textContent = value; };
	async function call(action, extra) {
		const data = new FormData();
		data.set('manage_git_restore_token', form.elements.manage_git_restore_token.value);
		data.set('action', action);
		if (ticket) data.set('ticket', ticket);
		Object.entries(extra || {}).forEach(([key, value]) => data.set(key, value));
		const response = await fetch('manage_git_restore_action.php', {method: 'POST', credentials: 'same-origin', body: data});
		let result;
		try { result = await response.json(); } catch (_) {
			throw new Error('The server did not return restore progress. Reopen this page and sign in again if requested; your upload can be resumed.');
		}
		if (!response.ok || !result.ok) throw new Error(result.error || 'Restore request failed.');
		return result.state;
	}
	function display(state) {
		el('cancel').hidden = !ticket || ['applying', 'preparing'].includes(state.phase);
		el('confirmation').hidden = state.phase !== 'ready';
		el('recovery').hidden = !['applying', 'preparing'].includes(state.phase) && !form.dataset.recovery;
		el('progress').hidden = false;
		el('progress').value = state.phase === 'complete' ? 100 : 100 * state.uploaded / state.size;
		if (state.report) {
			el('report').hidden = false;
			el('report').textContent = 'Archive SHA-256: ' + state.report.sha256 + '\nRepositories: ' + state.report.repositories.length +
				'\nLFS objects: ' + state.report.lfs_objects + '\nLFS bytes: ' + state.report.lfs_bytes +
				'\nGit history, LFS checksums and database file references: passed';
		}
		if (state.phase === 'complete') {
			message('Git/LFS store restored successfully. Cached worktrees will be recreated when needed.');
			sessionStorage.removeItem(storageKey);
		} else if (state.phase === 'ready') message('Backup validated. Review the details, then confirm replacement below.');
		else if (state.phase === 'uploading') message('Upload saved: ' + state.uploaded + ' of ' + state.size + ' bytes. Select the same file to resume.');
		else message('An interrupted installation needs recovery.');
	}
	async function work(fn) {
		if (busy) return;
		busy = true;
		for (const id of ['upload', 'apply', 'cancel', 'recover']) el(id).disabled = true;
		try { await fn(); } catch (error) { message(error.message); }
		finally {
			busy = false;
			for (const id of ['upload', 'apply', 'cancel', 'recover']) el(id).disabled = false;
		}
	}
	form.addEventListener('submit', event => {
		event.preventDefault();
		work(async () => {
			const file = el('file').files[0];
			if (!file) throw new Error('Select the backup archive.');
			let state;
			if (ticket) {
				state = await call('status');
				if (state.phase !== 'uploading' || !saved || saved.name !== file.name || saved.size !== file.size || saved.modified !== file.lastModified) {
					throw new Error('Discard the existing upload before selecting another backup.');
				}
			} else {
				state = await call('start', {name: file.name, size: file.size, sha256: el('sha256').value.trim()});
				ticket = state.ticket;
				saved = {ticket: ticket, name: file.name, size: file.size, modified: file.lastModified};
				sessionStorage.setItem(storageKey, JSON.stringify(saved));
			}
			display(state);
			while (state.uploaded < file.size) {
				state = await call('upload', {offset: state.uploaded, chunk: file.slice(state.uploaded, state.uploaded + state.chunk_bytes)});
				el('progress').value = 100 * state.uploaded / file.size;
				message('Uploading: ' + Math.floor(el('progress').value) + '%');
			}
			message('Validating Git history, LFS objects and database references…');
			display(await call('inspect'));
		});
	});
	el('apply').addEventListener('click', () => work(async () => {
		message('Replacing this server\'s Git/LFS store…');
		display(await call('apply', {confirm: el('confirm').value}));
	}));
	el('cancel').addEventListener('click', () => work(async () => {
		await call('cancel');
		ticket = ''; saved = null;
		sessionStorage.removeItem(storageKey);
		location.reload();
	}));
	el('recover').addEventListener('click', () => work(async () => {
		display(await call('recover', {confirm: el('recover-confirm').value}));
		form.dataset.recovery = '';
		el('recovery').hidden = true;
	}));
	if (ticket) work(async () => display(await call('status')));
});
