<?php
/**
 * Unit tests for the destinations REST controller and its ingest probe.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Modules\GoogleDataManager\RestController;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The validateOnly test route. Three lenses:
 *
 * - Access control (TS-12/TC-13): the same grant / deny / filtered-capability
 *   triple as every other route of the plugin.
 * - Contract (the .upstream silent-drift lens): the EXACT JSON body of the
 *   probe - the events:ingest request shape (U123) and the GA Destination
 *   shape (U124) fail silently upstream if a field name drifts, so the
 *   recording fake pins every byte we send.
 * - Custody: the response reports success or Google's reason and never a
 *   token.
 */
final class GoogleDataManagerRestControllerTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET    = 'unit-test-site-secret-do-not-reuse';
	private const FILTER    = 'gtm4wp_admin_page_capability';
	private const CUSTOMCAP = 'manage_gtm4wp';
	private const NOW       = 1_800_000_000;
	private const TOKEN     = 'ya29.unit-test-access-token';

	private KeyVault $vault;

	private FakeTransport $transport;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->stub_option_store();

		$this->vault     = new KeyVault( self::SECRET );
		$this->transport = new FakeTransport();
	}

	/**
	 * The controller over the shared vault and fake transport, with pinned time.
	 *
	 * @return RestController
	 */
	private function make_controller(): RestController {
		$clock  = static fn () => self::NOW;
		$tokens = new TokenService( $this->vault, $this->transport, $clock );

		return new RestController( $this->vault, new EventsIngest( $tokens, $this->transport, $clock ) );
	}

	/**
	 * Stores the fixture key and returns its account id.
	 *
	 * @return string
	 */
	private function store_account(): string {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		return $id;
	}

	/**
	 * Queues a successful token exchange.
	 *
	 * @return void
	 */
	private function queue_token(): void {
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => self::TOKEN,
				'expires_in'   => 3600,
			)
		);
	}

	/**
	 * A request for the test route.
	 *
	 * @param string               $account_id Account id.
	 * @param array<string, mixed> $overrides  Parameter overrides.
	 * @return \WP_REST_Request
	 */
	private static function request( string $account_id, array $overrides = array() ): \WP_REST_Request {
		return new \WP_REST_Request(
			array_merge(
				array(
					DestinationRows::COLUMN_ACCOUNT     => $account_id,
					DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
					DestinationRows::COLUMN_PROPERTY    => '123456789',
					DestinationRows::COLUMN_MEASUREMENT => 'G-ABC123',
				),
				$overrides
			)
		);
	}

	// ---- Access control ----------------------------------------------------

	public function test_can_manage_checks_manage_options_by_default(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( $this->make_controller()->can_manage() );
	}

	public function test_can_manage_grants_when_the_filtered_capability_is_held(): void {
		Filters\expectApplied( self::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( true );

		$this->assertTrue( $this->make_controller()->can_manage() );
	}

	public function test_can_manage_denies_when_the_filtered_capability_is_missing(): void {
		Filters\expectApplied( self::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( false );

		$this->assertFalse( $this->make_controller()->can_manage() );
	}

	public function test_the_route_is_registered_with_can_manage_as_its_gate(): void {
		$captured = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$captured ) {
				$captured[] = array(
					'ns'    => $ns,
					'route' => $route,
					'args'  => $args,
				);
				return true;
			}
		);

		$controller = $this->make_controller();
		$controller->register_routes();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'gtm4wp/v2', $captured[0]['ns'] );
		$this->assertSame( RestController::REST_ROUTE, $captured[0]['route'] );
		$this->assertSame( array( $controller, 'can_manage' ), $captured[0]['args']['permission_callback'] );
		$this->assertSame( 'POST', $captured[0]['args']['methods'] );
	}

	// ---- Contract ----------------------------------------------------------

	/**
	 * The whole wire format of the probe, byte for byte: a drifted field name
	 * here fails silently at Google (U123/U124), so nothing about this body
	 * is asserted loosely.
	 */
	public function test_the_probe_posts_the_exact_validate_only_ingest_request(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['ok'] );

		$this->assertCount( 2, $this->transport->requests, 'One token exchange, one ingest probe.' );

		$probe = $this->transport->requests[1];
		$this->assertSame( 'POST_JSON', $probe['method'] );
		$this->assertSame( EventsIngest::ENDPOINT, $probe['url'] );
		$this->assertSame( array( 'Authorization' => 'Bearer ' . self::TOKEN ), $probe['headers'] );
		$this->assertSame(
			array(
				'destinations' => array(
					array(
						'operatingAccount'     => array(
							'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
							'accountId'   => '123456789',
						),
						'productDestinationId' => 'G-ABC123',
					),
				),
				'events'       => array(
					array(
						'eventName'      => 'refund',
						'eventTimestamp' => '2027-01-15T08:00:00Z',
						'transactionId'  => 'GTM4WP-VALIDATE',
						'clientId'       => '1000000000.1000000000',
						'eventSource'    => 'WEB',
					),
				),
				'validateOnly' => true,
			),
			$probe['body']
		);
	}

	public function test_a_lowercase_measurement_id_is_normalized_before_it_is_sent(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$this->make_controller()->test_destination(
			self::request( $id, array( DestinationRows::COLUMN_MEASUREMENT => ' g-abc123 ' ) )
		);

		$this->assertSame( 'G-ABC123', $this->transport->requests[1]['body']['destinations'][0]['productDestinationId'] );
	}

	// ---- Refusals before any request --------------------------------------

	/**
	 * Invalid parameters never reach the network - the fake would throw on an
	 * unexpected request, so the empty request log is a real assertion.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalid_requests(): array {
		return array(
			'malformed account'    => array( array( DestinationRows::COLUMN_ACCOUNT => '../etc/passwd' ) ),
			'unknown type'         => array( array( DestinationRows::COLUMN_TYPE => 'google-ads' ) ),
			'non-numeric property' => array( array( DestinationRows::COLUMN_PROPERTY => 'UA-1' ) ),
			'hostile measurement'  => array( array( DestinationRows::COLUMN_MEASUREMENT => 'G-"</script>' ) ),
			'non-string values'    => array( array( DestinationRows::COLUMN_PROPERTY => array( 'nested' ) ) ),
		);
	}

	/**
	 * One invalid parameter at a time.
	 *
	 * @param array<string, mixed> $overrides Parameter overrides.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_requests' )]
	public function test_an_invalid_destination_is_refused_with_400_and_no_request( array $overrides ): void {
		$id = $this->store_account();

		$result = $this->make_controller()->test_destination( self::request( $id, $overrides ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_destination_invalid', $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	public function test_a_well_formed_but_unknown_account_is_refused_with_404_and_no_request(): void {
		$result = $this->make_controller()->test_destination( self::request( 'sa_ffffffffffff' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	// ---- Google-side refusals ----------------------------------------------

	public function test_a_google_refusal_reports_the_summarized_reason(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			403,
			array(
				'error' => array(
					'code'    => 403,
					'status'  => 'PERMISSION_DENIED',
					'message' => 'The caller does not have permission on the property.',
				),
			)
		);

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'PERMISSION_DENIED: The caller does not have permission on the property.', $data['message'] );
	}

	public function test_a_refusal_without_an_error_envelope_reports_the_http_status(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 500, null );

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertStringContainsString( '500', $data['message'] );
	}

	public function test_a_refused_token_exchange_surfaces_as_the_failure_reason(): void {
		$id = $this->store_account();
		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Invalid JWT signature.',
			)
		);

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'invalid_grant: Invalid JWT signature.', $data['message'] );
		$this->assertCount( 1, $this->transport->requests, 'No ingest probe follows a refused token exchange.' );
	}

	public function test_a_transport_failure_surfaces_as_the_failure_reason(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond( new \WP_Error( 'http_request_failed', 'Could not resolve host.' ) );

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'Could not resolve host.', $data['message'] );
	}

	// ---- Custody -----------------------------------------------------------

	public function test_no_response_carries_the_access_token(): void {
		$id = $this->store_account();

		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );
		$ok = $this->make_controller()->test_destination( self::request( $id ) );

		$this->queue_token();
		$this->transport->will_respond_json( 403, array( 'error' => array( 'message' => 'denied' ) ) );
		$refused = $this->make_controller()->test_destination( self::request( $id ) );

		foreach ( array( $ok, $refused ) as $response ) {
			$flat = serialize( $response->get_data() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.
			$this->assertStringNotContainsString( self::TOKEN, $flat, 'No access token.' );
			$this->assertStringNotContainsString( 'BEGIN', $flat, 'No PEM.' );
		}
	}
}
