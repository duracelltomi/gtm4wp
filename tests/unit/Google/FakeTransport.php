<?php
/**
 * Test double for the Google HTTP transport seam.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use GTM4WP\Google\Transport;
use GTM4WP\Google\WpTransport;

/**
 * Records every request and answers from a queue of canned responses.
 *
 * It records the FULL request - method, URL, fields/body, headers - so a test
 * can pin the request shape the way the token endpoint expects it. A stand-in
 * that swallowed those arguments would keep the suite green while the wire
 * format drifted (UC-3).
 *
 * It is also no more permissive than the real transport (TS-13): a URL the
 * real WpTransport would refuse throws here instead of being answered, so a
 * caller with a dynamic URL cannot pass over the fake what production would
 * refuse. The check is the real WpTransport::is_allowed_url() - one
 * definition - which reads wp_parse_url(), so a test whose flow reaches the
 * fake must stub that in its own setUp (TS-16).
 */
final class FakeTransport implements Transport {

	/**
	 * Every request made, in order.
	 *
	 * @var array<int, array{method: string, url: string, fields: array|null, body: array|null, headers: array}>
	 */
	public array $requests = array();

	/**
	 * Queued responses, shifted off per request. Each is the array or WP_Error
	 * the transport contract returns.
	 *
	 * @var array<int, array|\WP_Error>
	 */
	private array $responses = array();

	/**
	 * Queues a response for the next request.
	 *
	 * @param array|\WP_Error $response Transport-shaped response.
	 * @return self
	 */
	public function will_respond( $response ): self {
		$this->responses[] = $response;

		return $this;
	}

	/**
	 * Queues a decoded JSON response with a status.
	 *
	 * @param int        $status HTTP status.
	 * @param array|null $body   Decoded body.
	 * @return self
	 */
	public function will_respond_json( int $status, ?array $body ): self {
		return $this->will_respond(
			array(
				'status' => $status,
				'body'   => $body,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_form( string $url, array $fields, array $headers = array() ): array|\WP_Error {
		return $this->record( 'POST_FORM', $url, $fields, null, $headers );
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_json( string $url, array $body, array $headers = array() ): array|\WP_Error {
		self::refuse_multiple_ga4_destinations( $body );

		return $this->record( 'POST_JSON', $url, null, $body, $headers );
	}

	/**
	 * Google refuses a whole ingest request in which one event reaches two or
	 * more Google Analytics destinations (U125); answering it here instead
	 * kept the 2.1 refund lane green while every such send failed.
	 *
	 * @param array $body JSON body.
	 * @return void
	 * @throws \LogicException When an event targets 2+ GA4 destinations.
	 */
	private static function refuse_multiple_ga4_destinations( array $body ): void {
		$ga4 = array();

		foreach ( (array) ( $body['destinations'] ?? array() ) as $i => $destination ) {
			if ( 'GOOGLE_ANALYTICS_PROPERTY' === ( $destination['operatingAccount']['accountType'] ?? '' ) ) {
				$ga4[] = (string) ( $destination['reference'] ?? '#' . $i );
			}
		}

		foreach ( (array) ( $body['events'] ?? array() ) as $event ) {
			$refs    = (array) ( $event['destinationReferences'] ?? array() );
			$targets = array() === $refs ? $ga4 : array_intersect( $ga4, $refs );

			if ( count( $targets ) > 1 ) {
				throw new \LogicException( 'FakeTransport: Google refuses this request (MULTIPLE_DESTINATIONS_FOR_GOOGLE_ANALYTICS_EVENT).' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $url, array $headers = array() ): array|\WP_Error {
		return $this->record( 'GET', $url, null, null, $headers );
	}

	/**
	 * Records the request and pops the next response.
	 *
	 * @param string     $method  Transport method.
	 * @param string     $url     URL.
	 * @param array|null $fields  Form fields.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Headers.
	 * @return array|\WP_Error
	 * @throws \LogicException When the test queued no response for this request,
	 *                         or the real transport would have refused the URL.
	 */
	private function record( string $method, string $url, ?array $fields, ?array $body, array $headers ) {
		if ( ! WpTransport::is_allowed_url( $url ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
			throw new \LogicException( 'FakeTransport received a URL the real transport would refuse: ' . $method . ' ' . $url );
		}

		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'fields'  => $fields,
			'body'    => $body,
			'headers' => $headers,
		);

		if ( array() === $this->responses ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
			throw new \LogicException( 'FakeTransport received a request it had no response queued for: ' . $method . ' ' . $url );
		}

		return array_shift( $this->responses );
	}
}
