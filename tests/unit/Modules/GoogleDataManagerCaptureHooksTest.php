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
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\GoogleDataManagerModule;
use GTM4WP\Options\Options;
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

		// EDD order meta in memory, stubbed here (TS-16): the pass reads the
		// stored values and the counting marker back (#534-#536).
		$this->edd_meta = array();
		Functions\when( 'edd_get_order_meta' )->alias( fn ( $order_id, $key ) => $this->edd_meta[ $order_id ][ $key ] ?? '' );
		Functions\when( 'edd_update_order_meta' )->alias(
			function ( $order_id, $key, $value ): void {
				$this->edd_meta[ $order_id ][ $key ] = $value;
			}
		);
		Functions\when( 'edd_delete_order_meta' )->alias(
			function ( $order_id, $key ): void {
				unset( $this->edd_meta[ $order_id ][ $key ] );
			}
		);
	}

	/**
	 * EDD order meta written by the stubs, by order id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $edd_meta = array();

	/**
	 * A WooCommerce order that records deletions (the base stub has no
	 * delete_meta_data(), so a deletion would be skipped silently).
	 *
	 * @param array<string, mixed> $meta Stored meta.
	 * @return \WC_Order
	 */
	private static function deletable_order( array $meta = array() ): \WC_Order {
		return new class(
			array(
				'id'   => 42,
				'meta' => $meta,
			)
		) extends \WC_Order {
			/**
			 * Keys deleted.
			 *
			 * @var string[]
			 */
			public array $deleted = array();

			/**
			 * Times save() was called.
			 *
			 * @var int
			 */
			public int $save_count = 0;

			public function delete_meta_data( $key ) {
				$this->deleted[]          = $key;
				$this->saved_meta[ $key ] = '';
			}

			public function save() {
				++$this->save_count;

				return parent::save();
			}
		};
	}

	/**
	 * Sets the two capture cookies.
	 *
	 * @param array<string, mixed>|null $ids     The ids cookie payload (without the version), null for none.
	 * @param array<string, mixed>|null $signals The consent signals, null for no consent cookie.
	 * @return void
	 */
	private static function set_cookies( ?array $ids, ?array $signals ): void {
		$_COOKIE = array();
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a cookie fixture, not plugin output.
		if ( null !== $ids ) {
			$_COOKIE[ AttributionCookies::IDS_COOKIE ] = (string) json_encode( array( 'v' => AttributionCookies::FORMAT_VERSION ) + $ids );
		}

		if ( null !== $signals ) {
			$_COOKIE[ AttributionCookies::CONSENT_COOKIE ] = (string) json_encode(
				array(
					'v'           => AttributionCookies::FORMAT_VERSION,
					'signals'     => $signals,
					'captured_at' => 1_800_000_100,
				)
			);
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
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

		$hooks = $this->hooks();
		$hooks->register_hooks();

		$this->assertSame(
			10,
			has_action( 'woocommerce_checkout_order_created', array( $hooks, 'capture_woocommerce_order' ) ),
			'The classic checkout path, bound to the named callback.'
		);
		$this->assertSame(
			10,
			has_action( 'woocommerce_store_api_checkout_order_processed', array( $hooks, 'capture_woocommerce_order' ) ),
			'The Store API the block checkout posts to - a different hook, not a variant of the first.'
		);
		$this->assertFalse( has_action( 'edd_built_order' ) );
	}

	public function test_registers_the_edd_order_creation_hook(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'WC'  => false,
			'EDD' => true,
		);

		$hooks = $this->hooks();
		$hooks->register_hooks();

		$this->assertSame(
			10,
			has_action( 'edd_built_order', array( $hooks, 'capture_edd_order' ) )
		);
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
		$this->assertSame( '0', $order->get_meta( CaptureHooks::META_COUNTED, true ), 'Only the counting marker is written (#534, maintainer decision 2026-10-22).' );
		$this->assertSame( 1, $order->save_count );
	}

	/**
	 * #536: a later pass is the later observation, so its client id replaces the
	 * stored one (the maintainer's decision: the later observation wins). An
	 * order stored before the counting marker is not counted again.
	 */
	public function test_a_later_pass_replaces_the_stored_client_id(): void {
		$this->set_captured_cookies();
		$order = self::deletable_order( array( AttributionCapture::META_CLIENT_ID => 'the-original-id' ) );
		$stats = new CaptureStats( static fn () => 1_800_000_000 );

		( new CaptureHooks( $stats ) )->capture_woocommerce_order( $order );

		$this->assertSame( '111.222', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( 'abc123', $order->get_meta( '_gtm4wp_gclid', true ) );
		$this->assertSame( 0, $stats->get()['seen'], 'Captured before the marker existed: already counted.' );
		$this->assertSame( '1', $order->get_meta( CaptureHooks::META_COUNTED, true ) );
	}

	/**
	 * #534: a first pass that stored nothing is counted once; the resumed pass
	 * that finds a client id counts as a rescue, not a second order.
	 */
	public function test_an_empty_first_pass_then_a_capturing_resume_counts_one_order(): void {
		$order = self::deletable_order();
		$stats = new CaptureStats( static fn () => 1_800_000_000 );
		$hooks = new CaptureHooks( $stats );

		$hooks->capture_woocommerce_order( $order );
		$hooks->capture_woocommerce_order( $order );
		$this->assertSame( array( 1, 0 ), array( $stats->get()['seen'], $stats->get()['captured'] ), 'Two empty passes, one order.' );

		$this->set_captured_cookies();
		$hooks->capture_woocommerce_order( $order );

		$this->assertSame( array( 1, 1 ), array( $stats->get()['seen'], $stats->get()['captured'] ) );
		$this->assertSame( '1', $order->get_meta( CaptureHooks::META_COUNTED, true ) );
	}

	/**
	 * #536, per signal: a later consent map that did not observe a signal (the
	 * tracker writes an empty map when it saw no consent regime, or a partial
	 * one) keeps the stored value of that signal, so a stored denial stands.
	 */
	public function test_a_later_map_without_a_signal_keeps_the_stored_denial(): void {
		$denied = array(
			'signals'     => array(
				'analytics_storage' => 'denied',
				'ad_storage'        => 'denied',
			),
			'captured_at' => 1_800_000_000,
		);

		foreach ( array(
			'empty map'   => array(),
			'partial map' => array( 'analytics_storage' => 'denied' ),
		) as $label => $signals ) {
			self::set_cookies(
				array(
					'client_id' => '111.222',
					'gclid'     => 'abc123',
				),
				$signals
			);
			$order = self::deletable_order( array( AttributionCapture::META_CONSENT_STATE => $denied ) );

			$this->hooks()->capture_woocommerce_order( $order );

			$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ), $label );
			$this->assertSame( '', (string) $order->get_meta( '_gtm4wp_gclid', true ), $label );
			$this->assertSame( 'denied', $order->get_meta( AttributionCapture::META_CONSENT_STATE, true )['signals']['ad_storage'], $label );
		}
	}

	/**
	 * #536: a pass with ids but no consent cookie is gated by the stored state,
	 * so the ids never sit beside a stored denial.
	 */
	public function test_ids_without_a_consent_cookie_obey_the_stored_denial(): void {
		self::set_cookies( array( 'client_id' => '111.222' ), null );
		$order = self::deletable_order(
			array(
				AttributionCapture::META_CONSENT_STATE => array(
					'signals'     => array( 'analytics_storage' => 'denied' ),
					'captured_at' => 1_800_000_000,
				),
			)
		);

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
	}

	/**
	 * #536: an id group the pass resolved replaces the stored group whole, so a
	 * new client id never sits beside the previous client's session ids.
	 */
	public function test_a_new_client_id_does_not_keep_the_old_clients_sessions(): void {
		self::set_cookies( array( 'client_id' => '333.444' ), array( 'analytics_storage' => 'granted' ) );
		$order = self::deletable_order(
			array(
				AttributionCapture::META_CLIENT_ID   => '111.222',
				AttributionCapture::META_SESSION_IDS => array( 'G-ABC123' => '1788522496' ),
			)
		);

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( '333.444', $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertContains( AttributionCapture::META_SESSION_IDS, $order->deleted );
	}

	/**
	 * #535: the EDD resume mirrors the WooCommerce one: counted once, and a
	 * newer ad denial removes the stored click id.
	 */
	public function test_a_resumed_edd_order_is_counted_once_and_obeys_the_newer_denial(): void {
		$stats = new CaptureStats( static fn () => 1_800_000_000 );
		$hooks = new CaptureHooks( $stats );

		self::set_cookies(
			array(
				'client_id' => '111.222',
				'gclid'     => 'abc123',
			),
			array(
				'analytics_storage' => 'granted',
				'ad_storage'        => 'granted',
			)
		);
		$hooks->capture_edd_order( 42, array() );

		self::set_cookies( null, array( 'ad_storage' => 'denied' ) );
		$hooks->capture_edd_order( 42, array() );

		$this->assertArrayNotHasKey( '_gtm4wp_gclid', $this->edd_meta[42] );
		$this->assertSame( '111.222', $this->edd_meta[42][ AttributionCapture::META_CLIENT_ID ] );
		$this->assertSame(
			array(
				'analytics_storage' => 'granted',
				'ad_storage'        => 'denied',
			),
			$this->edd_meta[42][ AttributionCapture::META_CONSENT_STATE ]['signals']
		);
		$this->assertSame( 1, $stats->get()['seen'] );
	}

	/**
	 * #481: a resumed order runs the capture again. When the first pass stored
	 * no client id, the newest pass replaces what it stored (a click id must
	 * not sit beside a newer denial) and the order is counted once.
	 */
	public function test_a_resumed_order_is_replaced_by_the_newest_pass_and_counted_once(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a cookie fixture, not plugin output.
		$_COOKIE[ AttributionCookies::CONSENT_COOKIE ] = (string) json_encode(
			array(
				'v'           => AttributionCookies::FORMAT_VERSION,
				'signals'     => array(
					'analytics_storage' => 'denied',
					'ad_storage'        => 'denied',
				),
				'captured_at' => 1_800_000_100,
			)
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$order = new class(
			array(
				'id'   => 42,
				'meta' => array(
					'_gtm4wp_gclid'                        => 'abc123',
					AttributionCapture::META_CONSENT_STATE => array(
						'signals'     => array( 'ad_storage' => 'granted' ),
						'captured_at' => 1_800_000_000,
					),
				),
			)
		) extends \WC_Order {
			/**
			 * Keys deleted.
			 *
			 * @var string[]
			 */
			public array $deleted = array();

			public function delete_meta_data( $key ) {
				$this->deleted[]          = $key;
				$this->saved_meta[ $key ] = '';
			}
		};
		$stats = new CaptureStats( static fn () => 1_800_000_100 );

		( new CaptureHooks( $stats ) )->capture_woocommerce_order( $order );

		$this->assertSame( array( '_gtm4wp_gclid' ), $order->deleted );
		$this->assertSame( 'denied', $order->get_meta( AttributionCapture::META_CONSENT_STATE, true )['signals']['ad_storage'] );
		$this->assertSame( 0, $stats->get()['seen'], 'The first pass already counted this order.' );
	}

	/**
	 * #484: a lane left on after its last destination was deleted has nothing
	 * to capture for: no meta is stored and the order is not counted, so
	 * Site Health does not blame the tag for a missing destination.
	 */
	public function test_nothing_is_captured_or_counted_without_a_destination(): void {
		$this->set_captured_cookies();
		$this->options[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GDM_SEND_REFUNDS => true,
			GTM4WP_OPTION_GDM_DESTINATIONS => array(),
		);
		$stats                           = new CaptureStats( static fn () => 1_800_000_000 );
		$order                           = new \WC_Order( array( 'id' => 42 ) );

		( new CaptureHooks( $stats, new Options( ( new GoogleDataManagerModule() )->defaults() ) ) )->capture_woocommerce_order( $order );

		$this->assertSame( '', (string) $order->get_meta( AttributionCapture::META_CLIENT_ID, true ) );
		$this->assertSame( 0, $stats->get()['seen'] );
	}

	/**
	 * #481: a later pass that resolved nothing (cookies gone) is no newer
	 * answer, so it deletes nothing the first pass stored.
	 */
	public function test_a_pass_that_resolved_nothing_keeps_what_the_first_pass_stored(): void {
		$order = new class(
			array(
				'id'   => 42,
				'meta' => array( '_gtm4wp_gclid' => 'abc123' ),
			)
		) extends \WC_Order {
			/**
			 * Keys deleted.
			 *
			 * @var string[]
			 */
			public array $deleted = array();

			public function delete_meta_data( $key ) {
				$this->deleted[]          = $key;
				$this->saved_meta[ $key ] = '';
			}
		};

		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertSame( array(), $order->deleted );
		$this->assertSame( 'abc123', $order->get_meta( '_gtm4wp_gclid', true ) );
	}

	/**
	 * #484, the EDD half, through a real Options (TS-24): no destination means
	 * nothing stored and nothing counted; with one, the order is captured.
	 *
	 * @param bool $with_destination Whether a destination row is stored.
	 */
	#[\PHPUnit\Framework\Attributes\TestWith( array( false ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( true ) )]
	public function test_an_edd_order_is_captured_only_while_a_destination_exists( bool $with_destination ): void {
		$this->set_captured_cookies();
		$this->options[ GTM4WP_OPTIONS ] = array(
			GTM4WP_OPTION_GDM_SEND_REFUNDS => true,
			GTM4WP_OPTION_GDM_DESTINATIONS => $with_destination ? array(
				array(
					DestinationRows::COLUMN_ACCOUNT     => 'sa_0123456789ab',
					DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
					DestinationRows::COLUMN_PROPERTY    => '123456789',
					DestinationRows::COLUMN_MEASUREMENT => 'G-ABC123',
				),
			) : array(),
		);

		$written = array();
		Functions\when( 'edd_update_order_meta' )->alias(
			static function ( $order_id, $key ) use ( &$written ): void {
				$written[] = $key;
			}
		);
		$stats = new CaptureStats( static fn () => 1_800_000_000 );

		( new CaptureHooks( $stats, new Options( ( new GoogleDataManagerModule() )->defaults() ) ) )->capture_edd_order( 42, array() );

		$this->assertSame( $with_destination, in_array( AttributionCapture::META_CLIENT_ID, $written, true ) );
		$this->assertSame( $with_destination ? 1 : 0, $stats->get()['seen'] );
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
				array( 42, CaptureHooks::META_COUNTED, '1' ),
			),
			$written
		);
	}

	/**
	 * Only the counting marker is written for an order with nothing to store
	 * (#534, maintainer decision 2026-10-22).
	 */
	public function test_only_the_counting_marker_is_written_when_nothing_was_captured(): void {
		$this->hooks()->capture_edd_order( 42, array() );

		$this->assertSame( array( CaptureHooks::META_COUNTED => '0' ), $this->edd_meta[42] );
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
		// The EDD meta functions come from setUp() (TS-16): the branch bails on
		// function_exists() before it counts the order.
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

	/**
	 * The counters are written at order creation, which is a checkout request,
	 * so the row must never join the autoloaded set - it is read only on the
	 * settings screen. Same pin as the sibling health record.
	 */
	public function test_the_counter_row_is_never_autoloaded(): void {
		$stats = new CaptureStats( static fn () => 1_800_000_000 );

		$stats->record( true );
		$stats->record( false );

		$this->assertNotEmpty( $this->option_writes );
		foreach ( $this->option_writes as $write ) {
			$this->assertSame( CaptureStats::OPTION_NAME, $write['key'] );
			$this->assertFalse( $write['autoload'], 'Every write keeps the row out of autoload.' );
		}
	}

	public function test_a_corrupted_stored_record_reads_as_zeroes(): void {
		$this->stub_option_store( array( CaptureStats::OPTION_NAME => 'not an array' ) );

		$this->assertSame( 0, ( new CaptureStats() )->get()['seen'] );
	}

	// ---- The consent filter's order reference ------------------------------

	/**
	 * Every second argument the consent filter received.
	 *
	 * @var array<int, mixed>
	 */
	private array $filter_references = array();

	/**
	 * The creation path DEFINES the type contract of the filter's second
	 * argument (RI-31: the WC_Order object on WooCommerce, the order id as an
	 * int on EDD); the backfill route pins the same contract on its own path.
	 * The setUp alias discards the second argument, so without these two the
	 * creation path could hand the filter anything and stay green.
	 *
	 * @return void
	 */
	private function record_filter_references(): void {
		$this->filter_references = array();

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				if ( GTM4WP_WPFILTER_GDM_ORDER_CONSENT === $hook ) {
					$this->filter_references[] = $args[0] ?? null;
				}

				return $value;
			}
		);
	}

	public function test_the_woocommerce_path_hands_the_filter_the_order_object(): void {
		$this->set_captured_cookies();
		$this->record_filter_references();

		$order = new \WC_Order( array( 'id' => 7 ) );
		$this->hooks()->capture_woocommerce_order( $order );

		$this->assertCount( 1, $this->filter_references );
		$this->assertSame( $order, $this->filter_references[0], 'The WC_Order object itself, not its id.' );
	}

	public function test_the_edd_path_hands_the_filter_the_order_id_as_an_int(): void {
		$this->set_captured_cookies();
		$this->record_filter_references();

		Functions\when( 'edd_update_order_meta' )->justReturn( true );

		$this->hooks()->capture_edd_order( '42', array() );

		$this->assertCount( 1, $this->filter_references );
		$this->assertSame( 42, $this->filter_references[0], 'A strict int, whatever type the hook delivered.' );
	}
}
