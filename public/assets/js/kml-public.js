// Kuhmann MIDI Library - local file picker and demo controls.
(function () {
	'use strict';

	function initLocalPlayer(player) {
		if (player.dataset.kmlPickerReady) return;
		player.dataset.kmlPickerReady = '1';

		const roll = player.querySelector('.kml-roll');
		const input = player.querySelector('.kml-upload-input');
		const fileName = player.querySelector('.kml-upload-file-name');
		const message = player.querySelector('.kml-file-message');
		const demo = player.querySelector('.kml-demo');
		if (!roll) return;

		function reportError(text) {
			if (!message) return;
			message.textContent = text || '';
			message.hidden = !text;
		}

		function setFileName(name) {
			if (!fileName) return;
			fileName.textContent = name || fileName.getAttribute('data-default') || '';
			fileName.title = name || '';
		}

		async function openFile(file) {
			if (!file) return;
			reportError('');
			if (typeof roll.kmlLoadFile !== 'function') {
				reportError('The MIDI player is not ready. Please reload the page and try again.');
				return;
			}
			setFileName(file.name);
			try {
				// Read and parse locally, without a FormData request or server upload.
				await roll.kmlLoadFile(file);
			} catch (error) {
				reportError('The MIDI file could not be opened. Please choose another file.');
			}
		}

		if (input) {
			input.addEventListener('change', () => {
				const file = input.files && input.files.length ? input.files[0] : null;
				openFile(file);
				// Selecting the same file again should retry after a parse/read error.
				input.value = '';
			});
		}

		if (demo) {
			demo.addEventListener('click', async () => {
				reportError('');
				if (typeof roll.kmlLoadUrl !== 'function') {
					reportError('The MIDI player is not ready. Please reload the page and try again.');
					return;
				}
				const url = player.getAttribute('data-demo-url');
				const title = player.getAttribute('data-demo-title') || 'Demo';
				setFileName('');
				try {
					await roll.kmlLoadUrl(url, title);
				} catch (error) {
					reportError('The demo could not be opened. Please try again.');
				}
			});
		}

		if (!input || player.getAttribute('data-local-files') === '0') return;

		let dragDepth = 0;
		function hasFiles(event) {
			return event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
		}
		player.addEventListener('dragenter', (event) => {
			if (!hasFiles(event)) return;
			event.preventDefault();
			dragDepth += 1;
			player.classList.add('is-dragover');
		});
		player.addEventListener('dragover', (event) => {
			if (!hasFiles(event)) return;
			event.preventDefault();
			event.dataTransfer.dropEffect = 'copy';
		});
		player.addEventListener('dragleave', (event) => {
			if (!hasFiles(event) && dragDepth === 0) return;
			dragDepth = Math.max(0, dragDepth - 1);
			if (dragDepth === 0) player.classList.remove('is-dragover');
		});
		player.addEventListener('drop', (event) => {
			if (!hasFiles(event)) return;
			event.preventDefault();
			dragDepth = 0;
			player.classList.remove('is-dragover');
			const files = event.dataTransfer.files;
			if (files.length !== 1) {
				reportError('Please choose one MIDI file at a time.');
				return;
			}
			openFile(files[0]);
		});
	}

	function init() {
		document.querySelectorAll('.kml-midi-player-tool').forEach(initLocalPlayer);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
