<?php
/**
 * Plugin Name:       LW Audit Store
 * Plugin URI:        https://linkwhisper.com/
 * Description:       Captures Free Audit Tool submissions (URL audited, scan stats, email, UTM) into a dedicated MySQL table on linkwhisper.com. System of record for the free-tool acquisition funnel. Kit.com remains the email-delivery layer only.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LinkWhisper
 * Author URI:        https://linkwhisper.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lw-audit-store
 *
 * Phase 1 scope: plugin shell, DB schema (wp_lw_audits + wp_lw_audits_errors)
 * via dbDelta, activation/deactivation/uninstall hooks. No REST endpoints,
 * no admin page, no Kit.com integration yet — those are Phases 2-4.
 *
 * @package LW_Audit_Store
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants. Bump LW_AUDIT_DB_VERSION on any schema change so the
 * installer re-runs dbDelta on the next activation / version check.
 */
define( 'LW_AUDIT_VERSION', '0.1.0' );
define( 'LW_AUDIT_DB_VERSION', '1' );
define( 'LW_AUDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LW_AUDIT_URL', plugin_dir_url( __FILE__ ) );

// Manual require — no Composer in v0. Matt/Iliya don't need a build step
// to read or edit this plugin.
require_once LW_AUDIT_DIR . 'includes/class-installer.php';

// Phase 2: REST routes registered in includes/class-rest-controller.php
// Phase 2: Admin page registered in includes/class-admin-page.php
// Phase 3: Kit.com client in includes/class-kit-client.php

register_activation_hook( __FILE__, array( 'LW_Audit_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LW_Audit_Installer', 'deactivate' ) );
