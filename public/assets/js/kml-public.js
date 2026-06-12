// Kuhmann MIDI Library - public scripts
(function () {
	'use strict';

	function initUploadPicker(picker) {
		const input = picker.querySelector('.kml-upload-input');
		const fileName = picker.querySelector('.kml-upload-file-name');

		if (!input || !fileName) return;

		input.addEventListener('change', () => {
			const file = input.files && input.files.length ? input.files[0] : null;
			fileName.textContent = file ? file.name : fileName.getAttribute('data-default') || '';
			fileName.title = file ? file.name : '';
		});
	}

	function init() {
		document.querySelectorAll('.kml-upload-picker').forEach(initUploadPicker);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
