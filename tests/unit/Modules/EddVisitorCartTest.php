<?php
/**
 * Unit tests for the EDD cache-safe customer/cart delivery.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;
use GTM4WP\Modules\EasyDigitalDownloads\DownloadData;
use GTM4WP\Modules\EasyDigitalDownloads\EasyDigitalDownloadsModule;
use GTM4WP\Modules\EasyDigitalDownloads\PageDataLayer;
use GTM4WP\Modules\EasyDigitalDownloads\VisitorCart;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

require_once __DIR__ . '/edd-stubs.php';

// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- assertions read and snapshot the superglobals the code under test uses.

/**
 * Covers VisitorCart: when the block is declared, the endpoint resolver's
 * identity gates (PA-10, PA-11), the state cookie upkeep (set, unchanged,
 * changed, cleared, headers sent, #44 no-store) and the hook registration.
 */
final class EddVisitorCartTest extends TestCase {

	/**
	 * Options with the mode, tracking and both features on.
	 */
	private const ALL_ON = array(
		GTM4WP_OPTION_CACHE_SAFE_DATALAYER         => true,
		GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE  => true,
		GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA    => true,
		GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => true,
	);

	/**
	 * Captured setcookie() calls as array( name, value, options ).
	 *
	 * @var array<int, array{0:string,1:string,2:array}>
	 */
	private array $cookie_writes = array();

	/**
	 * Number of nocache_headers() calls.
	 *
	 * @var int
	 */
	private int $nocache_calls = 0;

	/**
	 * Snapshot of $_COOKIE / $_GET, restored in tearDown (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $cookie_backup = array();

	/**
	 * Every edd_get_customer_by() call as array( field, value ).
	 *
	 * @var array<int, array{0:mixed,1:mixed}>
	 */
	private array $customer_lookups = array();

	/**
	 * Snapshot of $_GET.
	 *
	 * @var array<string, mixed>
	 */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();

