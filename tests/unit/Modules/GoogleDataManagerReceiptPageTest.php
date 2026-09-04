<?php
/**
 * Unit tests for the backfill flag printed on a confirmation page.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\BackfillEndpoint;
use GTM4WP\Modules\GoogleDataManager\ReceiptPage;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/wc-stubs.php';

/**
 * The flag exists because the client cannot read order meta: only the server
 * knows whether capture already succeeded, so only the server can say "this
 * order still needs it". Two properties are asserted throughout:
 *
 * - It appears **only** when the order genuinely lacks attribution, so a
 *   receipt view does not cost a pointless write attempt.
 * - It carries nothing the visitor did not already have. The order id and the
 *   purchase secret are both in the address bar of the very page being
 *   rendered, which is why this discloses nothing even though the route it
 *   feeds is open to guests.
 */
final class GoogleDataManagerReceiptPageTest extends TestCase {

	private const ORDER_ID  = 42;
	private const ORDER_KEY = 'wc_order_aBcDeF123456';

	protected function setUp(): void {
		parent::setUp();

		$_GET = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'rest_url' )->alias( static fn ( $path ) => 'https://shop.example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'a-rest-nonce' );

		// Neither confirmation page by default; each test opts in.
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'edd_is_success_page' )->justReturn( false );
	}

	protected function tearDown(): void {
		$_GET                                    = array();
		$GLOBALS['gtm4wp_test_forced_functions'] = array();

		parent::tearDown();
	}

	/**
	 * Puts the request on a WooCommerce order-received page for an order with
	 * the given meta.
	 *
	 * @param array<string, mixed> $meta Meta the order carries.
	 * @return void
	 */
	private function given_wc_receipt_page( array $meta = array() ): void {
		Functions\when( 'is_order_received_page' )->justReturn( true );

		$_GET = array(
			'order-received' => (string) self::ORDER_ID,
			'key'            => self::ORDER_KEY,
		);

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
	}

	// ---- WooCommerce -------------------------------------------------------

	public function test_flags_a_woocommerce_order_that_has_no_attribution_yet(): void {
		$this->given_wc_receipt_page();

		$this->assertSame(
			array(
				'url'      => 'https://shop.example.com/wp-json/gtm4wp/v2' . BackfillEndpoint::REST_ROUTE,
				'nonce'    => 'a-rest-nonce',
				'platform' => BackfillEndpoint::PLATFORM_WC,
				'order'    => '42',
				'token'    => self::ORDER_KEY,
			),
			ReceiptPage::backfill_config()
		);
	}

	public function test_no_flag_when_the_order_already_carries_attribution(): void {
		$this->given_wc_receipt_page( array( AttributionCapture::META_CLIENT_ID => '111.222' ) );

		$this->assertNull(
			ReceiptPage::backfill_config(),
			'Capture already succeeded, so a backfill POST would be a write attempt for nothing.'
		);
	}

	/**
	 * The flag is only printed to a visitor who arrived holding the order key,
	 * so a receipt URL with a guessed id yields nothing - the same rule the
	 * route itself enforces, applied one step earlier.
	 */
	public function test_no_flag_without_the_matching_order_key(): void {
		$this->given_wc_receipt_page();
		$_GET['key'] = 'wc_order_guessed';

		$this->assertNull( ReceiptPage::backfill_config() );
	}

	public function test_no_flag_without_an_order_key_at_all(): void {
		$this->given_wc_receipt_page();
		unset( $_GET['key'] );

		$this->assertNull( ReceiptPage::backfill_config() );
	}

	public function test_no_flag_for_an_order_that_does_not_exist(): void {
		$this->given_wc_receipt_page();
		$_GET['order-received'] = '9999';

		$this->assertNull( ReceiptPage::backfill_config() );
	}

	public function test_no_flag_away_from_a_confirmation_page(): void {
		$this->assertNull( ReceiptPage::backfill_config() );
	}

	/**
	 * The printed object carries exactly five members. Pinned as an exact set
	 * because this is the one place where server-side order state is offered
	 * to the page: a field added here without thinking is how an order total,
	 * an email address or an internal id ends up in the HTML of a page a
	 * shared receipt link can open.
	 */
	public function test_the_flag_carries_nothing_beyond_the_five_expected_members(): void {
		$this->given_wc_receipt_page();

		$this->assertSame(
			array( 'url', 'nonce', 'platform', 'order', 'token' ),
			array_keys( ReceiptPage::backfill_config() )
		);
	}

	// ---- Easy Digital Downloads --------------------------------------------

	public function test_flags_an_edd_order_that_has_no_attribution_yet(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET = array( 'payment_key' => 'edd-payment-key-abc' );

		Functions\when( 'edd_get_order_by' )->alias(
			static fn ( $field, $value ) => ( 'payment_key' === $field && 'edd-payment-key-abc' === $value )
				? (object) array( 'id' => self::ORDER_ID )
				: false
		);
		Functions\when( 'edd_get_order_meta' )->justReturn( '' );

		$this->assertSame(
			array(
				'url'      => 'https://shop.example.com/wp-json/gtm4wp/v2' . BackfillEndpoint::REST_ROUTE,
				'nonce'    => 'a-rest-nonce',
				'platform' => BackfillEndpoint::PLATFORM_EDD,
				'order'    => '42',
				'token'    => 'edd-payment-key-abc',
			),
			ReceiptPage::backfill_config()
		);
	}

	public function test_no_edd_flag_when_the_order_already_carries_attribution(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET = array( 'payment_key' => 'edd-payment-key-abc' );

		Functions\when( 'edd_get_order_by' )->justReturn( (object) array( 'id' => self::ORDER_ID ) );
		Functions\when( 'edd_get_order_meta' )->justReturn( '111.222' );

		$this->assertNull( ReceiptPage::backfill_config() );
	}

	/**
	 * The order is resolved through EDD's own verified receipt chain, so a
	 * success page reached without a usable proof yields no flag - a bare,
	 * guessable order id must not be enough.
	 */
	public function test_no_edd_flag_without_a_resolvable_payment_key(): void {
		Functions\when( 'edd_is_success_page' )->justReturn( true );
		$_GET = array( 'id' => (string) self::ORDER_ID );

		Functions\when( 'edd_get_purchase_session' )->justReturn( array() );
		Functions\when( 'edd_get_order_by' )->justReturn( false );

		$this->assertNull( ReceiptPage::backfill_config() );
	}

	public function test_no_flag_when_the_platform_functions_are_absent(): void {
		$GLOBALS['gtm4wp_test_forced_functions'] = array(
			'is_order_received_page' => false,
			'edd_is_success_page'    => false,
		);

		$this->assertNull( ReceiptPage::backfill_config() );
	}
}
