<?php
/**
 * Unit tests for the service-account token exchange.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\ServiceAccountKey;
use GTM4WP\Google\TokenService;
use GTM4WP\Google\WpTransport;
use GTM4WP\Tests\unit\TestCase;

/**
 * Pins the wire contract of Google's OAuth 2.0 service-account flow (registry
 * rows U120/U121): where the request goes, what the form carries, and the
 * exact JWT header + claim set - with the signature verified against the
 * fixture's public key, so "signed" means signed, not "a third segment is
 * present". The FakeTransport records the request; a double that ignored it
 * would leave the contract untested while the suite stayed green (UC-3).
 *
 * The token is a secret. It may go into the transient and back to the
 * caller; it must never reach the vault row (the status ledger the settings
 * screen reads) and it must not be minted at all when the cache holds one.
 */
final class TokenServiceTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET = 'unit-test-site-secret-do-not-reuse';
	private const NOW    = 1_800_000_000;
	private const SCOPE  = TokenService::SCOPE_DATA_MANAGER;

	/**
	 * The transient table: name => [ value, ttl ].
	 *
	 * @var array<string, array{0: mixed, 1: int}>
	 */
	private array $transients = array();

	/**
	 * Names passed to delete_transient().
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	private FakeTransport $transport;

	private KeyVault $vault;

	private string $account_id;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$this->transients         = array();
		$this->deleted_transients = array();
		Functions\when( 'get_transient' )->alias(
			fn ( $name ) => isset( $this->transients[ $name ] ) ? $this->transients[ $name ][0] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $expiration = 0 ) {
				$this->transients[ $name ] = array( $value, (int) $expiration );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				$this->deleted_transients[] = $name;
				unset( $this->transients[ $name ] );
				return true;
			}
		);

		$this->stub_option_store();

		$this->vault     = new KeyVault( self::SECRET, static fn () => self::NOW );
		$this->transport = new FakeTransport();

		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file() );
		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$id = $this->vault->add( $key, 'Test' );
		$this->assertIsString( $id );
		$this->account_id = $id;
	}

	/**
	 * A service over the shared vault/transport with a fixed clock.
	 *
	 * @return TokenService
	 */
	private function make_service(): TokenService {
		return new TokenService( $this->vault, $this->transport, static fn () => self::NOW );
	}

	/**
	 * A successful token response as Google sends it.
	 *
	 * @param string $token      The token.
	 * @param int    $expires_in Lifetime in seconds.
	 * @return array{status: int, body: array}
	 */
	private static function token_response( string $token = 'ya29.test-token', int $expires_in = 3599 ): array {
		return array(
			'status' => 200,
			'body'   => array(
				'access_token' => $token,
				'expires_in'   => $expires_in,
				'token_type'   => 'Bearer',
			),
		);
	}

	/**
	 * Decodes one JOSE base64url segment.
	 *
	 * @param string $segment The segment.
	 * @return string
	 */
	private static function b64url_decode( string $segment ): string {
		return (string) base64_decode( strtr( $segment, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $segment ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT wire decoding.
	}

	// ---- The request -------------------------------------------------------

	public function test_mints_by_posting_a_signed_jwt_bearer_assertion_to_the_token_endpoint(): void {
		$this->transport->will_respond( self::token_response() );

		$token = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertSame( 'ya29.test-token', $token );
		$this->assertCount( 1, $this->transport->requests );

		$request = $this->transport->requests[0];
		$this->assertSame( 'POST_FORM', $request['method'], 'The exchange is a form POST, not JSON.' );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $request['url'] );
		$this->assertSame( array( 'grant_type', 'assertion' ), array_keys( $request['fields'] ), 'Exactly the two fields of the jwt-bearer grant.' );
		$this->assertSame( TokenService::GRANT_TYPE, $request['fields']['grant_type'] );
		$this->assertSame( array(), $request['headers'], 'No Authorization header: the assertion IS the credential.' );

		// The JWT itself: header, claims, and a signature that verifies.
		$segments = explode( '.', $request['fields']['assertion'] );
		$this->assertCount( 3, $segments );
		foreach ( $segments as $segment ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $segment, 'base64url alphabet, no padding (JOSE).' );
		}

		$this->assertSame(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
				'kid' => KeyFileFixture::PRIVATE_KEY_ID,
			),
			json_decode( self::b64url_decode( $segments[0] ), true )
		);
		$this->assertSame(
			array(
				'iss'   => KeyFileFixture::CLIENT_EMAIL,
				'scope' => self::SCOPE,
				'aud'   => TokenService::TOKEN_ENDPOINT,
				'iat'   => self::NOW,
				'exp'   => self::NOW + TokenService::ASSERTION_LIFETIME,
			),
			json_decode( self::b64url_decode( $segments[1] ), true )
		);
		$this->assertSame(
			1,
			openssl_verify( $segments[0] . '.' . $segments[1], self::b64url_decode( $segments[2] ), KeyFileFixture::rsa_public_pem(), OPENSSL_ALGO_SHA256 ),
			'The signature is RS256 over header.claims with the account key.'
		);
	}

	/**
	 * The endpoint the service posts to must be one the transport will send
	 * to. Two definitions, one test pinning them together (UC-6): a host added
	 * to one and not the other fails here rather than at the first real mint.
	 */
	public function test_the_token_endpoint_is_on_the_transport_allow_list(): void {
		$this->assertTrue( WpTransport::is_allowed_url( TokenService::TOKEN_ENDPOINT ) );
	}

	public function test_a_key_without_an_id_sends_no_kid_header(): void {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file( array( 'private_key_id' => null ) ) );
		$id  = $this->vault->add( $key, 'No kid' );
		$this->transport->will_respond( self::token_response() );

		$this->make_service()->access_token( $id, self::SCOPE );

		$header = json_decode( self::b64url_decode( explode( '.', $this->transport->requests[0]['fields']['assertion'] )[0] ), true );
		$this->assertArrayNotHasKey( 'kid', $header );
	}

	// ---- Success: caching and the status ledger ------------------------------

	public function test_a_minted_token_is_cached_a_minute_short_of_its_lifetime_and_reused(): void {
		$this->transport->will_respond( self::token_response( 'ya29.first', 3599 ) );
		$service = $this->make_service();

		$first  = $service->access_token( $this->account_id, self::SCOPE );
		$second = $service->access_token( $this->account_id, self::SCOPE );

		$this->assertSame( 'ya29.first', $first );
		$this->assertSame( 'ya29.first', $second, 'The second call is served from the cache.' );
		$this->assertCount( 1, $this->transport->requests, 'One exchange, not two.' );

		$this->assertCount( 1, $this->transients );
		$name = array_key_first( $this->transients );
		$this->assertStringStartsWith( 'gtm4wp_google_token_' . $this->account_id . '_', $name );
		$this->assertLessThanOrEqual( 172, strlen( $name ), 'Within the transient name limit.' );
		$this->assertSame( array( 'ya29.first', 3599 - TokenService::EARLY_EXPIRY ), $this->transients[ $name ] );
	}

	public function test_the_cache_is_per_scope(): void {
		$this->transport->will_respond( self::token_response( 'ya29.scope-a' ) );
		$this->transport->will_respond( self::token_response( 'ya29.scope-b' ) );
		$service = $this->make_service();

		$this->assertSame( 'ya29.scope-a', $service->access_token( $this->account_id, 'https://www.googleapis.com/auth/a' ) );
		$this->assertSame( 'ya29.scope-b', $service->access_token( $this->account_id, 'https://www.googleapis.com/auth/b' ) );
		$this->assertCount( 2, $this->transients );
	}

	public function test_fresh_bypasses_the_cache_but_refills_it(): void {
		$this->transport->will_respond( self::token_response( 'ya29.first' ) );
		$this->transport->will_respond( self::token_response( 'ya29.second' ) );
		$service = $this->make_service();

		$service->access_token( $this->account_id, self::SCOPE );
		$fresh = $service->access_token( $this->account_id, self::SCOPE, true );
		$after = $service->access_token( $this->account_id, self::SCOPE );

		$this->assertSame( 'ya29.second', $fresh, 'The test action mints even though a token is cached.' );
		$this->assertSame( 'ya29.second', $after, 'And the new token replaces the cached one.' );
		$this->assertCount( 2, $this->transport->requests );
	}

	/**
	 * The fresh mint also FORCES the vault write: an admin who pressed the Test
	 * button has to see last_checked move, and the vault's herd guard would
	 * otherwise skip a row that says the same thing within its window. The
	 * vault's own force flag is pinned in KeyVaultTest; this pins that the
	 * service forwards $fresh into it (T90 - a revert to `false` here left
	 * every earlier case green because the frozen clock makes the second
	 * mint "unchanged and recent").
	 */
	public function test_a_fresh_mint_always_writes_the_account_row(): void {
		$this->transport->will_respond( self::token_response( 'ya29.first' ) );
		$this->transport->will_respond( self::token_response( 'ya29.second' ) );
		$service = $this->make_service();

		$service->access_token( $this->account_id, self::SCOPE );
		$writes_before = count( $this->option_writes );

		$service->access_token( $this->account_id, self::SCOPE, true );

		$this->assertGreaterThan( $writes_before, count( $this->option_writes ), 'The forced mint writes the row even though nothing in it changed.' );
	}

	/**
	 * The Test button: test_account() sits behind the REST route and the
	 * test-service-account ability: it has to mint even though a token is
	 * cached, or a revoked key reads "ok" until the cache expires. Every
	 * adapter test stubs the cache empty, so this is the one case where the
	 * `true` the service passes to itself is observable (T97: flipping it to
	 * `false` left all three files green).
	 */
	public function test_the_test_action_mints_fresh_even_when_a_token_is_cached(): void {
		$this->transport->will_respond( self::token_response( 'ya29.cached' ) );
		$this->transport->will_respond( self::token_response( 'ya29.retested' ) );
		$service = $this->make_service();

		$service->access_token( $this->account_id, self::SCOPE );
		$this->assertCount( 1, $this->transients, 'Precondition: a token is cached.' );
		$writes_before = count( $this->option_writes );

		$result = $service->test_account( $this->account_id );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $this->transport->requests, 'The test sent a second exchange instead of answering from the cache.' );
		$this->assertSame( 'ya29.retested', array_values( $this->transients )[0][0], 'The fresh token replaced the cached one.' );
		$this->assertGreaterThan( $writes_before, count( $this->option_writes ), 'The vault row was written, so last_checked moves.' );
	}

	public function test_a_token_google_reports_as_already_expiring_is_not_cached(): void {
		$this->transport->will_respond( self::token_response( 'ya29.short', TokenService::EARLY_EXPIRY ) );

		$this->assertSame( 'ya29.short', $this->make_service()->access_token( $this->account_id, self::SCOPE ) );
		$this->assertSame( array(), $this->transients, 'expires_in - EARLY_EXPIRY <= 0 caches nothing (a zero TTL would mean "never expire").' );
	}

	public function test_success_records_ok_on_the_account_and_never_the_token(): void {
		$this->transport->will_respond( self::token_response( 'ya29.secret-token' ) );

		$this->make_service()->access_token( $this->account_id, self::SCOPE );

		$account = $this->vault->get( $this->account_id );
		$this->assertSame( KeyVault::STATUS_OK, $account['status'] );
		$this->assertSame( self::NOW, $account['last_checked'] );
		$this->assertSame( '', $account['last_error'] );

		$this->assertStringNotContainsString( 'ya29.secret-token', serialize( $this->options ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening the option table to search it.
	}

	/**
	 * A successful mint records its scope on the account so the vault can
	 * purge that scope's cached token at delete time (#226).
	 */
	public function test_a_successful_mint_records_its_scope_for_the_delete_time_purge(): void {
		$this->transport->will_respond( self::token_response() );

		$this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertSame(
			array( self::SCOPE ),
			$this->options[ KeyVault::OPTION_NAME ][ $this->account_id ]['scopes']
		);
	}

	// ---- Failure branches --------------------------------------------------

	public function test_a_refused_exchange_returns_an_error_records_googles_reason_and_caches_nothing(): void {
		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Invalid JWT Signature.',
			)
		);

		$result = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_token_refused', $result->get_error_code() );
		$this->assertSame( 'invalid_grant: Invalid JWT Signature.', $result->get_error_message() );

		$account = $this->vault->get( $this->account_id );
		$this->assertSame( KeyVault::STATUS_ERROR, $account['status'] );
		$this->assertSame( 'invalid_grant: Invalid JWT Signature.', $account['last_error'] );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * Google's reason is returned to the settings screen and to two abilities,
	 * so it is capped and stripped BEFORE it is returned, not only when the
	 * vault stores it (#259). A core-like sanitize_text_field stand-in here:
	 * tags out, whitespace collapsed, trimmed.
	 */
	public function test_a_refusal_message_is_capped_and_stripped_before_it_is_returned(): void {
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) )
		);

		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => "<script>alert(1)</script>Invalid JWT\nSignature. " . str_repeat( 'x', 5000 ),
			)
		);

		$result = $this->make_service()->test_account( $this->account_id );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( TokenService::ERROR_MAX_LENGTH, mb_strlen( $result['message'] ) );
		$this->assertStringStartsWith( 'invalid_grant: alert(1)Invalid JWT Signature. ', $result['message'] );
		$this->assertStringNotContainsString( '<', $result['message'] );
		$this->assertStringNotContainsString( "\n", $result['message'] );
		$this->assertSame( $result['message'], $this->vault->get( $this->account_id )['last_error'], 'The returned message and the stored one are the same text.' );
	}

	/**
	 * Response shapes that are not a token, with the summary each produces.
	 *
	 * @return array<string, array{0: int, 1: array|null, 2: string}>
	 */
	public static function non_token_responses(): array {
		return array(
			'error code only'                  => array( 400, array( 'error' => 'invalid_client' ), 'invalid_client' ),
			'HTTP 200 without a token'         => array( 200, array( 'token_type' => 'Bearer' ), 'Google did not issue a token (HTTP 200).' ),
			'HTTP 200 with empty token'        => array( 200, array( 'access_token' => '' ), 'Google did not issue a token (HTTP 200).' ),
			'token that is not a string'       => array( 200, array( 'access_token' => array( 'x' ) ), 'Google did not issue a token (HTTP 200).' ),
			'HTTP 500, no JSON'                => array( 500, null, 'Google did not issue a token (HTTP 500).' ),
			'HTTP 403 with a non-string error' => array( 403, array( 'error' => array( 'code' => 403 ) ), 'Google did not issue a token (HTTP 403).' ),
			// A well-formed token in a non-200 body is still refused: this is
			// the one row where ONLY the status check discriminates, so it pins
			// the check the other rows cannot.
			'HTTP 400 carrying a token'        => array(
				400,
				array(
					'access_token' => 'ya29.should-not-be-accepted',
					'expires_in'   => 3599,
					'token_type'   => 'Bearer',
				),
				'Google did not issue a token (HTTP 400).',
			),
		);
	}

	/**
	 * Any response that is not a token is a refusal with a readable summary.
	 *
	 * @param int        $status  HTTP status.
	 * @param array|null $body    Decoded body.
	 * @param string     $summary Expected error text.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'non_token_responses' )]
	public function test_anything_but_a_token_is_a_refusal( int $status, ?array $body, string $summary ): void {
		$this->transport->will_respond_json( $status, $body );

		$result = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_token_refused', $result->get_error_code() );
		$this->assertSame( $summary, $result->get_error_message() );
		$this->assertSame( $summary, $this->vault->get( $this->account_id )['last_error'] );
		$this->assertSame( array(), $this->transients );
	}

	public function test_a_transport_error_is_passed_through_and_recorded(): void {
		$this->transport->will_respond( new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ) );

		$result = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );

		$account = $this->vault->get( $this->account_id );
		$this->assertSame( KeyVault::STATUS_ERROR, $account['status'] );
		$this->assertSame( 'cURL error 28: Connection timed out', $account['last_error'] );
	}

	public function test_a_corrupt_cached_value_is_ignored_and_a_fresh_token_is_minted(): void {
		$this->transport->will_respond( self::token_response( 'ya29.first' ) );
		$service = $this->make_service();
		$this->assertSame( 'ya29.first', $service->access_token( $this->account_id, self::SCOPE ) );

		$name = array_key_first( $this->transients );
		$this->assertIsString( $name );

		// Both shapes the cache guard filters: an empty string and a non-string.
		foreach ( array( '', array( 'not' => 'a token' ) ) as $corrupt ) {
			$this->transients[ $name ][0] = $corrupt;
			$this->transport->will_respond( self::token_response( 'ya29.reminted' ) );
			$this->assertSame( 'ya29.reminted', $service->access_token( $this->account_id, self::SCOPE ) );
		}

		$this->assertCount( 3, $this->transport->requests, 'Each corrupt cached value forces a fresh mint.' );
	}

	/**
	 * The openssl_sign() failure leg. The namespaced shadow intercepts the
	 * unqualified call inside TokenService; once defined it lasts for the
	 * whole process and would break every real-signing test after it (TS-16),
	 * so this case runs isolated, like DefaultLanguageTest's.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_signing_failure_is_a_recorded_refusal_that_sends_nothing(): void {
		Functions\when( 'GTM4WP\Google\openssl_sign' )->justReturn( false );

		$result = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_token_sign_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests, 'Nothing leaves the site when signing fails.' );
		$this->assertSame( array(), $this->transients, 'A failure is never cached.' );

		$account = $this->vault->get( $this->account_id );
		$this->assertSame( KeyVault::STATUS_ERROR, $account['status'] );
		$this->assertSame( 'The request to Google could not be signed with the stored key.', $account['last_error'] );
	}

	/**
	 * An unreadable key (rotated salts) is the vault's finding, and its
	 * "re-upload required" status must survive: recording a token failure on
	 * top would relabel it "error" and send the admin to Google for a problem
	 * that is in wp-config.php. And nothing is sent - there is no key to sign
	 * with.
	 */
	public function test_an_unreadable_key_sends_nothing_and_keeps_the_reupload_status(): void {
		$unreadable_vault = new KeyVault( 'another-secret-after-salt-rotation' );
		$service          = new TokenService( $unreadable_vault, $this->transport, static fn () => self::NOW );

		$result = $service->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_unreadable', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests, 'No request leaves the site.' );
		$this->assertSame( KeyVault::STATUS_REUPLOAD, $unreadable_vault->get( $this->account_id )['status'] );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * The re-parse failure path is the other way open() can fail: the
	 * ciphertext still decrypts but the stored metadata no longer passes the
	 * key parser. No plugin write path can produce that state (the upload
	 * validates every field), so the trigger is DB-level damage - and the
	 * account must flip to "error" rather than keep showing its last status
	 * while every mint fails (#227). Unlike the unreadable-key path above,
	 * there is no better status to protect here.
	 */
	public function test_a_stored_row_that_no_longer_parses_records_the_failure_on_the_account(): void {
		$this->options[ KeyVault::OPTION_NAME ][ $this->account_id ]['client_email'] = '';
		$this->options[ KeyVault::OPTION_NAME ][ $this->account_id ]['status']       = KeyVault::STATUS_OK;

		$result = $this->make_service()->access_token( $this->account_id, self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_invalid', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests, 'No request leaves the site.' );
		$this->assertSame( array(), $this->transients );

		$account = $this->vault->get( $this->account_id );
		$this->assertSame( KeyVault::STATUS_ERROR, $account['status'], 'The panel must not keep reporting the last status while every mint fails.' );
		$this->assertNotSame( '', $account['last_error'], 'The stored reason tells the admin what to do.' );
	}

	public function test_an_unknown_account_sends_nothing(): void {
		$result = $this->make_service()->access_token( 'sa_ffffffffffff', self::SCOPE );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_account_unknown', $result->get_error_code() );
		$this->assertSame( array(), $this->transport->requests );
	}

	/**
	 * A cached token belongs to a key; forget() is what delete_account() calls
	 * so a removed key stops working at once instead of at the cache expiry.
	 */
	public function test_forget_drops_the_cached_token_of_that_account_and_scope(): void {
		$this->transport->will_respond( self::token_response( 'ya29.cached' ) );
		$this->transport->will_respond( self::token_response( 'ya29.reminted' ) );
		$service = $this->make_service();

		$service->access_token( $this->account_id, self::SCOPE );
		TokenService::forget( $this->account_id, self::SCOPE );

		$this->assertSame( array(), $this->transients );
		$this->assertCount( 1, $this->deleted_transients );
		$this->assertStringStartsWith( 'gtm4wp_google_token_' . $this->account_id . '_', $this->deleted_transients[0] );
		$this->assertSame( 'ya29.reminted', $service->access_token( $this->account_id, self::SCOPE ), 'The next call mints again.' );
	}
}
