<?php
/**
 * Unit tests for the attribution capture's order-creation hooks.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\AttributionCookies;
use GTM4WP\Modules\GoogleDataManager\CaptureHooks;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * The wiring and the two writers. What each platform stores is the same set of
 * meta - that is decided once, in AttributionCapture - so the mirrored suites
 * here are about the two things that genuinely differ: which hooks are
 * registered, and how the meta reaches the order (the WooCommerce order CRUD
 * versus EDD's own order-meta functions).
 *
 * Both platforms get the same assertions on purpose. "The feature quietly only
 * works on WooCommerce" is the failure this project has a standing rule
 * against, and a mirrored suite is what makes that rule checkable.
 */
final class GoogleDataManagerCaptureHooksTest extends TestCase {

	use OptionStoreTrait;

	protected function setUp(): void {
		parent::setUp();

		$_COOKIE = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );

		$this->stub_option_store( array() );

		// Both platforms present by default; the registration tests narrow it.
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => true,
			'EDD' => true,
		);
	}

	protected function tearDown(): void {
		$_COOKIE                                 = array();
		$GLOBALS['gtm4wp_test_forced_functions'] = array();

		parent::tearDown();
	}

	/**
	 * Stores capture cookies holding a full set of values.
	 *
	 * @return void
	 */
	private function set_captured_cookies(): void {
		// The plain JSON, not the percent-encoded form: the script encodes on
		// the way out and PHP has already decoded by the time a value reaches
		// $_COOKIE.
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a cookie fixture, not plugin output.
		$_COOKIE[ AttributionCookies::IDS_COOKIE ] = (string) json_encode(
			array(
				'v'         => AttributionCookies::FORMAT_VERSION,
				'client_id' => '111.222',
				'sessions'  => array( 'G-ABC123' => '1788522496' ),
				'gclid'     => 'abc123',
			)
		);

		$_COOKIE[ AttributionCookies::CONSENT_COOKIE ] = (string) json_encode(
			array(
				'v'           => AttributionCookies::FORMAT_VERSION,
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => 1_800_000_000,
			)
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A CaptureHooks over a stats record with a fixed clock.
	 *
	 * @return CaptureHooks
	 */
	private function hooks(): CaptureHooks {
		return new CaptureHooks( new CaptureStats( static fn () => 1_800_000_000 ) );
	}

	// ---- Hook registration -------------------------------------------------

	public function test_registers_both_woocommerce_order_creation_hooks(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => true,
			'EDD' => false,
		);

		$this->hooks()->register_hooks();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_created' ),
			'The classic checkout path.'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_store_api_checkout_order_processed' ),
			'The Store API the block checkout posts to - a different hook, not a variant of the first.'
		);
		$this->assertFalse( has_action( 'edd_built_order' ) );
	}

	public function test_registers_the_edd_order_creation_hook(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => false,
			'EDD' => true,
		);

		$this->hooks()->register_hooks();

		$this->assertNotFalse( has_action( 'edd_built_order' ) );
		$this->assertFalse( has_action( 'woocommerce_checkout_order_created' ) );
	}

	public function test_registers_nothing_without_a_platform(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => false,
			'EDD' => false,
		);

		$this->hooks()->register_hooks();

		$this->assertFalse( has_action( 'woocommerce_checkout_order_created' ) );
		$this->assertFalse( has_action( 'woocommerce_store_api_checkout_order_processed' ) );
		$this->assertFalse( has_action( 'edd_built_order' ) );
	}

	// ---- The WooCommerce writer --------------------------------------------

	public function test_writes_every_captured_value_onto_a_woocommerce_order(): void {
		$this->set_captured_cookies();

		$order = new \WC_Order( array( 'id' => 42 ) );

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( '111.222', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame(
			array( 'G-ABC123' => '1788522496' ),
			$order->get_meta( AttributionCapture::META_SESSION_IDS, true )
		);
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
	 * The meta has to be persisted, not merely staged on the object: an
	 * unsaved update_meta_data() is lost the moment the request ends, and
	 * nothing later would report it.
	 */
	public function test_the_woocommerce_order_is_saved(): void {
		$this->set_captured_cookies();

		$order = new class(array( 'id' => 42 )) extends \WC_Order {
			/**
			 * Times save() was called.
			 *
			 * @var int
			 */
			public int $save_count = 0;

			public function save() {
				++$this->save_count;

				return parent::save();
			}
		};

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( 1, $order->save_count );
	}

	public function test_nothing_is_written_or_saved_when_nothing_was_captured(): void {
		$order = new class(array( 'id' => 42 )) extends \WC_Order {
			/**
			 * Times save() was called.
			 *
			 * @var int
			 */
			public int $save_count = 0;

			public function save() {
				++$this->save_count;

				return parent::save();
			}
		};

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( 0, $order->save_count, 'An order with nothing to store is not written to at all.' );
	}

	/**
	 * Capture runs once, at creation. An order that already carries it is left
	 * alone, so a second pass can never replace resolved attribution with
	 * whatever the current request happens to hold.
	 */
	public function test_an_order_that_already_carries_capture_is_left_alone(): void {
		$this->set_captured_cookies();

		$order = new \WC_Order(
			array(
				'id'   => 42,
				'meta' => array( AttributionCapture::META_CLIENT_ID => 'the-original-id' ),
			)
		);

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( 'the-original-id', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( '', (string) $order->get_meta( '_gtm4wp_gclid', true ) );
	}

	public function test_a_non_order_argument_is_ignored(): void {
		$this->set_captured_cookies();

		$this->expectNotToPerformAssertions();

		$this->hooks()->capture_woocommerce_order( null );
		$this->hooks()->capture_woocommerce_order( 42 );
		$this->hooks()->capture_woocommerce_order( new \stdClass() );
	}

	// ---- The EDD writer ----------------------------------------------------

	public function test_writes_every_captured_value_onto_an_edd_order(): void {
		$this->set_captured_cookies();

		$written = array();
		Functions\when( 'edd_update_order_meta' )->alias(
			static function ( $order_id, $key, $value ) use ( &$written ): void {
				$written[] = array( $order_id, $key, $value );
			}
		);

		$this->hooks()->capture_edd_order( 42, array() );

		$this->assertSame(
			array(
				array( 42, AttributionCapture::META_CLIENT_ID, '111.222' ),
				array( 42, AttributionCapture::META_SESSION_IDS, array( 'G-ABC123' => '1788522496' ) ),
				array( 42, '_gtm4wp_gclid', 'abc123' ),
				array(
					42,
					AttributionCapture::META_CONSENT_STATE,
					array(
						'signals'     => array( 'analytics_storage' => 'granted' ),
						'captured_at' => 1_800_000_000,
					),
				),
			),
			$written
		);
	}

	public function test_no_edd_meta_is_written_when_nothing_was_captured(): void {
		Functions\expect( 'edd_update_order_meta' )->never();

		$this->hooks()->capture_edd_order( 42, array() );
	}

	public function test_an_invalid_edd_order_id_is_ignored(): void {
		$this->set_captured_cookies();

		Functions\expect( 'edd_update_order_meta' )->never();

		$this->hooks()->capture_edd_order( 0, array() );
		$this->hooks()->capture_edd_order( -1, array() );
	}

	// ---- The capture-rate counters -----------------------------------------

	public function test_an_order_with_attribution_counts_as_seen_and_captured(): void {
		$this->set_captured_cookies();

		$stats = new CaptureStats( static fn () => 1_800_000_000 );
		$hooks = new CaptureHooks( $stats );

		$hooks->capture_woocommerce_order( new \WC_Order( array( 'id' => 42 ) ) );

		$this->assertSame(
			array(
				'seen'             => 1,
				'captured'         => 1,
				'last_seen_at'     => 1_800_000_000,
				'last_captured_at' => 1_800_000_000,
			),
			$stats->get()
		);
		$this->assertFalse( $stats->is_failing() );
	}

	/**
	 * The counter that makes the feature's silent failure visible: orders keep
	 * arriving, nothing is ever captured. Without this the first symptom would
	 * be a refund that cannot be sent, weeks later.
	 */
	public function test_orders_flowing_with_nothing_captured_is_a_reportable_state(): void {
		$stats = new CaptureStats( static fn () => 1_800_000_000 );
		$hooks = new CaptureHooks( $stats );

		$hooks->capture_woocommerce_order( new \WC_Order( array( 'id' => 1 ) ) );
		$hooks->capture_edd_order( 2, array() );

		$this->assertSame( 2, $stats->get()['seen'] );
		$this->assertSame( 0, $stats->get()['captured'] );
		$this->assertSame( 0, $stats->get()['last_captured_at'] );
		$this->assertTrue( $stats->is_failing() );
	}

	/**
	 * A click id alone is not enough to match anything in Google Analytics, so
	 * such an order counts as seen but not captured - otherwise the rate would
	 * read as healthy on a store where no Analytics tag fires at all.
	 */
	public function test_an_order_with_only_a_click_id_does_not_count_as_captured(): void {
		$_COOKIE[ AttributionCookies::IDS_COOKIE ] = (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a cookie fixture, not plugin output.
			array(
				'v'     => AttributionCookies::FORMAT_VERSION,
				'gclid' => 'abc123',
			)
		);

		$stats = new CaptureStats( static fn () => 1_800_000_000 );
		$hooks = new CaptureHooks( $stats );

		$order = new \WC_Order( array( 'id' => 42 ) );
		$hooks->capture_woocommerce_order( $order );

		$this->assertSame( 'abc123', $order->get_meta( '_gtm4wp_gclid', true ), 'The click id is still stored - it is useful on its own.' );
		$this->assertSame( 1, $stats->get()['seen'] );
		$this->assertSame( 0, $stats->get()['captured'] );
	}

	/**
	 * The counters measure the recent past rather than the store's whole
	 * history, so a store that fixed its container sees the rate recover
	 * instead of being averaged down forever.
	 */
	public function test_the_counters_halve_at_the_window_boundary(): void {
		$stats = new CaptureStats( static fn () => 1_800_000_000 );

		for ( $i = 0; $i < CaptureStats::WINDOW; $i++ ) {
			$stats->record( true );
		}

		$record = $stats->get();

		$this->assertSame( (int) floor( CaptureStats::WINDOW / 2 ), $record['seen'] );
		$this->assertSame( (int) floor( CaptureStats::WINDOW / 2 ), $record['captured'] );
	}

	public function test_the_counters_start_at_zero_without_a_stored_record(): void {
		$this->assertSame(
			array(
				'seen'             => 0,
				'captured'         => 0,
				'last_seen_at'     => 0,
				'last_captured_at' => 0,
			),
			( new CaptureStats() )->get()
		);
		$this->assertFalse(
			( new CaptureStats() )->is_failing(),
			'A store with no orders yet is not a failing capture.'
		);
	}

	public function test_a_corrupted_stored_record_reads_as_zeroes(): void {
		$this->stub_option_store( array( CaptureStats::OPTION_NAME => 'not an array' ) );

		$this->assertSame( 0, ( new CaptureStats() )->get()['seen'] );
	}
}
