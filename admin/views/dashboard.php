<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $_GET['sitevault_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sitevault_msg'] ) ) : '';
$status  = isset( $_GET['sitevault_status'] ) ? sanitize_key( wp_unslash( $_GET['sitevault_status'] ) ) : '';
?>
<div class="wrap">
	<h1>SiteVault</h1>
	<p><strong>Version:</strong> <?php echo esc_html( SITEVAULT_VERSION ); ?></p>

	<?php if ( $message ) : ?>
		<div class="notice <?php echo 'error' === $status ? 'notice-error' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<h2>SITEVAULT-M1 — Core Backup Engine</h2>
	<p>Current development build: resumable database export.</p>

	<?php if ( empty( $active_backup_id ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sitevault_start_backup">
			<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
			<?php submit_button( 'Start Test Backup', 'primary' ); ?>
		</form>
	<?php else : ?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr>
					<th style="width:220px">Active Backup</th>
					<td><code><?php echo esc_html( $active_backup_id ); ?></code></td>
				</tr>
				<?php if ( $database_state ) : ?>
					<tr>
						<th>Database Status</th>
						<td id="sitevault-db-status"><?php echo esc_html( $database_state['status'] ?? 'unknown' ); ?></td>
					</tr>
					<tr>
						<th>Tables</th>
						<td>
							<?php
							$table_total = count( $database_state['tables'] ?? array() );
							$table_done  = min( (int) ( $database_state['table_index'] ?? 0 ), $table_total );
							echo '<span id="sitevault-table-progress">' . esc_html( $table_done . ' / ' . $table_total ) . '</span>';
							?>
						</td>
					</tr>
					<tr>
						<th>Rows Exported</th>
						<td id="sitevault-rows-exported"><?php echo esc_html( number_format_i18n( (int) ( $database_state['rows_exported'] ?? 0 ) ) ); ?></td>
					</tr>
					<?php if ( 'failed' === ( $database_state['status'] ?? '' ) ) : ?>
						<tr>
							<th>Error</th>
							<td><?php echo esc_html( $database_state['error'] ?? 'Unknown database export error.' ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $database_state && 'running' === ( $database_state['status'] ?? '' ) ) : ?>
			<div id="sitevault-auto-progress" style="margin-top:16px">
				<p><strong>Database export is processing automatically.</strong></p>
				<p class="description">SiteVault is running one bounded request at a time to avoid PHP timeout and memory-limit failures.</p>
			</div>

			<noscript>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="sitevault_continue_database_export">
					<?php wp_nonce_field( 'sitevault_continue_database_export' ); ?>
					<?php submit_button( 'Process Next Database Batch', 'primary', 'submit', false ); ?>
				</form>
			</noscript>

			<script>
			(function() {
				const statusEl = document.getElementById('sitevault-db-status');
				const tableEl  = document.getElementById('sitevault-table-progress');
				const rowsEl   = document.getElementById('sitevault-rows-exported');
				const box      = document.getElementById('sitevault-auto-progress');
				let stopped    = false;

				async function runBatch() {
					if (stopped) {
						return;
					}

					const body = new URLSearchParams();
					body.set('action', 'sitevault_process_database_batch');
					body.set('nonce', '<?php echo esc_js( wp_create_nonce( 'sitevault_process_database_batch' ) ); ?>');

					try {
						const response = await fetch(ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
							},
							body: body.toString()
						});

						const payload = await response.json();

						if (!payload.success) {
							stopped = true;
							statusEl.textContent = 'failed';
							box.innerHTML = '<p><strong>Database export stopped.</strong></p><p>' +
								(payload.data && payload.data.message ? payload.data.message : 'Unknown SiteVault error.') +
								'</p>';
							return;
						}

						const data = payload.data || {};
						statusEl.textContent = data.status || 'running';
						tableEl.textContent  = (data.table_done || 0) + ' / ' + (data.table_total || 0);
						rowsEl.textContent   = Number(data.rows_exported || 0).toLocaleString();

						if (data.status === 'complete') {
							stopped = true;
							box.innerHTML = '<p><strong>Database dump created successfully.</strong></p>' +
								'<p>The next M1 component is the wp-content file scanner/archive engine.</p>';
							return;
						}

						window.setTimeout(runBatch, 250);
					} catch (error) {
						stopped = true;
						statusEl.textContent = 'paused';
						box.innerHTML = '<p><strong>Automatic processing paused.</strong></p>' +
							'<p>Reload this SiteVault page to resume the export safely.</p>';
					}
				}

				window.setTimeout(runBatch, 350);
			})();
			</script>
		<?php elseif ( $database_state && 'complete' === ( $database_state['status'] ?? '' ) ) : ?>
			<p><strong>Database dump created successfully.</strong> The next M1 component is the wp-content file scanner/archive engine.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="sitevault_start_backup">
				<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
				<?php submit_button( 'Start Another Test Backup', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
