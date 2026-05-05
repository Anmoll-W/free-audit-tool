<?php
/**
 * Kit.com API client.
 *
 * Single responsibility: subscribe an email + (optionally) tag it. Returns a
 * normalized result. Caller is responsible for persisting state in the audits
 * row — the client itself is stateless.
 *
 * Phase 2 design choice: HTTP timeout 8s, no in-process retries. The hourly
 * cron handles replay so we keep this call fast and let the cron own backoff.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Kit_Client {

	const API_BASE = 'https://api.convertkit.com/v3';
	// 3s timeout: keep the inline /emails handler responsive even when Kit
	// is slow. The hourly cron picks up every failure and retries up to
	// 3 times before flagging `kit_dead` — so a tight inline budget is safe.
	const TIMEOUT  = 3;

	/**
	 * Result shape returned from subscribe():
	 *   [ 'ok' => bool, 'subscriber_id' => string|null, 'error' => string|null ]
	 *
	 * @param string $email  Already-validated email.
	 * @param array  $merge  Optional fields { first_name, last_name, audit_url, ... }.
	 * @return array
	 */
	public static function subscribe( $email, $merge = array() ) {
		$api_key = LW_Audit_Settings::get( 'kit_api_key' );
		$form_id = LW_Audit_Settings::get( 'kit_form_id' );

		if ( '' === $api_key || '' === $form_id ) {
			return array(
				'ok'            => false,
				'subscriber_id' => null,
				'error'         => 'missing kit credentials',
			);
		}

		$endpoint = self::API_BASE . '/forms/' . rawurlencode( $form_id ) . '/subscribe';
		$body     = array(
			'api_key' => $api_key,
			'email'   => $email,
		);
		if ( ! empty( $merge ) && is_array( $merge ) ) {
			$body['fields'] = $merge;
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'            => false,
				'subscriber_id' => null,
				'error'         => 'http: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : 'http_' . $code;
			return array(
				'ok'            => false,
				'subscriber_id' => null,
				'error'         => 'kit_' . $code . ': ' . $msg,
			);
		}

		$subscriber_id = null;
		if ( is_array( $json ) && isset( $json['subscription']['subscriber']['id'] ) ) {
			$subscriber_id = (string) $json['subscription']['subscriber']['id'];
		}

		// Apply tag if configured. A tag failure does not flip the overall
		// subscribe result — the subscriber is in Kit.com, which is the
		// minimum acceptable success state.
		$tag_id = LW_Audit_Settings::get( 'kit_tag_id' );
		if ( '' !== $tag_id ) {
			self::tag_subscriber( $email, $tag_id );
		}

		return array(
			'ok'            => true,
			'subscriber_id' => $subscriber_id,
			'error'         => null,
		);
	}

	/**
	 * Best-effort tag attach. Logged but not fatal.
	 */
	private static function tag_subscriber( $email, $tag_id ) {
		$api_key  = LW_Audit_Settings::get( 'kit_api_key' );
		$endpoint = self::API_BASE . '/tags/' . rawurlencode( $tag_id ) . '/subscribe';

		$response = wp_remote_post( $endpoint, array(
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( array(
				'api_key' => $api_key,
				'email'   => $email,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[lw-audit-store] tag attach failed: ' . $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[lw-audit-store] tag attach kit_' . $code );
		}
	}
}
