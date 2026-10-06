(function () {
	'use strict';

	function statusBox(form) {
		var box = form.querySelector('.sitevault-local-action-status');
		if (!box) {
			box = document.createElement('span');
			box.className = 'sitevault-local-action-status';
			form.appendChild(box);
		}
		return box;
	}

	function setBusy(box, message) {
		box.textContent = '';
		var spinner = document.createElement('span');
		spinner.className = 'sitevault-action-spinner';
		spinner.setAttribute('aria-hidden', 'true');
		var text = document.createElement('span');
		text.textContent = message;
		box.appendChild(spinner);
		box.appendChild(text);
		box.className = 'sitevault-local-action-status is-running';
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.sitevault-wrap')) {
			return;
		}
		var button = event.submitter || form.querySelector('button[type="submit"],input[type="submit"]');
		if (!button) {
			return;
		}
		button.disabled = true;
		if (button.tagName === 'INPUT') {
			button.value = 'Working...';
		} else {
			button.textContent = 'Working...';
		}
		setBusy(statusBox(form), 'Started - processing...');
	});
}());