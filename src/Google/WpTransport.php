<?php
/**
 * WordPress HTTP API transport.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * Transport implementation on top of wp_remote_post() / wp_remote_get().
 *
 * The host allow-list is the SSRF guard of the whole Google integration: no
 * user-supplied URL exists anywhere in the callers by design, and this class
 * makes that a property of the seam rather than of every caller's discipline.
 * A request to any other host is refused before a socket is opened.
 */
final class WpTransport implements Transport {

	/**
	 * Hosts the plugin may talk to (exact match, https only); the TokenService
	 * suite asserts the token endpoint's host against this list.
	 *
	 * @var string[]
	 */
	public const ALLOWED_HOSTS = array(
		'oauth2.googleapis.com',
		'datamanager.googleapis.com',
	);

	/**
	 * Request timeout in seconds; bounds how long an admin action or a queued
	 * job can hang on a stalled connection.
	 */
	public const TIMEOUT = 15;

	/**
	 * Nesting depth json_decode() accepts for a response body; bounds decoder recursion.
	 */
	private const JSON_MAX_DEPTH = 16;

	/**
	 * Sends an application/x-www-form-urlencoded POST.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, string> $fields  Form fields.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function post_form( string $url, array $fields, array $headers = array() ): array|\WP_Error {
		return $this->send(
			$url,
			'POST',
			array_merge( $headers, array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ),
			http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 )
		);
	}

	/**
	 * Sends an application/json POST.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, mixed>  $body    Request body, JSON encoded here.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function post_json( string $url, array $body, array $headers = array() ): array|\WP_Error {
		$encoded = wp_json_encode( $body );
		if ( false === $encoded ) {
			return new \WP_Error(
				'gtm4wp_google_transport_encode',
				__( 'The request to Google could not be encoded.', 'duracelltomi-google-tag-manager' )
			);
		}

		return $this->send(
			$url,
			'POST',
			array_merge( $headers, array( 'Content-Type' => 'application/json' ) ),
			$encoded
		);
	}

	/**
	 * Sends a GET.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function get( string $url, array $headers = array() ): array|\WP_Error {
		return $this->send( $url, 'GET', $headers, null );
	}

	/**
	 * Whether a URL is one the transport will send to: https, an allow-listed
	 * host, and no explicit port (the fixed Google endpoints are all on 443,
	 * so a port suffix is never a URL our own callers construct).
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ( 'https' === ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['port'] )
			&& in_array( $parts['host'] ?? '', self::ALLOWED_HOSTS, true );
	}

	/**
	 * Performs the request through the WordPress HTTP API.
	 *
	 * @param string                $url     Absolute URL.
	 * @param string                $method  HTTP method.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Encoded request body, null for GET.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	private function send( string $url, string $method, array $headers, ?string $body ): array|\WP_Error {
		if ( ! self::is_allowed_url( $url ) ) {
			return new \WP_Error(
				'gtm4wp_google_transport_refused',
				__( 'The plugin only sends requests to Google API endpoints.', 'duracelltomi-google-tag-manager' )
			);
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = ( 'GET' === $method ) ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true, self::JSON_MAX_DEPTH );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : null,
		);
	}
}
