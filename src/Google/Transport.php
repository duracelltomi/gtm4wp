<?php
/**
 * HTTP transport seam for the Google API clients.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * The single seam through which the plugin talks to Google's HTTP APIs.
 *
 * Production uses WpTransport (wp_remote_* underneath); unit tests inject a
 * fake that asserts the request shape it receives. Every method returns
 * either a decoded response - `status` plus the JSON-decoded `body` (null
 * when the body was not JSON) - or a WP_Error for a transport-level failure
 * (DNS, TLS, timeout, refused URL). The raw body is deliberately not part of
 * the contract: an error body can echo request fragments, and nothing on the
 * plugin side may log or store it.
 */
interface Transport {

	/**
	 * Sends an application/x-www-form-urlencoded POST.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, string> $fields  Form fields.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function post_form( string $url, array $fields, array $headers = array() ): array|\WP_Error;

	/**
	 * Sends an application/json POST.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, mixed>  $body    Request body, JSON encoded by the transport.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function post_json( string $url, array $body, array $headers = array() ): array|\WP_Error;

	/**
	 * Sends a GET.
	 *
	 * @param string                $url     Absolute URL of one of the fixed endpoints.
	 * @param array<string, string> $headers Extra request headers.
	 * @return array{status: int, body: array|null}|\WP_Error
	 */
	public function get( string $url, array $headers = array() ): array|\WP_Error;
}
