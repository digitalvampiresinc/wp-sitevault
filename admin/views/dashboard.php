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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="sitevault_prepare_restore_plan">
					<?php wp_nonce_field( 'sitevault_prepare_restore_plan' ); ?>
					<?php submit_button( 'Prepare Restore Plan', 'primary', 'submit', false ); ?>
				</form>
				<p class="sitevault-help">This prepares an isolated workspace and compatibility report only. It does not restore the database or wp-content.</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $restore_plan ) && is_array( $restore_plan ) ) : ?>
		<?php
		$plan_ready = 'ready' === ( $restore_plan['status'] ?? '' );
		$plan_blocked = 'blocked' === ( $restore_plan['status'] ?? '' );
		?>
		<div class="sitevault-card">
			<div class="sitevault-progress-head">
				<div>
					<h2 style="margin:0">Restore Compatibility Plan</h2>
					<div class="sitevault-help">Source-to-target comparison before any destructive restore operation is allowed.</div>
				</div>
				<span class="sitevault-badge <?php echo $plan_ready ? 'is-complete' : ( $plan_blocked ? 'is-failed' : 'is-pending' ); ?>">
					<?php echo esc_html( ucfirst( (string) ( $restore_plan['status'] ?? 'unknown' ) ) ); ?>
				</span>
			</div>

			<?php if ( ! empty( $restore_plan['error'] ) ) : ?>
				<div class="sitevault-status-banner is-error">
					<span class="sitevault-status-dot"></span>
					<div><strong>Restore planning failed.</strong><p><?php echo esc_html( $restore_plan['error'] ); ?></p></div>
				</div>
			<?php else : ?>
				<div class="sitevault-status-banner <?php echo $plan_ready ? 'is-complete' : 'is-error'; ?>">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong><?php echo $plan_ready ? 'Restore plan is ready for the next safety stage.' : 'Restore plan has blockers.'; ?></strong>
						<p>No database tables or live wp-content files were changed while preparing this report.</p>
					</div>
				</div>

				<div class="sitevault-metrics" style="margin-top:16px">
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Restore mode</span>
						<span class="sitevault-metric-value" style="font-size:16px"><?php echo esc_html( $restore_plan['mode'] ?? '—' ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">SQL tables detected</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $restore_plan['database']['sql_table_count'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Manifest files</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $restore_plan['content']['manifest_files'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Workspace integrity</span>
						<span class="sitevault-metric-value" style="font-size:16px"><?php echo ! empty( $restore_plan['workspace']['integrity_verified'] ) ? 'Verified' : 'Unknown'; ?></span>
					</div>
				</div>

				<table class="sitevault-detail-table">
					<tbody>
						<tr><th>Plan ID</th><td><code><?php echo esc_html( $restore_plan['plan_id'] ?? '—' ); ?></code></td></tr>
						<tr><th>Source home URL</th><td><?php echo esc_html( $restore_plan['source']['home_url'] ?? '—' ); ?></td></tr>
						<tr><th>Target home URL</th><td><?php echo esc_html( $restore_plan['target']['home_url'] ?? '—' ); ?></td></tr>
						<tr><th>URL replacement required</th><td><?php echo ! empty( $restore_plan['changes']['url_replacement_required'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Source DB prefix</th><td><code><?php echo esc_html( $restore_plan['source']['db_prefix'] ?? '—' ); ?></code></td></tr>
						<tr><th>Target DB prefix</th><td><code><?php echo esc_html( $restore_plan['target']['db_prefix'] ?? '—' ); ?></code></td></tr>
						<tr><th>DB prefix remap required</th><td><?php echo ! empty( $restore_plan['changes']['prefix_remap_required'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Filesystem path migration</th><td><?php echo ! empty( $restore_plan['changes']['path_replacement_required'] ) ? 'Required' : 'Not required'; ?></td></tr>
						<tr><th>Source WordPress</th><td><?php echo esc_html( $restore_plan['source']['wordpress'] ?? '—' ); ?></td></tr>
						<tr><th>Target WordPress</th><td><?php echo esc_html( $restore_plan['target']['wordpress'] ?? '—' ); ?></td></tr>
						<tr><th>Source PHP</th><td><?php echo esc_html( $restore_plan['source']['php'] ?? '—' ); ?></td></tr>
						<tr><th>Target PHP</th><td><?php echo esc_html( $restore_plan['target']['php'] ?? '—' ); ?></td></tr>
						<tr><th>wp-content writable</th><td><?php echo ! empty( $restore_plan['environment']['wp_content_writable'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Available disk space</th><td><?php echo null !== ( $restore_plan['environment']['free_space'] ?? null ) ? esc_html( size_format( (int) $restore_plan['environment']['free_space'], 2 ) ) : 'Unknown'; ?></td></tr>
						<tr><th>Restore safety estimate</th><td><?php echo esc_html( size_format( (int) ( $restore_plan['environment']['recommended_space'] ?? 0 ), 2 ) ); ?></td></tr>
					</tbody>
				</table>

				<?php if ( ! empty( $restore_plan['warnings'] ) ) : ?>
					<h3>Warnings</h3>
					<ul class="sitevault-plan-list">
						<?php foreach ( $restore_plan['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $restore_plan['blockers'] ) ) : ?>
					<h3>Blockers</h3>
					<ul class="sitevault-plan-list">
						<?php foreach ( $restore_plan['blockers'] as $blocker ) : ?>
							<li><?php echo esc_html( $blocker ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h3>Planned Restore Sequence</h3>
				<ol class="sitevault-plan-list">
					<?php foreach ( $restore_plan['restore_sequence'] ?? array() as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>

				<?php
				$safety_matches_plan = ! empty( $restore_safety )
					&& ( $restore_safety['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' );
				$safety_complete = $safety_matches_plan
					&& 'complete' === ( $restore_safety['status'] ?? '' )
					&& 'safety_ready' === ( $restore_safety['staging']['status'] ?? '' );
				$safety_running = $safety_matches_plan && 'running' === ( $restore_safety['status'] ?? '' );
				?>

				<div class="sitevault-card" style="margin-top:18px;background:#f9fbfd">
					<h3 style="margin-top:0">Mandatory Pre-Restore Safety Snapshot</h3>
					<p>Before SiteVault is allowed to restore this package, it must create and verify a fresh backup of the target site as it exists right now.</p>

					<?php if ( $safety_complete ) : ?>
						<div class="sitevault-status-banner is-complete">
							<span class="sitevault-status-dot"></span>
							<div>
								<strong>Safety snapshot verified and restore staging sealed.</strong>
								<p>The current target site is protected by a verified rollback package. Restore execution remains locked until the next M2 execution layer is installed.</p>
							</div>
						</div>
						<table class="sitevault-detail-table">
							<tbody>
								<tr><th>Safety snapshot ID</th><td><code><?php echo esc_html( $restore_safety['snapshot_backup_id'] ?? '—' ); ?></code></td></tr>
								<tr><th>Safety package</th><td><?php echo esc_html( $restore_safety['package']['package_name'] ?? '—' ); ?></td></tr>
								<tr><th>Safety package size</th><td><?php echo ! empty( $restore_safety['package']['package_size'] ) ? esc_html( size_format( (int) $restore_safety['package']['package_size'], 2 ) ) : '—'; ?></td></tr>
								<tr><th>Package verification</th><td><?php echo ! empty( $restore_safety['package']['verified'] ) ? 'Passed' : 'Failed'; ?></td></tr>
								<tr><th>Controlled staging</th><td><?php echo esc_html( $restore_safety['staging']['status'] ?? '—' ); ?></td></tr>
								<tr><th>Restore execution locked</th><td><?php echo ! empty( $restore_safety['staging']['restore_execution_locked'] ) ? 'Yes' : 'No'; ?></td></tr>
							</tbody>
						</table>
					<?php else : ?>
						<?php if ( ! $safety_running ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="sitevault_start_restore_safety">
								<?php wp_nonce_field( 'sitevault_start_restore_safety' ); ?>
								<?php submit_button( 'Create Mandatory Safety Snapshot', 'primary', 'submit', false ); ?>
							</form>
						<?php endif; ?>

						<?php if ( $safety_running ) : ?>
							<div id="sitevault-safety-progress">
								<div class="sitevault-progress-head">
									<div>
										<div class="sitevault-progress-title" id="sitevault-safety-stage">Preparing target rollback snapshot</div>
										<div class="sitevault-help">Snapshot ID: <code><?php echo esc_html( $restore_safety['snapshot_backup_id'] ?? '—' ); ?></code></div>
									</div>
									<div class="sitevault-progress-value" id="sitevault-safety-percent">Working…</div>
								</div>
								<div id="sitevault-safety-track" class="sitevault-progress-track is-indeterminate">
									<div id="sitevault-safety-bar" class="sitevault-progress-bar" style="width:0%"></div>
								</div>
								<div class="sitevault-stage-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-top:14px">
									<div id="sitevault-safety-db" class="sitevault-stage <?php echo 'database' === ( $restore_safety['stage'] ?? '' ) ? 'is-running' : 'is-complete'; ?>">
										<span class="sitevault-stage-name">1. Target Database</span><span class="sitevault-stage-state">snapshot</span>
									</div>
									<div id="sitevault-safety-content" class="sitevault-stage <?php echo 'content' === ( $restore_safety['stage'] ?? '' ) ? 'is-running' : ( 'database' === ( $restore_safety['stage'] ?? '' ) ? 'is-pending' : 'is-complete' ); ?>">
										<span class="sitevault-stage-name">2. Target Files</span><span class="sitevault-stage-state">snapshot</span>
									</div>
									<div id="sitevault-safety-package" class="sitevault-stage <?php echo 'package' === ( $restore_safety['stage'] ?? '' ) ? 'is-running' : 'is-pending'; ?>">
										<span class="sitevault-stage-name">3. Verify & Seal</span><span class="sitevault-stage-state">snapshot</span>
									</div>
								</div>
								<div class="sitevault-status-banner is-running">
									<span class="sitevault-status-dot"></span>
									<div>
										<strong>Do not start the restore manually.</strong>
										<p>SiteVault is preserving the current target site first. Keep this page open until the safety stage reaches complete.</p>
									</div>
								</div>
							</div>

							<script>
							(function(){
								const stage = '<?php echo esc_js( $restore_safety['stage'] ?? 'database' ); ?>';
								let current = stage;
								let stopped = false;
								const track = document.getElementById('sitevault-safety-track');
								const bar = document.getElementById('sitevault-safety-bar');
								const pct = document.getElementById('sitevault-safety-percent');
								const title = document.getElementById('sitevault-safety-stage');

								function setStageCard(id,state){
									const el=document.getElementById(id);
									if(!el)return;
									el.classList.remove('is-pending','is-running','is-complete','is-failed');
									el.classList.add('is-'+state);
								}

								function humanBytes(bytes){
									const value=Number(bytes||0);
									if(value<1024)return value+' B';
									const units=['KB','MB','GB','TB'];
									let size=value,index=-1;
									do{size/=1024;index++;}while(size>=1024&&index<units.length-1);
									return size.toFixed(2)+' '+units[index];
								}

								async function post(action,nonce){
									const body=new URLSearchParams();
									body.set('action',action); body.set('nonce',nonce);
									const res=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
									return res.json();
								}

								function fail(message){
									stopped=true;
									pct.textContent='Stopped';
									title.textContent=message||'Safety snapshot stopped';
								}

								async function run(){
									if(stopped)return;
									try{
										let payload;
										if(current==='database'){
											payload=await post('sitevault_restore_safety_database','<?php echo esc_js( wp_create_nonce( 'sitevault_restore_safety_database' ) ); ?>');
										}else if(current==='content'){
											payload=await post('sitevault_restore_safety_content','<?php echo esc_js( wp_create_nonce( 'sitevault_restore_safety_content' ) ); ?>');
										}else{
											payload=await post('sitevault_restore_safety_package','<?php echo esc_js( wp_create_nonce( 'sitevault_restore_safety_package' ) ); ?>');
										}
										if(!payload.success){fail(payload.data&&payload.data.message?payload.data.message:'Safety snapshot failed.');return;}
										const d=payload.data||{};
										current=d.stage||current;

										if(current==='database'){
											track.classList.remove('is-indeterminate');
											const total=Number(d.table_total||0),done=Number(d.table_done||0);
											const p=total>0?Math.min(30,(done/total)*30):2;
											bar.style.width=p+'%'; pct.textContent=Math.round(p)+'%'; title.textContent='Backing up target database';
										}else if(current==='content'){
											setStageCard('sitevault-safety-db','complete');
											setStageCard('sitevault-safety-content','running');
											if(d.content_phase==='scanning'){
												track.classList.add('is-indeterminate'); pct.textContent='Working…'; title.textContent='Scanning target wp-content';
											}else{
												track.classList.remove('is-indeterminate');
												const total=Number(d.files_discovered||0),done=Number(d.files_archived||0);
												const p=35+(total>0?Math.min(50,(done/total)*50):0);
												bar.style.width=p+'%'; pct.textContent=Math.round(p)+'%'; title.textContent='Archiving target wp-content';
											}
										}else if(current==='package'){
											setStageCard('sitevault-safety-db','complete');
											setStageCard('sitevault-safety-content','complete');
											setStageCard('sitevault-safety-package','running');
											track.classList.add('is-indeterminate'); pct.textContent='Working…'; title.textContent='Verifying rollback package and sealing restore staging';
										}

										if(d.safety_ready){
											stopped=true;
											track.classList.remove('is-indeterminate'); bar.style.width='100%'; pct.textContent='100%'; title.textContent='Safety snapshot complete';
											setStageCard('sitevault-safety-package','complete');
											window.setTimeout(function(){window.location.reload();},500);
											return;
										}
										window.setTimeout(run,250);
									}catch(e){fail('Safety snapshot paused. Reload this page to resume safely.');}
								}
								window.setTimeout(run,350);
							})();
							</script>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! $safety_complete ) : ?>
						<div class="sitevault-status-banner is-warning">
							<span class="sitevault-status-dot"></span>
							<div>
								<strong>Restore execution is locked.</strong>
								<p>SiteVault will not permit the next restore layer until the target safety snapshot and its package verification are complete.</p>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	$database_stage_plan_match = ! empty( $database_staging )
		&& ( $database_staging['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' );
	$database_stage_verified = $database_stage_plan_match && 'verified' === ( $database_staging['status'] ?? '' );
	$database_stage_running = $database_stage_plan_match && in_array(
		$database_staging['status'] ?? '',
		array( 'running', 'imported', 'transformed' ),
		true
	);
	$safety_ready_for_db = ! empty( $restore_safety )
		&& ( $restore_safety['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' )
		&& 'complete' === ( $restore_safety['status'] ?? '' )
		&& 'safety_ready' === ( $restore_safety['staging']['status'] ?? '' );
	?>

	<?php if ( ! empty( $restore_plan ) && 'ready' === ( $restore_plan['status'] ?? '' ) && $safety_ready_for_db ) : ?>
		<div class="sitevault-card">
			<div class="sitevault-progress-head">
				<div>
					<h2 style="margin:0">Shadow Database Staging</h2>
					<div class="sitevault-help">The source database is imported into isolated staging tables first. Live WordPress tables remain untouched.</div>
				</div>
				<?php if ( $database_stage_verified ) : ?>
					<span class="sitevault-badge is-complete">Verified</span>
				<?php elseif ( $database_stage_running ) : ?>
					<span class="sitevault-badge is-running">Processing</span>
				<?php else : ?>
					<span class="sitevault-badge is-pending">Not started</span>
				<?php endif; ?>
			</div>

			<?php if ( ! $database_stage_plan_match || 'failed' === ( $database_staging['status'] ?? '' ) ) : ?>
				<div class="sitevault-status-banner is-warning">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong>Live database restore is still locked.</strong>
						<p>This stage creates shadow tables only. It will not replace, rename or drop the target WordPress tables.</p>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="sitevault_start_database_staging">
					<?php wp_nonce_field( 'sitevault_start_database_staging' ); ?>
					<?php submit_button( 'Prepare Shadow Database', 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<div class="sitevault-metrics">
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Expected tables</span>
						<span class="sitevault-metric-value" id="sitevault-db-stage-expected"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['expected_tables'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Tables created</span>
						<span class="sitevault-metric-value" id="sitevault-db-stage-created"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['tables_created'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Manifest rows</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['manifest_rows'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Verified rows</span>
						<span class="sitevault-metric-value" id="sitevault-db-stage-verified-rows"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['verified_rows'] ?? 0 ) ) ); ?></span>
					</div>
				</div>

				<table class="sitevault-detail-table">
					<tbody>
						<tr><th>Staging prefix</th><td><code><?php echo esc_html( $database_staging['staging_prefix'] ?? '—' ); ?></code></td></tr>
						<tr><th>Current stage</th><td id="sitevault-db-stage-name"><?php echo esc_html( $database_staging['stage'] ?? '—' ); ?></td></tr>
						<tr><th>SQL statements executed</th><td id="sitevault-db-stage-statements"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['statements_executed'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Rows inserted</th><td id="sitevault-db-stage-inserted"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['inserted_rows'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Migration rows scanned</th><td id="sitevault-db-stage-scanned"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['transform_state']['rows_scanned'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Migration rows changed</th><td id="sitevault-db-stage-rows-changed"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['transform_state']['rows_changed'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Serialized/text replacements</th><td id="sitevault-db-stage-replacements"><?php echo esc_html( number_format_i18n( (int) ( $database_staging['transform_state']['replacements'] ?? 0 ) ) ); ?></td></tr>
						<tr><th>Live WordPress tables modified</th><td id="sitevault-db-stage-live"><?php echo ! empty( $database_staging['live_tables_modified'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Ready for future live promotion</th><td id="sitevault-db-stage-promotion"><?php echo ! empty( $database_staging['ready_for_live_promotion'] ) ? 'Yes' : 'No'; ?></td></tr>
					</tbody>
				</table>

				<?php if ( ! empty( $database_staging['promotion_blocker'] ) ) : ?>
					<div class="sitevault-status-banner is-warning">
						<span class="sitevault-status-dot"></span>
						<div><strong>Promotion remains blocked.</strong><p><?php echo esc_html( $database_staging['promotion_blocker'] ); ?></p></div>
					</div>
				<?php endif; ?>

				<?php if ( $database_stage_verified ) : ?>
					<div class="sitevault-status-banner is-complete">
						<span class="sitevault-status-dot"></span>
						<div>
							<strong>Shadow database verified.</strong>
							<p>The backup database has been imported and migration transforms were tested in isolated staging tables. The live WordPress database is still untouched.</p>
						</div>
					</div>
				<?php else : ?>
					<div id="sitevault-db-stage-progress" style="margin-top:16px">
						<div class="sitevault-progress-head">
							<div class="sitevault-progress-title" id="sitevault-db-stage-title">Processing shadow database</div>
							<div class="sitevault-progress-value" id="sitevault-db-stage-percent">Working…</div>
						</div>
						<div id="sitevault-db-stage-track" class="sitevault-progress-track">
							<div id="sitevault-db-stage-bar" class="sitevault-progress-bar" style="width:2%"></div>
						</div>
						<div class="sitevault-status-banner is-running">
							<span class="sitevault-status-dot"></span>
							<div>
								<strong>Staging only — live tables are protected.</strong>
								<p>Keep this page open while SiteVault imports, verifies and transforms the shadow database.</p>
							</div>
						</div>
					</div>

					<script>
					(function(){
						let current='<?php echo esc_js( $database_staging['stage'] ?? 'import' ); ?>';
						let stopped=false;
						const title=document.getElementById('sitevault-db-stage-title');
						const pct=document.getElementById('sitevault-db-stage-percent');
						const bar=document.getElementById('sitevault-db-stage-bar');
						const stageName=document.getElementById('sitevault-db-stage-name');
						const created=document.getElementById('sitevault-db-stage-created');
						const statements=document.getElementById('sitevault-db-stage-statements');
						const inserted=document.getElementById('sitevault-db-stage-inserted');
						const verifiedRows=document.getElementById('sitevault-db-stage-verified-rows');
						const scanned=document.getElementById('sitevault-db-stage-scanned');
						const rowsChanged=document.getElementById('sitevault-db-stage-rows-changed');
						const replacements=document.getElementById('sitevault-db-stage-replacements');

						async function post(action,nonce){
							const body=new URLSearchParams(); body.set('action',action); body.set('nonce',nonce);
							const res=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
							return res.json();
						}

						function setProgress(value,label){
							const safe=Math.max(1,Math.min(100,Math.round(value)));
							bar.style.width=safe+'%'; pct.textContent=safe+'%'; title.textContent=label;
						}

						function fail(message){stopped=true;pct.textContent='Stopped';title.textContent=message||'Database staging stopped';}

						async function run(){
							if(stopped)return;
							try{
								let action,nonce;
								if(current==='import'){action='sitevault_database_stage_import';nonce='<?php echo esc_js( wp_create_nonce( 'sitevault_database_stage_import' ) ); ?>';}
								else if(current==='verify_import'){action='sitevault_database_stage_verify';nonce='<?php echo esc_js( wp_create_nonce( 'sitevault_database_stage_verify' ) ); ?>';}
								else if(current==='transform'){action='sitevault_database_stage_transform';nonce='<?php echo esc_js( wp_create_nonce( 'sitevault_database_stage_transform' ) ); ?>';}
								else if(current==='verify_transform'){action='sitevault_database_stage_verify_transform';nonce='<?php echo esc_js( wp_create_nonce( 'sitevault_database_stage_verify_transform' ) ); ?>';}
								else{window.location.reload();return;}

								const payload=await post(action,nonce);
								if(!payload.success){fail(payload.data&&payload.data.message?payload.data.message:'Shadow database staging failed.');return;}
								const d=payload.data||{};
								current=d.stage||current;
								stageName.textContent=current;
								created.textContent=Number(d.tables_created||0).toLocaleString();
								statements.textContent=Number(d.statements_executed||0).toLocaleString();
								inserted.textContent=Number(d.inserted_rows||0).toLocaleString();
								verifiedRows.textContent=Number(d.verified_rows||0).toLocaleString();
								scanned.textContent=Number(d.transform_rows_scanned||0).toLocaleString();
								rowsChanged.textContent=Number(d.transform_rows_changed||0).toLocaleString();
								replacements.textContent=Number(d.transform_replacements||0).toLocaleString();

								if(d.status==='verified'){
									setProgress(100,'Shadow database verified');
									stopped=true; window.setTimeout(function(){window.location.reload();},500); return;
								}

								if(current==='import'){
									const total=Math.max(1,Number(d.expected_tables||0)),done=Number(d.tables_created||0);
									setProgress(Math.min(55,(done/total)*55),'Importing backup into shadow tables');
								}else if(current==='verify_import'){
									setProgress(60,'Verifying staged table and row counts');
								}else if(current==='transform'){
									const total=Math.max(1,Number(d.expected_tables||0)),done=Number(d.transform_table_index||0);
									setProgress(65+Math.min(28,(done/total)*28),'Applying serialized-safe URL and path transforms');
								}else{
									setProgress(96,'Verifying transformed shadow database');
								}
								window.setTimeout(run,200);
							}catch(e){fail('Database staging paused. Reload this page to resume safely.');}
						}
						window.setTimeout(run,350);
					})();
					</script>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	$content_stage_plan_match = ! empty( $content_staging )
		&& ( $content_staging['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' );
	$content_stage_verified = $content_stage_plan_match && 'verified' === ( $content_staging['status'] ?? '' );
	$content_stage_running = $content_stage_plan_match && 'running' === ( $content_staging['status'] ?? '' );
	?>

	<?php if ( $database_stage_verified && $safety_ready_for_db ) : ?>
		<div class="sitevault-card">
			<div class="sitevault-progress-head">
				<div>
					<h2 style="margin:0">Shadow wp-content Staging</h2>
					<div class="sitevault-help">The backup files are extracted into an isolated staging directory. Live wp-content remains untouched.</div>
				</div>
				<?php if ( $content_stage_verified ) : ?>
					<span class="sitevault-badge is-complete">Verified</span>
				<?php elseif ( $content_stage_running ) : ?>
					<span class="sitevault-badge is-running">Processing</span>
				<?php else : ?>
					<span class="sitevault-badge is-pending">Not started</span>
				<?php endif; ?>
			</div>

			<?php if ( ! $content_stage_plan_match || 'failed' === ( $content_staging['status'] ?? '' ) ) : ?>
				<div class="sitevault-status-banner is-warning">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong>Live wp-content promotion is still locked.</strong>
						<p>This stage creates a shadow filesystem only. It does not overwrite plugins, themes, uploads or other live content.</p>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="sitevault_start_content_staging">
					<?php wp_nonce_field( 'sitevault_start_content_staging' ); ?>
					<?php submit_button( 'Prepare Shadow wp-content', 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<div class="sitevault-metrics">
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Expected source files</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $content_staging['expected_files'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Files staged</span>
						<span class="sitevault-metric-value" id="sitevault-content-stage-files"><?php echo esc_html( number_format_i18n( (int) ( $content_staging['files_staged'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Verified files</span>
						<span class="sitevault-metric-value" id="sitevault-content-stage-verified"><?php echo esc_html( number_format_i18n( (int) ( $content_staging['verified_files'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Target files before restore</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $content_staging['target_before_files'] ?? 0 ) ) ); ?></span>
					</div>
				</div>

				<table class="sitevault-detail-table">
					<tbody>
						<tr><th>Current stage</th><td id="sitevault-content-stage-name"><?php echo esc_html( $content_staging['stage'] ?? '—' ); ?></td></tr>
						<tr><th>Source staged bytes</th><td id="sitevault-content-stage-bytes"><?php echo esc_html( size_format( (int) ( $content_staging['bytes_staged'] ?? 0 ), 2 ) ); ?></td></tr>
						<tr><th>Verified staged bytes</th><td id="sitevault-content-stage-verified-bytes"><?php echo esc_html( size_format( (int) ( $content_staging['verified_bytes'] ?? 0 ), 2 ) ); ?></td></tr>
						<tr><th>Target bytes before restore</th><td><?php echo esc_html( size_format( (int) ( $content_staging['target_before_bytes'] ?? 0 ), 2 ) ); ?></td></tr>
						<tr><th>Live wp-content files modified</th><td id="sitevault-content-stage-live"><?php echo ! empty( $content_staging['live_files_modified'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Ready for future promotion</th><td id="sitevault-content-stage-promotion"><?php echo ! empty( $content_staging['ready_for_promotion'] ) ? 'Yes' : 'No'; ?></td></tr>
					</tbody>
				</table>

				<?php if ( $content_stage_verified ) : ?>
					<div class="sitevault-status-banner is-complete">
						<span class="sitevault-status-dot"></span>
						<div>
							<strong>Shadow wp-content verified.</strong>
							<p>The backup files were extracted and verified in isolated staging. Live WordPress files remain untouched.</p>
						</div>
					</div>
				<?php else : ?>
					<div style="margin-top:16px">
						<div class="sitevault-progress-head">
							<div class="sitevault-progress-title" id="sitevault-content-stage-title">Extracting shadow wp-content</div>
							<div class="sitevault-progress-value" id="sitevault-content-stage-percent">Working…</div>
						</div>
						<div class="sitevault-progress-track">
							<div id="sitevault-content-stage-bar" class="sitevault-progress-bar" style="width:2%"></div>
						</div>
						<div class="sitevault-status-banner is-running">
							<span class="sitevault-status-dot"></span>
							<div><strong>Staging only — live files are protected.</strong><p>Keep this page open while SiteVault extracts and verifies the source wp-content in isolation.</p></div>
						</div>
					</div>

					<script>
					(function(){
						let current='<?php echo esc_js( $content_staging['stage'] ?? 'extract' ); ?>';
						let stopped=false;
						const title=document.getElementById('sitevault-content-stage-title');
						const pct=document.getElementById('sitevault-content-stage-percent');
						const bar=document.getElementById('sitevault-content-stage-bar');
						const stageName=document.getElementById('sitevault-content-stage-name');
						const files=document.getElementById('sitevault-content-stage-files');
						const verified=document.getElementById('sitevault-content-stage-verified');
						const bytes=document.getElementById('sitevault-content-stage-bytes');
						const verifiedBytes=document.getElementById('sitevault-content-stage-verified-bytes');

						function humanBytes(value){
							value=Number(value||0);
							if(value<1024)return value+' B';
							const units=['KB','MB','GB','TB'];let size=value,index=-1;
							do{size/=1024;index++;}while(size>=1024&&index<units.length-1);
							return size.toFixed(2)+' '+units[index];
						}

						async function post(action,nonce){
							const body=new URLSearchParams();body.set('action',action);body.set('nonce',nonce);
							const res=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
							return res.json();
						}

						function fail(message){stopped=true;pct.textContent='Stopped';title.textContent=message||'wp-content staging stopped';}

						async function run(){
							if(stopped)return;
							try{
								let payload;
								if(current==='extract'){
									payload=await post('sitevault_content_stage_extract','<?php echo esc_js( wp_create_nonce( 'sitevault_content_stage_extract' ) ); ?>');
								}else{
									payload=await post('sitevault_content_stage_verify','<?php echo esc_js( wp_create_nonce( 'sitevault_content_stage_verify' ) ); ?>');
								}
								if(!payload.success){fail(payload.data&&payload.data.message?payload.data.message:'Shadow wp-content staging failed.');return;}
								const d=payload.data||{};
								current=d.stage||current;
								stageName.textContent=current;
								files.textContent=Number(d.files_staged||0).toLocaleString();
								verified.textContent=Number(d.verified_files||0).toLocaleString();
								bytes.textContent=humanBytes(d.bytes_staged||0);
								verifiedBytes.textContent=humanBytes(d.verified_bytes||0);

								if(d.status==='verified'){
									bar.style.width='100%';pct.textContent='100%';title.textContent='Shadow wp-content verified';
									stopped=true;window.setTimeout(function(){window.location.reload();},500);return;
								}

								if(current==='extract'){
									const total=Math.max(1,Number(d.expected_files||0)),done=Number(d.files_staged||0);
									const p=Math.max(2,Math.min(92,(done/total)*92));
									bar.style.width=p+'%';pct.textContent=Math.round(p)+'%';title.textContent='Extracting shadow wp-content';
								}else{
									bar.style.width='96%';pct.textContent='96%';title.textContent='Verifying staged file count and bytes';
								}
								window.setTimeout(run,200);
							}catch(e){fail('wp-content staging paused. Reload this page to resume safely.');}
						}
						window.setTimeout(run,350);
					})();
					</script>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	$cutover_matches_plan = ! empty( $cutover_readiness )
		&& ( $cutover_readiness['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' );
	$cutover_ready = $cutover_matches_plan && 'cutover_ready' === ( $cutover_readiness['status'] ?? '' );
	?>

	<?php if ( $content_stage_verified && $database_stage_verified && $safety_ready_for_db ) : ?>
		<div class="sitevault-card">
			<div class="sitevault-progress-head">
				<div>
					<h2 style="margin:0">Cutover Readiness Gate</h2>
					<div class="sitevault-help">Final integrity gate before any live database or wp-content promotion can be introduced.</div>
				</div>
				<?php if ( $cutover_ready ) : ?>
					<span class="sitevault-badge is-complete">Cutover Ready</span>
				<?php else : ?>
					<span class="sitevault-badge is-pending">Not sealed</span>
				<?php endif; ?>
			</div>

			<?php if ( ! $cutover_ready ) : ?>
				<div class="sitevault-status-banner is-warning">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong>One final non-destructive verification remains.</strong>
						<p>SiteVault will recheck the safety rollback package, shadow database and shadow wp-content, then create a sealed cutover-readiness record. No live promotion happens here.</p>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="sitevault_seal_cutover_readiness">
					<?php wp_nonce_field( 'sitevault_seal_cutover_readiness' ); ?>
					<?php submit_button( 'Seal Cutover Readiness', 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<div class="sitevault-status-banner is-complete">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong>All staged restore components are sealed and ready for controlled cutover.</strong>
						<p>The rollback package, shadow database and shadow wp-content were rechecked after staging. Live execution remains locked in this build.</p>
					</div>
				</div>

				<div class="sitevault-metrics">
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Shadow DB tables</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $cutover_readiness['shadow_database_tables'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Shadow DB rows</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $cutover_readiness['shadow_database_rows'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Shadow files</span>
						<span class="sitevault-metric-value"><?php echo esc_html( number_format_i18n( (int) ( $cutover_readiness['shadow_content_files'] ?? 0 ) ) ); ?></span>
					</div>
					<div class="sitevault-metric">
						<span class="sitevault-metric-label">Shadow file bytes</span>
						<span class="sitevault-metric-value" style="font-size:16px"><?php echo esc_html( size_format( (int) ( $cutover_readiness['shadow_content_bytes'] ?? 0 ), 2 ) ); ?></span>
					</div>
				</div>

				<table class="sitevault-detail-table">
					<tbody>
						<tr><th>Restore mode</th><td><?php echo esc_html( $cutover_readiness['restore_mode'] ?? '—' ); ?></td></tr>
						<tr><th>Source site</th><td><?php echo esc_html( $cutover_readiness['source_home_url'] ?? '—' ); ?></td></tr>
						<tr><th>Target site</th><td><?php echo esc_html( $cutover_readiness['target_home_url'] ?? '—' ); ?></td></tr>
						<tr><th>Safety snapshot ID</th><td><code><?php echo esc_html( $cutover_readiness['safety_snapshot_id'] ?? '—' ); ?></code></td></tr>
						<tr><th>Safety package fingerprint</th><td><code><?php echo esc_html( substr( (string) ( $cutover_readiness['safety_package_sha256'] ?? '' ), 0, 20 ) ); ?>…</code></td></tr>
						<tr><th>URL replacement required</th><td><?php echo ! empty( $cutover_readiness['url_replacement_required'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Filesystem path replacement required</th><td><?php echo ! empty( $cutover_readiness['path_replacement_required'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Live database modified</th><td><?php echo ! empty( $cutover_readiness['live_tables_modified'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Live wp-content modified</th><td><?php echo ! empty( $cutover_readiness['live_files_modified'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Destructive actions taken</th><td><?php echo ! empty( $cutover_readiness['destructive_actions_taken'] ) ? 'Yes' : 'No'; ?></td></tr>
						<tr><th>Live execution locked</th><td><?php echo ! empty( $cutover_readiness['execution_locked'] ) ? 'Yes' : 'No'; ?></td></tr>
					</tbody>
				</table>

				<div class="sitevault-status-banner is-warning">
					<span class="sitevault-status-dot"></span>
					<div>
						<strong>Cutover is ready, but still not executable.</strong>
						<p>The next build will introduce the controlled live-promotion transaction and automatic rollback path. This build deliberately stops before that point.</p>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

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
