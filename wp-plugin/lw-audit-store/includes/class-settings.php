<?php
/**
 * Settings: WP admin → Settings → LW Audit.
 *
 * Stores HMAC secret, Kit.com credentials, support email addresses under a
 * single option `lw_audit_settings`. Operator (Matt/Iliya) rotates keys here
 * without touching wp-config.php.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Settings {

	const OPTION_KEY     = 'lw_audit_settings';
	const SETTINGS_GROUP = 'lw_audit_settings_group';
	const PAGE_SLUG      = 'lw-audit-settings';

	/**
	 * One source of truth for setting defaults. Anything missing on read
	 * falls back here, so a fresh install is non-fatal even before the
	 * operator visits the settings page.
	 */
	public static function defaults() {
		return array(
			'hmac_secret'             => '',
			'kit_api_key'             => '',
			'kit_form_id'             => '',
			'kit_tag_id'              => '',
			'support_email_from'      => 'support@linkwhisper.com',
			'support_email_name'      => 'LinkWhisper',
			'support_email_replyto'   => 'support@linkwhisper.com',
			// CAN-SPAM Sec. 5(a)(5): commercial email must show a valid
			// physical postal address. Operator (Matt) sets this in admin.
			'support_physical_address'=> '',
			// Newline-separated additional CORS origins (staging, preview hosts).
			// Format: one full origin per line, e.g. https://preview-foo.netlify.app
			'extra_cors_origins'      => '',
		);
	}

	/**
	 * Read a setting with default fallback. Always returns a string; never
	 * null. PHP code should `if ( '' === $val )` to detect unset.
	 */
	public static function get( $key ) {
		$opts = get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();
		if ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
			return (string) $opts[ $key ];
		}
		return isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
	}

	/**
	 * Settings API registration. Wired via admin_init.
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Defensive sanitization. Each field has a known shape — we never let
	 * an arbitrary array reach the database.
	 */
	public static function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		foreach ( $out as $key => $default ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$val = (string) $input[ $key ];
			if ( in_array( $key, array( 'support_email_from', 'support_email_replyto' ), true ) ) {
				$val = sanitize_email( $val );
			} elseif ( 'support_physical_address' === $key ) {
				$val = sanitize_textarea_field( $val );
			} elseif ( 'extra_cors_origins' === $key ) {
				// Multi-line textarea: keep one origin per line, validate
				// each as a URL with scheme + host. Reject anything else.
				$lines  = preg_split( '/\r\n|\r|\n/', $val );
				$valid  = array();
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$line = esc_url_raw( $line );
					$parts = wp_parse_url( $line );
					if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
						$valid[] = $parts['scheme'] . '://' . $parts['host']
							. ( ! empty( $parts['port'] ) ? ':' . intval( $parts['port'] ) : '' );
					}
				}
				$val = implode( "\n", $valid );
			} else {
				$val = sanitize_text_field( $val );
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/**
	 * Render the settings page. Wired via admin_menu.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>LW Audit — Settings</h1>
			<p>Capture endpoint: <code><?php echo esc_html( rest_url( 'lw/v1/emails' ) ); ?></code></p>
			<p>Cron schedule: hourly (Kit.com retry, max 3 attempts before <code>kit_dead</code>).</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				$opts = get_option( self::OPTION_KEY, self::defaults() );
				$opts = is_array( $opts ) ? array_merge( self::defaults(), $opts ) : self::defaults();
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hmac_secret">HMAC Shared Secret</label></th>
						<td>
							<input type="password" id="hmac_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hmac_secret]" value="<?php echo esc_attr( $opts['hmac_secret'] ); ?>" class="regular-text" autocomplete="new-password" spellcheck="false">
							<p class="description">Min 32 chars. Frontend signs request body with HMAC-SHA256 using this. Rotate by updating both ends together.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kit_api_key">Kit.com API Key</label></th>
						<td><input type="password" id="kit_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[kit_api_key]" value="<?php echo esc_attr( $opts['kit_api_key'] ); ?>" class="regular-text" autocomplete="new-password" spellcheck="false"></td>
					</tr>
					<tr>
						<th scope="row"><label for="kit_form_id">Kit.com Form ID</label></th>
						<td><input type="text" id="kit_form_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[kit_form_id]" value="<?php echo esc_attr( $opts['kit_form_id'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="kit_tag_id">Kit.com Tag ID</label></th>
						<td>
							<input type="text" id="kit_tag_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[kit_tag_id]" value="<?php echo esc_attr( $opts['kit_tag_id'] ); ?>" class="regular-text">
							<p class="description">Optional. Tag applied to the subscriber after add.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="support_email_from">From Email</label></th>
						<td><input type="email" id="support_email_from" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_email_from]" value="<?php echo esc_attr( $opts['support_email_from'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="support_email_name">From Name</label></th>
						<td><input type="text" id="support_email_name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_email_name]" value="<?php echo esc_attr( $opts['support_email_name'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="support_email_replyto">Reply-To Email</label></th>
						<td><input type="email" id="support_email_replyto" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_email_replyto]" value="<?php echo esc_attr( $opts['support_email_replyto'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="support_physical_address">Physical Mailing Address</label></th>
						<td>
							<textarea id="support_physical_address" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_physical_address]" rows="2" class="large-text" placeholder="123 Example St, Suite 100, City, State 00000"><?php echo esc_textarea( $opts['support_physical_address'] ); ?></textarea>
							<p class="description">Required for CAN-SPAM compliance. Appears in the email footer above the unsubscribe link.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="extra_cors_origins">Extra CORS Origins</label></th>
						<td>
							<textarea id="extra_cors_origins" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[extra_cors_origins]" rows="4" class="large-text code" placeholder="https://staging.linkwhisper.com&#10;https://preview-deploy.netlify.app"><?php echo esc_textarea( $opts['extra_cors_origins'] ); ?></textarea>
							<p class="description">One origin per line. Built-in allow-list already includes audit.linkwhisper.com, linkwhisper.com, www.linkwhisper.com, and the production Netlify host.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
