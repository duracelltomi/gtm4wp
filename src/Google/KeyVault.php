<?php
/**
 * Encrypted storage of Google service-account keys.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Google;

defined( 'ABSPATH' ) || exit;

/**
 * Stores uploaded service-account keys in their own, non-autoloaded option
 * row, with the private key encrypted at rest.
 *
 * Why a dedicated row and not a field in `gtm4wp-options`: that row is
 * returned wholesale to the settings screen, written by the settings POST,
 * and included in the settings export file. A private key must never travel
 * any of those paths, and keeping it out of the row makes that true by
 * construction rather than by filtering - the custody tests pin each path.
 *
 * Encryption: AES-256-GCM, key derived with HKDF from the wp-config.php
 * salts. This exists to make a database-only leak (SQL injection, a stray
 * backup) non-fatal: the attacker gets ciphertext. It does NOT protect
 * against an attacker who has both the database and the files - wp-config.php
 * holds the salts - and the settings screen says so. Rotating the salts
 * makes every stored key unreadable; that case flips the account to the
 * "re-upload required" status and raises an admin notice, it never fatals.
 *
 * The private key is write-only: nothing this class returns to a caller other
 * than open() carries key material, and open() is only called by the
 * TokenService at signing time. The ciphertext is not returned either - a
 * blob an admin can copy out is a blob that ends up in a support thread.
 */
final class KeyVault {

	/**
	 * Option row holding every uploaded account. Deleted by uninstall.php.
	 */
	public const OPTION_NAME = 'gtm4wp_google_service_accounts';

	/**
	 * Prefix of every generated account id. Destination rows and any other
	 * consumer reference an account by this id, so a relabel is never a rename.
	 */
	public const ID_PREFIX = 'sa_';

	/**
	 * Regex a route pattern or a lookup can use to recognise an id. Ours to
	 * define, so pinning the format is not the UC-5 mistake.
	 */
	public const ID_PATTERN = 'sa_[a-f0-9]{12}';

	/**
	 * Account states as reported by the listing.
	 */
	public const STATUS_UNVERIFIED = 'unverified';
	public const STATUS_OK         = 'ok';
	public const STATUS_ERROR      = 'error';
	public const STATUS_REUPLOAD   = 'reupload-required';

	/**
	 * Longest label kept. Long enough for "Production GA4 property (Acme)",
	 * short enough that a label is never a place to stash data.
	 */
	public const LABEL_MAX_LENGTH = 100;

	/**
	 * Longest last-error text kept per account. The text comes from Google's
	 * OAuth error response and is shown on the settings screen; the cap keeps a
	 * verbose upstream error from bloating the option row.
	 */
	private const ERROR_MAX_LENGTH = 200;

	/**
	 * Cipher and its parameters. GCM authenticates the ciphertext, so a
	 * tampered or foreign blob fails to decrypt instead of decrypting to junk.
	 */
	private const CIPHER    = 'aes-256-gcm';
	private const IV_BYTES  = 12;
	private const TAG_BYTES = 16;
	private const KEY_BYTES = 32;

	/**
	 * HKDF info string. Binds the derived key to this one purpose so that any
	 * other derivation from the same salts yields an unrelated key.
	 */
	private const HKDF_INFO = 'gtm4wp/google-service-accounts/v1';

	/**
	 * The secret the storage key is derived from, or null to derive it from
	 * the WordPress salts on first use.
	 *
	 * @var string|null
	 */
	private ?string $secret;

	/**
	 * Returns the current Unix time. Injectable so tests can pin the stored
	 * uploaded_at / last_checked values exactly; mirrors TokenService's seam.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string|null   $secret Input keying material; null derives it from the
	 *                              wp-config.php salts. Injected by tests only.
	 * @param callable|null $clock  Returns the current Unix time; null uses time().
	 *                              Injected by tests.
	 */
	public function __construct( ?string $secret = null, $clock = null ) {
		$this->secret = $secret;
		$this->clock  = $clock;
	}

