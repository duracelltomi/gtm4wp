<?php
/**
 * Test fixture: throwaway RSA keys and Google Cloud key files built around them.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Google;

use GTM4WP\Google\ServiceAccountKey;
use GTM4WP\Google\TokenService;

/**
 * Generates one RSA key pair per test process and produces service-account
 * key files (the JSON Google Cloud IAM downloads) carrying it. No key material
 * is checked in: a committed PEM would be a credential-shaped string in a
 * public repository even though nothing trusts it.
 *
 * The file shape mirrors registry row U122; a change to what Google exports
 * is a change to key_file() AND to ServiceAccountKey::from_json().
 */
final class KeyFileFixture {

	public const CLIENT_EMAIL   = 'gtm4wp-test@example-project.iam.gserviceaccount.com';
	public const PRIVATE_KEY_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

	/**
	 * Cached PEM of the generated RSA private key.
	 *
	 * @var string|null
	 */
	private static ?string $rsa_pem = null;

	/**
	 * Cached PEM of the generated RSA public key.
	 *
	 * @var string|null
	 */
	private static ?string $rsa_public_pem = null;

	/**
	 * Cached PEM of a generated EC private key (a valid key of the wrong type).
	 *
	 * @var string|null
	 */
	private static ?string $ec_pem = null;

	/**
	 * OpenSSL configuration arguments for key generation.
	 *
	 * Key generation needs an openssl.cnf. Linux builds find the system one
	 * by themselves; the Windows PHP build ships its own under extras/ssl and
	 * finds it only through OPENSSL_CONF, which a developer machine rarely
	 * sets. Without a config every openssl_pkey_new() call returns false and
	 * every test here fails with an unrelated-looking error, so the fixture
	 * points OpenSSL at the bundled file when nothing else does.
	 *
	 * @return array<string, string>
	 */
	private static function openssl_config(): array {
		if ( false !== getenv( 'OPENSSL_CONF' ) ) {
			return array();
		}

		$bundled = dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf';

		return is_file( $bundled ) ? array( 'config' => $bundled ) : array();
	}

	/**
	 * Generates a private key and returns it with its PEM export.
	 *
	 * @param array<string, mixed> $options openssl_pkey_new() options.
	 * @return array{0: \OpenSSLAsymmetricKey, 1: string}
	 * @throws \RuntimeException When OpenSSL cannot generate a key on this machine.
	 */
	private static function generate( array $options ): array {
		$config = self::openssl_config();
		$key    = openssl_pkey_new( $options + $config );
		$pem    = '';

		if ( false === $key || ! openssl_pkey_export( $key, $pem, null, $config ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
			throw new \RuntimeException( 'OpenSSL could not generate a test key: ' . (string) openssl_error_string() );
		}

		return array( $key, $pem );
	}

	/**
	 * A 2048-bit RSA private key, PEM encoded, generated once per process.
	 *
	 * Returned without the trailing newline of the export, which is the form
	 * the parser keeps; key_data() adds the newline back, as Google's files
	 * carry it.
	 *
	 * @return string
	 */
	public static function rsa_pem(): string {
		if ( null === self::$rsa_pem ) {
			list( $key, $pem ) = self::generate(
				array(
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
					'private_key_bits' => 2048,
				)
			);

			self::$rsa_pem        = trim( $pem );
			self::$rsa_public_pem = (string) openssl_pkey_get_details( $key )['key'];
		}

		return self::$rsa_pem;
	}

	/**
	 * The public half of rsa_pem(), for verifying signatures made with it.
	 *
	 * @return string
	 */
	public static function rsa_public_pem(): string {
		self::rsa_pem();

		return (string) self::$rsa_public_pem;
	}

	/**
	 * A P-256 EC private key: parses fine, but RS256 cannot sign with it.
	 *
	 * @return string
	 */
	public static function ec_pem(): string {
		if ( null === self::$ec_pem ) {
			list( , $pem ) = self::generate(
				array(
					'private_key_type' => OPENSSL_KEYTYPE_EC,
					'curve_name'       => 'prime256v1',
				)
			);

			self::$ec_pem = $pem;
		}

		return self::$ec_pem;
	}

	/**
	 * The decoded shape of a Google Cloud service-account key file with the
	 * plugin-relevant fields filled in. Fields the plugin ignores are present
	 * too, so that the parser is exercised against a realistic file rather than
	 * against the minimum it accepts.
	 *
	 * @param array<string, mixed> $overrides Fields to replace; a null value removes the field.
	 * @return array<string, mixed>
	 */
	public static function key_data( array $overrides = array() ): array {
		$data = array(
			'type'                        => 'service_account',
			'project_id'                  => 'example-project',
			'private_key_id'              => self::PRIVATE_KEY_ID,
			'private_key'                 => self::rsa_pem() . "\n",
			'client_email'                => self::CLIENT_EMAIL,
			'client_id'                   => '123456789012345678901',
			'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
			'token_uri'                   => TokenService::TOKEN_ENDPOINT,
			'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
			'client_x509_cert_url'        => 'https://www.googleapis.com/robot/v1/metadata/x509/gtm4wp-test%40example-project.iam.gserviceaccount.com',
			'universe_domain'             => 'googleapis.com',
		);

		foreach ( $overrides as $field => $value ) {
			if ( null === $value ) {
				unset( $data[ $field ] );
			} else {
				$data[ $field ] = $value;
			}
		}

		return $data;
	}

	/**
	 * The key_data() shape as the JSON text an admin uploads.
	 *
	 * @param array<string, mixed> $overrides See key_data().
	 * @return string
	 */
	public static function key_file( array $overrides = array() ): string {
		return (string) json_encode( self::key_data( $overrides ), JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The fixture parsed into the value object the vault stores.
	 *
	 * @return ServiceAccountKey
	 * @throws \LogicException When the fixture itself no longer parses.
	 */
	public static function parse(): ServiceAccountKey {
		$key = ServiceAccountKey::from_json( self::key_file() );
		if ( ! $key instanceof ServiceAccountKey ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception reported by PHPUnit.
			throw new \LogicException( 'The fixture key file no longer parses: ' . $key->get_error_code() );
		}

		return $key;
	}
}