		$this->cookie_backup = $_COOKIE;
		$this->get_backup    = $_GET;
		$_COOKIE             = array();
		$this->cookie_writes = array();
		$this->nocache_calls = 0;

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
		Functions\when( 'edd_get_currency' )->justReturn( 'USD' );
		Functions\when( 'edd_get_download_sku' )->justReturn( false );
		Functions\when( 'edd_get_download' )->alias(
			static fn ( $id ) => new \EDD_Download(
				array(
					'id'    => (int) $id,
					'name'  => 'My eBook',
					'price' => 9.99,
				)
			)
		);
		$this->customer_lookups = array();
		Functions\when( 'edd_get_customer_by' )->alias(
			function ( $field, $value ) {
				$this->customer_lookups[] = array( $field, $value );

				return new \EDD_Customer(
					array(
						'name'           => 'Jane Doe',
						'email'          => 'jane@example.com',
						'purchase_count' => 4,
						'purchase_value' => 100.5,
					)
				);
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_get_session_token' )->justReturn( 'tok1' );
		Functions\when( 'wp_hash' )->alias( static fn ( $data ) => md5( (string) $data ) );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'nocache_headers' )->alias(
			function () {
				++$this->nocache_calls;
			}
		);
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '', $options = array() ) {
				$this->cookie_writes[] = array( (string) $name, (string) $value, (array) $options );
				return true;
			}
		);

		$this->set_cart( array( 55 ) );
	}

	protected function tearDown(): void {
		$_COOKIE = $this->cookie_backup;
		$_GET    = $this->get_backup;

		parent::tearDown();
	}

	/**
	 * Stubs the EDD cart with one line per download id.
	 *
	 * @param int[] $download_ids Download ids in the cart.
	 * @return void
	 */
	private function set_cart( array $download_ids ): void {
		$lines = array();
		foreach ( $download_ids as $id ) {
			$lines[] = array(
				'id'       => $id,
				'quantity' => 1,
				'price'    => 9.99,
				'tax'      => 0.0,
				'discount' => 0.0,
			);
		}

		Functions\when( 'edd_get_cart_content_details' )->justReturn( $lines );
		Functions\when( 'edd_get_cart_subtotal' )->justReturn( 9.99 * count( $lines ) );
		Functions\when( 'edd_get_cart_total' )->justReturn( 9.99 * count( $lines ) );
	}

	/**
	 * Logs a user in (id 5).
	 *
	 * @return void
	 */
	private function log_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
	}

	/**
	 * Builds the stored-options Options service.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return Options
	 */
	private function options( array $stored ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Options( ( new EasyDigitalDownloadsModule() )->defaults() );
	}

	/**
	 * Builds a VisitorCart over the given stored options.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return VisitorCart
	 */
	private function make( array $stored = self::ALL_ON ): VisitorCart {
		$options = $this->options( $stored );

		return new VisitorCart( $options, new PageDataLayer( $options, new DownloadData( $options ), new DataLayer( $options ) ) );
	}

	public function test_is_enabled_needs_the_mode_tracking_and_one_feature(): void {
		$this->assertTrue( VisitorCart::is_enabled( $this->options( self::ALL_ON ) ) );
		$this->assertTrue( VisitorCart::is_enabled( $this->options( array( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA => false ) + self::ALL_ON ) ) );
		$this->assertTrue( VisitorCart::is_enabled( $this->options( array( GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => false ) + self::ALL_ON ) ) );

		$this->assertFalse( VisitorCart::is_enabled( $this->options( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ) + self::ALL_ON ) ), 'Mode off: the page renders the blocks itself.' );
		$this->assertFalse( VisitorCart::is_enabled( $this->options( array( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE => false ) + self::ALL_ON ) ) );
		$this->assertFalse(
			VisitorCart::is_enabled(
				$this->options(
					array(
						GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA    => false,
						GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => false,
					) + self::ALL_ON
				)
			)
		);
	}

	public function test_declares_one_gated_block_field_under_cache_safe_mode(): void {
		$fields = $this->make()->declare_visitor_scoped_fields( array() );

		$this->assertCount( 1, $fields );
		$field = $fields[0];
		$this->assertInstanceOf( VisitorField::class, $field );
		$this->assertSame( VisitorCart::FIELD_KEY, $field->key );
		$this->assertSame( VisitorField::TIER_ACTION, $field->tier );
		$this->assertSame( VisitorCart::STATE_COOKIE, $field->cookie_gate );
		$this->assertTrue( $field->block, 'The value is a { customer, cart } pair, never a visitorData key.' );
		$this->assertFalse( $field->one_shot );
		$this->assertTrue( is_callable( $field->resolver ) );
	}

	public function test_declares_nothing_while_the_mode_is_off(): void {
		$sentinel = array( 'already-declared' );

		$this->assertSame( $sentinel, $this->make( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ) + self::ALL_ON )->declare_visitor_scoped_fields( $sentinel ) );
	}

	public function test_resolver_does_not_touch_the_session_for_a_guest_without_edd_state(): void {
		Functions\expect( 'edd_get_cart_content_details' )->never();

		$this->assertNull( $this->make()->resolve_visitor_cart() );
	}

	public function test_resolver_returns_the_cart_but_no_customer_for_a_guest_with_an_edd_session(): void {
		$_COOKIE['wp_edd_session_abc123'] = 'opaque';

		$block = $this->make()->resolve_visitor_cart();

		$this->assertArrayNotHasKey( 'customer', $block, 'A guest has no customer record to show.' );
		$this->assertCount( 1, $block['cart']['cartContent']['items'] );
	}

	public function test_resolver_takes_the_customer_from_the_logged_in_user_never_the_request(): void {
		$this->log_in();
		$_GET['user_id'] = '9';

		$block = $this->make()->resolve_visitor_cart();

		$this->assertSame( array( array( 'user_id', 5 ) ), $this->customer_lookups, 'PA-10: the id comes from the session, never ?user_id=.' );
		$this->assertSame( 'jane@example.com', $block['customer']['customerEmail'] );
	}

	public function test_resolver_returns_null_when_both_features_are_off(): void {
		$this->log_in();

		$visitor_cart = $this->make(
			array(
				GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA    => false,
				GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL => false,
			) + self::ALL_ON
		);

		$this->assertNull( $visitor_cart->resolve_visitor_cart() );
	}

	public function test_state_cookie_is_set_to_an_opaque_js_readable_fingerprint(): void {
		$this->log_in();

		$this->make()->maintain_state_cookie();

		$this->assertCount( 1, $this->cookie_writes );
		list( $name, $value, $options ) = $this->cookie_writes[0];
		$this->assertSame( VisitorCart::STATE_COOKIE, $name );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{20}$/', $value );
		$this->assertStringNotContainsString( 'jane', $value, 'The cookie carries a hash, never a value.' );
		$this->assertFalse( $options['httponly'], 'The runtime must be able to read the gate.' );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertTrue( $options['secure'] );
		$this->assertGreaterThan( time(), $options['expires'] );
		$this->assertSame( $value, $_COOKIE[ VisitorCart::STATE_COOKIE ] );
		$this->assertSame( 1, $this->nocache_calls, 'A Set-Cookie response must never be cached (#44).' );
	}

	public function test_state_cookie_is_not_rewritten_while_current(): void {
		$this->log_in();
		$visitor_cart = $this->make();
		$visitor_cart->maintain_state_cookie();
		$this->cookie_writes = array();
		$this->nocache_calls = 0;

		$visitor_cart->maintain_state_cookie();

		$this->assertSame( array(), $this->cookie_writes );
		$this->assertSame( 0, $this->nocache_calls );
	}

	public function test_state_cookie_changes_when_the_cart_changes(): void {
		$_COOKIE['edd_session_x'] = 'opaque';
		$visitor_cart             = $this->make();
		$visitor_cart->maintain_state_cookie();
		$first = $_COOKIE[ VisitorCart::STATE_COOKIE ];

		$this->set_cart( array( 55, 66 ) );
		$visitor_cart->maintain_state_cookie();

		$this->assertCount( 2, $this->cookie_writes );
		$this->assertNotSame( $first, $_COOKIE[ VisitorCart::STATE_COOKIE ] );
	}

	public function test_state_cookie_is_bound_to_the_login_session(): void {
		$this->log_in();
		$visitor_cart = $this->make();
		$visitor_cart->maintain_state_cookie();
		$first = $_COOKIE[ VisitorCart::STATE_COOKIE ];

		Functions\when( 'wp_get_session_token' )->justReturn( 'tok2' );
		$visitor_cart->maintain_state_cookie();

		$this->assertNotSame( $first, $_COOKIE[ VisitorCart::STATE_COOKIE ], 'A re-login must re-fetch, not replay the cached block.' );
	}

	public function test_state_cookie_is_cleared_for_a_guest_with_an_empty_cart(): void {
		$this->set_cart( array() );
		$_COOKIE[ VisitorCart::STATE_COOKIE ] = 'stale0123456789abcde';

		$this->make()->maintain_state_cookie();

		$this->assertCount( 1, $this->cookie_writes );
		$this->assertSame( '', $this->cookie_writes[0][1] );
		$this->assertLessThan( time(), $this->cookie_writes[0][2]['expires'] );
		$this->assertArrayNotHasKey( VisitorCart::STATE_COOKIE, $_COOKIE );
		$this->assertSame( 1, $this->nocache_calls );
	}

	public function test_state_cookie_is_not_written_for_an_idle_guest(): void {
		$this->set_cart( array() );

		$this->make()->maintain_state_cookie();

		$this->assertSame( array(), $this->cookie_writes );
		$this->assertSame( 0, $this->nocache_calls );
	}

	public function test_state_cookie_is_not_written_after_headers_sent(): void {
		$this->log_in();
		Functions\when( 'headers_sent' )->justReturn( true );

		$this->make()->maintain_state_cookie();

		$this->assertSame( array(), $this->cookie_writes );
		$this->assertArrayNotHasKey( VisitorCart::STATE_COOKIE, $_COOKIE );
	}

	public function test_page_hook_leaves_a_guest_without_the_cookie_alone(): void {
		Functions\expect( 'edd_get_cart_content_details' )->never();

		$this->make()->maintain_state_cookie_on_page();

		$this->assertSame( array(), $this->cookie_writes );
	}

	public function test_page_hook_retires_a_guest_cookie_that_has_nothing_left_to_deliver(): void {
		$this->set_cart( array() );
		$_COOKIE[ VisitorCart::STATE_COOKIE ] = 'stale0123456789abcde';

		$this->make()->maintain_state_cookie_on_page();

		$this->assertSame( '', $this->cookie_writes[0][1] ?? null );
	}

	public function test_page_hook_keeps_a_logged_in_customer_current(): void {
		$this->log_in();

		$this->make()->maintain_state_cookie_on_page();

		$this->assertSame( VisitorCart::STATE_COOKIE, $this->cookie_writes[0][0] ?? null );
	}

	public function test_state_hooks_are_registered_on_every_edd_cart_mutation_when_enabled(): void {
		VisitorCart::register_state_hooks( $this->options( self::ALL_ON ) );

		// U170: the exact set of EDD actions the gate follows.
		foreach ( array( 'edd_post_add_to_cart', 'edd_post_remove_from_cart', 'edd_after_set_cart_item_quantity', 'edd_empty_cart', 'edd_cart_discounts_updated', 'edd_cart_discounts_removed', 'wp_logout' ) as $hook ) {
			$this->assertSame( 20, has_action( $hook, VisitorCart::class . '->maintain_state_cookie()' ), $hook );
		}
		$this->assertNotFalse( has_action( 'template_redirect', VisitorCart::class . '->maintain_state_cookie_on_page()' ) );
	}

	public function test_no_state_hooks_while_the_mode_is_off(): void {
		VisitorCart::register_state_hooks( $this->options( array( GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false ) + self::ALL_ON ) );

		$this->assertFalse( has_action( 'edd_post_add_to_cart' ) );
		$this->assertFalse( has_action( 'template_redirect' ) );
		$this->assertFalse( has_action( 'wp_logout' ) );
	}
}
