<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $_GET['sitevault_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sitevault_msg'] ) ) : '';
$status  = isset( $_GET['sitevault_status'] ) ? sanitize_key( wp_unslash( $_GET['sitevault_status'] ) ) : '';

$db_status      = $database_state['status'] ?? '';
$content_status = $content_state['status'] ?? '';
$content_phase  = $content_state['phase'] ?? 'scanning';
$backup_done    = 'complete' === $db_status && 'complete' === $content_status;
$legacy_db_only  = 'complete' === $db_status && empty( $content_state );
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
	<p>Current development build: database export + resumable wp-content scan/archive.</p>

	<?php if ( empty( $active_backup_id ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sitevault_start_backup">
			<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
			<?php submit_button( 'Start Test Backup', 'primary' ); ?>
		</form>
	<?php else : ?>
		<table class="widefat striped" style="max-width:960px">
			<tbody>
				<tr>
					<th style="width:230px">Active Backup</th>
					<td><code><?php echo esc_html( $active_backup_id ); ?></code></td>
				</tr>

				<?php if ( $database_state ) : ?>
					<tr>
						<th>Database Status</th>
						<td id="sitevault-db-status"><?php echo esc_html( $db_status ?: 'unknown' ); ?></td>
					</tr>
					<tr>
						<th>Database Tables</th>
						<td>
							<?php
							$table_total = count( $database_state['tables'] ?? array() );
							$table_done  = min( (int) ( $database_state['table_index'] ?? 0 ), $table_total );
							?>
							<span id="sitevault-table-progress"><?php echo esc_html( $table_done . ' / ' . $table_total ); ?></span>
						</td>
					</tr>
					<tr>
						<th>Database Rows Exported</th>
						<td id="sitevault-rows-exported"><?php echo esc_html( number_format_i18n( (int) ( $database_state['rows_exported'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( $content_state ) : ?>
					<tr>
						<th>wp-content Status</th>
						<td id="sitevault-content-status"><?php echo esc_html( $content_status ?: 'unknown' ); ?></td>
					</tr>
					<tr>
						<th>wp-content Phase</th>
						<td id="sitevault-content-phase"><?php echo esc_html( $content_phase ); ?></td>
					</tr>
					<tr>
						<th>Directories Scanned</th>
						<td id="sitevault-directories-scanned"><?php echo esc_html( number_format_i18n( (int) ( $content_state['directories_scanned'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>Files Discovered</th>
						<td id="sitevault-files-discovered"><?php echo esc_html( number_format_i18n( (int) ( $content_state['files_discovered'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>Files Archived</th>
						<td id="sitevault-files-archived"><?php echo esc_html( number_format_i18n( (int) ( $content_state['files_archived'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>Data Archived</th>
						<td id="sitevault-bytes-archived"><?php echo esc_html( size_format( (int) ( $content_state['bytes_archived'] ?? 0 ), 2 ) ); ?></td>
					</tr>
					<tr>
						<th>Files Skipped</th>
						<td id="sitevault-files-skipped"><?php echo esc_html( number_format_i18n( (int) ( $content_state['files_skipped'] ?? 0 ) ) ); ?></td>
					</tr>
					<?php if ( 'complete' === $content_status ) : ?>
						<tr>
							<th>Archive Verification</th>
							<td><?php echo ! empty( $content_state['archive_verified'] ) ? 'passed' : 'pending'; ?></td>
						</tr>
						<tr>
							<th>Archive Entries</th>
							<td><?php echo esc_html( number_format_i18n( (int) ( $content_state['archive_entries'] ?? 0 ) ) ); ?></td>
						</tr>
						<tr>
							<th>SiteVault Runtime Excluded</th>
							<td><?php echo ! empty( $content_state['self_backup_excluded'] ) ? 'yes' : 'no'; ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<div id="sitevault-auto-progress" style="margin-top:16px">
			<?php if ( $legacy_db_only ) : ?>
				<div class="notice notice-info inline">
					<p><strong>This active backup was created before the wp-content archive engine was added.</strong></p>
					<p>Start a new test backup to run the full database + wp-content pipeline.</p>
				</div>
			<?php elseif ( $backup_done ) : ?>
				<p><strong>Database and wp-content backup stages completed successfully.</strong></p>
			<?php elseif ( 'running' === $db_status ) : ?>
				<p><strong>Database export is processing automatically.</strong></p>
				<p class="description">SiteVault is running one bounded request at a time to avoid PHP timeout and memory-limit failures.</p>
			<?php elseif ( 'complete' === $db_status && 'running' === $content_status ) : ?>
				<p><strong>wp-content backup is processing automatically.</strong></p>
				<p class="description">SiteVault scans directories first, then adds discovered files to the archive in bounded batches.</p>
			<?php endif; ?>
		</div>

		<?php if ( $backup_done || $legacy_db_only ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="sitevault_start_backup">
				<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
				<?php submit_button( 'Start Another Test Backup', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<?php if ( ! $backup_done && ! $legacy_db_only && 'failed' !== $db_status && 'failed' !== $content_status ) : ?>
			<script>
			(function() {
				const dbStatusEl     = document.getElementById('sitevault-db-status');
				const tableEl        = document.getElementById('sitevault-table-progress');
				const rowsEl         = document.getElementById('sitevault-rows-exported');
				const contentStatus  = document.getElementById('sitevault-content-status');
				const contentPhase   = document.getElementById('sitevault-content-phase');
				const dirsEl         = document.getElementById('sitevault-directories-scanned');
				const discoveredEl   = document.getElementById('sitevault-files-discovered');
				const archivedEl     = document.getElementById('sitevault-files-archived');
				const bytesEl        = document.getElementById('sitevault-bytes-archived');
				const skippedEl      = document.getElementById('sitevault-files-skipped');
				const box            = document.getElementById('sitevault-auto-progress');
				let stopped          = false;

				function humanBytes(bytes) {
					const value = Number(bytes || 0);
					if (value < 1024) return value + ' B';
					const units = ['KB', 'MB', 'GB', 'TB'];
					let size = value;
					let index = -1;
					do {
						size /= 1024;
						index++;
					} while (size >= 1024 && index < units.length - 1);
					return size.toFixed(2) + ' ' + units[index];
				}

				function stopWithError(message) {
					stopped = true;
					box.innerHTML = '';
					const strong = document.createElement('strong');
					strong.textContent = 'SiteVault processing stopped.';
					const p1 = document.createElement('p');
					const p2 = document.createElement('p');
					p1.appendChild(strong);
					p2.textContent = message || 'Unknown SiteVault error.';
					box.appendChild(p1);
					box.appendChild(p2);
				}

				async function postBatch(action, nonce) {
					const body = new URLSearchParams();
					body.set('action', action);
					body.set('nonce', nonce);

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
						},
						body: body.toString()
					});

					return response.json();
				}

				async function runContentBatch() {
					if (stopped) return;

					try {
						const payload = await postBatch(
							'sitevault_process_content_batch',
							'<?php echo esc_js( wp_create_nonce( 'sitevault_process_content_batch' ) ); ?>'
						);

						if (!payload.success) {
							if (contentStatus) contentStatus.textContent = 'failed';
							stopWithError(payload.data && payload.data.message ? payload.data.message : 'wp-content backup failed.');
							return;
						}

						const data = payload.data || {};
						if (contentStatus) contentStatus.textContent = data.status || 'running';
						if (contentPhase) contentPhase.textContent = data.phase || 'scanning';
						if (dirsEl) dirsEl.textContent = Number(data.directories_scanned || 0).toLocaleString();
						if (discoveredEl) discoveredEl.textContent = Number(data.files_discovered || 0).toLocaleString();
						if (archivedEl) archivedEl.textContent = Number(data.files_archived || 0).toLocaleString();
						if (bytesEl) bytesEl.textContent = humanBytes(data.bytes_archived || 0);
						if (skippedEl) skippedEl.textContent = Number(data.files_skipped || 0).toLocaleString();

						if (data.status === 'complete') {
							stopped = true;
							box.innerHTML = '<p><strong>Database and wp-content backup stages completed successfully.</strong></p>' +
								'<p>Reload the page to start another test backup.</p>';
							return;
						}

						window.setTimeout(runContentBatch, 250);
					} catch (error) {
						stopWithError('Automatic wp-content processing paused. Reload this SiteVault page to resume safely.');
					}
				}

				async function runDatabaseBatch() {
					if (stopped) return;

					try {
						const payload = await postBatch(
							'sitevault_process_database_batch',
							'<?php echo esc_js( wp_create_nonce( 'sitevault_process_database_batch' ) ); ?>'
						);

						if (!payload.success) {
							if (dbStatusEl) dbStatusEl.textContent = 'failed';
							stopWithError(payload.data && payload.data.message ? payload.data.message : 'Database export failed.');
							return;
						}

						const data = payload.data || {};
						if (dbStatusEl) dbStatusEl.textContent = data.status || 'running';
						if (tableEl) tableEl.textContent = (data.table_done || 0) + ' / ' + (data.table_total || 0);
						if (rowsEl) rowsEl.textContent = Number(data.rows_exported || 0).toLocaleString();

						if (data.status === 'complete') {
							box.innerHTML = '<p><strong>Database export completed. Starting wp-content scan.</strong></p>';
							window.setTimeout(runContentBatch, 250);
							return;
						}

						window.setTimeout(runDatabaseBatch, 250);
					} catch (error) {
						stopWithError('Automatic database processing paused. Reload this SiteVault page to resume safely.');
					}
				}

				<?php if ( 'running' === $db_status ) : ?>
					window.setTimeout(runDatabaseBatch, 350);
				<?php elseif ( 'complete' === $db_status && 'running' === $content_status ) : ?>
					window.setTimeout(runContentBatch, 350);
				<?php endif; ?>
			})();
			</script>
		<?php endif; ?>
	<?php endif; ?>
</div>