	/**
	 * The current Unix time through the injectable clock.
	 *
	 * @return int
	 */
	private function now(): int {
		return is_callable( $this->clock ) ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Stores a validated key under a new id.
	 *
	 * @param ServiceAccountKey $key   The parsed key file.
	 * @param string            $label Admin-chosen label; the account e-mail when empty.
	 * @return string|\WP_Error The new account id.
	 */
	public function add( ServiceAccountKey $key, string $label ) {
		$accounts = $this->read();
		$id       = $this->new_id( $accounts );

		$sealed = $this->seal( $key->private_key, $id );
		if ( $sealed instanceof \WP_Error ) {
			return $sealed;
		}

		$label = self::clean_label( $label );

		$accounts[ $id ] = array(
			'label'          => ( '' === $label ) ? $key->client_email : $label,
			'client_email'   => $key->client_email,
			'private_key_id' => $key->private_key_id,
			'token_uri'      => $key->token_uri,
			'uploaded_at'    => $this->now(),
			'status'         => self::STATUS_UNVERIFIED,
			'last_checked'   => 0,
			'last_error'     => '',
			'scopes'         => array(),
			'key'            => $sealed,
		);

		// A refused write must not produce a phantom id the next read cannot
		// find. Only this path treats false as fatal: the array always carries
		// a fresh random id and a fresh IV, so update_option()'s "unchanged
		// value" false cannot occur here - false genuinely means the store
		// refused the write. On the status/delete paths it can mean either,
		// which is why they do not check.
		if ( ! $this->write( $accounts ) ) {
			return new \WP_Error(
				'gtm4wp_google_key_store_failed',
				__( 'The service account could not be saved to the database. Please try again.', 'duracelltomi-google-tag-manager' )
			);
		}

		return $id;
	}

	/**
	 * Removes an account, unless a consumer still references it.
	 *
	 * @param string $id Account id.
	 * @return true|\WP_Error
	 */
	public function delete( string $id ) {
		$accounts = $this->read();

		if ( ! isset( $accounts[ $id ] ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' )
			);
		}

		/**
		 * Filters whether a Google service account is still referenced somewhere
		 * and therefore must not be deleted.
		 *
		 * Any feature that stores an account id (a Data Manager destination, for
		 * example) returns true here while the reference exists, so that the
		 * settings screen refuses the deletion with an explanation instead of
		 * leaving the feature pointing at a key that is gone.
		 *
		 * @since 2.1.0
		 *
		 * @param bool   $in_use Whether the account is referenced. Default false.
		 * @param string $id     The account id about to be deleted.
		 */
		if ( true === apply_filters( GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE, false, $id ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_in_use',
				__( 'This service account is still used by a configured destination. Remove or reassign that destination first.', 'duracelltomi-google-tag-manager' )
			);
		}

		// A token minted from this key must not outlive it on this site. The
		// purge lives here rather than in the REST controller so that every
		// deletion path drops the cached token of every scope the account was
		// actually minted for - a scope added later is covered without anyone
		// remembering another forget call. Deleting the transient cannot
		// revoke the token at Google; it stops this site reusing it.
		foreach ( self::minted_scopes( $accounts[ $id ] ) as $scope ) {
			TokenService::forget( $id, $scope );
		}

		unset( $accounts[ $id ] );
		$this->write( $accounts );

		return true;
	}

	/**
	 * Every stored account with its metadata and status - never key material,
	 * not even the ciphertext.
	 *
	 * @return array<int, array<string, mixed>> Ordered by upload time.
	 */
	public function all(): array {
		$listed = array();

		foreach ( $this->read() as $id => $account ) {
			$listed[] = self::public_view( $id, $account );
		}

		return $listed;
	}

	/**
	 * One account's metadata and status, or null when the id is unknown.
	 *
	 * @param string $id Account id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$accounts = $this->read();

		return isset( $accounts[ $id ] ) ? self::public_view( $id, $accounts[ $id ] ) : null;
	}

	/**
	 * Whether an account exists.
	 *
	 * @param string $id Account id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->read()[ $id ] );
	}

	/**
	 * Ids of the accounts whose stored key can no longer be read.
	 *
	 * @return string[]
	 */
	public function unreadable(): array {
		$ids = array();

		foreach ( $this->read() as $id => $account ) {
			if ( self::STATUS_REUPLOAD === ( $account['status'] ?? '' ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Decrypts and returns the key of an account for signing.
	 *
	 * The only method that hands out key material. A key that no longer
	 * decrypts (rotated salts, a tampered row) marks the account as needing a
	 * fresh upload and returns an error; the admin notice picks the status up.
	 *
	 * @param string $id Account id.
	 * @return ServiceAccountKey|\WP_Error
	 */
	public function open( string $id ) {
		$accounts = $this->read();

		if ( ! isset( $accounts[ $id ] ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' )
			);
		}

		$account = $accounts[ $id ];
		$pem     = $this->unseal( $account['key'] ?? null, $id );

		if ( null === $pem ) {
			$accounts[ $id ]['status']     = self::STATUS_REUPLOAD;
			$accounts[ $id ]['last_error'] = '';
			$this->write( $accounts );

			return new \WP_Error(
				'gtm4wp_google_key_unreadable',
				__( 'The stored key of this service account can no longer be decrypted (this happens when the security keys in wp-config.php change). Please upload the key file again.', 'duracelltomi-google-tag-manager' )
			);
		}

		// The stored metadata was validated on upload; ServiceAccountKey is
		// rebuilt through the same parser so the decrypted key is checked again
		// rather than trusted because it came from our own row.
		return ServiceAccountKey::from_json(
			(string) wp_json_encode(
				array(
					'type'           => ServiceAccountKey::REQUIRED_TYPE,
					'client_email'   => (string) ( $account['client_email'] ?? '' ),
					'private_key'    => $pem,
					'private_key_id' => (string) ( $account['private_key_id'] ?? '' ),
					'token_uri'      => (string) ( $account['token_uri'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Records the outcome of a token mint against the account.
	 *
	 * @param string $id      Account id.
	 * @param bool   $ok      Whether Google issued a token.
	 * @param string $message Error summary from Google when it did not; ignored on success.
	 * @param string $scope   OAuth scope the mint was for; remembered on success so
	 *                        delete() can purge that scope's cached token (#226).
	 * @return void
	 */
	public function record_token_result( string $id, bool $ok, string $message = '', string $scope = '' ): void {
		$accounts = $this->read();

		if ( ! isset( $accounts[ $id ] ) ) {
			return;
		}

		$accounts[ $id ]['status']       = $ok ? self::STATUS_OK : self::STATUS_ERROR;
		$accounts[ $id ]['last_checked'] = $this->now();
		$accounts[ $id ]['last_error']   = $ok
			? ''
			: mb_substr( sanitize_text_field( $message ), 0, self::ERROR_MAX_LENGTH );

		if ( $ok && ( '' !== $scope ) ) {
			$scopes = self::minted_scopes( $accounts[ $id ] );
			if ( ! in_array( $scope, $scopes, true ) ) {
				$scopes[] = $scope;
			}
			$accounts[ $id ]['scopes'] = $scopes;
		}

		$this->write( $accounts );
	}

	/**
	 * The scopes an account has successfully minted a token for. Tolerates a
	 * row written before the field existed.
	 *
	 * @param array<string, mixed> $account Stored account.
	 * @return string[]
	 */
	private static function minted_scopes( array $account ): array {
		return array_values( array_filter( (array) ( $account['scopes'] ?? array() ), 'is_string' ) );
	}

	/**
	 * Normalises an admin-supplied label.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	public static function clean_label( string $label ): string {
		return mb_substr( sanitize_text_field( $label ), 0, self::LABEL_MAX_LENGTH );
	}

	/**
	 * The part of a stored account that may leave this class.
	 *
	 * Built as an allow-list of fields rather than by unsetting `key`, so that a
	 * field added to the stored shape later is not exposed by default.
	 *
	 * @param string               $id      Account id.
	 * @param array<string, mixed> $account Stored account.
	 * @return array<string, mixed>
	 */
	private static function public_view( string $id, array $account ): array {
		return array(
			'id'             => $id,
			'label'          => (string) ( $account['label'] ?? '' ),
			'client_email'   => (string) ( $account['client_email'] ?? '' ),
			'private_key_id' => (string) ( $account['private_key_id'] ?? '' ),
			'uploaded_at'    => (int) ( $account['uploaded_at'] ?? 0 ),
			'status'         => (string) ( $account['status'] ?? self::STATUS_UNVERIFIED ),
			'last_checked'   => (int) ( $account['last_checked'] ?? 0 ),
			'last_error'     => (string) ( $account['last_error'] ?? '' ),
		);
	}

	/**
	 * Generates an id no stored account uses.
	 *
	 * @param array<string, array> $accounts Stored accounts.
	 * @return string
	 */
	private function new_id( array $accounts ): string {
		do {
			$id = self::ID_PREFIX . bin2hex( random_bytes( 6 ) );
		} while ( isset( $accounts[ $id ] ) );

		return $id;
	}

	/**
	 * Encrypts a PEM for storage.
	 *
	 * The account id is the GCM additional authenticated data, so a blob moved
	 * from one account row to another fails to open.
	 *
	 * @param string $pem Private key.
	 * @param string $id  Account id the blob belongs to.
	 * @return array{iv: string, tag: string, ciphertext: string}|\WP_Error Base64 fields.
	 */
	private function seal( string $pem, string $id ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			return new \WP_Error(
				'gtm4wp_google_key_no_openssl',
				__( 'The PHP OpenSSL extension is required to use Google service accounts, but it is not available on this server.', 'duracelltomi-google-tag-manager' )
			);
		}

		$iv  = random_bytes( self::IV_BYTES );
		$tag = '';

		$ciphertext = openssl_encrypt( $pem, self::CIPHER, $this->storage_key(), OPENSSL_RAW_DATA, $iv, $tag, $id, self::TAG_BYTES );

		if ( false === $ciphertext ) {
			return new \WP_Error(
				'gtm4wp_google_key_seal_failed',
				__( 'The key could not be encrypted for storage.', 'duracelltomi-google-tag-manager' )
			);
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored in a serialized option, not obfuscation.
		return array(
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
			'ciphertext' => base64_encode( $ciphertext ),
		);
		// phpcs:enable
	}

	/**
	 * Decrypts a stored blob, or null when it does not authenticate.
	 *
	 * @param mixed  $sealed The stored {iv, tag, ciphertext} array.
	 * @param string $id     Account id the blob must belong to.
	 * @return string|null
	 */
	private function unseal( $sealed, string $id ): ?string {
		if ( ! is_array( $sealed ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own stored ciphertext.
		$iv         = base64_decode( (string) ( $sealed['iv'] ?? '' ), true );
		$tag        = base64_decode( (string) ( $sealed['tag'] ?? '' ), true );
		$ciphertext = base64_decode( (string) ( $sealed['ciphertext'] ?? '' ), true );
		// phpcs:enable

		if ( ( false === $iv ) || ( false === $tag ) || ( false === $ciphertext ) || ( self::IV_BYTES !== strlen( $iv ) ) || ( self::TAG_BYTES !== strlen( $tag ) ) ) {
			return null;
		}

		$pem = openssl_decrypt( $ciphertext, self::CIPHER, $this->storage_key(), OPENSSL_RAW_DATA, $iv, $tag, $id );

		return ( false === $pem ) ? null : $pem;
	}

	/**
	 * Derives the AES key from the site secret.
	 *
	 * The salt function, wp_salt(), reads the wp-config.php keys when they are defined and falls
	 * back to values it generates and stores in the database otherwise. On such
	 * a site the derived key lives next to the ciphertext, and the encryption
	 * protects against nothing - which is why the settings screen tells the
	 * admin to define the keys in wp-config.php.
	 *
	 * @return string 32 raw bytes.
	 */
	private function storage_key(): string {
		if ( null === $this->secret ) {
			$this->secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}

		return hash_hkdf( 'sha256', $this->secret, self::KEY_BYTES, self::HKDF_INFO );
	}

	/**
	 * Reads the option row.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Writes the option row, non-autoloaded: it is only read on the settings
	 * screen and at send time, never on a frontend pageview.
	 *
	 * @param array<string, array<string, mixed>> $accounts Every account.
	 * @return bool Whether the store accepted the write. Only add() treats
	 *              false as fatal - see the note there.
	 */
	private function write( array $accounts ): bool {
		// update_option() only applies the autoload flag when the row is created,
		// so the very first write goes through add_option() to set it. A row that
		// already exists keeps whatever autoload it has, which is this one.
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			return add_option( self::OPTION_NAME, $accounts, '', false );
		}

		return update_option( self::OPTION_NAME, $accounts, false );
	}
}
