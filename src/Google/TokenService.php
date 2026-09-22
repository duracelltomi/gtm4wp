<?php
/**
 * OAuth 2.0 access tokens for Google service accounts.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * Mints a short-lived access token for a stored service account: signs a
 * JWT with the account's RSA key (RS256), exchanges it at Google's token
 * endpoint with the JWT-bearer grant, and caches the result in a transient
 * that expires a minute before the token does.
 *
 * Tokens are never logged and never returned to the browser; the only thing
 * the settings screen learns is whether the mint succeeded.
 *
 * The endpoint, grant type, scope string and JWT claim set are external
 * contracts (Google OAuth 2.0 for service accounts), registered as U120 and
 * U121 in .upstream/upstream-review-checklist.md; the token-exchange suite
 * pins the request shape.
 */
final class TokenService {

	/**
	 * Google's OAuth 2.0 token endpoint. The one place the URL is written; a
	 * key file must name the same endpoint (ServiceAccountKey) and the
	 * transport must allow its host (WpTransport::ALLOWED_HOSTS).
	 */
	public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * The grant type of a service-account token exchange.
	 */
	public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/**
	 * OAuth scope of the Data Manager API.
	 */
	public const SCOPE_DATA_MANAGER = 'https://www.googleapis.com/auth/datamanager';

	/**
	 * Lifetime requested in the JWT (`exp` - `iat`), the maximum Google allows.
	 */
	public const ASSERTION_LIFETIME = 3600;

	/**
	 * Seconds subtracted from Google's `expires_in` when caching, so a token
	 * pulled from the cache never expires mid-request.
	 */
	public const EARLY_EXPIRY = 60;

	/**
	 * Prefix of the transient holding a cached token.
	 */
	private const TRANSIENT_PREFIX = 'gtm4wp_google_token_';

	/**
	 * Constructor.
	 *
	 * @param KeyVault      $vault     The key store.
	 * @param Transport     $transport The HTTP seam.
	 * @param callable|null $clock     Returns the current Unix time; null uses time(). Injected by tests.
	 */
	public function __construct( private KeyVault $vault, private Transport $transport, private $clock = null ) {
	}

	/**
	 * Returns an access token for an account and scope, minting one when the
	 * cache holds none.
	 *
	 * @param string $account_id Account id.
	 * @param string $scope      OAuth scope, e.g. self::SCOPE_DATA_MANAGER.
	 * @param bool   $fresh      Skip the cache and mint now - the settings screen's "test" action.
	 * @return string|\WP_Error The bearer token.
	 */
	public function access_token( string $account_id, string $scope, bool $fresh = false ) {
		$transient = self::transient_name( $account_id, $scope );

		if ( ! $fresh ) {
			$cached = get_transient( $transient );
			if ( is_string( $cached ) && ( '' !== $cached ) ) {
				return $cached;
			}
		}

		$key = $this->vault->open( $account_id );
		if ( $key instanceof \WP_Error ) {
			// open() records "re-upload required" itself; recording a token
			// failure on top would overwrite it with the weaker "error". Other
			// open() failures (damaged metadata) are recorded here; an unknown
			// id records nothing.
			if ( 'gtm4wp_google_key_unreadable' !== $key->get_error_code() ) {
				$this->vault->record_token_result(
					$account_id,
					false,
					__( 'The stored data of this service account is damaged and cannot be used to sign a request. Please upload its key file again.', 'duracelltomi-google-tag-manager' )
				);
			}

			return $key;
		}

		$assertion = $this->sign_assertion( $key, $scope );
		if ( $assertion instanceof \WP_Error ) {
			$this->vault->record_token_result( $account_id, false, $assertion->get_error_message() );
			return $assertion;
		}

		$response = $this->transport->post_form(
			self::TOKEN_ENDPOINT,
			array(
				'grant_type' => self::GRANT_TYPE,
				'assertion'  => $assertion,
			)
		);

		if ( $response instanceof \WP_Error ) {
			$this->vault->record_token_result( $account_id, false, $response->get_error_message() );
			return $response;
		}

		$token      = $response['body']['access_token'] ?? null;
		$expires_in = (int) ( $response['body']['expires_in'] ?? 0 );

		if ( ( 200 !== $response['status'] ) || ! is_string( $token ) || ( '' === $token ) ) {
			$message = self::error_summary( $response );
			$this->vault->record_token_result( $account_id, false, $message );

			return new \WP_Error( 'gtm4wp_google_token_refused', $message );
		}

		// $fresh is the Test button, which always writes (see is_unchanged_success()).
		$this->vault->record_token_result( $account_id, true, '', $scope, $fresh );

		$ttl = $expires_in - self::EARLY_EXPIRY;
		if ( $ttl > 0 ) {
			set_transient( $transient, $token, $ttl );
		}

		return $token;
	}

