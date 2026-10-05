<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $_GET['sitevault_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sitevault_msg'] ) ) : '';
$status  = isset( $_GET['sitevault_status'] ) ? sanitize_key( wp_unslash( $_GET['sitevault_status'] ) ) : '';

$db_status        = $database_state['status'] ?? '';
$content_status   = $content_state['status'] ?? '';
$content_phase    = $content_state['phase'] ?? 'scanning';
$package_status   = $package_state['status'] ?? '';
$package_verified = ! empty( $package_state['verified'] );
$archive_verified = ! empty( $content_state['archive_verified'] );
$backup_done      = 'complete' === $db_status && 'complete' === $content_status && 'complete' === $package_status && $package_verified;
$needs_package    = 'complete' === $db_status && 'complete' === $content_status && ! $backup_done;
$legacy_db_only   = 'complete' === $db_status && empty( $content_state );
$table_total      = count( $database_state['tables'] ?? array() );
$table_done       = min( (int) ( $database_state['table_index'] ?? 0 ), $table_total );
$files_found      = (int) ( $content_state['files_discovered'] ?? 0 );
$files_archived   = (int) ( $content_state['files_archived'] ?? 0 );

$overall_progress = 0;
$progress_mode     = 'determinate';
$current_stage     = 'Ready';

if ( $backup_done ) {
	$overall_progress = 100;
	$current_stage     = 'Backup complete';
} elseif ( $legacy_db_only ) {
	$overall_progress = 25;
	$current_stage     = 'Previous database-only backup';
} elseif ( 'running' === $db_status ) {
	$ratio             = $table_total > 0 ? $table_done / $table_total : 0;
	$overall_progress  = max( 2, (int) round( 25 * $ratio ) );
	$current_stage     = 'Exporting database';
} elseif ( 'complete' === $db_status && 'running' === $content_status ) {
	if ( 'scanning' === $content_phase ) {
		$overall_progress = 30;
		$progress_mode     = 'indeterminate';
		$current_stage     = 'Scanning wp-content';
	} else {
		$ratio             = $files_found > 0 ? min( 1, $files_archived / $files_found ) : 0;
		$overall_progress  = 35 + (int) round( 50 * $ratio );
		$current_stage     = 'Archiving wp-content';
	}
} elseif ( $needs_package ) {
	$overall_progress = 92;
	$current_stage     = 'Building portable package';
	$progress_mode     = 'indeterminate';
}

