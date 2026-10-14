<?php
/**
 * Shared page footer: closes the main content, renders the common menu
 * toggle script plus any page-specific script, and closes the document.
 *
 * Pages may set $extraScript (string) before requiring this file to inject
 * additional JavaScript (e.g. cascading dropdown data) ahead of the shared code.
 */
$extraScript = $extraScript ?? '';
?>
	
<!--footer section -->
	<script>
		<?= $extraScript ?>

		const menuToggle = document.getElementById('menu-toggle');
		const menuList = document.getElementById('menu-list');

		menuToggle.addEventListener('click', () => {
			const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
			menuToggle.setAttribute('aria-expanded', String(!isExpanded));
			menuList.hidden = isExpanded;
		});

		document.addEventListener('click', (event) => {
			if (!event.target.closest('.menu')) {
				menuToggle.setAttribute('aria-expanded', 'false');
				menuList.hidden = true;
			}
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				menuToggle.setAttribute('aria-expanded', 'false');
				menuList.hidden = true;
				menuToggle.focus();
			}
		});
	</script>
</body>
</html>
