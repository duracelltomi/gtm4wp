<?php
/**
 * Unit tests for EDD reliable purchase tracking under the cache-safe data layer.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\EasyDigitalDownloads\ReliablePurchase;
use GTM4WP\Modules\VisitorData\VisitorDataEndpoint;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- the tests set and snapshot the superglobals the code under test reads.

/**
 * Covers ReliablePurchase: when the one-shot is declared, the read-only
 * resolver's identity gate (PA-10) and dedupe gauntlet, the pending re-check,
 * the confirm beacon (FP-5 gate, flag-once) and the event cookie.
 */
final class EddReliablePurchaseTest extends TestCase {

	/**
	 * Options with every precondition of the fallback on.
	 */
	private const ALL_ON = array(
		GTM4WP_OPTION_CACHE_SAFE_DATALAYER        => true,
		GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => true,
		GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => true,
	);

	/**
	 * The purchase key in the buyer's own EDD purchase session.
	 */
	private const SESSION_KEY = 'pk_own_session_key';

	/**
	 * Captured setcookie() calls as array( name, value, options ).
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	private array $cookie_writes = array();

	/**
	 * Captured edd_update_order_meta() calls as array( id, key, value ).
	 *
	 * @var array<int, array{0:mixed,1:mixed,2:mixed}>
	 */
	private array $meta_writes = array();

	/**
	 * Captured edd_get_order_by() lookups as array( field, value ).
	 *
	 * @var array<int, array{0:mixed,1:mixed}>
	 */
	private array $order_lookups = array();

	/**
	 * Snapshots of the superglobals, restored in tearDown (TS-7).
	 *
	 * @var array<string, array>
	 */
	private array $backup = array();

	/**
	 * Origin the stubbed get_http_origin() reports.
	 *
	 * @var string
	 */
	private string $stub_origin = '';