	/**
	 * The settings screen's "test" action: mints a token now, bypassing the
	 * cache, and reports whether Google accepted the key. The one definition
	 * behind the REST test route and the gtm4wp/test-service-account ability
	 * (UC-6). The token itself stays on the server; the answer is the
	 * outcome plus a sentence - Google's capped summary on a refusal - and
	 * the vault's status row is updated as a side effect, as for any mint.
	 *
	 * @param string $account_id Account id.
	 * @return array{ok: bool, message: string}|\WP_Error 404 when the account does not exist; nothing is sent then.
	 */
	public function test_account( string $account_id ) {
		if ( ! $this->vault->has( $account_id ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 404 )
			);
		}

		$token = $this->access_token( $account_id, self::SCOPE_DATA_MANAGER, true );
		$ok    = ! ( $token instanceof \WP_Error );

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Google accepted the key and issued an access token.', 'duracelltomi-google-tag-manager' )
				: $token->get_error_message(),
		);
	}

	/**
	 * Drops the cached token of an account and scope. Called when the account
	 * is deleted so a consumer cannot keep using a key the admin removed.
	 *
	 * @param string $account_id Account id.
	 * @param string $scope      OAuth scope.
	 * @return void
	 */
	public static function forget( string $account_id, string $scope ): void {
		delete_transient( self::transient_name( $account_id, $scope ) );
	}

	/**
	 * Transient name for one account and scope. The scope is hashed so the name
	 * stays within the length WordPress accepts for a transient.
	 *
	 * @param string $account_id Account id.
	 * @param string $scope      OAuth scope.
	 * @return string
	 */
	private static function transient_name( string $account_id, string $scope ): string {
		return self::TRANSIENT_PREFIX . $account_id . '_' . substr( hash( 'sha256', $scope ), 0, 16 );
	}

	/**
	 * Builds and signs the JWT-bearer assertion.
	 *
	 * Claims per Google's service-account flow: `iss` the account e-mail,
	 * `scope` the requested scope, `aud` the token endpoint, `iat` now and
	 * `exp` one hour later. Header `alg` RS256 with the key id as `kid`.
	 *
	 * @param ServiceAccountKey $key   The account key.
	 * @param string            $scope OAuth scope.
	 * @return string|\WP_Error Compact serialised JWT.
	 */
	private function sign_assertion( ServiceAccountKey $key, string $scope ) {
		$now = is_callable( $this->clock ) ? (int) call_user_func( $this->clock ) : time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		if ( '' !== $key->private_key_id ) {
			$header['kid'] = $key->private_key_id;
		}

		$claims = array(
			'iss'   => $key->client_email,
			'scope' => $scope,
			'aud'   => $key->token_uri,
			'iat'   => $now,
			'exp'   => $now + self::ASSERTION_LIFETIME,
		);

		$signing_input = self::base64url( (string) wp_json_encode( $header ) ) . '.' . self::base64url( (string) wp_json_encode( $claims ) );

		$signature = '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unusable key makes OpenSSL warn while returning false; the return value is handled, the warning must not reach the output buffer of a REST response.
		$signed = @openssl_sign( $signing_input, $signature, $key->private_key, OPENSSL_ALGO_SHA256 );

		if ( true !== $signed ) {
			return new \WP_Error(
				'gtm4wp_google_token_sign_failed',
				__( 'The request to Google could not be signed with the stored key.', 'duracelltomi-google-tag-manager' )
			);
		}

		return $signing_input . '.' . self::base64url( $signature );
	}

	/**
	 * URL-safe base64 without padding, as JOSE requires.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT wire encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * A short, storable description of a refused token exchange from Google's
	 * `error` and `error_description` (never key material; the vault caps it).
	 *
	 * @param array{status: int, body: array|null} $response The transport response.
	 * @return string
	 */
	private static function error_summary( array $response ): string {
		$body        = is_array( $response['body'] ) ? $response['body'] : array();
		$code        = is_string( $body['error'] ?? null ) ? $body['error'] : '';
		$description = is_string( $body['error_description'] ?? null ) ? $body['error_description'] : '';

		if ( '' === $code ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Google did not issue a token (HTTP %d).', 'duracelltomi-google-tag-manager' ),
				(int) $response['status']
			);
		}

		return ( '' === $description ) ? $code : $code . ': ' . $description;
	}
}
