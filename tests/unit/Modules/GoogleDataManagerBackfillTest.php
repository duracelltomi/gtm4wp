<?php
/**
 * Unit tests for the attribution backfill route.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\BackfillEndpoint;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * A mutation exposed to logged-out visitors, so it is tested as one.
 *
 * Four independent controls, each with its own grant and deny case, because
 * any one of them silently failing open would be enough:
 *
 * 1. The request must demonstrably come from a page on this site (the Origin
 *    gate; the REST nonce is only a malformed-request filter, since for a
 *    logged-out caller it is a site-wide constant).
 * 2. The caller must hold the order's own purchase secret - the WooCommerce
 *    order key or the EDD payment key. An order id alone is guessable.
 * 3. A field that already carries a value is never overwritten, so even a
 *    caller holding a valid key can only fill a hole, never replace real
 *    attribution with values of their choosing.
 * 4. Posted values pass the same grammar as the cookie, and are mapped onto
 *    the meta keys rather than used as keys, so no caller-chosen key can reach
 *    order meta.
 */
final class GoogleDataManagerBackfillTest extends TestCase {

	private const ORDER_ID  = 42;
	private const ORDER_KEY = 'wc_order_aBcDeF123456';

	/**
	 * Meta written through the EDD writer during a test.
	 *
	 * @var array<int, array{0: int, 1: string, 2: mixed}>
	 */
	private array $edd_written = array();

	/**
	 * Meta the EDD order already carries.
	 *
	 * @var array<string, mixed>
	 */
	private array $edd_existing = array();

