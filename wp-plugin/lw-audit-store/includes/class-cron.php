<?php
/**
 * Cron: hourly retry of Kit.com syncs that failed at capture time.
 *
 * Logic:
 *  - Pull rows where kit_status = 'failed' AND kit_attempts < 3.
 *  - Cap batch size at 50/run so a backlog doesn't drown wp-cron.
 *  - Re-call Kit.com. On success → kit_status='synced'. On failure →
 *    increment kit_attempts. After the 3rd failed attempt → kit_status='dead'.
 *
 * The hourly schedule is registered in LW_Audit_Installer::activate() and
 * removed in deactivate().
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Cron {

	const MAX_ATTEMPTS = 3;
	const BATCH_SIZE   = 50;
	const LOCK_KEY     = 'lw_audit_cron_running';
	const LOCK_TTL     = 600; // 10 min — longer than any realistic batch.

	/**
	 * Cron entry point. Wired via add_action( LW_AUDIT_CRON_HOOK, ... ).
	 *
	 * Concurrency guard: WP-cron is non-locking by default, so two overlapping
	 * fires can both pull the same `failed` rows and double-subscribe Kit.com.
	 * We use a transient as a cheap mutex. If the lock is held we exit early
	 * — the next hourly fire will pick up where we left off.
	 *
	 * Counter math: a row arrives here with kit_attempts >= 1 (capture
	 * already attempted once). After two more failed retries it hits
	 * MAX_ATTEMPTS and flips to 'dead'. Total Kit calls per row = 3.
	 */
	public static function run_retries() {
		if ( get_transient( self::LOCK_KEY ) ) {
			error_log( '[lw-audit-store] cron lock held, skipping run' );
			return;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

		try {
			self::process_batch();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	private static function process_batch() {
		global $wpdb;
		$table = $wpdb->prefix . 'lw_audits';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, url_audited, score, kit_attempts
			 FROM {$table}
			 WHERE kit_status = %s AND kit_attempts < %d
			 ORDER BY id ASC
			 LIMIT %d",
			'failed',
			self::MAX_ATTEMPTS,
			self::BATCH_SIZE
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		$synced = 0;
		$dead   = 0;
		$still  = 0;

		foreach ( $rows as $row ) {
			$id = intval( $row['id'] );
			$result = LW_Audit_Kit_Client::subscribe( (string) $row['email'], array(
				'audit_url' => (string) $row['url_audited'],
				'score'     => isset( $row['score'] ) ? (string) intval( $row['score'] ) : '',
			) );

			// Atomic increment via SQL — two concurrent runs can't lose the +1.
			if ( $result['ok'] ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$table}
					 SET kit_status = %s,
					     kit_attempts = kit_attempts + 1,
					     kit_subscriber_id = %s,
					     kit_last_error = NULL
					 WHERE id = %d",
					'synced',
					(string) $result['subscriber_id'],
					$id
				) );
				$synced++;
				continue;
			}

			// On failure: bump attempts atomically; flip to 'dead' iff post-
			// increment value reaches MAX_ATTEMPTS. We cap status using a CASE
			// expression so the read-back of kit_attempts is server-side.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET kit_status = CASE
				                    WHEN kit_attempts + 1 >= %d THEN %s
				                    ELSE %s
				                  END,
				     kit_attempts = kit_attempts + 1,
				     kit_last_error = %s
				 WHERE id = %d",
				self::MAX_ATTEMPTS,
				'dead',
				'failed',
				(string) $result['error'],
				$id
			) );

			$attempts_after = intval( $row['kit_attempts'] ) + 1;
			if ( $attempts_after >= self::MAX_ATTEMPTS ) {
				$dead++;
			} else {
				$still++;
			}
		}

		error_log( sprintf(
			'[lw-audit-store] cron retries: synced=%d dead=%d still_failing=%d total=%d',
			$synced, $dead, $still, count( $rows )
		) );
	}
}