	protected function setUp(): void {
		parent::setUp();

		$this->backup = array(
			'cookie' => $_COOKIE,
			'get'    => $_GET,
			'server' => $_SERVER,
		);
		$_COOKIE      = array();
		$_GET         = array();
		unset( $_SERVER['HTTP_REFERER'] );

		$this->cookie_writes = array();
		$this->meta_writes   = array();
		$this->order_lookups = array();
		$this->stub_origin   = '';

		// TS-16: everything this file reaches is stubbed here.
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			static fn ( $data, $flags = 0 ) => json_encode( $data, $flags )
		);
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'yoast_get_primary_term_id' )->justReturn( false );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'get_term_parents_list' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'edd_get_currency' )->justReturn( 'USD' );
		Functions\when( 'edd_get_download_sku' )->justReturn( false );
		Functions\when( 'edd_get_payment_meta' )->justReturn( null );
		Functions\when( 'edd_get_order_meta' )->justReturn( '' );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);
		Functions\when( 'edd_update_order_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta_writes[] = array( $id, $key, $value );
				return true;
			}
		);
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => self::SESSION_KEY ) );
		$this->set_order( $this->make_order() );

		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://shop.example/wp-json/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '', $options = array() ) {
				$this->cookie_writes[] = array( (string) $name, (string) $value, (array) $options );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_COOKIE = $this->backup['cookie'];
		$_GET    = $this->backup['get'];
		$_SERVER = $this->backup['server'];

		parent::tearDown();
	}

	/**
	 * Builds a ReliablePurchase over the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ReliablePurchase
	 */
	private function make( array $stored = self::ALL_ON ): ReliablePurchase {
		Functions\when( 'get_option' )->justReturn( $stored );

		$options = new Options( ( new EasyDigitalDownloadsModule() )->defaults() );

		return new ReliablePurchase( $options, new DownloadData( $options ) );
	}

	/**
	 * A complete order belonging to the session key, with a sequential order
	 * number distinct from its id (RI-14: the guard stores the NUMBER).
	 *
	 * @param array<string, mixed> $data Order data overrides.
	 * @return \EDD\Orders\Order
	 */
	private function make_order( array $data = array() ): \EDD\Orders\Order {
		return new \EDD\Orders\Order(
			array_merge(
				array(
					'id'           => 77,
					'order_number' => 'EDD-0077',
					'status'       => 'complete',
					'currency'     => 'USD',
					'tax'          => 2.0,
					'total'        => 38.0,
					'email'        => 'buyer@example.com',
					'customer_id'  => 0,
					'payment_key'  => self::SESSION_KEY,
					'date_created' => gmdate( 'Y-m-d H:i:s' ),
					'items'        => array(
						new \EDD\Orders\Order_Item(
							array(
								'product_id' => 55,
								'quantity'   => 2,
								'tax'        => 2.0,
								'total'      => 38.0,
							)
						),
					),
				),
				$data
			)
		);
	}

	/**
	 * Makes edd_get_order_by() return the given order for the session key only.
	 *
	 * @param \EDD\Orders\Order|null $order The order, or null for none.
	 * @return void
	 */
	private function set_order( ?\EDD\Orders\Order $order ): void {
		Functions\when( 'edd_get_order_by' )->alias(
			function ( $field, $value ) use ( $order ) {
				$this->order_lookups[] = array( $field, $value );
				return ( 'payment_key' === $field && self::SESSION_KEY === $value ) ? $order : false;
			}
		);
	}

	/**
	 * Stubs the URL helpers RequestOrigin reads.
	 *
	 * @return void
	 */
	private function stub_origin_helpers(): void {
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url, $component = -1 ) => parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		);
		Functions\when( 'get_http_origin' )->alias( fn () => $this->stub_origin );
		Functions\when( 'esc_url_raw' )->alias( static fn ( $value ) => (string) $value );
	}

	public function test_declares_one_cookie_gated_one_shot_with_its_confirm_beacon(): void {
		$fields = $this->make()->declare_visitor_scoped_fields( array() );

		$this->assertCount( 1, $fields );
		$field = $fields[0];
		$this->assertSame( ReliablePurchase::FIELD_KEY, $field->key );
		$this->assertSame( VisitorField::TIER_ACTION, $field->tier );
		$this->assertSame( ReliablePurchase::EVENT_COOKIE, $field->cookie_gate );
		$this->assertTrue( $field->one_shot );
		$this->assertFalse( $field->block );
		$this->assertSame(
			'https://shop.example/wp-json/' . ltrim( VisitorDataEndpoint::REST_NAMESPACE . ReliablePurchase::REST_ROUTE_CONFIRM, '/' ),
			$field->confirm_url
		);
		$this->assertNotSame( 'pendingPurchase', $field->key, 'WooCommerce owns that key; the endpoint merges by key.' );
	}

	public function test_nothing_is_declared_unless_every_precondition_holds(): void {
		$cases = array(
			'cache-safe off'          => array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ),
			'tracking off'            => array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => false ),
			'reliable purchase off'   => array( GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => false ),
			'tracked flag not in use' => array( GTM4WP_OPTION_INTEGRATE_EDDNOORDERTRACKEDFLAG => true ),
		);

		foreach ( $cases as $label => $override ) {
			$this->assertSame( array(), $this->make( array_merge( self::ALL_ON, $override ) )->declare_visitor_scoped_fields( array() ), $label );
		}
	}

	public function test_resolver_does_not_touch_the_session_without_the_event_cookie(): void {
		Functions\expect( 'edd_get_purchase_session' )->never();

		$this->assertNull( $this->make()->resolve_pending_purchase() );
	}

	public function test_resolver_returns_the_own_purchase_with_the_order_number_and_writes_nothing(): void {
		$_COOKIE[ ReliablePurchase::EVENT_COOKIE ] = '1';

		$payload = $this->make()->resolve_pending_purchase();

		$this->assertIsArray( $payload );
		$this->assertSame( 'purchase', $payload['push']['event'] );
		$this->assertSame( 38.0, (float) $payload['push']['ecommerce']['value'] );
		$this->assertSame( 'EDD-0077', $payload['orderNumber'], 'The guard key is the order NUMBER, as purchase_dedupe_guard() writes it (RI-14).' );
		$this->assertTrue( $payload['flag'] );
		$this->assertArrayHasKey( 'new_customer', $payload['push'] );
		$this->assertArrayHasKey( 'customer_type', $payload['push'] );
		$this->assertArrayNotHasKey( 'orderData', $payload['push'], 'orderData stays on the confirmation page.' );
		$this->assertSame( array(), $this->meta_writes, 'The GET is read-only: the flag is the beacon\'s job.' );
		$this->assertSame( array(), $this->cookie_writes );
	}

	public function test_resolver_ignores_request_supplied_order_identifiers(): void {
		$_COOKIE[ ReliablePurchase::EVENT_COOKIE ] = '1';
		$_GET['payment_key']                       = 'pk_someone_else';
		$_GET['order']                             = 'f00';
		$_GET['id']                                = '12';
		Functions\when( 'edd_get_purchase_session' )->justReturn( null );

		$this->assertNull( $this->make()->resolve_pending_purchase(), 'No purchase session: nothing, whatever the URL says.' );
		$this->assertSame( array(), $this->order_lookups, 'No lookup by a request value (PA-10).' );

		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => self::SESSION_KEY ) );
		$this->assertIsArray( $this->make()->resolve_pending_purchase() );
		$this->assertSame( array( array( 'payment_key', self::SESSION_KEY ) ), $this->order_lookups );
	}

	public function test_resolver_honors_every_dedupe_and_eligibility_guard(): void {
		$_COOKIE[ ReliablePurchase::EVENT_COOKIE ] = '1';

		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => '' ) );
		$this->assertNull( $this->make()->resolve_pending_purchase(), 'empty purchase key' );
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => self::SESSION_KEY ) );

		$this->set_order( null );
		$this->assertNull( $this->make()->resolve_pending_purchase(), 'unknown order' );

		$this->set_order( $this->make_order( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) ) );
		$this->assertNull( $this->make()->resolve_pending_purchase(), 'older than the 30-minute maximum age' );

		$this->set_order( $this->make_order() );
		Functions\when( 'edd_get_order_meta' )->justReturn( '1' );
		$this->assertNull( $this->make()->resolve_pending_purchase(), '_ga_tracked set' );
		Functions\when( 'edd_get_order_meta' )->justReturn( '' );

		foreach ( array( '77', 'EDD-0077' ) as $tracked ) {
			$_COOKIE['gtm4wp_orderid_tracked'] = $tracked;
			$this->assertNull( $this->make()->resolve_pending_purchase(), 'tracked cookie ' . $tracked );
		}
		unset( $_COOKIE['gtm4wp_orderid_tracked'] );

		$this->set_order( $this->make_order( array( 'status' => 'failed' ) ) );
		$this->assertNull( $this->make()->resolve_pending_purchase(), 'failed order' );

		$this->set_order( $this->make_order() );
		$this->assertIsArray( $this->make()->resolve_pending_purchase()['push'] ?? null, 'Control: the same order passes once nothing blocks it.' );
	}

	public function test_an_order_waiting_for_a_tracked_status_is_reported_pending_inside_the_window(): void {
		$_COOKIE[ ReliablePurchase::EVENT_COOKIE ] = '1';
		$complete_only                             = self::ALL_ON + array(
			GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES => array( 'complete' ),
			GTM4WP_OPTION_INTEGRATE_EDDORDERMAXAGE      => 0,
		);

		$this->set_order( $this->make_order( array( 'status' => 'pending' ) ) );
		$this->assertSame( array( 'pending' => true ), $this->make( $complete_only )->resolve_pending_purchase() );

		$this->set_order(
			$this->make_order(
				array(
					'status'       => 'pending',
					'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( DownloadData::PENDING_PURCHASE_RECHECK_WINDOW_MINUTES + 5 ) * 60 ),
				)
			)
		);
		$this->assertNull( $this->make( $complete_only )->resolve_pending_purchase(), 'Past the re-check window the client may drop the cookie.' );

		foreach ( DownloadData::PENDING_PURCHASE_TERMINAL_STATUSES as $terminal ) {
			$this->set_order( $this->make_order( array( 'status' => $terminal ) ) );
			$this->assertNull( $this->make( $complete_only )->resolve_pending_purchase(), $terminal );
		}

		$this->assertSame( array(), $this->meta_writes );
	}

	/**
	 * U132: the terminal list is edd_get_payment_statuses() of EDD 3.7.0 minus
	 * the placement and waiting statuses. A new EDD status fails this test
	 * until it is classified.
	 */
	public function test_terminal_statuses_partition_the_edd_status_list(): void {
		$edd_370_statuses = array( 'pending', 'processing', 'complete', 'refunded', 'partially_refunded', 'revoked', 'failed', 'abandoned', 'on_hold' );
		$waiting          = array( 'pending', 'processing', 'complete', 'on_hold' );

		$this->assertEqualsCanonicalizing( $edd_370_statuses, array_merge( $waiting, DownloadData::PENDING_PURCHASE_TERMINAL_STATUSES ) );
	}

	public function test_confirm_flags_the_session_order_exactly_once_per_call_and_reads_no_request(): void {
		// The client cleared the event cookie as the beacon left, and without
		// localStorage it has just written the tracked cookie: neither may block it.
		$_COOKIE['gtm4wp_orderid_tracked'] = 'EDD-0077';
		$_GET['payment_key']               = 'pk_someone_else';

		$response = $this->make()->confirm_purchase_tracked();

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( array( array( 77, \GTM4WP\Ecommerce\Helpers::ORDER_TRACKED_META, 1 ) ), $this->meta_writes );
		$this->assertSame( array( array( 'payment_key', self::SESSION_KEY ) ), $this->order_lookups );
	}

	public function test_confirm_does_not_flag_an_order_the_get_would_not_deliver(): void {
		$cases = array(
			'no session'   => static fn () => Functions\when( 'edd_get_purchase_session' )->justReturn( null ),
			'failed order' => fn () => $this->set_order( $this->make_order( array( 'status' => 'failed' ) ) ),
			'too old'      => fn () => $this->set_order( $this->make_order( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) ) ),
		);

		foreach ( $cases as $label => $arrange ) {
			Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => self::SESSION_KEY ) );
			$this->set_order( $this->make_order() );
			$arrange();
			$this->make()->confirm_purchase_tracked();
			$this->assertSame( array(), $this->meta_writes, $label );
		}

		// Control: the restored arrangement does flag, so each case above is
		// blocked by its own condition.
		Functions\when( 'edd_get_purchase_session' )->justReturn( array( 'purchase_key' => self::SESSION_KEY ) );
		$this->set_order( $this->make_order() );
		$this->make()->confirm_purchase_tracked();
		$this->assertCount( 1, $this->meta_writes );
		$this->meta_writes = array();

		$this->set_order( $this->make_order( array( 'status' => 'pending' ) ) );
		$this->make( self::ALL_ON + array( GTM4WP_OPTION_INTEGRATE_EDDPURCHASESTATUSES => array( 'complete' ) ) )->confirm_purchase_tracked();
		$this->assertSame( array(), $this->meta_writes, 'A still-pending order is not ended by a stray POST.' );
	}

	public function test_confirm_permission_requires_the_nonce_and_the_same_origin(): void {
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn ( $nonce, $action ) => ( 'wp_rest' === $action && 'good' === $nonce ) ? 1 : false
		);
		$this->stub_origin_helpers();
		$purchase = $this->make();
		$good     = static fn () => new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'good' ) );

		$this->stub_origin = 'https://shop.example';
		$this->assertTrue( $purchase->check_confirm_permission( $good() ), 'own origin, valid nonce' );
		$this->assertTrue( $purchase->check_confirm_permission( new \WP_REST_Request( array( '_wpnonce' => 'good' ) ) ), 'sendBeacon form' );
		$this->assertFalse( $purchase->check_confirm_permission( new \WP_REST_Request( array(), array( 'X-WP-Nonce' => 'bad' ) ) ), 'bad nonce' );
		$this->assertFalse( $purchase->check_confirm_permission( new \WP_REST_Request() ), 'no nonce' );

		$this->stub_origin = 'https://evil.example';
		$this->assertFalse( $purchase->check_confirm_permission( $good() ), 'foreign origin despite a valid nonce' );
	}

	public function test_confirm_route_is_a_post_bound_to_the_permission_gate_and_only_when_enabled(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $route ] = array( $ns, $args );
			}
		);

		$this->make( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ) + self::ALL_ON )->register_confirm_route();
		$this->assertSame( array(), $routes );

		$purchase = $this->make();
		$purchase->register_confirm_route();

		$this->assertSame( array( ReliablePurchase::REST_ROUTE_CONFIRM ), array_keys( $routes ) );
		list( $namespace, $args ) = $routes[ ReliablePurchase::REST_ROUTE_CONFIRM ];
		$this->assertSame( VisitorDataEndpoint::REST_NAMESPACE, $namespace );
		$this->assertSame( 'POST', $args['methods'] );
		$this->assertSame( array( $purchase, 'check_confirm_permission' ), $args['permission_callback'] );
		$this->assertSame( array( $purchase, 'confirm_purchase_tracked' ), $args['callback'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_no_event_cookie_hook_without_edd_even_with_the_options_on(): void {
		// Own process: an EDD() stub or EDD_VERSION from another test is process-wide.
		Functions\when( 'get_option' )->justReturn( self::ALL_ON );
		ReliablePurchase::register_flag_hooks( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );

		$this->assertFalse( has_action( 'edd_built_order' ) );
	}

	public function test_the_event_cookie_is_set_on_edd_built_order_only_when_enabled(): void {
		Functions\when( 'EDD' )->justReturn( new \stdClass() );
		if ( ! defined( 'EDD_VERSION' ) ) {
			define( 'EDD_VERSION', '3.7.0' );
		}

		Functions\when( 'get_option' )->justReturn( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKONANYPAGE => false ) + self::ALL_ON );
		ReliablePurchase::register_flag_hooks( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );
		$this->assertFalse( has_action( 'edd_built_order' ) );

		Functions\when( 'get_option' )->justReturn( self::ALL_ON );
		ReliablePurchase::register_flag_hooks( new Options( ( new EasyDigitalDownloadsModule() )->defaults() ) );
		$this->assertTrue( has_action( 'edd_built_order' ) );

		$this->make()->flag_event();

		$this->assertCount( 1, $this->cookie_writes );
		list( $name, $value, $attributes ) = $this->cookie_writes[0];
		$this->assertSame( ReliablePurchase::EVENT_COOKIE, $name );
		$this->assertSame( '1', $value );
		$this->assertFalse( $attributes['httponly'], 'The client runtime reads it.' );
		$this->assertSame( '/', $attributes['path'] );
		$this->assertArrayNotHasKey( 'domain', $attributes, 'Host-only, or the JS clearer never removes it (#270).' );
		$this->assertSame( '1', $_COOKIE[ ReliablePurchase::EVENT_COOKIE ] );
	}

	public function test_no_event_cookie_after_headers_are_sent(): void {
		Functions\when( 'headers_sent' )->justReturn( true );

		$this->make()->flag_event();

		$this->assertSame( array(), $this->cookie_writes );
	}
}
