<?php
/**
 * Kit.com legacy V3 client.
 *
 * Single responsibility: subscribe an email + (optionally) tag it. Returns a
 * normalized result. Caller is responsible for persisting state in the audits
 * row; the client itself is stateless.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Kit_Client {

	const API_BASE_V3 = 'https://api.convertkit.com/v3';
	// 3s timeout keeps the inline /emails handler responsive. The hourly
	// cron picks up failures and retries up to 3 times before `kit_dead`.
	const TIMEOUT  = 3;

	/**
	 * Result shape returned from subscribe():
	 *   [ 'ok' => bool, 'subscriber_id' => string|null, 'error' => string|null ]
	 *
	 * @param string $email Already-validated email.
	 * @param array  $merge Optional custom fields { audit_url, audit_score, ... }.
	 * @return array
	 */
	public static function subscribe( $email, $merge = array() ) {
		$api_key = trim( LW_Audit_Settings::get( 'kit_v3_api_key' ) );
		$api_secret = trim( LW_Audit_Settings::get( 'kit_v3_api_secret' ) );
		$form_id = trim( LW_Audit_Settings::get( 'kit_form_id' ) );
		$tag_id = trim( LW_Audit_Settings::get( 'kit_tag_id' ) );

		if ( '' === $api_key || '' === $api_secret ) {
			return array(
				'ok'            => false,
				'subscriber_id' => null,
				'error'         => 'missing kit v3 api key or secret',
			);
		}

		if ( '' === $form_id && '' === $tag_id ) {
			return array(
				'ok'            => false,
				'subscriber_id' => null,
				'error'         => 'missing kit form id or tag id',
			);
		}

		$result = null;
		if ( '' !== $form_id ) {
			$result = self::subscribe_form_v3( $form_id, $email, $merge );
			if ( ! $result['ok'] ) {
				return array(
					'ok'            => false,
					'subscriber_id' => null,
					'error'         => $result['error'],
				);
			}
		}

		if ( '' !== $tag_id ) {
			$tag_result = self::subscribe_tag_v3( $tag_id, $email );
			if ( ! $tag_result['ok'] ) {
				if ( null === $result ) {
					return array(
						'ok'            => false,
						'subscriber_id' => null,
						'error'         => $tag_result['error'],
					);
				}
			}
		}

		$subscriber_id = self::extract_subscriber_id( null !== $result ? $result['json'] : null );

		return array(
			'ok'            => true,
			'subscriber_id' => $subscriber_id,
			'error'         => null,
		);
	}

	/**
	 * Legacy ConvertKit v3 tag subscribe.
	 *
	 * @param string $tag_id Kit tag ID.
	 * @param string $email  Subscriber email.
	 * @return array
	 */
	private static function subscribe_tag_v3( $tag_id, $email ) {
		$body = self::v3_auth_body( $email );

		return self::post_v3( '/tags/' . rawurlencode( $tag_id ) . '/subscribe', $body );
	}

	/**
	 * Legacy ConvertKit v3 form subscribe.
	 *
	 * @param string $form_id Kit form ID.
	 * @param string $email   Subscriber email.
	 * @param array  $merge   Optional custom fields.
	 * @return array
	 */
	private static function subscribe_form_v3( $form_id, $email, array $merge = array() ) {
		$body = self::v3_auth_body( $email );

		if ( ! empty( $merge ) && is_array( $merge ) ) {
			$body['fields'] = $merge;
		}

		return self::post_v3( '/forms/' . rawurlencode( $form_id ) . '/subscribe', $body );
	}

	/**
	 * Shared V3 auth/body fields.
	 *
	 * @param string $email Subscriber email.
	 * @return array
	 */
	private static function v3_auth_body( $email ) {
		return array(
			'api_key'    => trim( LW_Audit_Settings::get( 'kit_v3_api_key' ) ),
			'api_secret' => trim( LW_Audit_Settings::get( 'kit_v3_api_secret' ) ),
			'email'      => $email,
		);
	}

	/**
	 * Extract subscriber ID from common V3 response shapes.
	 *
	 * @param mixed $json Decoded response.
	 * @return string|null
	 */
	private static function extract_subscriber_id( $json ) {
		if ( ! is_array( $json ) ) {
			return null;
		}

		if ( isset( $json['subscription']['subscriber']['id'] ) ) {
			return (string) $json['subscription']['subscriber']['id'];
		}

		if ( isset( $json['subscriber']['id'] ) ) {
			return (string) $json['subscriber']['id'];
		}

		return null;
	}

	/**
	 * POST JSON to legacy ConvertKit v3.
	 *
	 * @param string $path API path beginning with a slash.
	 * @param array  $body JSON body.
	 * @return array
	 */
	private static function post_v3( $path, array $body ) {
		$response = wp_remote_post( self::API_BASE_V3 . $path, array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'json'   => null,
				'error'  => 'http: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'     => false,
				'status' => $code,
				'json'   => $json,
				'error'  => 'kit_v3_' . $code . ': ' . self::format_error_message( $json, $code ),
			);
		}

		return array(
			'ok'     => true,
			'status' => $code,
			'json'   => $json,
			'error'  => null,
		);
	}

	/**
	 * Extract Kit error shapes into a compact log string.
	 *
	 * @param mixed $json Decoded JSON response.
	 * @param int   $code HTTP status code.
	 * @return string
	 */
	private static function format_error_message( $json, $code ) {
		if ( is_array( $json ) ) {
			if ( isset( $json['errors'] ) && is_array( $json['errors'] ) ) {
				return implode( '; ', array_map( 'strval', $json['errors'] ) );
			}
			if ( isset( $json['message'] ) ) {
				return (string) $json['message'];
			}
		}

		return 'http_' . (int) $code;
	}

}