	protected function setUp(): void {
		parent::setUp();

		$_SERVER = array_diff_key( $_SERVER, array( 'HTTP_REFERER' => true ) );

		$this->edd_written  = array();
		$this->edd_existing = array();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn ( $url, $component = -1 ) => parse_url( $url, $component ) // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		);
		Functions\when( 'get_http_origin' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	protected function tearDown(): void {
		$_SERVER                                 = array_diff_key( $_SERVER, array( 'HTTP_REFERER' => true ) );
		$GLOBALS['gtm4wp_test_forced_functions'] = array();

		parent::tearDown();
	}

	/**
	 * A request carrying a full, valid payload.
	 *
	 * @param array<string, mixed> $overrides Parameter overrides.
	 * @return \WP_REST_Request
	 */
	private static function request( array $overrides = array() ): \WP_REST_Request {
		return new \WP_REST_Request(
			array_merge(
				array(
					'_wpnonce' => 'a-rest-nonce',
					'platform' => BackfillEndpoint::PLATFORM_WC,
					'order'    => (string) self::ORDER_ID,
					'token'    => self::ORDER_KEY,
					'values'   => array(
						'client_id' => '111.222',
						'sessions'  => array( 'G-ABC123' => '1788522496' ),
						'gclid'     => 'abc123',
					),
					'consent'  => array(
						'signals'     => array( 'analytics_storage' => 'granted' ),
						'captured_at' => 1_800_000_000,
					),
				),
				$overrides
			)
		);
	}

	/**
	 * Registers a WooCommerce order for wc_get_order() to find.
	 *
	 * @param array<string, mixed> $meta Meta the order already carries.
	 * @return \WC_Order
	 */
	private function given_woocommerce_order( array $meta = array() ): \WC_Order {
		$order = new \WC_Order(
			array(
				'id'        => self::ORDER_ID,
				'order_key' => self::ORDER_KEY,
				'meta'      => $meta,
			)
		);

		Functions\when( 'wc_get_order' )->alias(
			static fn ( $id ) => self::ORDER_ID === (int) $id ? $order : false
		);

		return $order;
	}

	/**
	 * Registers an EDD order resolvable by its payment key.
	 *
	 * @param string $payment_key The key that resolves it.
	 * @return void
	 */
	private function given_edd_order( string $payment_key ): void {
		Functions\when( 'edd_get_order_by' )->alias(
			static function ( $field, $value ) use ( $payment_key ) {
				if ( 'payment_key' !== $field || $value !== $payment_key ) {
					return false;
				}

				return (object) array( 'id' => self::ORDER_ID );
			}
		);

		Functions\when( 'edd_get_order_meta' )->alias(
			fn ( $order_id, $key, $single = false ) => $this->edd_existing[ $key ] ?? ''
		);

		Functions\when( 'edd_update_order_meta' )->alias(
			function ( $order_id, $key, $value ): void {
				$this->edd_written[] = array( $order_id, $key, $value );
			}
		);
	}

	// ---- Control 1: the origin gate ----------------------------------------

	public function test_a_same_origin_request_with_a_nonce_is_allowed(): void {
		$this->assertTrue( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	public function test_a_cross_origin_request_is_refused(): void {
		Functions\when( 'get_http_origin' )->justReturn( 'https://attacker.example.net' );

		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	/**
	 * A subdomain is a different host, and a different port is a different
	 * server: neither is this site.
	 */
	public function test_a_neighbouring_host_or_port_is_refused(): void {
		Functions\when( 'get_http_origin' )->justReturn( 'https://evil.shop.example.com' );
		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );

		Functions\when( 'get_http_origin' )->justReturn( 'https://shop.example.com:8443' );
		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	/**
	 * No Origin and no Referer is not evidence of anything, so it is refused -
	 * "no evidence" is not the same as "same origin".
	 */
	public function test_a_request_with_no_origin_evidence_at_all_is_refused(): void {
		Functions\when( 'get_http_origin' )->justReturn( '' );

		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	public function test_the_referer_is_the_fallback_when_origin_is_absent(): void {
		Functions\when( 'get_http_origin' )->justReturn( '' );
		$_SERVER['HTTP_REFERER'] = 'https://shop.example.com/checkout/order-received/42/';

		$this->assertTrue( ( new BackfillEndpoint() )->check_permission( self::request() ) );

		$_SERVER['HTTP_REFERER'] = 'https://attacker.example.net/page';

		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	public function test_a_request_without_a_valid_nonce_is_refused(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->assertFalse( ( new BackfillEndpoint() )->check_permission( self::request() ) );
	}

	// ---- Control 2: proof of purchase --------------------------------------

	public function test_a_valid_woocommerce_order_key_writes_the_attribution(): void {
		$order = $this->given_woocommerce_order();

		$response = ( new BackfillEndpoint() )->backfill( self::request() );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '111.222', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( array( 'G-ABC123' => '1788522496' ), $order->get_meta( AttributionCapture::META_SESSION_IDS, true ) );
		$this->assertSame( 'abc123', $order->get_meta( '_gtm4wp_gclid', true ) );
		$this->assertSame(
			array(
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => 1_800_000_000,
			),
			$order->get_meta( AttributionCapture::META_CONSENT_STATE, true )
		);
	}

	/**
	 * The order id is sequential and guessable, so it can never be the
	 * authorization on its own.
	 */
	public function test_a_wrong_woocommerce_order_key_is_refused(): void {
		$order = $this->given_woocommerce_order();

		$result = ( new BackfillEndpoint() )->backfill( self::request( array( 'token' => 'wc_order_guessed' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
	}

	public function test_an_empty_token_is_refused(): void {
		$order = $this->given_woocommerce_order();

		$result = ( new BackfillEndpoint() )->backfill( self::request( array( 'token' => '' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
	}

	public function test_an_unknown_order_is_refused(): void {
		$this->given_woocommerce_order();

		$result = ( new BackfillEndpoint() )->backfill( self::request( array( 'order' => '9999' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * "No such order", "wrong key" and "that platform is not active" all answer
	 * the same way, so the route cannot be used to find out which orders exist.
	 */
	public function test_every_refusal_looks_the_same(): void {
		$this->given_woocommerce_order();

		$unknown_order = ( new BackfillEndpoint() )->backfill( self::request( array( 'order' => '9999' ) ) );
		$wrong_key     = ( new BackfillEndpoint() )->backfill( self::request( array( 'token' => 'wc_order_guessed' ) ) );

		$this->assertSame( $unknown_order->get_error_code(), $wrong_key->get_error_code() );
		$this->assertSame( $unknown_order->get_error_message(), $wrong_key->get_error_message() );
		$this->assertSame( $unknown_order->get_error_data(), $wrong_key->get_error_data() );
	}

	public function test_a_valid_edd_payment_key_writes_the_attribution(): void {
		$this->given_edd_order( 'edd-payment-key-abc' );

		$response = ( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'platform' => BackfillEndpoint::PLATFORM_EDD,
					'token'    => 'edd-payment-key-abc',
				)
			)
		);

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame(
			array(
				array( self::ORDER_ID, AttributionCapture::META_CLIENT_ID, '111.222' ),
				array( self::ORDER_ID, AttributionCapture::META_SESSION_IDS, array( 'G-ABC123' => '1788522496' ) ),
				array( self::ORDER_ID, '_gtm4wp_gclid', 'abc123' ),
				array(
					self::ORDER_ID,
					AttributionCapture::META_CONSENT_STATE,
					array(
						'signals'     => array( 'analytics_storage' => 'granted' ),
						'captured_at' => 1_800_000_000,
					),
				),
			),
			$this->edd_written
		);
	}

	public function test_a_wrong_edd_payment_key_is_refused(): void {
		$this->given_edd_order( 'edd-payment-key-abc' );

		$result = ( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'platform' => BackfillEndpoint::PLATFORM_EDD,
					'token'    => 'edd-payment-key-guessed',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->edd_written );
	}

	/**
	 * The payment key resolves an order, and that order must be the one the
	 * request names - otherwise a caller holding their own valid key could
	 * write into somebody else's order.
	 */
	public function test_an_edd_key_belonging_to_another_order_is_refused(): void {
		$this->given_edd_order( 'edd-payment-key-abc' );

		$result = ( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'platform' => BackfillEndpoint::PLATFORM_EDD,
					'order'    => '99',
					'token'    => 'edd-payment-key-abc',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->edd_written );
	}

	/**
	 * Naming the other platform must not become a way around the first one's
	 * check.
	 */
	public function test_a_woocommerce_key_cannot_be_spent_on_the_edd_path(): void {
		$this->given_woocommerce_order();
		$this->given_edd_order( 'edd-payment-key-abc' );

		$result = ( new BackfillEndpoint() )->backfill(
			self::request( array( 'platform' => BackfillEndpoint::PLATFORM_EDD ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->edd_written );
	}

	// ---- Control 3: write only if absent -----------------------------------

	/**
	 * The property that makes this route safe even for a caller who legitimately
	 * holds the key: backfill can fill a hole, never replace what is there.
	 */
	public function test_a_field_that_already_has_a_value_is_never_overwritten(): void {
		$order = $this->given_woocommerce_order(
			array(
				AttributionCapture::META_CLIENT_ID => 'the-real-client-id',
				'_gtm4wp_gclid'                    => 'the-real-gclid',
			)
		);

		( new BackfillEndpoint() )->backfill( self::request() );

		$this->assertSame( 'the-real-client-id', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( 'the-real-gclid', $order->get_meta( '_gtm4wp_gclid', true ) );
		$this->assertSame(
			array( 'G-ABC123' => '1788522496' ),
			$order->get_meta( AttributionCapture::META_SESSION_IDS, true ),
			'A field that was still empty is filled in the same request.'
		);
	}

	public function test_a_second_post_cannot_replace_what_the_first_one_wrote(): void {
		$order = $this->given_woocommerce_order();

		( new BackfillEndpoint() )->backfill( self::request() );
		( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'values' => array(
						'client_id' => '999.888',
						'gclid'     => 'attacker-gclid',
					),
				)
			)
		);

		$this->assertSame( '111.222', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( 'abc123', $order->get_meta( '_gtm4wp_gclid', true ) );
	}

	/**
	 * Two of the captured values are arrays - the session map and the consent
	 * state - and the write-only-if-absent rule has to hold for those too. It
	 * is asserted separately because the obvious way to test "is this empty"
	 * (a string cast) both warns and answers the wrong question for an array,
	 * so a version of this guard that only worked for strings would leave the
	 * array fields overwritable.
	 */
	public function test_an_array_valued_field_is_not_overwritten_either(): void {
		$order = $this->given_woocommerce_order(
			array(
				AttributionCapture::META_SESSION_IDS   => array( 'G-ABC123' => 'the-real-session' ),
				AttributionCapture::META_CONSENT_STATE => array( 'signals' => array( 'analytics_storage' => 'denied' ) ),
			)
		);

		$errors = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only warning trap, restored below.
		set_error_handler(
			static function ( $severity, $message ) use ( &$errors ): bool {
				$errors[] = $message;

				return true;
			}
		);

		try {
			( new BackfillEndpoint() )->backfill( self::request() );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $errors, 'Deciding whether an array-valued meta is present must not raise a PHP warning.' );
		$this->assertSame(
			array( 'G-ABC123' => 'the-real-session' ),
			$order->get_meta( AttributionCapture::META_SESSION_IDS, true )
		);
		$this->assertSame(
			array( 'signals' => array( 'analytics_storage' => 'denied' ) ),
			$order->get_meta( AttributionCapture::META_CONSENT_STATE, true ),
			'A recorded denial must not be replaceable with a grant posted from the page.'
		);
	}

	public function test_the_edd_writer_also_refuses_to_overwrite(): void {
		$this->given_edd_order( 'edd-payment-key-abc' );
		$this->edd_existing = array( AttributionCapture::META_CLIENT_ID => 'the-real-client-id' );

		( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'platform' => BackfillEndpoint::PLATFORM_EDD,
					'token'    => 'edd-payment-key-abc',
				)
			)
		);

		$written_keys = array_column( $this->edd_written, 1 );

		$this->assertNotContains( AttributionCapture::META_CLIENT_ID, $written_keys );
		$this->assertContains( '_gtm4wp_gclid', $written_keys );
	}

	// ---- Control 4: the value grammar --------------------------------------

	public function test_hostile_values_are_dropped_rather_than_stored(): void {
		$order = $this->given_woocommerce_order();

		( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'values'  => array(
						'client_id' => '</script><script>alert(1)</script>',
						'gclid'     => 'has spaces and "quotes"',
						'sessions'  => array( 'not-a-measurement-id' => '123' ),
					),
					'consent' => array( 'signals' => array( '</script>' => 'granted' ) ),
				)
			)
		);

		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( '', (string) $order->get_meta( '_gtm4wp_gclid', true ) );
		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_SESSION_IDS, true ) );
		$this->assertSame(
			array(),
			$order->get_meta( AttributionCapture::META_CONSENT_STATE, true )['signals'],
			'A signal with an unusable name is dropped, but an observed consent state is still recorded.'
		);
	}

	/**
	 * The posted values are mapped onto the plugin's own meta keys rather than
	 * used as keys, so a caller cannot write a key of their choosing - the
	 * attack that would turn a backfill into arbitrary order-meta injection.
	 */
	public function test_a_caller_chosen_key_cannot_reach_order_meta(): void {
		$order = $this->given_woocommerce_order();

		( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'values' => array(
						'client_id'   => '111.222',
						'_ga_tracked' => 1,
						'total'       => '0.00',
						'evil'        => 'value',
					),
				)
			)
		);

		$this->assertSame( '111.222', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( '', (string) $order->get_meta( '_ga_tracked', true ) );
		$this->assertSame( '', (string) $order->get_meta( 'total', true ) );
		$this->assertSame( '', (string) $order->get_meta( 'evil', true ) );
	}

	public function test_an_empty_payload_is_accepted_without_writing_anything(): void {
		$order = $this->given_woocommerce_order();

		$response = ( new BackfillEndpoint() )->backfill(
			self::request(
				array(
					'values'  => array(),
					'consent' => array(),
				)
			)
		);

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
	}

	/**
	 * A platform the site does not run cannot be used to reach anything. The
	 * absence has to be forced through the namespaced shim: Brain Monkey
	 * defines a stubbed wc_get_order() process-wide and permanently, so a
	 * sibling test having mocked it would otherwise make this pass or fail
	 * depending on order (TS-16).
	 */
	public function test_a_platform_that_is_not_active_is_refused(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'wc_get_order'     => false,
			'edd_get_order_by' => false,
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			( new BackfillEndpoint() )->backfill( self::request() )
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			( new BackfillEndpoint() )->backfill( self::request( array( 'platform' => BackfillEndpoint::PLATFORM_EDD ) ) )
		);
	}
}
