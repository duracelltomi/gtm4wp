<?php
/**
 * Unit tests for the WooCommerce purchase tracking fallback.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\WooCommerce\Helpers;
use GTM4WP\Modules\WooCommerce\ProductData;
use GTM4WP\Modules\WooCommerce\PurchaseTracking;
use GTM4WP\Modules\WooCommerce\WooCommerceModule;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';
require_once __DIR__ . '/wc-datastore-stub.php';

/**
 * Covers the eligibility gauntlet and script output of
 * PurchaseTracking::on_thankyou() (the woocommerce_thankyou fallback used on
 * customized order-received pages).
 */
final class PurchaseTrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'wp_kses' )->alias( static fn ( $content ) => $content );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		// Used by the order-tracked cookie read in is_purchase_already_tracked().
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : ''
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $value ) => strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		Functions\when( 'current_theme_supports' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( false );

		unset(
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$_COOKIE['gtm4wp_orderid_tracked']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'],
			$_COOKIE['gtm4wp_orderid_tracked'],
			$_COOKIE[ Helpers::ONESHOT_EVENT_COOKIE ]
		);
		parent::tearDown();
	}

	/**
	 * Builds a PurchaseTracking instance backed by the given stored options.
	 *
	 * @param array<string, mixed> $options Stored option overrides.
	 * @return PurchaseTracking
	 */
	private function make_tracking( array $options = array() ): PurchaseTracking {
		Functions\when( 'get_option' )->justReturn( $options );

		$service_options = new Options( ( new WooCommerceModule() )->defaults() );
		$product_data    = new ProductData( $service_options );

		return new PurchaseTracking(
			$service_options,
			$product_data,
			new DataLayer( $service_options ),
			new ScriptTag( $service_options )
		);
	}

	/**
	 * Builds a stubbed order with sensible, recent defaults.
	 *
	 * @param array<string, mixed> $data Order data overrides.
	 * @return \WC_Order
	 */
	private function make_order( array $data = array() ): \WC_Order {
		return new \WC_Order(
			array_merge(
				array(
					'order_number' => '1001',
					'total'        => 100.0,
					'currency'     => 'EUR',
					'status'       => 'processing',
					'items'        => array(),
				),
				$data
			)
		);
	}

	/**
	 * Runs on_thankyou() for the given order and returns the printed output.
	 *
	 * @param array<string, mixed> $options  Stored option overrides.
	 * @param \WC_Order            $order    The order returned by wc_get_order().
	 * @param int                  $order_id The order id passed to the hook.
	 * @return string
	 */
	private function run_thankyou( array $options, \WC_Order $order, int $order_id = 1001 ): string {
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$tracking = $this->make_tracking( $options );

		ob_start();
		$tracking->on_thankyou( $order_id );
		return (string) ob_get_clean();
	}

	public function test_skips_when_on_order_received_page(): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$output = $this->run_thankyou( array(), $this->make_order() );

		$this->assertSame( '', $output, 'The fallback must not run when the standard order-received page already fired the purchase event.' );
	}

	public function test_skips_when_purchase_already_pushed(): void {
		$GLOBALS['gtm4wp_woocommerce_purchase_data_pushed'] = true;

		$output = $this->run_thankyou( array(), $this->make_order() );

		$this->assertSame( '', $output );
	}

	public function test_outputs_purchase_event_and_flags_order_tracked(): void {
		$order = $this->make_order();

		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$order
		);

		$this->assertStringContainsString( '"event":"purchase"', $output );
		$this->assertStringContainsString( '"transaction_id":"1001"', $output );
		$this->assertStringContainsString( '"new_customer":true', $output );
		$this->assertStringContainsString( 'dataLayer.push(', $output );
		$this->assertSame( 1, $order->saved_meta['_ga_tracked'] ?? null, 'A tracked order must be flagged with the _ga_tracked meta.' );
	}

	/**
	 * Google publishes two names for the same idea on two surfaces: Google Ads
	 * customer acquisition reads the boolean `new_customer`, while the GA4
	 * e-commerce reference documents a `customer_type` string of `new` or
	 * `returning`. Both must ship - they are not alternatives, and dropping
	 * either silently breaks one integration while the other keeps working.
	 * Asserts the pair together so neither can be removed as a duplicate.
	 */
	public function test_outputs_both_new_customer_and_customer_type(): void {
		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$this->make_order()
		);

		$this->assertStringContainsString( '"new_customer":true', $output, 'Google Ads customer acquisition reads the boolean new_customer.' );
		$this->assertStringContainsString( '"customer_type":"new"', $output, 'The GA4 e-commerce reference documents customer_type as a new/returning string.' );
		$this->assertStringNotContainsString( '"customer_type":true', $output, 'customer_type is a string, never the raw boolean.' );
	}

	/**
	 * TS-1 / TS-2 / stored-XSS regression. When the optional orderData block is on,
	 * billing/shipping/coupon fields — user-controllable at checkout (finding #3) —
	 * reach the inline-<script> purchase sink. This is the only test that drives the
	 * WCORDERDATA branch; it proves the value is hex-encoded (each flag pinned) and
	 * that no raw break-out or upstream entity-encoding survives. Break-out chars are
	 * written with \xNN so no literal char appears in this file (TC-2).
	 */
	public function test_order_data_branch_hex_encodes_hostile_billing_and_coupon(): void {
		// \x3C < , \x3E > , \x22 " , \x26 &
		$order = $this->make_order(
			array(
				'billing_first_name' => "x\x3C/script\x3E",
				'billing_company'    => "Marks \x26 Spencer",
				'coupon_codes'       => array( "a\x22\x3E" ),
			)
		);

		$output = $this->run_thankyou(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERDATA      => true,
			),
			$order
		);

		$this->assertStringContainsString( '"orderData"', $output, 'The orderData block must be present when the option is on.' );

		// Direction 2 (TS-2): the injected value's raw break-out must never survive.
		// The 'x' prefix distinguishes the billing-name payload from the script
		// block's own closing tag (the output legitimately ends with </script>).
		$this->assertStringNotContainsString( 'x</script>', $output );
		$this->assertStringNotContainsString( 'Marks & Spencer', $output );
		$this->assertStringNotContainsString( '&amp;', $output, 'The value must not be entity-encoded upstream of the sink (RI-4).' );

		// Direction 1 (TS-2): each break-out char is hex-encoded, pinning the flag
		// set. Build the expected fragment with json_encode(... hex flags) exactly as
		// the source does (TC-2: compute, never hand-type \uXXXX); trim() drops the
		// quotes json_encode adds. The company (&) and coupon (" and >) carry no '/',
		// so slash-escaping cannot affect the match — this pins JSON_HEX_AMP,
		// JSON_HEX_QUOT and JSON_HEX_TAG.
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;
		$this->assertStringContainsString(
			trim( (string) json_encode( "Marks \x26 Spencer", $flags ), '"' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$output,
			'The company & must be hex-encoded (JSON_HEX_AMP).'
		);
		$this->assertStringContainsString(
			trim( (string) json_encode( "a\x22\x3E", $flags ), '"' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$output,
			'The coupon " and > must be hex-encoded (JSON_HEX_QUOT / JSON_HEX_TAG).'
		);
	}

	/**
	 * #141: order data reaches the sink through public filters, so a third party
	 * can put a value in it that wp_json_encode() refuses. The encoder then returns
	 * false, PHP concatenates that as '', and the emitted line was `.push()` - a
	 * call that pushes nothing.
	 *
	 * The part that makes this worse than a dropped event is the line after it:
	 * flagging _ga_tracked on an order whose event was never actually pushed
	 * suppresses that purchase permanently, on every later page view too. So the
	 * assertion that matters is the meta one - bailing has to leave the order
	 * un-flagged so the next request can try again.
	 *
	 * @return void
	 */
	public function test_does_not_flag_order_tracked_when_the_payload_cannot_be_encoded(): void {
		$order = $this->make_order();

		Filters\expectApplied( GTM4WP_WPFILTER_ECC_PURCHASE_DATALAYER )
			->andReturn( array( 'brokenValue' => NAN ) );

		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$order
		);

		// The argument-less push is the defect.
		$this->assertStringNotContainsString( 'dataLayer.push();', $output );
		$this->assertStringNotContainsString( '.push(', $output );

		// ...and the order must stay eligible for a later attempt.
		$this->assertArrayNotHasKey(
			'_ga_tracked',
			$order->saved_meta,
			'An order whose purchase event was never emitted must not be flagged as tracked.'
		);
	}

	public function test_does_not_flag_order_when_no_tracked_flag_option_set(): void {
		$order = $this->make_order();

		$this->run_thankyou(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG => true,
			),
			$order
		);

		$this->assertArrayNotHasKey( '_ga_tracked', $order->saved_meta );
	}

	public function test_skips_order_already_tracked(): void {
		$order = $this->make_order( array( 'meta' => array( '_ga_tracked' => 1 ) ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		$this->assertStringNotContainsString( 'purchase', $output, 'An order already flagged as tracked must not be output again.' );
	}

	public function test_skips_failed_order(): void {
		$order = $this->make_order( array( 'status' => 'failed' ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		$this->assertStringNotContainsString( 'purchase', $output );
	}

	public function test_skips_order_older_than_max_age(): void {
		$order = $this->make_order( array( 'date_created' => '2000-01-01T00:00:00+00:00' ) );

		$output = $this->run_thankyou(
			array(
				GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true,
				GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE    => 30,
			),
			$order
		);

		$this->assertStringNotContainsString( 'purchase', $output, 'Orders older than the configured max age must not be tracked.' );
	}

	public function test_skips_order_matching_tracked_cookie(): void {
		$_COOKIE['gtm4wp_orderid_tracked'] = '1001';

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $this->make_order(), 1001 );

		$this->assertStringNotContainsString( 'purchase', $output, 'An order id matching the browser cookie must be treated as already tracked.' );
	}

	/**
	 * The gtm4wp_orderid_tracked cookie carries the order NUMBER - that is what
	 * both the inline order-received guard and gtm4wp-visitor-data.js write into
	 * it. The server-side read used to parse it with FILTER_VALIDATE_INT and
	 * compare it to the order ID, so on any store whose order numbers are not the
	 * plain id (a sequential/prefixed order-number plugin) the cookie leg of the
	 * guard silently never matched and the purchase could be counted twice.
	 */
	public function test_skips_order_matching_tracked_cookie_with_a_prefixed_order_number(): void {
		$_COOKIE['gtm4wp_orderid_tracked'] = 'WC-1001';

		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$this->make_order( array( 'order_number' => 'WC-1001' ) ),
			1001
		);

		$this->assertStringNotContainsString( 'purchase', $output, 'A non-numeric order number matching the browser cookie must be treated as already tracked.' );
	}

	/**
	 * The negative half: a cookie holding a DIFFERENT order number must not
	 * suppress this order's purchase.
	 */
	public function test_tracks_order_when_the_cookie_holds_another_order_number(): void {
		$_COOKIE['gtm4wp_orderid_tracked'] = 'WC-2002';

		$output = $this->run_thankyou(
			array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ),
			$this->make_order( array( 'order_number' => 'WC-1001' ) ),
			1001
		);

		$this->assertStringContainsString( 'purchase', $output, 'A cookie for a different order must not suppress this purchase.' );
	}


	/**
	 * Stubs WC() to return a store with a session that records set() calls.
	 *
	 * @param array<string, mixed> $session_values Values the session get() returns.
	 * @return object The session object (inspect its ->sets array).
	 */
	private function stub_wc_with_session( array $session_values = array() ): object {
		$session = new class( $session_values ) {
			/**
			 * Recorded set() calls, keyed by session key.
			 *
			 * @var array<string, mixed>
			 */
			public array $sets = array();

			public function __construct( private array $values ) {}

			public function get( $key ) {
				return $this->values[ $key ] ?? null;
			}

			public function set( $key, $value ) {
				$this->sets[ $key ] = $value;
			}
		};

		$store          = new \stdClass();
		$store->session = $session;
		Functions\when( 'WC' )->justReturn( $store );

		return $session;
	}

	public function test_remember_order_seeds_a_trackable_order_into_the_session(): void {
		$session = $this->stub_wc_with_session();
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order() ); // A processing order is trackable.

		$this->make_tracking()->remember_order( 1001 );

		$this->assertSame( 1001, $session->sets[ ProductData::PENDING_PURCHASE_SESSION_KEY ] ?? null, 'A placed, trackable order must be remembered for the next-page fallback.' );
	}

	public function test_remember_order_skips_a_non_trackable_status(): void {
		$session = $this->stub_wc_with_session();
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order( array( 'status' => 'pending' ) ) );

		$this->make_tracking()->remember_order( 1001 );

		$this->assertArrayNotHasKey( ProductData::PENDING_PURCHASE_SESSION_KEY, $session->sets, 'A still-pending order must not be seeded.' );
	}

	public function test_remember_order_skips_an_invalid_order_id(): void {
		$session = $this->stub_wc_with_session();

		$this->make_tracking()->remember_order( 0 );

		$this->assertSame( array(), $session->sets );
	}

	public function test_remember_order_skips_a_missing_order(): void {
		$session = $this->stub_wc_with_session();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->make_tracking()->remember_order( 1001 );

		$this->assertArrayNotHasKey( ProductData::PENDING_PURCHASE_SESSION_KEY, $session->sets );
	}

	public function test_remember_order_flags_the_oneshot_event_cookie_in_cache_safe_mode(): void {
		// Phase 3 (issue #398): seeding the pending-purchase marker also sets the
		// short-lived event cookie so the client fetches the fallback on the next page.
		$this->stub_wc_with_session();
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order() );

		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( false );
		$cookie_names = array();
		Functions\when( 'setcookie' )->alias(
			static function ( $name ) use ( &$cookie_names ) {
				$cookie_names[] = $name;
				return true;
			}
		);

		$this->make_tracking(
			array(
				GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true,
				GTM4WP_OPTION_CACHE_SAFE_DATALAYER => true,
			)
		)->remember_order( 1001 );

		$this->assertContains( Helpers::ONESHOT_EVENT_COOKIE, $cookie_names, 'The one-shot event cookie must be set alongside the session marker in cache-safe mode.' );
	}

	public function test_remember_order_does_not_flag_the_event_cookie_when_cache_safe_off(): void {
		$this->stub_wc_with_session();
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order() );

		Functions\when( 'headers_sent' )->justReturn( false );
		$cookie_names = array();
		Functions\when( 'setcookie' )->alias(
			static function ( $name ) use ( &$cookie_names ) {
				$cookie_names[] = $name;
				return true;
			}
		);

		$this->make_tracking( array( GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE => true ) )
			->remember_order( 1001 );

		$this->assertSame( array(), $cookie_names, 'No event cookie may be set unless the cache-safe mode is on (behavior unchanged).' );
	}

	public function test_remember_order_does_nothing_without_a_session(): void {
		$store          = new \stdClass();
		$store->session = null;
		Functions\when( 'WC' )->justReturn( $store );

		// Without a session the order can never be looked up.
		Functions\expect( 'wc_get_order' )->never();

		$this->make_tracking()->remember_order( 1001 );
	}

	public function test_purchase_datalayer_is_hex_encoded_in_script_context(): void {
		// A break-out attempt in a value reaching the purchase data layer (here
		// the order number, which a plugin can customize) must be hex-encoded by
		// wp_json_encode so it cannot close the inline <script> element.
		$order = $this->make_order( array( 'order_number' => 'ORD</script>' ) );

		$output = $this->run_thankyou( array( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE => true ), $order );

		// JSON_HEX_TAG turns < into < (the trailing hex-letter case varies
		// by PHP build, so match the digit-only prefix).
		$this->assertStringContainsString( 'ORD\u003', $output, 'The < character must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( 'ORD</script>', $output, 'The raw </script> sequence must never appear in the data layer value.' );
	}
}
