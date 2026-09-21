<?php
/**
 * Unit tests for the personal-data export and erasure of captured attribution.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\PrivacyData;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * Uninstalling deliberately leaves the attribution meta on the orders, so
 * these two callbacks are the per-person removal path rather than a
 * convenience. That makes the erasure assertions the important ones: what
 * matters is not that the eraser ran but that afterwards the data is gone and
 * the request was told so.
 *
 * Both platforms are covered by the same suite for the same reason as the
 * capture hooks - a privacy feature that quietly only works on WooCommerce is
 * worse than none, because the request reports success either way.
 */
final class GoogleDataManagerPrivacyTest extends TestCase {

	private const EMAIL = 'buyer@example.com';

	/**
	 * Meta the stubbed EDD order carries, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $edd_meta = array();

	/**
	 * Meta keys deleted from the stubbed EDD order.
	 *
	 * @var array<int, string>
	 */
	private array $edd_deleted = array();

	protected function setUp(): void {
		parent::setUp();

		$this->edd_meta    = array();
		$this->edd_deleted = array();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The stand-in for the function under stub.
			static fn ( $value, $flags = 0 ) => json_encode( $value, $flags )
		);

		// Neither platform present unless a test says so, forced through the
		// namespaced shim: Brain Monkey defines a mocked function
		// process-wide, so without this the "platform absent" cases would
		// depend on which test file ran first (TS-16).
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'wc_get_orders'         => false,
			'edd_get_orders'        => false,
			'edd_get_order_meta'    => false,
			'edd_delete_order_meta' => false,
		);
	}

	protected function tearDown(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array();

		parent::tearDown();
	}

	/**
	 * A WooCommerce order carrying the given meta, findable by wc_get_orders().
	 *
	 * @param array<string, mixed> $meta Meta the order carries.
	 * @return \WC_Order
	 */
	private function given_woocommerce_order( array $meta ): \WC_Order {
		$order = new class(array(
			'id'   => 42,
			'meta' => $meta,
		)) extends \WC_Order {
			/**
			 * Keys removed through delete_meta_data().
			 *
			 * @var array<int, string>
			 */
			public array $deleted = array();

			public function get_id() {
				return 42;
			}

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

		$GLOBALS['gtm4wp_test_forced_functions']['wc_get_orders'] = true;

		Functions\when( 'wc_get_orders' )->alias(
			static fn ( $args ) => ( self::EMAIL === ( $args['billing_email'] ?? '' ) && 1 === (int) ( $args['paged'] ?? 1 ) )
				? array( $order )
				: array()
		);

		return $order;
	}

	/**
	 * An EDD order carrying the given meta.
	 *
	 * @param array<string, mixed> $meta Meta the order carries.
	 * @return void
	 */
	private function given_edd_order( array $meta ): void {
		$this->edd_meta = $meta;

		$GLOBALS['gtm4wp_test_forced_functions']['edd_get_orders']        = true;
		$GLOBALS['gtm4wp_test_forced_functions']['edd_get_order_meta']    = true;
		$GLOBALS['gtm4wp_test_forced_functions']['edd_delete_order_meta'] = true;

		Functions\when( 'edd_get_orders' )->alias(
			static fn ( $args ) => ( self::EMAIL === ( $args['email'] ?? '' ) && 0 === (int) ( $args['offset'] ?? 0 ) )
				? array( (object) array( 'id' => 77 ) )
				: array()
		);

		Functions\when( 'edd_get_order_meta' )->alias(
			fn ( $order_id, $key, $single = false ) => $this->edd_meta[ $key ] ?? ''
		);

		Functions\when( 'edd_delete_order_meta' )->alias(
			function ( $order_id, $key ): void {
				$this->edd_deleted[] = $key;
				unset( $this->edd_meta[ $key ] );
			}
		);
	}

	/**
	 * A full set of captured attribution.
	 *
	 * @return array<string, mixed>
	 */
	private static function captured(): array {
		return array(
			AttributionCapture::META_CLIENT_ID     => '111.222',
			AttributionCapture::META_SESSION_IDS   => array( 'G-ABC123' => '1788522496' ),
			'_gtm4wp_gclid'                        => 'abc123',
			AttributionCapture::META_CONSENT_STATE => array( 'signals' => array( 'analytics_storage' => 'granted' ) ),
		);
	}

	// ---- Registration ------------------------------------------------------

	public function test_registers_both_callbacks(): void {
		$privacy = new PrivacyData();
		$privacy->register_hooks();

		// The named callbacks at the default priority, not merely something on
		// the hooks (TC-4).
		$this->assertSame(
			10,
			has_filter( 'wp_privacy_personal_data_exporters', array( $privacy, 'register_exporter' ) )
		);
		$this->assertSame(
			10,
			has_filter( 'wp_privacy_personal_data_erasers', array( $privacy, 'register_eraser' ) )
		);
	}

	public function test_the_callbacks_are_added_under_this_plugins_own_group(): void {
		$privacy = new PrivacyData();

		$exporters = $privacy->register_exporter( array() );
		$erasers   = $privacy->register_eraser( array() );

		$this->assertArrayHasKey( PrivacyData::GROUP_ID, $exporters );
		$this->assertArrayHasKey( PrivacyData::GROUP_ID, $erasers );
		$this->assertIsCallable( $exporters[ PrivacyData::GROUP_ID ]['callback'] );
		$this->assertIsCallable( $erasers[ PrivacyData::GROUP_ID ]['callback'] );
	}

	public function test_existing_registrations_are_preserved(): void {
		$existing = array( 'someone-else' => array( 'exporter_friendly_name' => 'Theirs' ) );

		$result = ( new PrivacyData() )->register_exporter( $existing );

		$this->assertArrayHasKey( 'someone-else', $result );
		$this->assertCount( 2, $result );
	}

	// ---- Export ------------------------------------------------------------

	public function test_exports_every_captured_field_of_a_woocommerce_order(): void {
		$this->given_woocommerce_order( self::captured() );

		$result = ( new PrivacyData() )->export( self::EMAIL );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertSame( '111.222', $values['Google Analytics client ID'] );
		$this->assertSame( 'abc123', $values['Google Ads click ID (gclid)'] );
		$this->assertStringContainsString( '1788522496', $values['Google Analytics session IDs'] );
		$this->assertStringContainsString( 'analytics_storage', $values['Consent state at the time of the order'] );
	}

	public function test_exports_an_edd_order_the_same_way(): void {
		$this->given_edd_order( self::captured() );

		$result = ( new PrivacyData() )->export( self::EMAIL );

		$this->assertCount( 1, $result['data'] );

		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertSame( '111.222', $values['Google Analytics client ID'] );
		$this->assertSame( 'abc123', $values['Google Ads click ID (gclid)'] );
	}

	/**
	 * An order that never carried attribution produces no entry at all, rather
	 * than an entry full of empty fields - the export should say what is held,
	 * not list what is not.
	 */
	public function test_an_order_without_attribution_produces_no_entry(): void {
		$this->given_woocommerce_order( array() );

		$result = ( new PrivacyData() )->export( self::EMAIL );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_only_the_fields_that_are_present_are_exported(): void {
		$this->given_woocommerce_order( array( AttributionCapture::META_CLIENT_ID => '111.222' ) );

		$result = ( new PrivacyData() )->export( self::EMAIL );

		$this->assertCount( 1, $result['data'][0]['data'] );
	}

	public function test_an_export_for_somebody_else_finds_nothing(): void {
		$this->given_woocommerce_order( self::captured() );

		$result = ( new PrivacyData() )->export( 'someone-else@example.com' );

		$this->assertSame( array(), $result['data'] );
	}

	public function test_an_empty_email_finds_nothing(): void {
		$this->given_woocommerce_order( self::captured() );

		$this->assertSame( array(), ( new PrivacyData() )->export( '' )['data'] );
	}

	public function test_the_export_is_done_when_neither_platform_is_active(): void {
		$result = ( new PrivacyData() )->export( self::EMAIL );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The paging contract: a full page must not report done, or the request
	 * stops before it has seen every order.
	 */
	public function test_a_full_page_is_not_reported_as_done(): void {
		$GLOBALS['gtm4wp_test_forced_functions']['wc_get_orders'] = true;

		$orders = array();
		for ( $i = 0; $i < PrivacyData::PER_PAGE; $i++ ) {
			$orders[] = new \WC_Order(
				array(
					'id'   => $i + 1,
					'meta' => array( AttributionCapture::META_CLIENT_ID => '111.' . $i ),
				)
			);
		}

		Functions\when( 'wc_get_orders' )->alias(
			static fn ( $args ) => 1 === (int) ( $args['paged'] ?? 1 ) ? $orders : array()
		);

		$first  = ( new PrivacyData() )->export( self::EMAIL, 1 );
		$second = ( new PrivacyData() )->export( self::EMAIL, 2 );

		$this->assertFalse( $first['done'], 'A full page means there may be more.' );
		$this->assertCount( PrivacyData::PER_PAGE, $first['data'] );
		$this->assertTrue( $second['done'] );
	}

	// ---- Erasure -----------------------------------------------------------

	/**
	 * The assertion that matters: afterwards the data is gone. Asserting only
	 * that the eraser ran, or only that it reported a removal, would pass on a
	 * version that reported success and deleted nothing - which is the failure
	 * mode with real consequences here, since uninstalling leaves this meta in
	 * place by design.
	 */
	public function test_erases_every_captured_field_of_a_woocommerce_order(): void {
		$order = $this->given_woocommerce_order( self::captured() );

		$result = ( new PrivacyData() )->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );

		foreach ( array_keys( self::captured() ) as $key ) {
			$this->assertContains( $key, $order->deleted );
		}

		// Persistence, not just staging: the stub answers get_meta() out of
		// what was staged on the object, so an eraser that never saved would
		// look identical here while the row survived in the database - and the
		// request would have reported success (TS-3). Exactly once: the CRUD
		// stages every delete_meta_data() and one save() writes them all, so a
		// save per key would be five needless writes per order per erasure page.
		$this->assertSame( 1, $order->save_count, 'The deletions are written to the database, in one write per order.' );

		$this->assertSame(
			array(),
			( new PrivacyData() )->export( self::EMAIL )['data'],
			'A second export finds nothing left to report.'
		);
	}

	public function test_erases_an_edd_order_the_same_way(): void {
		$this->given_edd_order( self::captured() );

		$result = ( new PrivacyData() )->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );

		foreach ( array_keys( self::captured() ) as $key ) {
			$this->assertContains( $key, $this->edd_deleted );
		}

		$this->assertSame( array(), $this->edd_meta );
	}

	/**
	 * Only this plugin's own keys are touched: an eraser that reached beyond
	 * them would delete another plugin's data on a request meant for ours.
	 */
	public function test_nothing_outside_the_plugins_own_keys_is_deleted(): void {
		$order = $this->given_woocommerce_order(
			array_merge(
				self::captured(),
				array(
					'_ga_tracked'    => 1,
					'_billing_email' => self::EMAIL,
					'_order_total'   => '99.00',
				)
			)
		);

		( new PrivacyData() )->erase( self::EMAIL );

		$this->assertSame(
			array(),
			array_diff( $order->deleted, AttributionCapture::meta_keys() ),
			'Every deleted key must be one this plugin wrote.'
		);
	}

	public function test_erasing_an_order_with_nothing_stored_reports_no_removal(): void {
		$order = $this->given_woocommerce_order( array() );

		$result = ( new PrivacyData() )->erase( self::EMAIL );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $order->save_count, 'An order with nothing of ours on it is not written to.' );
	}

	public function test_an_erasure_for_somebody_else_removes_nothing(): void {
		$order = $this->given_woocommerce_order( self::captured() );

		$result = ( new PrivacyData() )->erase( 'someone-else@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertSame( array(), $order->deleted );
	}

	public function test_the_erasure_is_done_when_neither_platform_is_active(): void {
		$result = ( new PrivacyData() )->erase( self::EMAIL );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}
}
