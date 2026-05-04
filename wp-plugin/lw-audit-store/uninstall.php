<?php
/**
 * Uninstall handler — DESTRUCTIVE.
 *
 * Runs ONLY when an admin clicks "Delete" on the plugin in wp-admin/plugins.php.
 * Does NOT run on deactivation. Drops both lw_audits and lw_audits_errors
 * tables and removes the schema-version option.
 *
 * Data lifecycle for Matt/Iliya:
 *   - Deactivate plugin  -> tables preserved, plugin can be reactivated
 *                           without data loss.
 *   - Delete   plugin    -> THIS FILE RUNS. Tables dropped. Data destroyed.
 *                           No undo. Take a mysqldump first if uncertain.
 *
 * @package LW_Audit_Store
 */

// Standard guard. WP defines this constant only inside the uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$audits_table = $wpdb->prefix . 'lw_audits';
$errors_table = $wpdb->prefix . 'lw_audits_errors';

// Phpcs:ignore WordPress.DB.DirectDatabaseQuery -- DROP TABLE has no
// $wpdb->prepare equivalent; table names are built from $wpdb->prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$audits_table}" );
// Phpcs:ignore WordPress.DB.DirectDatabaseQuery -- same as above.
$wpdb->query( "DROP TABLE IF EXISTS {$errors_table}" );

delete_option( 'lw_audit_db_version' );
