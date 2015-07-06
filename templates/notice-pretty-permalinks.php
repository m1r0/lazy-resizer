<div class="error">
	<p>
		<?php 
			_e( sprintf(
				'The plugin <code>Lazy Resizer</code> requires <a href="%s" target="_blank">"Pretty" permalinks</a> in order to work. You can change your permalink settings from <a href="%s">Settings -> Permalinks</a>.',
				'https://codex.wordpress.org/Using_Permalinks#Choosing_your_permalink_structure',
				admin_url('options-permalink.php')
			), 'lazy-resizer' );
		?>
	</p>
</div>