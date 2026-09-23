<?php
/**
 * Google service-account key value object.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * The four fields of a Google Cloud service-account JSON key file that the
 * plugin needs to sign a JWT and exchange it for an access token. Nothing else
 * from the uploaded file survives: the object is built from the raw upload
 * once, and only these fields are ever stored (see KeyVault).
 *
 * The key-file shape (`type`, `client_email`, `private_key`, `private_key_id`,
 * `token_uri`) is an external contract owned by Google Cloud IAM, registered as
 * U122 in .upstream/upstream-review-checklist.md.
 */
final class ServiceAccountKey {

	/**
	 * The `type` of a service-account key file; every other exportable key
	 * type is refused, none carries a private key to sign with.
	 */
	public const REQUIRED_TYPE = 'service_account';

	/**
	 * The `token_uri` older key files carry (Google's IAM docs still show it):
	 * the same endpoint under an older host, accepted and normalized.
	 */
	public const LEGACY_TOKEN_ENDPOINT = 'https://accounts.google.com/o/oauth2/token';

	/**
	 * Nesting depth json_decode() accepts: a key file is flat; this only bounds
	 * decoder recursion on a hostile upload.
	 */
	private const JSON_MAX_DEPTH = 4;

	/**
	 * Constructor. Private: the only way in is from_json(). The properties are
	 * immutable in intent but not `readonly`: that is PHP 8.1 syntax and the
	 * floor is 8.0.
	 *
	 * @param string $client_email   Service-account e-mail address (the JWT issuer).
	 * @param string $private_key    PEM encoded RSA private key.
	 * @param string $private_key_id Key id, sent as the JWT `kid` header.
	 * @param string $token_uri      Token endpoint (the JWT audience).
	 */
	private function __construct(
		public string $client_email,
		public string $private_key,
		public string $private_key_id,
		public string $token_uri
	) {
	}

	/**
	 * Builds the value object from a downloaded key file: `type` must be
	 * "service_account", `client_email` non-empty, `private_key` an RSA key
	 * OpenSSL parses, and `token_uri` the endpoint the plugin posts to (the
	 * legacy spelling is normalized). The last check matters: another
	 * endpoint would produce a JWT whose audience never matches, a silent
	 * failure at every mint.
	 *
	 * @param string $json Raw contents of the uploaded key file.
	 * @return self|\WP_Error
	 */
	public static function from_json( string $json ): self|\WP_Error {
		if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
			return new \WP_Error(
				'gtm4wp_google_key_no_openssl',
				__( 'The PHP OpenSSL extension is required to use Google service accounts, but it is not available on this server.', 'duracelltomi-google-tag-manager' )
			);
		}

		$data = json_decode( $json, true, self::JSON_MAX_DEPTH );

		if ( ! is_array( $data ) || ( self::REQUIRED_TYPE !== ( $data['type'] ?? null ) ) ) {
			return self::invalid_file_error();
		}

		$client_email   = self::string_field( $data, 'client_email' );
		$private_key    = self::string_field( $data, 'private_key' );
		$private_key_id = self::string_field( $data, 'private_key_id' );
		$token_uri      = self::string_field( $data, 'token_uri' );

		if ( ( '' === $client_email ) || ( '' === $private_key ) ) {
			return self::invalid_file_error();
		}

		if ( ! self::is_rsa_private_key( $private_key ) ) {
			return new \WP_Error(
				'gtm4wp_google_key_unparseable',
				__( 'The private key inside the uploaded file could not be read. Please download a new JSON key for the service account from the Google Cloud console and upload that file unchanged.', 'duracelltomi-google-tag-manager' )
			);
		}

		if ( self::LEGACY_TOKEN_ENDPOINT === $token_uri ) {
			$token_uri = TokenService::TOKEN_ENDPOINT;
		}

		if ( TokenService::TOKEN_ENDPOINT !== $token_uri ) {
			return new \WP_Error(
				'gtm4wp_google_key_token_uri',
				sprintf(
					/* translators: %s: the OAuth token endpoint URL the plugin supports. */
					__( 'The uploaded key file names a token endpoint other than %s, which this plugin does not support.', 'duracelltomi-google-tag-manager' ),
					TokenService::TOKEN_ENDPOINT
				)
			);
		}

		return new self( $client_email, $private_key, $private_key_id, $token_uri );
	}

	/**
	 * Reads one string field of the decoded file, or '' when missing or not a
	 * string (never stringified: an array there is a malformed file).
	 *
	 * @param array<string, mixed> $data The decoded key file.
	 * @param string               $key  Field name.
	 * @return string
	 */
	private static function string_field( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Whether OpenSSL parses the PEM as an RSA private key - the only key type
	 * RS256 can sign with.
	 *
	 * @param string $pem PEM encoded key.
	 * @return bool
	 */
	private static function is_rsa_private_key( string $pem ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed PEM makes OpenSSL emit a warning while it fails; the false return is the answer, the warning is not.
		$key = @openssl_pkey_get_private( $pem );
		if ( false === $key ) {
			return false;
		}

		$details = openssl_pkey_get_details( $key );

		return is_array( $details ) && ( OPENSSL_KEYTYPE_RSA === ( $details['type'] ?? null ) );
	}

	/**
	 * The one error for every "not a service-account key file" case:
	 * deliberately unspecific (the fix is always to download the file again,
	 * and it doubles as the answer to a hostile upload).
	 *
	 * @return \WP_Error
	 */
	private static function invalid_file_error(): \WP_Error {
		return new \WP_Error(
			'gtm4wp_google_key_invalid',
			__( 'The uploaded file is not a Google Cloud service-account key file. Please upload the JSON key file downloaded from the Google Cloud console.', 'duracelltomi-google-tag-manager' )
		);
	}
}
