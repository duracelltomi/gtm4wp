<?php
/**
 * Unit tests for the destination probe shared by the REST route and the ability.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\DestinationProbe;
use GTM4WP\Modules\GoogleDataManager\DestinationRows;
use GTM4WP\Modules\GoogleDataManager\EventsIngest;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * DestinationProbe is the one definition of "test this destination" behind
 * the destinations panel's route and the test-google-data-manager-destination
 * ability. The two callers' tests pin their own guards; this file pins the
 * service once, so a leg is not "covered" only because one caller happened
 * to exercise it (T105f): the unknown-account refusal before any request,
 * the three ways a probe fails, and what a passing probe does to the
 * destination's failure streak - through an injected health record and
 * through the one the probe builds itself when none is injected.
 *
 * The transport is the recording fake, which throws on a request nobody
 * queued a response for, so "no request" assertions are real.
 */
final class DestinationProbeTest extends TestCase {

	use OptionStoreTrait;

	private const NOW         = 1_800_000_000;
	private const SECRET      = 'unit-test-site-secret-do-not-reuse';
	private const TOKEN       = 'ya29.unit-test-access-token';
	private const MEASUREMENT = 'G-ABC123';

	private KeyVault $vault;

	private FakeTransport $transport;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->stub_option_store();

		$this->vault     = new KeyVault( self::SECRET, static fn () => self::NOW );
		$this->transport = new FakeTransport();
	}

	/**
	 * A probe over the shared vault and fake transport with pinned time.
	 *
	 * @param DestinationHealth|null $health The health record to inject, or none.
	 * @return DestinationProbe
	 */
	private function probe( ?DestinationHealth $health = null ): DestinationProbe {
		$clock  = static fn () => self::NOW;
		$tokens = new TokenService( $this->vault, $this->transport, $clock );

		return new DestinationProbe( $this->vault, new EventsIngest( $tokens, $this->transport, $clock ), $health );
	}

	/**
	 * Stores the fixture key and returns its account id.
	 *
	 * @return string
	 */
	private function store_account(): string {
		$id = $this->vault->add( KeyFileFixture::parse(), 'Production' );
		$this->assertIsString( $id );

		return $id;
	}

	/**
	 * A valid destination row over the given account.
	 *
	 * @param string $account_id Service-account id.
	 * @return array<string, string>
	 */
	private static function row( string $account_id ): array {
		return array(
			DestinationRows::COLUMN_LABEL       => 'Shop GA4',
			DestinationRows::COLUMN_ACCOUNT     => $account_id,
			DestinationRows::COLUMN_TYPE        => DestinationRows::TYPE_GA4,
			DestinationRows::COLUMN_PROPERTY    => '123456789',
			DestinationRows::COLUMN_MEASUREMENT => self::MEASUREMENT,
		);
	}

	/**
	 * Queues a successful token exchange.
	 *
	 * @return void
	 */
	private function queue_token(): void {
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => self::TOKEN,
				'expires_in'   => 3600,
			)
		);
	}

	/**
	 * A health record over the shared option table with three failures on the
	 * destination, past the notice threshold.
	 *
	 * @return DestinationHealth
	 */
	private function failing_health(): DestinationHealth {
		$health = new DestinationHealth( static fn () => self::NOW );

		for ( $i = 0; $i < DestinationHealth::FAILURE_THRESHOLD; $i++ ) {
			$health->record_failure( self::MEASUREMENT, 'NOT_FOUND', 'NOT_FOUND' );
		}

		$this->assertTrue( $health->is_failing( self::MEASUREMENT ), 'Precondition: the streak is on.' );

		return $health;
	}

	// ---- Before anything leaves the site -----------------------------------

	public function test_a_row_whose_account_is_gone_is_refused_with_404_and_no_request(): void {
		$result = $this->probe()->probe( self::row( 'sa_000000000000' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->transport->requests );
	}

	// ---- Outcomes ----------------------------------------------------------

	public function test_a_passing_probe_is_ok_with_a_sentence_and_no_token(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$result = $this->probe()->probe( self::row( $id ) );

		$this->assertSame( array( 'ok', 'message' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertStringNotContainsString( self::TOKEN, serialize( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.
		$this->assertCount( 2, $this->transport->requests, 'One token exchange, one validateOnly probe.' );
		$this->assertTrue( $this->transport->requests[1]['body']['validateOnly'] );
	}

	public function test_a_refused_token_exchange_is_the_reason_and_sends_no_probe(): void {
		$id = $this->store_account();
		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Invalid JWT signature.',
			)
		);

		$result = $this->probe()->probe( self::row( $id ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_grant: Invalid JWT signature.', $result['message'] );
		$this->assertCount( 1, $this->transport->requests, 'No ingest probe follows a refused token exchange.' );
	}

	public function test_a_google_refusal_is_a_normal_answer_with_the_reason_class(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			403,
			array(
				'error' => array(
					'code'    => 403,
					'status'  => 'PERMISSION_DENIED',
					'message' => 'The caller does not have permission on the property.',
				),
			)
		);

		$result = $this->probe()->probe( self::row( $id ) );

		$this->assertIsArray( $result, 'A refused probe is an answer, not an error.' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'PERMISSION_DENIED', $result['message'] );
	}

	public function test_a_transport_failure_on_the_probe_is_the_reason(): void {
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond( new \WP_Error( 'http_request_failed', 'Could not resolve host.' ) );

		$result = $this->probe()->probe( self::row( $id ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Could not resolve host.', $result['message'] );
	}

	// ---- The failure streak ------------------------------------------------

	public function test_a_passing_probe_clears_the_injected_health_records_streak(): void {
		$health = $this->failing_health();
		$id     = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$this->assertTrue( $this->probe( $health )->probe( self::row( $id ) )['ok'] );

		$this->assertFalse( $health->is_failing( self::MEASUREMENT ), 'The probe proved the chain; the notice has nothing left to say.' );
		$this->assertSame( 0, $health->get( self::MEASUREMENT )['consecutive_failures'] );
		$this->assertSame( 0, $health->get( self::MEASUREMENT )['last_success'], 'A probe is not a send: no success timestamp.' );
	}

	/**
	 * The `?? new DestinationHealth()` leg: a caller that injects nothing (the
	 * ability's default constructor in production) still ends the streak,
	 * through the record the probe builds over the same option table.
	 */
	public function test_a_passing_probe_clears_the_streak_through_the_health_record_it_builds_itself(): void {
		$this->failing_health();
		$id = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json( 200, array( 'requestId' => 'req-1' ) );

		$this->assertTrue( $this->probe( null )->probe( self::row( $id ) )['ok'] );

		$this->assertFalse( ( new DestinationHealth( static fn () => self::NOW ) )->is_failing( self::MEASUREMENT ), 'Read back fresh from the option table.' );
	}

	public function test_a_failing_probe_leaves_the_streak_alone(): void {
		$health = $this->failing_health();
		$id     = $this->store_account();
		$this->queue_token();
		$this->transport->will_respond_json(
			404,
			array(
				'error' => array(
					'status'  => 'NOT_FOUND',
					'message' => 'Property not found',
				),
			)
		);

		$this->assertFalse( $this->probe( $health )->probe( self::row( $id ) )['ok'] );

		$this->assertTrue( $health->is_failing( self::MEASUREMENT ) );
		$this->assertSame( DestinationHealth::FAILURE_THRESHOLD, $health->get( self::MEASUREMENT )['consecutive_failures'], 'A probe is not a send: it neither adds a failure nor removes one.' );
	}
}
