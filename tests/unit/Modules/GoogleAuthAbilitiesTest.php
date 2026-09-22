<?php
/**
 * Unit tests for the Google service accounts abilities.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Modules\GoogleAuth\Abilities;
use GTM4WP\Modules\GoogleAuth\AdminSchema;
use GTM4WP\Tests\unit\Abilities\AbilitiesTestCase;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;

/**
 * The read, gtm4wp/get-service-accounts, is the ability whose answer is the
 * most tempting to over-share - the vault's public view carries the account
 * e-mail and key id - so what is NOT in it is pinned over the whole
 * serialised answer (TS-2 both directions). The write, gtm4wp/test-service-account,
 * runs the same TokenService::test_account() as the REST route; pinned here
 * are the ability's own guards (the write switch, the unknown id refused
 * before any request) and that the token never rides along. The transport
 * is the recording fake, which throws on a request nobody queued a response
 * for, so "no request" assertions are real.
 */
final class GoogleAuthAbilitiesTest extends AbilitiesTestCase {

	private const NOW    = 1_800_000_000;
	private const SECRET = 'unit-test-site-secret-do-not-reuse';
	private const TOKEN  = 'ya29.unit-test-access-token';

	private KeyVault $vault;

	private FakeTransport $transport;

	protected function setUp(): void {
		parent::setUp();

		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		// The token cache; the test action bypasses it but the minter still writes it.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->vault     = new KeyVault( self::SECRET, static fn () => self::NOW );
		$this->transport = new FakeTransport();

		$this->provider()->register();
	}

	/**
	 * A provider over the shared vault and fake transport, with pinned time.
	 *
	 * @return Abilities
	 */
	private function provider(): Abilities {
		return new Abilities( $this->vault, new TokenService( $this->vault, $this->transport, static fn () => self::NOW ) );
	}

