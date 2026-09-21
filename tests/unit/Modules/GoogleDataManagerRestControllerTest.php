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
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Modules\GoogleDataManager\RestController;
use GTM4WP\Modules\GoogleDataManager\SendLog;
use GTM4WP\Options\Options;
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

	/**
	 * Jobs the replay tests saw queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = array();

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

		return new RestController( $this->vault, new EventsIngest( $tokens, $this->transport, $clock ), new SendLog() );
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

		$this->assertCount( 3, $captured );
		$this->assertSame( 'gtm4wp/v2', $captured[0]['ns'] );
		$this->assertSame( RestController::REST_ROUTE, $captured[0]['route'] );
		$this->assertSame( array( $controller, 'can_manage' ), $captured[0]['args']['permission_callback'] );
		$this->assertSame( 'POST', $captured[0]['args']['methods'] );
		$this->assertSame( array( $controller, 'test_destination' ), $captured[0]['args']['callback'] );

		// The args schema is defense-in-depth (the handler re-validates every
		// param itself - see invalid_requests()), but a dropped `required` or a
		// widened type would change what WordPress hands the handler, so the
		// whole block is pinned exactly, like the sibling's upload args (T77).
		$this->assertSame(
			array(
				DestinationRows::COLUMN_ACCOUNT     => array(
					'type'     => 'string',
					'required' => true,
				),
				DestinationRows::COLUMN_TYPE        => array(
					'type'     => 'string',
					'required' => true,
				),
				DestinationRows::COLUMN_PROPERTY    => array(
					'type'     => 'string',
					'required' => true,
				),
				DestinationRows::COLUMN_MEASUREMENT => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			$captured[0]['args']['args']
		);

		// The send log is a second route on the same controller, and it is the
		// one that returns stored content rather than probing. Its gate is
		// pinned here for the same reason as the probe's: it is read-only, but
		// what it returns names the store's own orders and refunds.
		$this->assertSame( 'gtm4wp/v2', $captured[1]['ns'] );
		$this->assertSame( RestController::LOG_ROUTE, $captured[1]['route'] );
		$this->assertSame( 'GET', $captured[1]['args']['methods'] );
		$this->assertSame( array( $controller, 'send_log' ), $captured[1]['args']['callback'] );
		$this->assertSame( array( $controller, 'can_manage' ), $captured[1]['args']['permission_callback'] );

		// The replay route queues work that ends in requests to Google, so its
		// gate is the one that matters most of the three. POST, same capability.
		$this->assertSame( 'gtm4wp/v2', $captured[2]['ns'] );
		$this->assertSame( RestController::REPLAY_ROUTE, $captured[2]['route'] );
		$this->assertSame( 'POST', $captured[2]['args']['methods'] );
		$this->assertSame( array( $controller, 'replay' ), $captured[2]['args']['callback'] );
		$this->assertSame( array( $controller, 'can_manage' ), $captured[2]['args']['permission_callback'] );
		// Pinned exactly like the first route's block (T94g): `references`
		// is a filter over stored entries, and a widened item type would hand
		// the handler shapes it only re-validates as strings.
		$this->assertSame(
			array(
				'references' => array(
					'type'     => 'array',
					'required' => false,
					'items'    => array( 'type' => 'string' ),
				),
			),
			$captured[2]['args']['args']
		);
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
	 * unexpected request, so the empty request log is a real assertion. Each
	 * case names the field its refusal message must mention: the message is
	 * the panel's only diagnostic, so "not valid" without the field would be
	 * a regression to the lump wording.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_requests(): array {
		return array(
			'malformed account'    => array( array( DestinationRows::COLUMN_ACCOUNT => '../etc/passwd' ), 'service account' ),
			'unknown type'         => array( array( DestinationRows::COLUMN_TYPE => 'google-ads' ), 'destination type' ),
			'non-numeric property' => array( array( DestinationRows::COLUMN_PROPERTY => 'UA-1' ), 'property ID' ),
			'hostile measurement'  => array( array( DestinationRows::COLUMN_MEASUREMENT => 'G-"</script>' ), 'measurement ID' ),
			'non-string values'    => array( array( DestinationRows::COLUMN_PROPERTY => array( 'nested' ) ), 'property ID' ),
		);
	}

	/**
	 * One invalid parameter at a time, each named in the refusal.
	 *
	 * @param array<string, mixed> $overrides      Parameter overrides.
	 * @param string               $named_field    Wording the message must carry for the failing field.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_requests' )]
	public function test_an_invalid_destination_is_refused_with_400_naming_the_field_and_no_request( array $overrides, string $named_field ): void {
		$id = $this->store_account();

		$result = $this->make_controller()->test_destination( self::request( $id, $overrides ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_gdm_destination_invalid', $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertStringContainsString( $named_field, $result->get_error_message() );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	/**
	 * Several mistakes at once are all named in one answer, and a field that
	 * is fine is not blamed - the pre-fix wording listed every field on every
	 * mistake, which is what made it useless as a diagnostic.
	 */
	public function test_a_destination_with_several_invalid_fields_names_each_and_only_them(): void {
		$id = $this->store_account();

		$result = $this->make_controller()->test_destination(
			self::request(
				$id,
				array(
					DestinationRows::COLUMN_PROPERTY    => '654987lll',
					DestinationRows::COLUMN_MEASUREMENT => 'Partner / Agency',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'property ID', $message );
		// The property sentence also says "not the ... measurement ID", so the
		// measurement problem is pinned by its own unique wording.
		$this->assertStringContainsString( 'format G-XXXXXXX', $message );
		$this->assertStringNotContainsString( 'service account', $message, 'The valid account is not blamed.' );
		$this->assertStringNotContainsString( 'destination type', $message, 'The valid type is not blamed.' );
	}

	public function test_a_well_formed_but_unknown_account_is_refused_with_404_and_no_request(): void {
		$result = $this->make_controller()->test_destination( self::request( 'sa_ffffffffffff' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	// ---- Google-side refusals ----------------------------------------------

	public function test_a_google_refusal_explains_the_status_and_keeps_the_original_reason(): void {
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
		// Plain-words explanation first, Google's own sentence kept for support.
		$this->assertStringContainsString( 'Editor role', $data['message'] );
		$this->assertStringContainsString( 'Data Manager API', $data['message'] );
		$this->assertStringContainsString( '(PERMISSION_DENIED: The caller does not have permission on the property.)', $data['message'] );
	}

	/**
	 * The reported case: a well-formed but non-existing property ID answers
	 * "NOT_FOUND: Requested entity was not found." - true and useless. The
	 * explanation must say what to double-check, including the access angle:
	 * Google reports a property the account may not see as not found too.
	 */
	public function test_a_not_found_refusal_says_what_to_double_check(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			404,
			array(
				'error' => array(
					'code'    => 404,
					'status'  => 'NOT_FOUND',
					'message' => 'Requested entity was not found.',
				),
			)
		);

		$data = $this->make_controller()->test_destination( self::request( $id ) )->get_data();

		$this->assertFalse( $data['ok'] );
		$this->assertStringContainsString( 'double-check both the GA4 property ID and the measurement ID', $data['message'] );
		$this->assertStringContainsString( 'service account was added to the property', $data['message'] );
		$this->assertStringContainsString( '(NOT_FOUND: Requested entity was not found.)', $data['message'] );
	}

	public function test_an_unmapped_status_still_reports_the_raw_summary(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			409,
			array(
				'error' => array(
					'code'    => 409,
					'status'  => 'ABORTED',
					'message' => 'Concurrency conflict.',
				),
			)
		);

		$data = $this->make_controller()->test_destination( self::request( $id ) )->get_data();

		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'ABORTED: Concurrency conflict.', $data['message'], 'A status with no mapped wording degrades to the raw summary, never to silence.' );
	}

	/**
	 * The error summary's sanitize + 200-char cap, driven hostile (T79): its
	 * sibling cap in DestinationHealth::record_failure() is hostile-pinned, and
	 * this sink feeds the same notice/health surfaces. The tag is written with
	 * \xNN escapes per TC-2 so no literal break-out chars sit in the source.
	 */
	public function test_a_hostile_oversized_error_message_is_stripped_and_capped(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			403,
			array(
				'error' => array(
					'code'    => 403,
					'status'  => 'PERMISSION_DENIED',
					'message' => "\x3Cscript\x3Ealert(1)\x3C/script\x3Estopped" . str_repeat( 'x', 500 ),
				),
			)
		);

		$response = $this->make_controller()->test_destination( self::request( $id ) );

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertStringNotContainsString( "\x3Cscript", $data['message'], 'The tag is stripped, not passed through.' );
		$this->assertStringContainsString( 'PERMISSION_DENIED: alert(1)stopped', $data['message'], 'The surviving text still reads as the refusal reason.' );
		// The 200-char cap governs the RAW portion (Google's text); the
		// translated explanation in front is the plugin's own. The raw part is
		// 34 chars of status+prefix, so at most 166 of the 500 padding
		// characters may survive into the message.
		$this->assertStringNotContainsString( str_repeat( 'x', 167 ), $data['message'], 'A raw body fragment cannot ride along past the cap.' );
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

	// ---- The send log route ------------------------------------------------

	public function test_the_send_log_route_hands_every_entry_its_tone(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_SKIPPED,
				'reason'      => 'consent_denied',
			)
		);
		$log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:35',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'status'      => 200,
				'request_id'  => 'req-42',
			)
		);

		$response = $this->make_controller()->send_log();
		$entries  = $response->get_data()['entries'];

		// Newest first: the accepted one, then the skipped one.
		$this->assertCount( 2, $entries );
		$this->assertSame( SendLog::TONE_PENDING, $entries[0]['tone'] );
		$this->assertSame( SendLog::TONE_WARN, $entries[1]['tone'] );

		// Derived, never stored: the ring itself keeps no such field.
		foreach ( $log->all() as $stored ) {
			$this->assertArrayNotHasKey( 'tone', $stored );
		}
	}

	public function test_the_send_log_route_still_carries_no_token_key_or_response_body(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_ACCEPTED,
				'status'      => 200,
				'request_id'  => 'req-42',
			)
		);

		$entries = $this->make_controller()->send_log()->get_data()['entries'];

		$this->assertSame(
			array( 'time', 'feature', 'reference', 'destination', 'outcome', 'attempt', 'status', 'request_id', 'reason', 'result', 'errors', 'warnings', 'tone', 'replayable' ),
			array_keys( $entries[0] ),
			'The route adds exactly two derived fields to what the ring stores, and nothing else joins it.'
		);
	}

	// ---- The replay route --------------------------------------------------

	/**
	 * A controller whose queue calls are captured, over a log seeded by the
	 * caller, with sending turned on unless said otherwise.
	 *
	 * @param SendLog              $log    The ring.
	 * @param array<string, mixed> $stored Stored option values.
	 * @return RestController
	 */
	private function replay_controller( SendLog $log, array $stored = array( GTM4WP_OPTION_GDM_SEND_REFUNDS => true ) ): RestController {
		$this->scheduled = array();

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $when, $hook, $args, $group ) {
				$this->scheduled[] = array(
					'hook'    => $hook,
					'payload' => $args[0],
				);
				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$clock  = static fn () => self::NOW;
		$tokens = new TokenService( $this->vault, $this->transport, $clock );

		// The option store trait serves get_option() from $this->options.
		$this->options['gtm4wp-options'] = $stored;
		$options                         = new Options( ( new GoogleDataManagerModule() )->defaults() );

		return new RestController(
			$this->vault,
			new EventsIngest( $tokens, $this->transport, $clock ),
			$log,
			$options,
			new DestinationHealth()
		);
	}

	/**
	 * A stored entry.
	 *
	 * @param array<string, mixed> $overrides Fields.
	 * @return array<string, mixed>
	 */
	private static function row( array $overrides ): array {
		return array_merge(
			array(
				'feature'     => SendLog::FEATURE_REFUND,
				'reference'   => 'woocommerce:12:34',
				'destination' => 'G-ABC123',
				'outcome'     => SendLog::OUTCOME_FAILED,
				'attempt'     => 6,
				'reason'      => 'PERMISSION_DENIED',
			),
			$overrides
		);
	}

	public function test_replay_queues_each_failed_refund_once_aimed_at_the_destinations_still_failing(): void {
		$log = new SendLog( static fn () => self::NOW );
		// Six attempts at one refund, two destinations: the ring holds twelve rows.
		for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
			$log->record(
				self::row(
					array(
						'attempt'     => $attempt,
						'destination' => 'G-ABC123',
						'outcome'     => $attempt < 6 ? SendLog::OUTCOME_RETRYING : SendLog::OUTCOME_FAILED,
					)
				)
			);
			$log->record(
				self::row(
					array(
						'attempt'     => $attempt,
						'destination' => 'G-XYZ789',
						'outcome'     => $attempt < 6 ? SendLog::OUTCOME_RETRYING : SendLog::OUTCOME_FAILED,
					)
				)
			);
		}

		$controller = $this->replay_controller( $log );

		$response = $controller->replay( new \WP_REST_Request() );

		$this->assertSame( 1, $response->get_data()['queued'], 'Twelve rows, one refund, one job.' );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame(
			array(
				'platform'  => 'woocommerce',
				'order_id'  => 12,
				'refund_id' => 34,
				'attempt'   => 1,
				'only'      => array( 'G-ABC123', 'G-XYZ789' ),
			),
			$this->scheduled[0]['payload']
		);
	}

	public function test_replay_leaves_out_a_destination_that_recovered_on_a_later_attempt(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'attempt'     => 1,
					'destination' => 'G-ABC123',
					'outcome'     => SendLog::OUTCOME_RETRYING,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'     => 1,
					'destination' => 'G-XYZ789',
					'outcome'     => SendLog::OUTCOME_RETRYING,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'     => 2,
					'destination' => 'G-ABC123',
					'outcome'     => SendLog::OUTCOME_ACCEPTED,
					'reason'      => '',
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'     => 6,
					'destination' => 'G-XYZ789',
					'outcome'     => SendLog::OUTCOME_FAILED,
				)
			)
		);

		$controller = $this->replay_controller( $log );
		$controller->replay( new \WP_REST_Request() );

		$this->assertSame( array( 'G-XYZ789' ), $this->scheduled[0]['payload']['only'], 'The destination Google accepted must not be sent the same refund again.' );
	}

	public function test_replay_queues_a_fixable_skip_whole_and_ignores_one_nothing_can_fix(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'reference'   => 'edd:3:4',
					'destination' => '',
					'outcome'     => SendLog::OUTCOME_SKIPPED,
					'reason'      => 'no_destination',
					'attempt'     => 1,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'reference'   => 'edd:5:6',
					'destination' => '',
					'outcome'     => SendLog::OUTCOME_SKIPPED,
					'reason'      => 'no_client_id',
					'attempt'     => 1,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'reference'   => 'edd:7:8',
					'destination' => '',
					'outcome'     => SendLog::OUTCOME_SKIPPED,
					'reason'      => 'consent_no_client_id',
					'attempt'     => 1,
				)
			)
		);

		$controller = $this->replay_controller( $log );
		$response   = $controller->replay( new \WP_REST_Request() );

		$this->assertSame( array( 'edd:3:4' ), $response->get_data()['references'] );
		$this->assertArrayNotHasKey( 'only', $this->scheduled[0]['payload'], 'A refund never sent anywhere is queued whole.' );
	}

	public function test_replay_can_be_limited_to_named_references(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record( self::row( array( 'reference' => 'woocommerce:1:2' ) ) );
		$log->record( self::row( array( 'reference' => 'woocommerce:3:4' ) ) );

		$controller = $this->replay_controller( $log );

		$response = $controller->replay(
			new \WP_REST_Request( array( 'references' => array( 'woocommerce:3:4', 'not a reference', 42 ) ) )
		);

		$this->assertSame( array( 'woocommerce:3:4' ), $response->get_data()['references'] );
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_replay_queues_nothing_when_nothing_needs_it(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'outcome' => SendLog::OUTCOME_ACCEPTED,
					'reason'  => '',
					'attempt' => 1,
				)
			)
		);

		$controller = $this->replay_controller( $log );
		$response   = $controller->replay( new \WP_REST_Request() );

		$this->assertSame( 0, $response->get_data()['queued'] );
		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * A refusal from the scheduler (Action Scheduler returns 0 for an action
	 * it would not take) must not be counted as queued: the panel reports the
	 * count back to the admin as "sent again" (T94g).
	 */
	public function test_a_refund_the_scheduler_refuses_is_not_counted_as_queued(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record( self::row( array() ) );

		$controller = $this->replay_controller( $log );
		Functions\when( 'as_schedule_single_action' )->justReturn( 0 );

		$response = $controller->replay( new \WP_REST_Request() );

		$this->assertSame( 0, $response->get_data()['queued'] );
		$this->assertSame( array(), $response->get_data()['references'] );
	}

	public function test_replay_refuses_while_sending_is_off(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record( self::row( array() ) );

		$controller = $this->replay_controller( $log, array( GTM4WP_OPTION_GDM_SEND_REFUNDS => false ) );
		$result     = $controller->replay( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->scheduled, 'A job queued now would be dropped by the sender with no row to say so.' );
	}

	// ---- What the rows say they can do ------------------------------------

	public function test_a_failure_a_later_success_overtook_stops_offering_a_replay(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'attempt' => 6,
					'outcome' => SendLog::OUTCOME_FAILED,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'    => 1,
					'outcome'    => SendLog::OUTCOME_ACCEPTED,
					'reason'     => '',
					'request_id' => 'req-replay',
				)
			)
		);

		$controller = $this->replay_controller( $log );
		$entries    = $controller->send_log()->get_data()['entries'];

		// Newest first: the accepted replay, then the failure it answered.
		$this->assertFalse( $entries[0]['replayable'] );
		$this->assertFalse(
			$entries[1]['replayable'],
			'The row is still a failure, but the refund has been sent since; offering a replay would queue nothing and say so - the screen should not ask the question at all.'
		);
		$this->assertSame( 0, $controller->replay( new \WP_REST_Request() )->get_data()['queued'], 'What the rows advertise and what the button does must agree.' );
	}

	public function test_only_the_newest_failed_row_per_destination_offers_a_replay(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'attempt' => 5,
					'outcome' => SendLog::OUTCOME_RETRYING,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt' => 6,
					'outcome' => SendLog::OUTCOME_FAILED,
				)
			)
		);

		$entries = $this->replay_controller( $log )->send_log()->get_data()['entries'];

		$this->assertTrue( $entries[0]['replayable'], 'The final failure is the row that stands for the refund.' );
		$this->assertFalse( $entries[1]['replayable'], 'An earlier attempt of the same refund is history, not a second thing to send.' );
	}

	public function test_a_skip_a_later_send_overtook_stops_offering_a_replay(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'destination' => '',
					'outcome'     => SendLog::OUTCOME_SKIPPED,
					'reason'      => 'no_destination',
					'attempt'     => 1,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'    => 1,
					'outcome'    => SendLog::OUTCOME_ACCEPTED,
					'reason'     => '',
					'request_id' => 'req-1',
				)
			)
		);

		$entries = $this->replay_controller( $log )->send_log()->get_data()['entries'];

		$this->assertFalse( $entries[1]['replayable'], 'The destination was added and the refund went through; the old skip is not a refund waiting.' );
	}

	public function test_a_destination_still_failing_offers_a_replay_while_its_sibling_does_not(): void {
		$log = new SendLog( static fn () => self::NOW );
		$log->record(
			self::row(
				array(
					'attempt'     => 6,
					'destination' => 'G-ABC123',
					'outcome'     => SendLog::OUTCOME_FAILED,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'     => 6,
					'destination' => 'G-XYZ789',
					'outcome'     => SendLog::OUTCOME_FAILED,
				)
			)
		);
		$log->record(
			self::row(
				array(
					'attempt'     => 1,
					'destination' => 'G-ABC123',
					'outcome'     => SendLog::OUTCOME_ACCEPTED,
					'reason'      => '',
					'request_id'  => 'req-r',
				)
			)
		);

		$entries = $this->replay_controller( $log )->send_log()->get_data()['entries'];
		$by_key  = array();

		foreach ( $entries as $entry ) {
			$by_key[ $entry['destination'] . '#' . $entry['outcome'] ] = $entry['replayable'];
		}

		$this->assertTrue( $by_key['G-XYZ789#failed'] );
		$this->assertFalse( $by_key['G-ABC123#failed'] );
		$this->assertFalse( $by_key['G-ABC123#accepted'] );
	}

	// ---- A passing test ends the failure streak ----------------------------

	public function test_a_passing_probe_clears_the_destinations_failure_streak(): void {
		$health = new DestinationHealth( static fn () => self::NOW );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );
		$this->assertTrue( $health->is_failing( 'G-ABC123' ) );

		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$clock      = static fn () => self::NOW;
		$controller = new RestController(
			$this->vault,
			new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock ),
			new SendLog(),
			null,
			$health
		);

		$response = $controller->test_destination( self::request( $id ) );

		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertFalse( $health->is_failing( 'G-ABC123' ), 'The probe proved the chain; the site-wide notice has nothing left to say.' );
		$this->assertSame( 0, $health->get( 'G-ABC123' )['consecutive_failures'] );
		$this->assertSame( 0, $health->get( 'G-ABC123' )['last_success'], 'A probe is not a send: it must not fake a success timestamp.' );
	}

	public function test_a_failing_probe_leaves_the_streak_alone(): void {
		$health = new DestinationHealth( static fn () => self::NOW );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );
		$health->record_failure( 'G-ABC123', 'NOT_FOUND', 'NOT_FOUND' );

		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			404,
			array(
				'error' => array(
					'status'  => 'NOT_FOUND',
					'message' => 'Property not found',
				),
			)
		);

		$clock      = static fn () => self::NOW;
		$controller = new RestController(
			$this->vault,
			new EventsIngest( new TokenService( $this->vault, $this->transport, $clock ), $this->transport, $clock ),
			new SendLog(),
			null,
			$health
		);

		$controller->test_destination( self::request( $id ) );

		$this->assertTrue( $health->is_failing( 'G-ABC123' ) );
	}
}
