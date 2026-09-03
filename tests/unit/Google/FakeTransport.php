<?php
/**
 * Test double for the Google HTTP transport seam.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use GTM4WP\Google\Transport;

/**
 * Records every request and answers from a queue of canned responses.
 *
 * It records the FULL request - method, URL, fields/body, headers - so a test
 * can pin the request shape the way the token endpoint expects it. A stand-in
 * that swallowed those arguments would keep the suite green while the wire
 * format drifted (UC-3).
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
	public function post_form( string $url, array $fields, array $headers = array() ) {
		return $this->record( 'POST_FORM', $url, $fields, null, $headers );
	}

	/**
	 * {@inheritDoc}
	 */
	public function post_json( string $url, array $body, array $headers = array() ) {
		return $this->record( 'POST_JSON', $url, null, $body, $headers );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $url, array $headers = array() ) {
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
	 * @throws \LogicException When the test queued no response for this request.
	 */
	private function record( string $method, string $url, ?array $fields, ?array $body, array $headers ) {
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
