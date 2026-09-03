<?php
/**
 * Unit tests for the WordPress HTTP API transport.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use Brain\Monkey\Functions;
use GTM4WP\Google\WpTransport;
use GTM4WP\Tests\unit\TestCase;

/**
 * WpTransport is the SSRF guard of the Google integration: whatever a caller
 * asks for, only https requests to the allow-listed Google hosts leave the
 * site. The deny direction is asserted BEFORE the HTTP function is reached -
 * wp_remote_post() is set to fail the test if called - so a refused URL is
 * refused by the seam, not by a mocked network (TS-12).
 *
 * The request arguments are pinned exactly: a redirect that is followed, a
 * missing timeout or a dropped Content-Type are silent regressions.
 */
final class WpTransportTest extends TestCase {

	/**
	 * Every wp_remote_get()/wp_remote_post() call: function, URL and args.
	 *
	 * @var array<int, array{fn: string, url: string, args: array}>
	 */
	private array $sent = array();

	/**
	 * What the HTTP function returns next.
	 *
	 * @var mixed
	 */
	private $http_response;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $response ) => is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $response ) => is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : ''
		);

		$this->sent          = array();
		$this->http_response = self::http_ok( '{"ok":true}' );

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args = array() ) {
				$this->sent[] = array(
					'fn'   => 'wp_remote_post',
					'url'  => $url,
					'args' => $args,
				);
				return $this->http_response;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) {
				$this->sent[] = array(
					'fn'   => 'wp_remote_get',
					'url'  => $url,
					'args' => $args,
				);
				return $this->http_response;
			}
		);
	}

	/**
	 * A WP HTTP API response array.
	 *
	 * @param string $body Raw body.
	 * @param int    $code Status code.
	 * @return array<string, mixed>
	 */
	private static function http_ok( string $body, int $code = 200 ): array {
		return array(
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
		);
	}

	// ---- The allow-list ----------------------------------------------------

	/**
	 * URLs the transport must send to.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function allowed_urls(): array {
		return array(
			'token endpoint'      => array( 'https://oauth2.googleapis.com/token' ),
			'data manager ingest' => array( 'https://datamanager.googleapis.com/v1/audienceMembers:ingest' ),
			'data manager events' => array( 'https://datamanager.googleapis.com/v1/events:ingest' ),
			'with a query string' => array( 'https://datamanager.googleapis.com/v1/requestStatus:retrieve?requestId=abc' ),
		);
	}

	/**
	 * URLs the transport must refuse. The interesting ones are the lookalikes:
	 * the allowed host as a subdomain, as a userinfo part, as a path, and the
	 * plain-http form of an allowed URL.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_urls(): array {
		return array(
			'another host'                => array( 'https://example.com/token' ),
			'http, not https'             => array( 'http://oauth2.googleapis.com/token' ),
			'allowed host as a subdomain' => array( 'https://oauth2.googleapis.com.evil.example/token' ),
			'allowed host as a prefix'    => array( 'https://oauth2.googleapis.com-evil.example/token' ),
			'allowed host in userinfo'    => array( 'https://oauth2.googleapis.com@evil.example/token' ),
			'allowed host in the path'    => array( 'https://evil.example/oauth2.googleapis.com/token' ),
			'a sibling google host'       => array( 'https://www.googleapis.com/oauth2/v4/token' ),
			'the bare host'               => array( 'https://googleapis.com/token' ),
			'loopback'                    => array( 'https://127.0.0.1/token' ),
			'a relative path'             => array( '/token' ),
			'garbage'                     => array( 'not a url' ),
			'empty'                       => array( '' ),
		);
	}

	/**
	 * Allowed URLs pass the check.
	 *
	 * @param string $url The URL.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'allowed_urls' )]
	public function test_is_allowed_url_accepts_https_on_the_google_hosts( string $url ): void {
		$this->assertTrue( WpTransport::is_allowed_url( $url ) );
	}

	/**
	 * Refused URLs fail the check.
	 *
	 * @param string $url The URL.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_urls' )]
	public function test_is_allowed_url_refuses_everything_else( string $url ): void {
		$this->assertFalse( WpTransport::is_allowed_url( $url ) );
	}

	/**
	 * A refused URL never reaches the HTTP API - on any of the three methods.
	 *
	 * @param string $url The URL.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_urls' )]
	public function test_a_refused_url_is_rejected_before_any_request_is_made( string $url ): void {
		$transport = new WpTransport();

		$results = array(
			$transport->post_form( $url, array( 'a' => 'b' ) ),
			$transport->post_json( $url, array( 'a' => 'b' ) ),
			$transport->get( $url ),
		);

		foreach ( $results as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'gtm4wp_google_transport_refused', $result->get_error_code() );
		}
		$this->assertSame( array(), $this->sent, 'No socket is opened for a refused URL.' );
	}

	// ---- The request -------------------------------------------------------

	public function test_post_form_sends_an_rfc3986_encoded_form_body_with_the_fixed_request_arguments(): void {
		$result = ( new WpTransport() )->post_form(
			'https://oauth2.googleapis.com/token',
			array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => 'a.b.c-_ +',
			)
		);

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'wp_remote_post', $this->sent[0]['fn'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $this->sent[0]['url'] );
		$this->assertSame(
			array(
				'method'      => 'POST',
				'timeout'     => WpTransport::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => 'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=a.b.c-_%20%2B',
			),
			$this->sent[0]['args'],
			'No redirects (a 3xx to another host would otherwise be followed), a bounded timeout, and RFC 3986 encoding (space is %20, never +).'
		);

		$this->assertSame(
			array(
				'status' => 200,
				'body'   => array( 'ok' => true ),
			),
			$result
		);
	}

	public function test_post_json_sends_the_encoded_body_and_the_json_content_type(): void {
		( new WpTransport() )->post_json(
			'https://datamanager.googleapis.com/v1/events:ingest',
			array( 'destinations' => array( array( 'productDestinationId' => '123' ) ) ),
			array( 'Authorization' => 'Bearer ya29.x' )
		);

		$args = $this->sent[0]['args'];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( '{"destinations":[{"productDestinationId":"123"}]}', $args['body'] );
		$this->assertSame(
			array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ya29.x',
				'Content-Type'  => 'application/json',
			),
			$args['headers'],
			'Caller headers ride along; the content type is the transport\'s and cannot be overridden by the caller.'
		);
	}

	public function test_get_sends_no_body(): void {
		( new WpTransport() )->get( 'https://datamanager.googleapis.com/v1/requestStatus:retrieve?requestId=abc', array( 'Authorization' => 'Bearer ya29.x' ) );

		$this->assertSame( 'wp_remote_get', $this->sent[0]['fn'] );
		$this->assertArrayNotHasKey( 'body', $this->sent[0]['args'] );
		$this->assertSame( 'GET', $this->sent[0]['args']['method'] );
		$this->assertSame( 0, $this->sent[0]['args']['redirection'] );
	}

	// ---- The response ------------------------------------------------------

	public function test_a_non_json_body_decodes_to_null_with_the_status_kept(): void {
		$this->http_response = self::http_ok( '<html>Bad Gateway</html>', 502 );

		$result = ( new WpTransport() )->get( 'https://datamanager.googleapis.com/v1/x' );

		$this->assertSame(
			array(
				'status' => 502,
				'body'   => null,
			),
			$result,
			'The raw body is never returned: a caller sees decoded JSON or nothing.'
		);
	}

	public function test_a_json_scalar_body_decodes_to_null(): void {
		$this->http_response = self::http_ok( '"just a string"', 200 );

		$this->assertNull( ( new WpTransport() )->get( 'https://datamanager.googleapis.com/v1/x' )['body'] );
	}

	public function test_a_wp_error_from_the_http_api_is_passed_through(): void {
		$this->http_response = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		$result = ( new WpTransport() )->post_form( 'https://oauth2.googleapis.com/token', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_a_body_that_cannot_be_json_encoded_is_refused_before_sending(): void {
		$result = ( new WpTransport() )->post_json( 'https://datamanager.googleapis.com/v1/x', array( 'bad' => "\xB1\x31" ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_transport_encode', $result->get_error_code() );
		$this->assertSame( array(), $this->sent );
	}
}
