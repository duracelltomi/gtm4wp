<?php
/**
 * Unit tests for the DataLayer service.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DataLayer;

/**
 * Ports the behavioral contract of the data layer parts of 1.x:
 * data layer naming, compile filter and the additional push queue.
 */
final class DataLayerTest extends FrontendTestCase {

	public function test_name_defaults_to_datalayer(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertSame( 'dataLayer', $datalayer->name() );
	}

	public function test_name_uses_configured_value(): void {
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 'myDataLayer' ) )
		);

		$this->assertSame( 'myDataLayer', $datalayer->name() );
	}

	public function test_name_falls_back_when_configured_value_is_not_a_string(): void {
		// A corrupted/non-string stored value must not reach the script output;
		// name() falls back to the safe default.
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => 12345 ) )
		);

		$this->assertSame( 'dataLayer', $datalayer->name() );
	}

	/**
	 * Finding #114, read side (PA-2: re-validate at the output sink, do not
	 * trust a value because it was sanitized on save). The name is emitted
	 * unquoted - `var <name> = <name> || [];` - so a stored value that is not a
	 * JavaScript identifier would make the whole head <script> block a
	 * SyntaxError, taking the data layer initialization down with it.
	 *
	 * The save side alone cannot cover this: 1.x accepted hyphenated names and
	 * the migration stores them verbatim, so an upgrading site arrives here
	 * with a value this line has to reject on its own.
	 *
	 * @param mixed $stored The stored option value.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_unusable_datalayer_names' )]
	public function test_name_falls_back_when_the_stored_value_is_not_a_js_identifier( $stored ): void {
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => $stored ) )
		);

		$this->assertSame( 'dataLayer', $datalayer->name() );
	}

	/**
	 * Stored values that cannot be emitted as a bare JavaScript identifier.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_unusable_datalayer_names(): array {
		return array(
			// The finding: accepted by the 1.x sanitizer, fatal in the emitted JS.
			'a hyphen (1.x accepted this)' => array( 'my-layer' ),
			'a leading digit'              => array( '2layer' ),
			'a space'                      => array( 'my layer' ),
			'a dot'                        => array( 'my.layer' ),
			'a quote'                      => array( 'a"b' ),
			'a script break-out'           => array( "a\x3c/script\x3e" ),
			'empty'                        => array( '' ),
			'whitespace only'              => array( '   ' ),
			'a non-string'                 => array( 12345 ),
			'an array'                     => array( array( 'unexpected' ) ),
		);
	}

	/**
	 * The other direction: a name that IS a valid identifier still reaches the
	 * output untouched, including the two leading characters the old rule
	 * wrongly excluded. Without this the fallback above could pass by always
	 * returning the default.
	 *
	 * @param string $stored The stored option value.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_usable_datalayer_names' )]
	public function test_name_keeps_a_valid_js_identifier( string $stored ): void {
		$datalayer = new DataLayer(
			$this->make_options( array( GTM4WP_OPTION_DATALAYER_NAME => $stored ) )
		);

		$this->assertSame( $stored, $datalayer->name() );
	}

	/**
	 * Stored values that are usable as a bare JavaScript identifier.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_usable_datalayer_names(): array {
		return array(
			'a plain rename'       => array( 'myDataLayer' ),
			'a trailing digit'     => array( 'dataLayer2' ),
			'an underscore inside' => array( 'my_data_layer' ),
			'a leading underscore' => array( '_private' ),
			'a leading dollar'     => array( '$dl' ),
		);
	}

	public function test_compile_applies_public_filter_and_mirrors_global(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->once()
			->with( array() )
			->andReturn( array( 'pagePostType' => 'post' ) );

		$datalayer = new DataLayer( $this->make_options() );
		$compiled  = $datalayer->compile();

		$this->assertSame( array( 'pagePostType' => 'post' ), $compiled );
		$this->assertSame( array( 'pagePostType' => 'post' ), $GLOBALS['gtm4wp_datalayer_data'] );
	}

	public function test_compiled_is_empty_before_compile_and_cached_after(): void {
		// compiled() is the read-only accessor the AMP module uses; it must not
		// re-run the compile filter (asserted via ->once()).
		Filters\expectApplied( GTM4WP_WPFILTER_COMPILE_DATALAYER )
			->once()
			->andReturn( array( 'pagePostType' => 'post' ) );

		$datalayer = new DataLayer( $this->make_options() );

		$this->assertSame( array(), $datalayer->compiled(), 'compiled() is empty before compile().' );

		$datalayer->compile();

		$this->assertSame( array( 'pagePostType' => 'post' ), $datalayer->compiled() );
		$this->assertSame( array( 'pagePostType' => 'post' ), $datalayer->compiled(), 'Repeated reads must not re-run the compile filter.' );
	}

	public function test_queue_push_rejects_invalid_parameters(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertFalse( $datalayer->queue_push( 123 ) );
		$this->assertFalse( $datalayer->queue_push( 'event', 'not-an-array' ) );
	}

	public function test_queue_push_appends_to_compat_global(): void {
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertTrue( $datalayer->queue_push( 'gtm4wp.test', array( 'foo' => 'bar' ), 'before();', 'after();' ) );

		$this->assertCount( 1, $GLOBALS['gtm4wp_additional_datalayer_pushes'] );
		$this->assertSame(
			array(
				// #348: event serializes first, matching the client-side push helper.
				'datalayer_object' => array(
					'event' => 'gtm4wp.test',
					'foo'   => 'bar',
				),
				'js_before'        => 'before();',
				'js_after'         => 'after();',
				'js_wrapper'       => '',
				'js_wrapper_args'  => array(),
			),
			$GLOBALS['gtm4wp_additional_datalayer_pushes'][0]
		);
	}

	/**
	 * #405: the product-detail view_item is rendered into cacheable HTML, so the
	 * visitor's originating list has to be merged in the browser. The push is
	 * therefore wrapped in a JS call whose argument list carries the lookup id.
	 *
	 * The emitted call resolves the function off window with an identity fallback
	 * on purpose: the helper lives in a separate bundle, and a load order that
	 * puts it after this inline script must cost the attribution, never the event.
	 *
	 * @return void
	 */
	public function test_flush_pushes_wraps_the_object_in_the_named_js_function(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'view_item', array( 'value' => 42 ), '', '', 'gtm4wp_enrich', array( 123, 'list' ) );

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$this->assertStringContainsString(
			'dataLayer.push((window.gtm4wp_enrich||function(d){return d;})({"event":"view_item","value":42},123,"list"));',
			$captured[0]
		);
	}

	public function test_flush_pushes_emits_a_plain_push_without_a_wrapper(): void {
		// The wrapper is opt-in: with no wrapper the emitted line must stay exactly
		// what every release so far produced, byte for byte.
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'view_item', array( 'value' => 42 ) );

		$datalayer->flush_pushes();

		$this->assertStringContainsString( 'dataLayer.push({"event":"view_item","value":42});', $captured[0] );
		$this->assertStringNotContainsString( 'function(d){return d;}', $captured[0] );
	}

	/**
	 * The wrapper name is written into a <script> body unescaped, so it is the one
	 * parameter of queue_push() that could be a script-injection sink. Only a plain
	 * JavaScript identifier is emitted; anything else is dropped and the object is
	 * pushed unwrapped, so a caller cannot smuggle statements in through it.
	 *
	 * @param string $hostile A wrapper value that must never reach the output.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_hostile_js_wrappers' )]
	public function test_flush_pushes_rejects_a_wrapper_that_is_not_an_identifier( string $hostile ): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'view_item', array( 'value' => 42 ), '', '', $hostile );

		$datalayer->flush_pushes();

		// Both directions: the safe form is what got emitted, and no fragment of the
		// hostile value survived anywhere in the script.
		$this->assertStringContainsString( 'dataLayer.push({"event":"view_item","value":42});', $captured[0] );
		$this->assertStringNotContainsString( 'alert', $captured[0] );
		$this->assertStringNotContainsString( '</script', $captured[0] );
		$this->assertStringNotContainsString( $hostile, $captured[0] );
	}

	/**
	 * Wrapper values that are not a plain JavaScript identifier.
	 *
	 * @return array<string, string[]>
	 */
	public static function provide_hostile_js_wrappers(): array {
		return array(
			'statement injection'   => array( 'foo(1);alert(1);//' ),
			'script tag breakout'   => array( "\x3C/script\x3E\x3Cscript\x3Ealert(1)\x3C/script\x3E" ),
			'argument list closing' => array( 'foo),alert(1),(' ),
			'member expression'     => array( 'window.alert' ),
			'leading digit'         => array( '1foo' ),
			'whitespace'            => array( 'foo bar' ),
			'quote breakout'        => array( 'foo"),alert(1)//' ),
			// PCRE lets `$` match before a trailing newline unless the pattern is
			// anchored with /D, so this case fails the moment that modifier is lost.
			'trailing newline'      => array( "gtm4wp_enrich\n" ),
		);
	}

	/**
	 * The push handle carries no src, so its inline script runs while the document
	 * is parsed - ahead of every deferred bundle. A push that wraps its payload in
	 * a function from one of those bundles therefore needs the bundle printed
	 * first, which is what this dependency arranges.
	 *
	 * @return void
	 */
	public function test_add_push_handle_dependency_appends_the_handle_once(): void {
		$push_script       = new \stdClass();
		$push_script->deps = array();

		$registry             = new \stdClass();
		$registry->registered = array( DataLayer::PUSH_HANDLE => $push_script );

		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_scripts' )->justReturn( $registry );

		$datalayer = new DataLayer( $this->make_options() );

		$this->assertTrue( $datalayer->add_push_handle_dependency( 'gtm4wp-ecommerce-generic' ) );
		$this->assertSame( array( 'gtm4wp-ecommerce-generic' ), $push_script->deps );

		// Idempotent: a second call must not duplicate the dependency.
		$this->assertTrue( $datalayer->add_push_handle_dependency( 'gtm4wp-ecommerce-generic' ) );
		$this->assertSame( array( 'gtm4wp-ecommerce-generic' ), $push_script->deps );
	}

	/**
	 * WordPress drops a script whose dependency is not registered - silently, with
	 * no error. Adding one unchecked here would therefore delete every data layer
	 * push on the page, which no test elsewhere would notice. The guard is the only
	 * thing standing between a load-order optimization and that outage.
	 *
	 * @return void
	 */
	public function test_add_push_handle_dependency_refuses_an_unregistered_handle(): void {
		$push_script       = new \stdClass();
		$push_script->deps = array();

		$registry             = new \stdClass();
		$registry->registered = array( DataLayer::PUSH_HANDLE => $push_script );

		Functions\when( 'wp_script_is' )->alias(
			static function ( $handle ) {
				return DataLayer::PUSH_HANDLE === $handle;
			}
		);
		Functions\when( 'wp_scripts' )->justReturn( $registry );

		$datalayer = new DataLayer( $this->make_options() );

		$this->assertFalse( $datalayer->add_push_handle_dependency( 'gtm4wp-ecommerce-generic' ) );
		$this->assertSame( array(), $push_script->deps, 'An unregistered dependency would make WordPress drop every push.' );
	}

	/**
	 * A wrapper the queue names but nothing defines is not an error condition -
	 * the identity fallback covers it - but a wrapper whose ARGUMENTS cannot be
	 * encoded means the call would be malformed, so the whole wrapper is dropped
	 * rather than emitting a broken expression that takes the push down with it.
	 *
	 * @return void
	 */
	public function test_flush_pushes_drops_the_wrapper_when_an_argument_cannot_be_encoded(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );

		// An invalid UTF-8 byte sequence: wp_json_encode() returns false for it.
		$datalayer->queue_push( 'view_item', array( 'value' => 42 ), '', '', 'gtm4wp_enrich', array( "\xB1\x31" ) );

		$datalayer->flush_pushes();

		$this->assertStringContainsString( 'dataLayer.push({"event":"view_item","value":42});', $captured[0] );
		$this->assertStringNotContainsString( 'gtm4wp_enrich', $captured[0] );
	}

	/**
	 * Third party code appends entries to the compat global by hand and will never
	 * carry the wrapper keys. Reading them must not warn or fatal.
	 *
	 * @return void
	 */
	public function test_flush_pushes_handles_entries_without_the_wrapper_keys(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$GLOBALS['gtm4wp_additional_datalayer_pushes'][] = array(
			'datalayer_object' => array( 'event' => 'thirdparty.event' ),
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->flush_pushes();

		$this->assertStringContainsString( 'dataLayer.push({"event":"thirdparty.event"});', $captured[0] );
	}

	public function test_queue_push_lets_event_data_override_the_event_arg(): void {
		// #348: array_merge keeps an `event` key already present in $event_data (e.g.
		// the purchase data layer sets it first), so $event_data['event'] wins over
		// the $event_name argument and its leading position is preserved.
		$datalayer = new DataLayer( $this->make_options() );

		$this->assertTrue(
			$datalayer->queue_push(
				'a',
				array(
					'event' => 'b',
					'x'     => 1,
				)
			)
		);

		$this->assertSame(
			array(
				'event' => 'b',
				'x'     => 1,
			),
			$GLOBALS['gtm4wp_additional_datalayer_pushes'][0]['datalayer_object'],
			'The event key from $event_data overrides the $event_name argument, order preserved.'
		);
	}

	public function test_flush_pushes_adds_inline_script_and_resets_queue(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = array( $handle, $code, $position );
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'value' => 42 ), '// before', '// after' );

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'gtm4wp-additional-datalayer-pushes', $captured[0][0] );
		$this->assertSame( 'after', $captured[0][2] );
		$this->assertStringContainsString( '// before', $captured[0][1] );
		$this->assertStringContainsString( 'dataLayer.push({"event":"gtm4wp.test","value":42});', $captured[0][1] );
		$this->assertStringContainsString( '// after', $captured[0][1] );

		$this->assertSame( array(), $GLOBALS['gtm4wp_additional_datalayer_pushes'] );

		// A second flush must not duplicate output.
		$datalayer->flush_pushes();
		$this->assertCount( 1, $captured );
	}

	public function test_flush_pushes_reads_third_party_entries_from_global(): void {
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		// Third party code appending directly to the compat global.
		$GLOBALS['gtm4wp_additional_datalayer_pushes'][] = array(
			'datalayer_object' => array( 'event' => 'thirdparty.event' ),
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$this->assertStringContainsString( 'thirdparty.event', $captured[0] );
	}

	public function test_enqueue_push_handle_registers_empty_handle_and_flushes_queue(): void {
		Functions\expect( 'wp_register_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes', '', array(), GTM4WP_VERSION, true );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'gtm4wp-additional-datalayer-pushes' );

		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'foo' => 'bar' ) );

		$datalayer->enqueue_push_handle();

		// Registering the handle also flushes everything queued so far.
		$this->assertCount( 1, $captured, 'enqueue_push_handle() must flush the queued pushes.' );
		$this->assertStringContainsString( 'gtm4wp.test', $captured[0] );
		$this->assertSame( array(), $GLOBALS['gtm4wp_additional_datalayer_pushes'], 'The queue is reset after the flush.' );
	}

	public function test_flush_pushes_preserves_numeric_looking_strings_and_typed_numbers(): void {
		// This sink never used JSON_NUMERIC_CHECK - which is why ecommerce.items
		// carried a leading-zero SKU correctly while the main data layer encode
		// (which had the flag until it was removed) mangled the same product in
		// cartContent (wp.org forum report). Pin the contract on this side too, so
		// the flag is never "helpfully" added here: identifier strings stay
		// byte-exact strings, PHP floats/ints stay JSON numbers.
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push(
			'purchase',
			array(
				'ecommerce' => array(
					'transaction_id' => '000123',
					'value'          => 35.9,
					'items'          => array(
						array(
							'item_id'  => '000035180',
							'price'    => 35.9,
							'quantity' => 1,
						),
					),
				),
			)
		);

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$code = $captured[0];

		$this->assertStringContainsString( '"transaction_id":"000123"', $code );
		$this->assertStringContainsString( '"item_id":"000035180"', $code );
		$this->assertStringContainsString( '"value":35.9', $code );
		$this->assertStringContainsString( '"price":35.9', $code );
		$this->assertStringNotContainsString( '"item_id":35180', $code, 'A leading-zero SKU must not be coerced into a JSON number.' );
		$this->assertStringNotContainsString( '"value":"35.9"', $code, 'A float value must encode as a JSON number, not a string.' );
	}

	public function test_flush_pushes_hex_encodes_script_breakout_characters(): void {
		// The additional-push path prints via wp_add_inline_script (no
		// htmlspecialchars_decode), so JSON_HEX_TAG is the load-bearing guard
		// against a </script> break-out; the amp/quot flags are applied for
		// uniformity (see .security patterns FP-2 / RI-2).
		$captured = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code, $position ) use ( &$captured ) {
				$captured[] = $code;
			}
		);

		$datalayer = new DataLayer( $this->make_options() );
		$datalayer->queue_push( 'gtm4wp.test', array( 'evil' => '</script><svg onload=alert(1)> & "quote"' ) );

		$datalayer->flush_pushes();

		$this->assertCount( 1, $captured );
		$code = $captured[0];

		// The exact hex-encoded JSON the source emits, built the same way, so
		// the assertion tracks the real output instead of a hand-typed literal.
		$expected_json = wp_json_encode(
			array(
				'event' => 'gtm4wp.test',
				'evil'  => '</script><svg onload=alert(1)> & "quote"',
			),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$this->assertStringContainsString( $expected_json, $code );
		$this->assertStringNotContainsString( '</script>', $code, 'A </script> in an additional push must never survive unencoded.' );
		$this->assertStringNotContainsString( '<svg', $code, 'A raw < must be hex-encoded (JSON_HEX_TAG).' );
		$this->assertStringNotContainsString( '"quote"', $code, 'A raw double quote must be hex-encoded (JSON_HEX_QUOT).' );
	}
}
