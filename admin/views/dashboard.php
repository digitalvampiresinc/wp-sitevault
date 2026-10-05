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
						<td><?php echo esc_html( $database_state['status'] ?? 'unknown' ); ?></td>
					</tr>
					<tr>
						<th>Tables</th>
						<td>
							<?php
							$table_total = count( $database_state['tables'] ?? array() );
							$table_done  = min( (int) ( $database_state['table_index'] ?? 0 ), $table_total );
							echo esc_html( $table_done . ' / ' . $table_total );
							?>
						</td>
					</tr>
					<tr>
						<th>Rows Exported</th>
						<td><?php echo esc_html( number_format_i18n( (int) ( $database_state['rows_exported'] ?? 0 ) ) ); ?></td>
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="sitevault_continue_database_export">
				<?php wp_nonce_field( 'sitevault_continue_database_export' ); ?>
				<?php submit_button( 'Process Next Database Batch', 'primary', 'submit', false ); ?>
			</form>
			<p class="description">Each click processes one bounded batch. Automatic chained processing will replace this manual control later in M1.</p>
		<?php elseif ( $database_state && 'complete' === ( $database_state['status'] ?? '' ) ) : ?>
			<p><strong>Database dump created successfully.</strong> The next M1 component is the wp-content file scanner/archive engine.</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
