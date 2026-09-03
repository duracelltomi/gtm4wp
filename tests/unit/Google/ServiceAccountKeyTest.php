<?php
/**
 * Unit tests for the service-account key file parser.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use Brain\Monkey\Functions;
use GTM4WP\Google\ServiceAccountKey;
use GTM4WP\Google\TokenService;
use GTM4WP\Tests\unit\TestCase;

/**
 * ServiceAccountKey::from_json() is the only way an uploaded file becomes
 * something the plugin signs with, so it is the input validator of the whole
 * Google integration: an admin upload is admin free-text (RI-6), and the four
 * fields it keeps are the external contract registered as U122.
 *
 * Every rejection branch has its own case (TS-5), including the two that look
 * alike from the outside - "not a key file" and "a key file whose key does not
 * parse" - because they carry different admin guidance. A PHP warning during
 * parsing is promoted to a failure: the parser silences OpenSSL on purpose and
 * must stay silent on hostile input that reaches a REST response buffer.
 */
final class ServiceAccountKeyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();

		// Promotes an UNSILENCED warning to a failure. The handler honours the
		// @ operator (error_reporting() excludes E_WARNING inside it), so a
		// warning OpenSSL emits under the parser's deliberate @ passes, and the
		// same warning reaching the REST output buffer because the @ was
		// removed fails the test.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only warning trap, restored in tearDown.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting,WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- reading, not changing, the level to honour @.
					return false;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
				throw new \ErrorException( $errstr, 0, $errno );
			}
		);
	}

	protected function tearDown(): void {
		restore_error_handler();
		parent::tearDown();
	}

	public function test_accepts_a_google_cloud_key_file_and_keeps_the_four_signing_fields(): void {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file() );

		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $key->client_email );
		$this->assertSame( KeyFileFixture::rsa_pem(), $key->private_key );
		$this->assertSame( KeyFileFixture::PRIVATE_KEY_ID, $key->private_key_id );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $key->token_uri );
	}

	/**
	 * The value object carries exactly the fields the JWT needs and nothing else
	 * from the file: project id, client id and certificate URLs never reach the
	 * vault, so they can never leak out of it either.
	 */
	public function test_keeps_nothing_beyond_the_four_signing_fields(): void {
		$properties = array_map(
			static fn ( \ReflectionProperty $p ) => $p->getName(),
			( new \ReflectionClass( ServiceAccountKey::class ) )->getProperties()
		);

		$this->assertSame( array( 'client_email', 'private_key', 'private_key_id', 'token_uri' ), $properties );
	}

	/**
	 * Google's IAM page shows two key-file examples with two different
	 * `token_uri` values (U122); the older host is the same endpoint. The JWT
	 * audience must be the endpoint the assertion is posted to, so the legacy
	 * spelling is accepted at upload and stored as the one the plugin uses.
	 */
	public function test_a_legacy_token_endpoint_is_accepted_and_stored_as_the_current_one(): void {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file( array( 'token_uri' => ServiceAccountKey::LEGACY_TOKEN_ENDPOINT ) ) );

		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $key->token_uri );
		$this->assertNotSame( ServiceAccountKey::LEGACY_TOKEN_ENDPOINT, TokenService::TOKEN_ENDPOINT );
	}

	public function test_a_missing_key_id_is_accepted_as_empty(): void {
		$key = ServiceAccountKey::from_json( KeyFileFixture::key_file( array( 'private_key_id' => null ) ) );

		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$this->assertSame( '', $key->private_key_id );
	}

	public function test_surrounding_whitespace_in_fields_is_trimmed(): void {
		$key = ServiceAccountKey::from_json(
			KeyFileFixture::key_file(
				array(
					'client_email' => '  ' . KeyFileFixture::CLIENT_EMAIL . "\n",
					'private_key'  => "\n" . KeyFileFixture::rsa_pem() . "\n\n",
				)
			)
		);

		$this->assertInstanceOf( ServiceAccountKey::class, $key );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $key->client_email );
		$this->assertSame( KeyFileFixture::rsa_pem(), $key->private_key );
	}

	/**
	 * Files that are not a service-account key. All share the one deliberately
	 * unspecific error so a hostile upload learns nothing about which check
	 * tripped.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function not_a_key_file(): array {
		return array(
			'empty string'                  => array( '' ),
			'not JSON'                      => array( '-----BEGIN PRIVATE KEY-----' ),
			'a JSON string'                 => array( '"service_account"' ),
			'a JSON list'                   => array( '["service_account"]' ),
			'an OAuth client file'          => array( KeyFileFixture::key_file( array( 'type' => 'authorized_user' ) ) ),
			'type missing'                  => array( KeyFileFixture::key_file( array( 'type' => null ) ) ),
			'type not a string'             => array( KeyFileFixture::key_file( array( 'type' => array( 'service_account' ) ) ) ),
			'client_email missing'          => array( KeyFileFixture::key_file( array( 'client_email' => null ) ) ),
			'client_email empty'            => array( KeyFileFixture::key_file( array( 'client_email' => '   ' ) ) ),
			'client_email not a string'     => array( KeyFileFixture::key_file( array( 'client_email' => array( 'a@b.c' ) ) ) ),
			'private_key missing'           => array( KeyFileFixture::key_file( array( 'private_key' => null ) ) ),
			'private_key not a string'      => array( KeyFileFixture::key_file( array( 'private_key' => array( 'x' ) ) ) ),
			'nested deeper than a key file' => array( '{"type":"service_account","a":{"b":{"c":{"d":{"e":1}}}}}' ),
		);
	}

	/**
	 * Anything that is not a service-account key file is refused as invalid.
	 *
	 * @param string $json The upload.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'not_a_key_file' )]
	public function test_rejects_anything_that_is_not_a_service_account_key_file( string $json ): void {
		$result = ServiceAccountKey::from_json( $json );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_invalid', $result->get_error_code() );
	}

	/**
	 * Keys that are present but unusable for RS256.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_private_keys(): array {
		return array(
			'garbage'                                  => array( 'not a pem' ),
			'PEM header without a body'                => array( "-----BEGIN PRIVATE KEY-----\n-----END PRIVATE KEY-----\n" ),
			'a public key'                             => array( KeyFileFixture::rsa_public_pem() ),
			'an EC key (right shape, wrong algorithm)' => array( KeyFileFixture::ec_pem() ),
			'a truncated RSA key'                      => array( substr( KeyFileFixture::rsa_pem(), 0, 400 ) . "\n-----END PRIVATE KEY-----\n" ),
		);
	}

	/**
	 * A present-but-unusable private key is refused as unparseable.
	 *
	 * @param string $pem The private_key value.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unusable_private_keys' )]
	public function test_rejects_a_private_key_openssl_cannot_sign_with( string $pem ): void {
		$result = ServiceAccountKey::from_json( KeyFileFixture::key_file( array( 'private_key' => $pem ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_unparseable', $result->get_error_code() );
	}

	/**
	 * The plugin posts to its fixed endpoint constant, never to the file's URL,
	 * so a file naming another endpoint would sign a JWT whose audience never
	 * matches. Refused at upload rather than failing at every mint.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function foreign_token_endpoints(): array {
		return array(
			'another host'    => array( 'https://oauth2.example.com/token' ),
			'http, not https' => array( 'http://oauth2.googleapis.com/token' ),
			'legacy, http'    => array( 'http://accounts.google.com/o/oauth2/token' ),
			'a subpath'       => array( TokenService::TOKEN_ENDPOINT . '/v2' ),
			'missing'         => array( null ),
			'empty'           => array( '' ),
			'not a string'    => array( array( TokenService::TOKEN_ENDPOINT ) ),
		);
	}

	/**
	 * A token endpoint other than the plugin's fixed one is refused.
	 *
	 * @param mixed $token_uri The token_uri value; null removes the field.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreign_token_endpoints' )]
	public function test_rejects_a_token_endpoint_other_than_the_one_the_plugin_posts_to( $token_uri ): void {
		$result = ServiceAccountKey::from_json( KeyFileFixture::key_file( array( 'token_uri' => $token_uri ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_google_key_token_uri', $result->get_error_code() );
	}

	/**
	 * The one thing every rejection has in common: none of the messages quotes
	 * the upload back. An error that echoed the file would put a private key
	 * into a REST response and from there into a browser's network log.
	 */
	public function test_no_rejection_message_echoes_the_upload(): void {
		$uploads = array_merge(
			array_column( self::not_a_key_file(), 0 ),
			array_map( static fn ( array $args ) => KeyFileFixture::key_file( array( 'private_key' => $args[0] ) ), self::unusable_private_keys() ),
			array( KeyFileFixture::key_file( array( 'token_uri' => 'https://oauth2.example.com/token' ) ) )
		);

		foreach ( $uploads as $json ) {
			$result = ServiceAccountKey::from_json( $json );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertStringNotContainsString( 'BEGIN', $result->get_error_message() );
			$this->assertStringNotContainsString( KeyFileFixture::CLIENT_EMAIL, $result->get_error_message() );
			if ( '' !== $json ) {
				$this->assertStringNotContainsString( substr( $json, 0, 40 ), $result->get_error_message() );
			}
		}
	}
}
