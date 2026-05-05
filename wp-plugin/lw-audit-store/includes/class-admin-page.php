<?php
/**
 * Admin pages registration.
 *
 * Top-level menu "LW Audit" with two children:
 *   - Dashboard (status counts + recent rows)
 *   - Settings (delegates to LW_Audit_Settings::render_page)
 *
 * Capability: manage_options. No granular capability split in v0 — anyone
 * who can install plugins can read audits. Tighten in Phase 3 if needed.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Admin_Page {

	const MENU_SLUG = 'lw-audit';

	public static function register_pages() {
		add_menu_page(
			'LW Audit',
			'LW Audit',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-bar',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Dashboard',
			'Dashboard',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Settings',
			'Settings',
			'manage_options',
			LW_Audit_Settings::PAGE_SLUG,
			array( 'LW_Audit_Settings', 'render_page' )
		);
	}

	/**
	 * Dashboard renderer. Pure PHP — no React, no JS.
	 *
	 * Sections:
	 *   1. Status counts (sent / mail_failed / kit_synced / kit_failed / kit_dead)
	 *   2. Recent 50 audit rows table
	 *   3. Recent errors (last 20 from wp_lw_audits_errors)
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$audits = $wpdb->prefix . 'lw_audits';
		$errors = $wpdb->prefix . 'lw_audits_errors';

		// Aggregate counts in two cheap queries.
		$email_counts = $wpdb->get_results(
			"SELECT email_status AS status, COUNT(*) AS n FROM {$audits} GROUP BY email_status",
			ARRAY_A
		);
		$kit_counts = $wpdb->get_results(
			"SELECT kit_status AS status, COUNT(*) AS n FROM {$audits} GROUP BY kit_status",
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audits}" );

		$recent = $wpdb->get_results(
			"SELECT id, created_at, url_audited, score, email, email_status, kit_status, kit_attempts, kit_last_error
			 FROM {$audits}
			 ORDER BY id DESC
			 LIMIT 50",
			ARRAY_A
		);

		$recent_errors = $wpdb->get_results(
			"SELECT id, created_at, endpoint, error_message
			 FROM {$errors}
			 ORDER BY id DESC
			 LIMIT 20",
			ARRAY_A
		);

		$next_cron = wp_next_scheduled( LW_AUDIT_CRON_HOOK );
		$next_cron_str = $next_cron ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ) ) . ' (site time)' : 'NOT SCHEDULED';

		?>
		<div class="wrap">
			<h1>LW Audit — Dashboard</h1>

			<h2>At a glance</h2>
			<table class="widefat" style="max-width:700px;">
				<tr><th>Total audits captured</th><td><strong><?php echo (int) $total; ?></strong></td></tr>
				<tr><th>Next Kit.com retry</th><td><?php echo esc_html( $next_cron_str ); ?></td></tr>
			</table>

			<h2>Email send status</h2>
			<table class="widefat" style="max-width:500px;">
				<thead><tr><th>Status</th><th style="text-align:right;">Count</th></tr></thead>
				<tbody>
					<?php foreach ( $email_counts as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r['status'] ); ?></code></td>
							<td style="text-align:right;"><?php echo (int) $r['n']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Kit.com sync status</h2>
			<table class="widefat" style="max-width:500px;">
				<thead><tr><th>Status</th><th style="text-align:right;">Count</th></tr></thead>
				<tbody>
					<?php foreach ( $kit_counts as $r ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $r['status'] ); ?></code>
								<?php if ( 'failed' === $r['status'] ) : ?>
									<em>— retrying hourly</em>
								<?php elseif ( 'dead' === $r['status'] ) : ?>
									<em>— gave up after 3 attempts</em>
								<?php endif; ?>
							</td>
							<td style="text-align:right;"><?php echo (int) $r['n']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Recent audits (last 50)</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Captured</th>
						<th>URL</th>
						<th>Score</th>
						<th>Email</th>
						<th>Email send</th>
						<th>Kit sync</th>
						<th>Attempts</th>
						<th>Last error</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent ) ) : ?>
						<tr><td colspan="9"><em>No audits captured yet.</em></td></tr>
					<?php endif; ?>
					<?php foreach ( $recent as $r ) : ?>
						<tr>
							<td><?php echo (int) $r['id']; ?></td>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $r['url_audited'] ); ?>"><?php echo esc_html( $r['url_audited'] ); ?></td>
							<td><?php echo '' === $r['score'] || null === $r['score'] ? '—' : (int) $r['score']; ?></td>
							<td><?php echo esc_html( $r['email'] ); ?></td>
							<td><code><?php echo esc_html( $r['email_status'] ); ?></code></td>
							<td><code><?php echo esc_html( $r['kit_status'] ); ?></code></td>
							<td><?php echo (int) $r['kit_attempts']; ?></td>
							<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( (string) $r['kit_last_error'] ); ?>"><?php echo esc_html( (string) $r['kit_last_error'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Recent errors (last 20)</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>When</th>
						<th>Endpoint</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent_errors ) ) : ?>
						<tr><td colspan="4"><em>No errors logged.</em></td></tr>
					<?php endif; ?>
					<?php foreach ( $recent_errors as $e ) : ?>
						<tr>
							<td><?php echo (int) $e['id']; ?></td>
							<td><?php echo esc_html( $e['created_at'] ); ?></td>
							<td><code><?php echo esc_html( $e['endpoint'] ); ?></code></td>
							<td><?php echo esc_html( $e['error_message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
