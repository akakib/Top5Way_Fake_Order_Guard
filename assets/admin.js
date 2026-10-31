(function () {
	var modal = document.getElementById('t5fob-modal');
	if (!modal) { return; }
	document.addEventListener('click', function (e) {
		if (e.target.closest('[data-t5fob-open]')) { modal.hidden = false; }
		if (e.target.closest('[data-t5fob-close]') || e.target === modal) { modal.hidden = true; }
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { modal.hidden = true; }
	});
	// Block needs at least one box ticked.
	modal.querySelector('form').addEventListener('submit', function (e) {
		if (!modal.querySelector('input[name="types[]"]:checked')) { e.preventDefault(); }
	});
})();