	/**
	 * Stores the fixture key and returns its account id.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private function store_account( string $label = 'Production' ): string {
		$id = $this->vault->add( KeyFileFixture::parse(), $label );
		$this->assertIsString( $id );

		return $id;
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

	// ---- Hand-over ---------------------------------------------------------

	public function test_the_module_schema_hands_the_provider_to_the_registrar(): void {
		$this->registered = array();

		$schema = new AdminSchema();
		$this->assertInstanceOf( AbilitiesInterface::class, $schema, 'Opting in on the admin schema is how a module reaches the abilities surface.' );

		$provider = $schema->abilities();
		$this->assertInstanceOf( Abilities::class, $provider );

		$provider->register();

		$this->assertSame( array( Abilities::GET_ACCOUNTS, Abilities::TEST_ACCOUNT ), array_keys( $this->registered ), 'The provider the schema builds registers the two module abilities, nothing else.' );
	}

	// ---- get-service-accounts ----------------------------------------------

	public function test_the_list_names_each_account_by_id_label_status_and_test_time_only(): void {
		$id = $this->store_account();

		$result = $this->execute( Abilities::GET_ACCOUNTS );

		$this->assertCount( 1, $result['accounts'] );
		$this->assertSame(
			array(
				'id'        => $id,
				'label'     => 'Production',
				'status'    => KeyVault::STATUS_UNVERIFIED,
				'tested_at' => 0,
			),
			$result['accounts'][0],
			'Exactly four members: the vault\'s public view carries more, and none of the rest is an assistant\'s to see.'
		);
	}

	public function test_the_list_never_discloses_the_account_address_key_id_or_key_material(): void {
		$this->store_account();

		$text = (string) json_encode( $this->execute( Abilities::GET_ACCOUNTS ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for substring assertions.

		// Present: what an assistant needs to pick an account.
		$this->assertStringContainsString( 'Production', $text, 'The account is named by its label.' );
		$this->assertStringContainsString( KeyVault::STATUS_UNVERIFIED, $text, 'And its status.' );

		// Absent: what nobody on the other end is entitled to.
		$this->assertStringNotContainsString( KeyFileFixture::CLIENT_EMAIL, $text );
		$this->assertStringNotContainsString( KeyFileFixture::PRIVATE_KEY_ID, $text );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $text );
		$this->assertStringNotContainsString( 'ciphertext', $text );
		foreach ( $this->options[ KeyVault::OPTION_NAME ] ?? array() as $row ) {
			$this->assertStringNotContainsString( $row['key']['ciphertext'], $text, 'Not the sealed blob either.' );
		}
	}

	public function test_the_list_is_empty_before_any_upload(): void {
		$this->assertSame( array( 'accounts' => array() ), $this->execute( Abilities::GET_ACCOUNTS ) );
	}

	public function test_the_list_reflects_the_last_test(): void {
		$id = $this->store_account();
		$this->queue_token();

		$this->execute( Abilities::TEST_ACCOUNT, array( 'id' => $id ) );
		$account = $this->execute( Abilities::GET_ACCOUNTS )['accounts'][0];

		$this->assertSame( KeyVault::STATUS_OK, $account['status'] );
		$this->assertSame( self::NOW, $account['tested_at'], 'The vault\'s last_checked, read back fresh.' );
	}

	// ---- test-service-account ----------------------------------------------

	public function test_the_write_is_registered_with_the_vaults_id_format(): void {
		$schema = $this->registered[ Abilities::TEST_ACCOUNT ]['input_schema'];

		$this->assertSame( array( 'id' ), $schema['required'] );
		$this->assertSame( '^' . KeyVault::ID_PATTERN . '$', $schema['properties']['id']['pattern'], 'The vault\'s own id format, ours to pin.' );
		$this->assertStringContainsString( 'get-service-accounts first', $this->registered[ Abilities::TEST_ACCOUNT ]['description'], 'The runbook says where the id comes from.' );
	}

	public function test_the_test_mints_fresh_and_reports_success_without_the_token(): void {
		$id = $this->store_account();
		$this->queue_token();

		$result = $this->execute( Abilities::TEST_ACCOUNT, array( 'id' => $id ) );

		$this->assertSame( array( 'ok', 'message' ), array_keys( $result ), 'The outcome and a sentence; not the account row the panel gets, which carries the address.' );
		$this->assertTrue( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertStringNotContainsString( self::TOKEN, serialize( $result ), 'No access token.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.

		$this->assertCount( 1, $this->transport->requests );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $this->transport->requests[0]['url'] );
		$this->assertSame( KeyVault::STATUS_OK, $this->vault->get( $id )['status'], 'The same side effect as the panel\'s Test button.' );
	}

	public function test_a_refused_key_is_reported_with_googles_reason_as_a_normal_answer(): void {
		$id = $this->store_account();
		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Invalid JWT Signature.',
			)
		);

		$result = $this->execute( Abilities::TEST_ACCOUNT, array( 'id' => $id ) );

		$this->assertIsArray( $result, 'A refused key is a result the assistant explains, not an error.' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_grant: Invalid JWT Signature.', $result['message'] );
		$this->assertSame( KeyVault::STATUS_ERROR, $this->vault->get( $id )['status'] );
	}

	/**
	 * Ids that name no stored account: a well-formed one, a malformed one, a
	 * missing one. Each is refused with 404 before anything leaves the site.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unknown_ids(): array {
		return array(
			'well-formed but unknown' => array( array( 'id' => 'sa_ffffffffffff' ) ),
			'malformed'               => array( array( 'id' => '../etc/passwd' ) ),
			'not a string'            => array( array( 'id' => array( 'sa_ffffffffffff' ) ) ),
			'missing'                 => array( array() ),
		);
	}

	/**
	 * An unknown id is 404 and sends nothing.
	 *
	 * @param array<string, mixed> $input The input.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unknown_ids' )]
	public function test_an_unknown_account_is_refused_with_404_and_no_request( array $input ): void {
		$this->store_account();

		$result = $this->execute( Abilities::TEST_ACCOUNT, $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
	}

	// ---- The write switch (TS-12: both halves) -----------------------------

	public function test_a_read_only_site_lists_the_accounts_but_not_the_test(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		$this->registered = array();
		$this->provider()->register();

		$this->assertSame( array( Abilities::GET_ACCOUNTS ), array_keys( $this->registered ) );
	}

	public function test_a_test_run_after_the_switch_flipped_refuses_with_403_and_no_request(): void {
		$id = $this->store_account();
		$this->assertArrayHasKey( Abilities::TEST_ACCOUNT, $this->registered, 'Registered while writes were allowed.' );

		Filters\expectApplied( GTM4WP_WPFILTER_ABILITIES_ALLOW_WRITE )->atLeast()->once()->with( true )->andReturn( false );

		$result = $this->execute( Abilities::TEST_ACCOUNT, array( 'id' => $id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_abilities_write_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->transport->requests, 'Nothing left the site.' );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $this->vault->get( $id )['status'], 'Nothing was recorded either.' );
	}
}