$db_stage_state      = 'complete' === $db_status ? 'complete' : ( 'running' === $db_status ? 'running' : 'pending' );
$scan_stage_state    = empty( $content_state ) ? 'pending' : ( 'scanning' === $content_phase && 'running' === $content_status ? 'running' : 'complete' );
$archive_stage_state = 'complete' === $content_status ? 'complete' : ( 'archiving' === $content_phase && 'running' === $content_status ? 'running' : 'pending' );
$verify_stage_state  = $archive_verified ? 'complete' : 'pending';
$package_stage_state = $package_verified ? 'complete' : ( $needs_package ? 'running' : 'pending' );
?>
<div class="wrap sitevault-wrap">
	<div class="sitevault-header">
		<div>
			<h1>SiteVault</h1>
			<p class="sitevault-subtitle">Backup, restore and migration engine</p>
		</div>
		<span class="sitevault-version">Version <?php echo esc_html( SITEVAULT_VERSION ); ?></span>
	</div>

	<?php if ( $message ) : ?>
		<div class="notice <?php echo 'error' === $status ? 'notice-error' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $active_backup_id ) ) : ?>
		<div class="sitevault-card">
			<h2>Create Backup</h2>
			<p>Create a portable SiteVault backup containing the WordPress database, wp-content archive, manifest and SHA-256 integrity data.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sitevault_start_backup">
				<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
				<?php submit_button( 'Create Backup', 'primary', 'submit', false ); ?>
			</form>
		</div>
	<?php else : ?>
		<div class="sitevault-card">
			<div class="sitevault-progress-head">
				<div>
					<div class="sitevault-progress-title" id="sitevault-current-stage"><?php echo esc_html( $current_stage ); ?></div>
					<div class="sitevault-help">Backup ID: <code><?php echo esc_html( $active_backup_id ); ?></code></div>
				</div>
				<div class="sitevault-progress-value" id="sitevault-progress-value">
					<?php echo 'indeterminate' === $progress_mode ? 'Working…' : esc_html( $overall_progress . '%' ); ?>
				</div>
			</div>

			<div id="sitevault-progress-track" class="sitevault-progress-track <?php echo 'indeterminate' === $progress_mode ? 'is-indeterminate' : ''; ?>" aria-label="Backup progress">
				<div id="sitevault-progress-bar" class="sitevault-progress-bar" style="width:<?php echo esc_attr( $overall_progress ); ?>%"></div>
			</div>

			<div class="sitevault-stage-grid">
				<div id="sitevault-stage-db" class="sitevault-stage is-<?php echo esc_attr( $db_stage_state ); ?>">
					<span class="sitevault-stage-name">1. Database</span>
					<span class="sitevault-stage-state"><?php echo esc_html( $db_stage_state ); ?></span>
				</div>
				<div id="sitevault-stage-scan" class="sitevault-stage is-<?php echo esc_attr( $scan_stage_state ); ?>">
					<span class="sitevault-stage-name">2. File Scan</span>
					<span class="sitevault-stage-state"><?php echo esc_html( $scan_stage_state ); ?></span>
				</div>
				<div id="sitevault-stage-archive" class="sitevault-stage is-<?php echo esc_attr( $archive_stage_state ); ?>">
					<span class="sitevault-stage-name">3. Archive</span>
					<span class="sitevault-stage-state"><?php echo esc_html( $archive_stage_state ); ?></span>
				</div>
				<div id="sitevault-stage-verify" class="sitevault-stage is-<?php echo esc_attr( $verify_stage_state ); ?>">
					<span class="sitevault-stage-name">4. Verify</span>
					<span class="sitevault-stage-state"><?php echo esc_html( $verify_stage_state ); ?></span>
				</div>
				<div id="sitevault-stage-package" class="sitevault-stage is-<?php echo esc_attr( $package_stage_state ); ?>">
					<span class="sitevault-stage-name">5. Package</span>
					<span class="sitevault-stage-state"><?php echo esc_html( $package_stage_state ); ?></span>
				</div>
			</div>

			<div id="sitevault-running-banner" class="sitevault-status-banner <?php echo $backup_done ? 'is-complete' : ( $legacy_db_only ? 'is-warning' : 'is-running' ); ?>">
				<span class="sitevault-status-dot"></span>
				<div>
					<strong id="sitevault-running-title">
						<?php
						if ( $backup_done ) {
							echo 'Backup complete — your portable SiteVault package is ready.';
						} elseif ( $legacy_db_only ) {
							echo 'This is an older database-only backup.';
						} else {
							echo 'Backup is still running — keep this page open.';
						}
						?>
					</strong>
					<p id="sitevault-running-copy">
						<?php
						if ( $backup_done ) {
							echo 'All backup, integrity and packaging stages have finished. You may leave this page.';
						} elseif ( $legacy_db_only ) {
							echo 'Start a fresh backup to run the complete SiteVault pipeline.';
						} else {
							echo 'You may use another browser tab, but closing this SiteVault tab pauses browser-driven processing safely. Returning to this page resumes from the saved state.';
						}
						?>
					</p>
				</div>
			</div>
		</div>

		<div class="sitevault-card">
			<h2>Live Backup Details</h2>
			<div class="sitevault-metrics">
				<div class="sitevault-metric">
					<span class="sitevault-metric-label">Database tables</span>
					<span class="sitevault-metric-value" id="sitevault-table-progress"><?php echo esc_html( $table_done . ' / ' . $table_total ); ?></span>
				</div>
				<div class="sitevault-metric">
					<span class="sitevault-metric-label">Database rows</span>
					<span class="sitevault-metric-value" id="sitevault-rows-exported"><?php echo esc_html( number_format_i18n( (int) ( $database_state['rows_exported'] ?? 0 ) ) ); ?></span>
				</div>
				<div class="sitevault-metric">
					<span class="sitevault-metric-label">Files discovered</span>
					<span class="sitevault-metric-value" id="sitevault-files-discovered"><?php echo esc_html( number_format_i18n( $files_found ) ); ?></span>
				</div>
				<div class="sitevault-metric">
					<span class="sitevault-metric-label">Files archived</span>
					<span class="sitevault-metric-value" id="sitevault-files-archived"><?php echo esc_html( number_format_i18n( $files_archived ) ); ?></span>
				</div>
			</div>

			<table class="sitevault-detail-table">
				<tbody>
					<tr>
						<th>Database status</th>
						<td><span id="sitevault-db-status" class="sitevault-badge is-<?php echo esc_attr( $db_stage_state ); ?>"><?php echo esc_html( $db_status ?: 'pending' ); ?></span></td>
					</tr>
					<tr>
						<th>wp-content status</th>
						<td><span id="sitevault-content-status" class="sitevault-badge is-<?php echo esc_attr( $content_status ?: 'pending' ); ?>"><?php echo esc_html( $content_status ?: 'pending' ); ?></span></td>
					</tr>
					<tr>
						<th>wp-content phase</th>
						<td id="sitevault-content-phase"><?php echo esc_html( $content_phase ); ?></td>
					</tr>
					<tr>
						<th>Directories scanned</th>
						<td id="sitevault-directories-scanned"><?php echo esc_html( number_format_i18n( (int) ( $content_state['directories_scanned'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>Data archived</th>
						<td id="sitevault-bytes-archived"><?php echo esc_html( size_format( (int) ( $content_state['bytes_archived'] ?? 0 ), 2 ) ); ?></td>
					</tr>
					<tr>
						<th>Files skipped</th>
						<td id="sitevault-files-skipped"><?php echo esc_html( number_format_i18n( (int) ( $content_state['files_skipped'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>Archive verification</th>
						<td id="sitevault-archive-verification"><?php echo $archive_verified ? 'Passed' : 'Pending'; ?></td>
					</tr>
					<tr>
						<th>Archive entries</th>
						<td id="sitevault-archive-entries"><?php echo esc_html( number_format_i18n( (int) ( $content_state['archive_entries'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th>SiteVault runtime excluded</th>
						<td id="sitevault-runtime-excluded"><?php echo ! empty( $content_state['self_backup_excluded'] ) ? 'Yes' : 'Pending'; ?></td>
					</tr>
					<tr>
						<th>Portable package</th>
						<td id="sitevault-package-name"><?php echo esc_html( $package_state['package_name'] ?? 'Pending' ); ?></td>
					</tr>
					<tr>
						<th>Package size</th>
						<td id="sitevault-package-size"><?php echo ! empty( $package_state['package_size'] ) ? esc_html( size_format( (int) $package_state['package_size'], 2 ) ) : 'Pending'; ?></td>
					</tr>
					<tr>
						<th>Package verification</th>
						<td id="sitevault-package-verification"><?php echo $package_verified ? 'Passed' : 'Pending'; ?></td>
					</tr>
				</tbody>
			</table>

			<div class="sitevault-actions">
				<?php
				$download_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'sitevault_download_backup',
							'backup_id' => $active_backup_id,
						),
						admin_url( 'admin-post.php' )
					),
					'sitevault_download_backup_' . $active_backup_id
				);
				?>
				<a id="sitevault-download-current" class="button button-primary <?php echo $backup_done ? '' : 'sitevault-hidden'; ?>" href="<?php echo esc_url( $download_url ); ?>">Download .sitevault</a>

				<form id="sitevault-new-backup-form" class="<?php echo ( $backup_done || $legacy_db_only ) ? '' : 'sitevault-hidden'; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sitevault_start_backup">
					<?php wp_nonce_field( 'sitevault_start_backup' ); ?>
					<?php submit_button( 'Create Another Backup', 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<?php if ( ! $backup_done && ! $legacy_db_only && 'failed' !== $db_status && 'failed' !== $content_status && 'failed' !== $package_status ) : ?>
			<script>
			(function() {
				const dbStatusEl       = document.getElementById('sitevault-db-status');
				const contentStatus    = document.getElementById('sitevault-content-status');
				const contentPhase     = document.getElementById('sitevault-content-phase');
				const tableEl          = document.getElementById('sitevault-table-progress');
				const rowsEl           = document.getElementById('sitevault-rows-exported');
				const dirsEl           = document.getElementById('sitevault-directories-scanned');
				const discoveredEl     = document.getElementById('sitevault-files-discovered');
				const archivedEl       = document.getElementById('sitevault-files-archived');
				const bytesEl          = document.getElementById('sitevault-bytes-archived');
				const skippedEl        = document.getElementById('sitevault-files-skipped');
				const verifyEl         = document.getElementById('sitevault-archive-verification');
				const entriesEl        = document.getElementById('sitevault-archive-entries');
				const runtimeEl        = document.getElementById('sitevault-runtime-excluded');
				const packageNameEl    = document.getElementById('sitevault-package-name');
				const packageSizeEl    = document.getElementById('sitevault-package-size');
				const packageVerifyEl  = document.getElementById('sitevault-package-verification');
				const progressTrack    = document.getElementById('sitevault-progress-track');
				const progressBar      = document.getElementById('sitevault-progress-bar');
				const progressValue    = document.getElementById('sitevault-progress-value');
				const currentStage     = document.getElementById('sitevault-current-stage');
				const banner           = document.getElementById('sitevault-running-banner');
				const bannerTitle      = document.getElementById('sitevault-running-title');
				const bannerCopy       = document.getElementById('sitevault-running-copy');
				const newBackupForm    = document.getElementById('sitevault-new-backup-form');
				const downloadCurrent  = document.getElementById('sitevault-download-current');
				let stopped            = false;

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

				function setStage(id, state) {
					const el = document.getElementById(id);
					if (!el) return;
					el.classList.remove('is-pending', 'is-running', 'is-complete', 'is-failed');
					el.classList.add('is-' + state);
					const stateEl = el.querySelector('.sitevault-stage-state');
					if (stateEl) stateEl.textContent = state;
				}

				function setBadge(el, state) {
					if (!el) return;
					el.classList.remove('is-pending', 'is-running', 'is-complete', 'is-failed');
					el.classList.add('is-' + state);
					el.textContent = state;
				}

				function setProgress(percent, label, indeterminate) {
					currentStage.textContent = label;
					progressTrack.classList.toggle('is-indeterminate', !!indeterminate);
					if (indeterminate) {
						progressValue.textContent = 'Working…';
					} else {
						const safe = Math.max(0, Math.min(100, Math.round(percent)));
						progressBar.style.width = safe + '%';
						progressValue.textContent = safe + '%';
					}
				}

				function setRunningBanner(title, copy, mode) {
					banner.classList.remove('is-running', 'is-complete', 'is-warning', 'is-error');
					banner.classList.add('is-' + mode);
					bannerTitle.textContent = title;
					bannerCopy.textContent = copy;
				}

				function stopWithError(message) {
					stopped = true;
					setRunningBanner('SiteVault processing stopped.', message || 'Unknown SiteVault error.', 'error');
				}

				async function postBatch(action, nonce) {
					const body = new URLSearchParams();
					body.set('action', action);
					body.set('nonce', nonce);

					const response = await fetch(ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: body.toString()
					});

					return response.json();
				}

				async function runPackage() {
					if (stopped) return;

					setStage('sitevault-stage-package', 'running');
					setProgress(92, 'Building portable package', true);
					setRunningBanner(
						'Creating the portable SiteVault package.',
						'Checksums are being written and the final .sitevault file is being assembled and verified.',
						'running'
					);

					try {
						const payload = await postBatch(
							'sitevault_build_package',
							'<?php echo esc_js( wp_create_nonce( 'sitevault_build_package' ) ); ?>'
						);

						if (!payload.success) {
							setStage('sitevault-stage-package', 'failed');
							stopWithError(payload.data && payload.data.message ? payload.data.message : 'Package creation failed.');
							return;
						}

						const data = payload.data || {};
						stopped = true;
						setStage('sitevault-stage-package', data.verified ? 'complete' : 'failed');
						packageNameEl.textContent = data.package_name || 'Created';
						packageSizeEl.textContent = humanBytes(data.package_size || 0);
						packageVerifyEl.textContent = data.verified ? 'Passed' : 'Failed';
						setProgress(100, 'Backup complete', false);
						setRunningBanner(
							'Backup complete — your portable SiteVault package is ready.',
							'All backup, integrity and packaging stages have finished. Reload once to show the protected download action and updated Backup History.',
							'complete'
						);
						newBackupForm.classList.remove('sitevault-hidden');
						if (downloadCurrent) downloadCurrent.classList.remove('sitevault-hidden');
					} catch (error) {
						setStage('sitevault-stage-package', 'failed');
						stopWithError('Package creation paused or failed. Reload this SiteVault page to retry safely.');
					}
				}

				async function runContentBatch() {
					if (stopped) return;

					try {
						const payload = await postBatch(
							'sitevault_process_content_batch',
							'<?php echo esc_js( wp_create_nonce( 'sitevault_process_content_batch' ) ); ?>'
						);

						if (!payload.success) {
							setBadge(contentStatus, 'failed');
							setStage('sitevault-stage-scan', 'failed');
							setStage('sitevault-stage-archive', 'failed');
							stopWithError(payload.data && payload.data.message ? payload.data.message : 'wp-content backup failed.');
							return;
						}

						const data = payload.data || {};
						setBadge(contentStatus, data.status || 'running');
						contentPhase.textContent = data.phase || 'scanning';
						dirsEl.textContent = Number(data.directories_scanned || 0).toLocaleString();
						discoveredEl.textContent = Number(data.files_discovered || 0).toLocaleString();
						archivedEl.textContent = Number(data.files_archived || 0).toLocaleString();
						bytesEl.textContent = humanBytes(data.bytes_archived || 0);
						skippedEl.textContent = Number(data.files_skipped || 0).toLocaleString();

						if (data.phase === 'scanning' && data.status !== 'complete') {
							setStage('sitevault-stage-scan', 'running');
							setStage('sitevault-stage-archive', 'pending');
							setProgress(30, 'Scanning wp-content', true);
						} else if (data.status !== 'complete') {
							setStage('sitevault-stage-scan', 'complete');
							setStage('sitevault-stage-archive', 'running');
							const total = Number(data.files_discovered || 0);
							const done = Number(data.files_archived || 0);
							const ratio = total > 0 ? Math.min(1, done / total) : 0;
							setProgress(35 + (50 * ratio), 'Archiving wp-content', false);
						}

						if (data.status === 'complete') {
							setStage('sitevault-stage-scan', 'complete');
							setStage('sitevault-stage-archive', 'complete');
							setStage('sitevault-stage-verify', data.archive_verified ? 'complete' : 'failed');
							verifyEl.textContent = data.archive_verified ? 'Passed' : 'Failed';
							entriesEl.textContent = Number(data.archive_entries || 0).toLocaleString();
							runtimeEl.textContent = data.self_backup_excluded ? 'Yes' : 'No';

							if (!data.archive_verified) {
								stopWithError('wp-content archive verification failed.');
								return;
							}

							window.setTimeout(runPackage, 250);
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
							setBadge(dbStatusEl, 'failed');
							setStage('sitevault-stage-db', 'failed');
							stopWithError(payload.data && payload.data.message ? payload.data.message : 'Database export failed.');
							return;
						}

						const data = payload.data || {};
						setBadge(dbStatusEl, data.status || 'running');
						tableEl.textContent = (data.table_done || 0) + ' / ' + (data.table_total || 0);
						rowsEl.textContent = Number(data.rows_exported || 0).toLocaleString();

						const total = Number(data.table_total || 0);
						const done = Number(data.table_done || 0);
						const ratio = total > 0 ? Math.min(1, done / total) : 0;
						setProgress(25 * ratio, 'Exporting database', false);

						if (data.status === 'complete') {
							setStage('sitevault-stage-db', 'complete');
							setStage('sitevault-stage-scan', 'running');
							setProgress(30, 'Scanning wp-content', true);
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
				<?php elseif ( $needs_package ) : ?>
					window.setTimeout(runPackage, 350);
				<?php endif; ?>
			})();
			</script>
		<?php endif; ?>
	<?php endif; ?>


	<div class="sitevault-card">
		<div class="sitevault-progress-head">
			<div>
				<h2 style="margin:0">Restore Package Validation</h2>
				<div class="sitevault-help">M2 safety stage: validate a .sitevault package without changing this WordPress site.</div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="sitevault_import_validate">
			<?php wp_nonce_field( 'sitevault_import_validate' ); ?>
			<input type="file" name="sitevault_package" accept=".sitevault,application/octet-stream" required>
			<?php submit_button( 'Upload & Validate Package', 'secondary', 'submit', false ); ?>
		</form>
		<p class="sitevault-help" style="margin-top:10px">
			Current PHP upload ceiling: <?php echo esc_html( size_format( wp_max_upload_size(), 0 ) ); ?>.
			Chunked large-package upload is a separate transfer layer planned before production migration use.
		</p>

		<?php if ( ! empty( $import_validation ) && is_array( $import_validation ) ) : ?>
			<?php $validation_ok = 'validated' === ( $import_validation['status'] ?? '' ) && ! empty( $import_validation['ready_for_restore'] ); ?>
			<div class="sitevault-status-banner <?php echo $validation_ok ? 'is-complete' : 'is-error'; ?>" style="margin-top:18px">
				<span class="sitevault-status-dot"></span>
				<div>
					<strong><?php echo $validation_ok ? 'Package validated — ready for restore planning.' : 'Package validation failed.'; ?></strong>
					<p>
						<?php
						echo esc_html(
							$validation_ok
								? 'Validation only. No database tables or WordPress files were changed.'
								: ( $import_validation['error'] ?? 'The selected package did not pass SiteVault validation.' )
						);
						?>
					</p>
				</div>
			</div>

			<?php if ( $validation_ok ) : ?>
				<table class="sitevault-detail-table">
					<tbody>
						<tr><th>Backup ID</th><td><code><?php echo esc_html( $import_validation['backup_id'] ?? '—' ); ?></code></td></tr>
						<tr><th>Source site</th><td><?php echo esc_html( $import_validation['source_home_url'] ?? '—' ); ?></td></tr>
						<tr><th>Format version</th><td><?php echo esc_html( (string) ( $import_validation['format_version'] ?? '—' ) ); ?></td></tr>
						<tr><th>Database tables</th><td><?php echo esc_html( number_format_i18n( (int) ( $import_validation['database_tables'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Database rows</th><td><?php echo esc_html( number_format_i18n( (int) ( $import_validation['database_rows'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>wp-content files</th><td><?php echo esc_html( number_format_i18n( (int) ( $import_validation['content_files'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Nested archive entries</th><td><?php echo esc_html( number_format_i18n( (int) ( $import_validation['nested_entries'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Payload checksums verified</th><td><?php echo esc_html( number_format_i18n( (int) ( $import_validation['checksums_verified'] ?? 0 ) ) ); ?> / 3</td></tr>
						<tr><th>SiteVault runtime excluded</th><td><?php echo ! empty( $import_validation['runtime_excluded'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Package size</th><td><?php echo isset( $import_validation['package_size'] ) ? esc_html( size_format( (int) $import_validation['package_size'], 2 ) ) : '—'; ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="sitevault-card">
		<div class="sitevault-progress-head">
			<div>
				<h2 style="margin:0">Backup History</h2>
				<div class="sitevault-help">Latest SiteVault backups stored on this server.</div>
			</div>
		</div>

		<?php if ( empty( $backup_history ) ) : ?>
			<p>No SiteVault backups have been created yet.</p>
		<?php else : ?>
			<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Created</th>
							<th>Backup ID</th>
							<th>Status</th>
							<th>Package Size</th>
							<th>Files</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backup_history as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['created_at'] ? wp_date( 'Y-m-d H:i:s', strtotime( (string) $item['created_at'] ) ) : '—' ); ?></td>
								<td><code><?php echo esc_html( $item['backup_id'] ); ?></code></td>
								<td><?php echo esc_html( 'needs_package' === $item['package_status'] ? 'Ready to package' : ucfirst( (string) $item['package_status'] ) ); ?></td>
								<td><?php echo null !== $item['package_size'] ? esc_html( size_format( (int) $item['package_size'], 2 ) ) : '—'; ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $item['files_archived'] ) ); ?></td>
								<td>
									<?php if ( ! empty( $item['downloadable'] ) ) : ?>
										<?php
										$item_download_url = wp_nonce_url(
											add_query_arg(
												array(
													'action'    => 'sitevault_download_backup',
													'backup_id' => $item['backup_id'],
												),
												admin_url( 'admin-post.php' )
											),
											'sitevault_download_backup_' . $item['backup_id']
										);
										?>
										<a class="button button-small" href="<?php echo esc_url( $item_download_url ); ?>">Download</a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:6px">
											<input type="hidden" name="action" value="sitevault_validate_existing">
											<input type="hidden" name="backup_id" value="<?php echo esc_attr( $item['backup_id'] ); ?>">
											<?php wp_nonce_field( 'sitevault_validate_existing' ); ?>
											<button type="submit" class="button button-small">Validate for Restore</button>
										</form>
									<?php else : ?>
										<span class="sitevault-help">Unavailable</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
