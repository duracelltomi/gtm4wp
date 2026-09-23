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
 * Stores uploaded service-account keys in their own non-autoloaded option row
 * (never in `gtm4wp-options`, which travels to the settings screen and the
 * export file; the custody tests pin each path), with the private key
 * encrypted at rest: AES-256-GCM, key HKDF-derived from the wp-config.php
 * salts. That makes a database-only leak non-fatal; it does NOT protect
 * against an attacker holding the files too, and the settings screen says
 * so. Rotated salts flip the account to "re-upload required" with a notice,
 * never a fatal. The private key is write-only: only open() returns key
 * material, and only TokenService calls it; the ciphertext is never returned either.
 */
final class KeyVault {

	/**
	 * Option row holding every uploaded account. Deleted by uninstall.php.
	 */
	public const OPTION_NAME = 'gtm4wp_google_service_accounts';

	/**
	 * Prefix of every generated account id; consumers reference an account by
	 * this id, so a relabel is never a rename.
	 */
	public const ID_PREFIX = 'sa_';

	/**
	 * Regex recognising an id; ours to define, so pinning it is not UC-5.
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
	 * Longest label kept; a label is never a place to stash data.
	 */
	public const LABEL_MAX_LENGTH = 100;

	/**
	 * How stale a working account's "last checked" may get before an otherwise
	 * unchanged successful mint writes the row again; see is_unchanged_success().
	 */
	public const STATUS_REFRESH_INTERVAL = 900;

	/**
	 * Cipher and its parameters; GCM authenticates the ciphertext, so a tampered
	 * blob fails to decrypt instead of decrypting to junk.
	 */
	private const CIPHER    = 'aes-256-gcm';
	private const IV_BYTES  = 12;
	private const TAG_BYTES = 16;
	private const KEY_BYTES = 32;

	/**
	 * HKDF info string, binding the derived key to this one purpose.
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

		// Only this path treats false as fatal: a fresh id and IV mean
		// update_option()'s "unchanged" false cannot occur, so false is a
		// refused write and must not produce a phantom id.
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
		 * Filters whether a Google service account is still referenced (e.g. by
		 * a Data Manager destination) and therefore must not be deleted.
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

		// A cached token must not outlive its key on this site; purged here so
		// every deletion path covers every scope the account was minted for.
		foreach ( self::minted_scopes( $accounts[ $id ] ) as $scope ) {
			TokenService::forget( $id, $scope );
		}

		unset( $accounts[ $id ] );
		$this->write( $accounts );

		return true;
	}

	/**
	 * Changes an account's label and nothing else (consumers reference the id,
	 * so nothing is invalidated).
	 *
	 * @param string $id    Account id.
	 * @param string $label New label; the account e-mail when empty, as on add().
	 * @return array<string, mixed>|\WP_Error The account's refreshed public view.
	 */
	public function relabel( string $id, string $label ) {
		$accounts = $this->read();

		if ( ! isset( $accounts[ $id ] ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' )
			);
		}

		$label = self::clean_label( $label );

		$accounts[ $id ]['label'] = ( '' === $label ) ? (string) ( $accounts[ $id ]['client_email'] ?? '' ) : $label;
		$this->write( $accounts );

		return self::public_view( $id, $accounts[ $id ] );
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
	 * The name an account is reported under outside the settings screen (Site
	 * Health, an ability). An account uploaded without a label is stored under
	 * its e-mail address, which names the owner's Cloud project, so a label
	 * that is an address is replaced by the account id.
	 *
	 * @param array<string, mixed> $account An account as all() lists it.
	 * @return string
	 */
	public static function safe_label( array $account ): string {
		$label = (string) ( $account['label'] ?? '' );

		if ( ( '' === $label ) || ( false !== strpos( $label, '@' ) ) ) {
			return (string) ( $account['id'] ?? '' );
		}

		return $label;
	}

	/**
	 * Decrypts and returns the key of an account for signing: the only method
	 * that hands out key material. A key that no longer decrypts marks the
	 * account as needing a fresh upload.
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

		// Rebuilt through the same parser, so the decrypted key is checked
		// again rather than trusted because it came from our own row.
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
	 * @param string $scope   OAuth scope the mint was for; remembered so delete() can purge its token (#226).
	 * @param bool   $force   Write even when nothing changed (the Test button: the admin must see the time move).
	 * @return void
	 */
	public function record_token_result( string $id, bool $ok, string $message = '', string $scope = '', bool $force = false ): void {
		$accounts = $this->read();

		if ( ! isset( $accounts[ $id ] ) ) {
			return;
		}

		if ( ! $force && $this->is_unchanged_success( $accounts[ $id ], $ok, $scope ) ) {
			return;
		}

		$accounts[ $id ]['status']       = $ok ? self::STATUS_OK : self::STATUS_ERROR;
		$accounts[ $id ]['last_checked'] = $this->now();
		$accounts[ $id ]['last_error']   = $ok
			? ''
			: mb_substr( sanitize_text_field( $message ), 0, TokenService::ERROR_MAX_LENGTH );

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
	 * Whether a successful mint would record nothing new, recently enough that
	 * the timestamp is not worth a write. The concurrency mitigation: the row
	 * is read-modify-written whole with no compare-and-swap, and concurrent
	 * send jobs can lose each other's change, so a write that would only move
	 * the timestamp is skipped. A failure, a status change, a new scope and
	 * the Test button always write.
	 *
	 * @param array<string, mixed> $account The stored account.
	 * @param bool                 $ok      Whether Google issued a token.
	 * @param string               $scope   The scope the mint was for.
	 * @return bool
	 */
	private function is_unchanged_success( array $account, bool $ok, string $scope ): bool {
		if ( ! $ok ) {
			return false;
		}

		if ( self::STATUS_OK !== ( $account['status'] ?? '' ) || '' !== (string) ( $account['last_error'] ?? '' ) ) {
			return false;
		}

		if ( ( '' !== $scope ) && ! in_array( $scope, self::minted_scopes( $account ), true ) ) {
			return false;
		}

		return ( $this->now() - (int) ( $account['last_checked'] ?? 0 ) ) < self::STATUS_REFRESH_INTERVAL;
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
	 * Derives the AES key from the site secret. wp_salt() falls back to
	 * database-stored values when wp-config.php defines no keys; on such a
	 * site the key lives next to the ciphertext, which is why the settings
	 * screen tells the admin to define them.
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
