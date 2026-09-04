<?php
/**
 * Unit tests for the attribution cookie parser and meta builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCapture;
use GTM4WP\Modules\GoogleDataManager\AttributionCookies;
use GTM4WP\Tests\unit\TestCase;

/**
 * The cookies are ours by format, but a cookie is attacker-writable whoever
 * defined it, so the parser is treated as a hostile-input boundary: every
 * field has a grammar, a value that does not match is dropped rather than
 * repaired, and an absent capture produces an absent meta key rather than an
 * empty-string placeholder.
 *
 * That last property is the one worth stating twice. A later send has to be
 * able to tell "no client ID was ever resolved" from "the client ID is the
 * empty string", and only a missing key says the first one - which is why the
 * omission cases assert the key is absent (assertArrayNotHasKey), never that
 * it is falsy.
 */
final class GoogleDataManagerCaptureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$_COOKIE = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			static fn ( $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		$_COOKIE = array();

		parent::tearDown();
	}

	/**
	 * Stores a payload the way the capture script does: JSON, URL encoded.
	 *
	 * @param string               $cookie  Cookie name.
	 * @param array<string, mixed> $payload Payload, version added unless present.
	 * @return void
	 */
	private function set_cookie( string $cookie, array $payload ): void {
		if ( ! array_key_exists( 'v', $payload ) ) {
			$payload['v'] = AttributionCookies::FORMAT_VERSION;
		}

		$_COOKIE[ $cookie ] = rawurlencode( (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Stores a raw cookie value, for the cases that are not valid JSON.
	 *
	 * @param string $cookie Cookie name.
	 * @param string $raw    Raw value.
	 * @return void
	 */
	private function set_raw_cookie( string $cookie, string $raw ): void {
		$_COOKIE[ $cookie ] = $raw;
	}

	// ---- The happy path ----------------------------------------------------

	public function test_parses_a_complete_ids_payload(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => '1234567890.1234567890',
				'sessions'  => array( 'G-ABC123' => '1788522496' ),
				'gclid'     => 'EAIaIQobChMI',
				'gbraid'    => '0AAAAA',
				'wbraid'    => 'Cj0KCQ',
			)
		);

		$this->assertSame(
			array(
				'client_id' => '1234567890.1234567890',
				'sessions'  => array( 'G-ABC123' => '1788522496' ),
				'gclid'     => 'EAIaIQobChMI',
				'gbraid'    => '0AAAAA',
				'wbraid'    => 'Cj0KCQ',
			),
			AttributionCapture::parse_ids()
		);
	}

	public function test_builds_the_namespaced_meta_keys(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => '111.222',
				'sessions'  => array( 'G-ABC123' => '1788522496' ),
				'gclid'     => 'abc123',
			)
		);
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array(
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => 1_800_000_000,
			)
		);

		$this->assertSame(
			array(
				'_gtm4wp_ga_client_id'   => '111.222',
				'_gtm4wp_ga_session_ids' => array( 'G-ABC123' => '1788522496' ),
				'_gtm4wp_gclid'          => 'abc123',
				'_gtm4wp_consent_state'  => array(
					'signals'     => array( 'analytics_storage' => 'granted' ),
					'captured_at' => 1_800_000_000,
				),
			),
			AttributionCapture::meta_for_order( 123 )
		);
	}

	/**
	 * The meta keys are the list the privacy exporter and eraser work from, so
	 * they are pinned here: a key added to the capture without the privacy
	 * wiring following would leave personal data behind an erasure request
	 * that reported success.
	 */
	public function test_the_meta_key_list_covers_every_key_the_capture_writes(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => '111.222',
				'sessions'  => array( 'G-ABC123' => '1788522496' ),
				'gclid'     => 'a',
				'gbraid'    => 'b',
				'wbraid'    => 'c',
			)
		);
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array( 'signals' => array( 'analytics_storage' => 'granted' ) )
		);

		$written = array_keys( AttributionCapture::meta_for_order( 1 ) );

		$this->assertSame(
			array(),
			array_diff( $written, AttributionCapture::meta_keys() ),
			'Every key the capture writes must be declared in meta_keys().'
		);
		$this->assertCount( 6, AttributionCapture::meta_keys() );
	}

	// ---- Omission, never a placeholder -------------------------------------

	public function test_nothing_is_stored_without_the_cookies(): void {
		$this->assertSame( array(), AttributionCapture::parse_ids() );
		$this->assertNull( AttributionCapture::parse_consent() );
		$this->assertSame( array(), AttributionCapture::meta_for_order( 1 ) );
	}

	public function test_an_unresolved_client_id_is_an_absent_key(): void {
		$this->set_cookie( AttributionCookies::IDS_COOKIE, array( 'gclid' => 'abc123' ) );

		$meta = AttributionCapture::meta_for_order( 1 );

		$this->assertArrayNotHasKey( AttributionCapture::META_CLIENT_ID, $meta );
		$this->assertArrayNotHasKey( AttributionCapture::META_SESSION_IDS, $meta );
		$this->assertSame( 'abc123', $meta['_gtm4wp_gclid'] );
		$this->assertFalse(
			AttributionCapture::is_usable( $meta ),
			'Without a client id there is nothing Google Analytics could match a later event to.'
		);
	}

	public function test_an_empty_string_id_is_refused_rather_than_stored(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => '',
				'gclid'     => '',
			)
		);

		$meta = AttributionCapture::meta_for_order( 1 );

		$this->assertSame( array(), $meta );
	}

	// ---- Hostile and malformed input ---------------------------------------

	public function test_a_malformed_cookie_yields_nothing(): void {
		$this->set_raw_cookie( AttributionCookies::IDS_COOKIE, 'not json at all' );

		$this->assertSame( array(), AttributionCapture::parse_ids() );
	}

	public function test_a_cookie_larger_than_the_cap_is_not_even_decoded(): void {
		$this->set_raw_cookie(
			AttributionCookies::IDS_COOKIE,
			str_repeat( 'a', AttributionCookies::MAX_BYTES + 1 )
		);

		$this->assertSame( array(), AttributionCapture::parse_ids() );
	}

	public function test_a_payload_of_another_format_version_is_refused(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'v'         => 99,
				'client_id' => '111.222',
			)
		);

		$this->assertSame( array(), AttributionCapture::parse_ids() );
	}

	public function test_a_payload_without_a_version_is_refused(): void {
		$_COOKIE[ AttributionCookies::IDS_COOKIE ] = rawurlencode( '{"client_id":"111.222"}' );

		$this->assertSame( array(), AttributionCapture::parse_ids() );
	}

	/**
	 * The values reach order meta and, later, a JSON request body and the
	 * store admin. A crafted one is dropped at the grammar rather than stored
	 * and escaped downstream, so there is no later sink to get wrong.
	 *
	 * @param string $hostile The crafted id.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'hostile_ids' )]
	public function test_a_hostile_id_is_dropped( string $hostile ): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => $hostile,
				'gclid'     => $hostile,
			)
		);

		$meta = AttributionCapture::meta_for_order( 1 );

		$this->assertArrayNotHasKey( AttributionCapture::META_CLIENT_ID, $meta );
		$this->assertArrayNotHasKey( '_gtm4wp_gclid', $meta );
	}

	/**
	 * Values that must never survive the parser.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_ids(): array {
		return array(
			'script tag'       => array( '</script><script>alert(1)</script>' ),
			'double quote'     => array( '111"222' ),
			'single quote'     => array( "111'222" ),
			'ampersand'        => array( '111&222' ),
			'angle bracket'    => array( '<b>111</b>' ),
			'whitespace'       => array( '111 222' ),
			'newline'          => array( "111\n222" ),
			'trailing newline' => array( "111.222\n" ),
			'null byte'        => array( "111\0222" ),
			'sql-ish'          => array( "1' OR '1'='1" ),
			'path traversal'   => array( '../../etc/passwd' ),
		);
	}

	/**
	 * The two id kinds have different length caps on purpose: an Analytics id
	 * is short and structured, while a click id is a long opaque token, and a
	 * cap tight enough for the first would refuse legitimate values of the
	 * second. Both are generous enough not to refuse a future Google format
	 * as though it were user error, and bounded so a crafted cookie cannot
	 * turn into unbounded order meta.
	 */
	public function test_each_id_kind_is_bounded_by_its_own_length_cap(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => str_repeat( '1', 65 ),
				'gclid'     => str_repeat( 'a', 512 ),
				'gbraid'    => str_repeat( 'a', 513 ),
			)
		);

		$parsed = AttributionCapture::parse_ids();

		$this->assertArrayNotHasKey( 'client_id', $parsed, 'An over-long Analytics id is refused.' );
		$this->assertSame( str_repeat( 'a', 512 ), $parsed['gclid'], 'A long click id is legitimate up to its own cap.' );
		$this->assertArrayNotHasKey( 'gbraid', $parsed, 'Past that cap it is refused too.' );
	}

	public function test_a_non_string_id_is_dropped(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'client_id' => array( 'nested' => 'array' ),
				'gclid'     => 12345,
			)
		);

		$this->assertSame( array(), AttributionCapture::parse_ids() );
	}

	/**
	 * The session map's keys are measurement ids, held to the same grammar the
	 * settings table validates - so a crafted map cannot introduce a key of
	 * any other shape into stored meta.
	 */
	public function test_session_entries_with_an_invalid_measurement_id_are_dropped(): void {
		$this->set_cookie(
			AttributionCookies::IDS_COOKIE,
			array(
				'sessions' => array(
					'G-ABC123'            => '1788522496',
					'not-a-measurementid' => '1788522497',
					'</script>'           => '1788522498',
					'G-VALID2'            => 'not a session id"',
				),
			)
		);

		$this->assertSame(
			array( 'sessions' => array( 'G-ABC123' => '1788522496' ) ),
			AttributionCapture::parse_ids()
		);
	}

	public function test_the_session_map_is_capped(): void {
		$sessions = array();
		for ( $i = 0; $i < AttributionCapture::MAX_SESSIONS + 10; $i++ ) {
			$sessions[ 'G-STREAM' . $i ] = '178852249' . $i;
		}

		$this->set_cookie( AttributionCookies::IDS_COOKIE, array( 'sessions' => $sessions ) );

		$this->assertCount( AttributionCapture::MAX_SESSIONS, AttributionCapture::parse_ids()['sessions'] );
	}

	// ---- Consent parsing ---------------------------------------------------

	public function test_parses_the_consent_map_with_its_timestamp(): void {
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array(
				'signals'     => array(
					'analytics_storage'  => 'granted',
					'ad_storage'         => 'denied',
					'ad_user_data'       => 'granted',
					'ad_personalization' => 'denied',
				),
				'captured_at' => 1_800_000_000,
			)
		);

		$this->assertSame(
			array(
				'signals'     => array(
					'analytics_storage'  => 'granted',
					'ad_storage'         => 'denied',
					'ad_user_data'       => 'granted',
					'ad_personalization' => 'denied',
				),
				'captured_at' => 1_800_000_000,
			),
			AttributionCapture::parse_consent()
		);
	}

	/**
	 * Only the two documented values are stored. Anything else is dropped
	 * rather than kept as-is, because the consent gate compares against
	 * "granted" and a stored third value would sit in the meta looking
	 * meaningful while behaving as a denial.
	 */
	public function test_a_signal_with_an_unknown_value_is_dropped(): void {
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array(
				'signals' => array(
					'analytics_storage' => 'granted',
					'ad_storage'        => 'maybe',
					'ad_user_data'      => true,
					'weird'             => array( 'granted' ),
				),
			)
		);

		$this->assertSame(
			array( 'analytics_storage' => 'granted' ),
			AttributionCapture::parse_consent()['signals']
		);
	}

	public function test_a_signal_with_a_hostile_name_is_dropped(): void {
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array(
				'signals' => array(
					'analytics_storage'    => 'granted',
					'</script><b>'         => 'granted',
					'ANALYTICS_STORAGE_UC' => 'granted',
					'has spaces'           => 'granted',
				),
			)
		);

		$this->assertSame(
			array( 'analytics_storage' => 'granted' ),
			AttributionCapture::parse_consent()['signals']
		);
	}

	public function test_a_non_integer_timestamp_becomes_zero_rather_than_being_stored(): void {
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array(
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => '</script>',
			)
		);

		$this->assertSame( 0, AttributionCapture::parse_consent()['captured_at'] );
	}

	/**
	 * An empty map is still an observed consent state - the visitor's tool ran
	 * and told us nothing about these signals - and is stored as such. What
	 * must never happen is storing it as though signals had been denied, or
	 * dropping the record so that "unknown" and "observed but empty" collapse.
	 */
	public function test_an_observed_but_empty_signal_map_is_still_recorded(): void {
		$this->set_cookie( AttributionCookies::CONSENT_COOKIE, array( 'signals' => array() ) );

		$parsed = AttributionCapture::parse_consent();

		$this->assertSame( array(), $parsed['signals'] );
	}

	public function test_a_consent_cookie_without_a_signal_map_yields_nothing(): void {
		$this->set_cookie( AttributionCookies::CONSENT_COOKIE, array( 'captured_at' => 1_800_000_000 ) );

		$this->assertNull( AttributionCapture::parse_consent() );
	}

	// ---- The consent filter ------------------------------------------------

	public function test_the_filter_can_supply_a_consent_state_the_page_never_reflected(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $order ) {
				if ( GTM4WP_WPFILTER_GDM_ORDER_CONSENT === $hook ) {
					return array(
						'signals'     => array( 'analytics_storage' => 'granted' ),
						'captured_at' => 1_800_000_042,
						'source'      => 'order ' . $order,
					);
				}

				return $value;
			}
		);

		$meta = AttributionCapture::meta_for_order( 77 );

		$this->assertSame(
			array(
				'signals'     => array( 'analytics_storage' => 'granted' ),
				'captured_at' => 1_800_000_042,
				'source'      => 'order 77',
			),
			$meta[ AttributionCapture::META_CONSENT_STATE ]
		);
	}

	public function test_the_filter_can_withhold_the_consent_state_entirely(): void {
		$this->set_cookie(
			AttributionCookies::CONSENT_COOKIE,
			array( 'signals' => array( 'analytics_storage' => 'granted' ) )
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return GTM4WP_WPFILTER_GDM_ORDER_CONSENT === $hook ? null : $value;
			}
		);

		$this->assertArrayNotHasKey(
			AttributionCapture::META_CONSENT_STATE,
			AttributionCapture::meta_for_order( 1 ),
			'Returning null stores nothing, which stays distinguishable from an empty map.'
		);
	}
}
