<?php
/**
 * Admin notice for service-account keys that can no longer be read.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Admin\SettingsPage;
use GTM4WP\Google\KeyVault;

defined( 'ABSPATH' ) || exit;

/**
 * Names the accounts whose stored key stopped decrypting (rotated salts,
 * typically, #506), and a GTM4WP_ENCRYPTION_KEY that is defined but refused
 * (#469). Not dismissible: each is a live gap that clears itself once fixed.
 * Costs one non-autoloaded option read and one decryption attempt per account
 * per admin pageview for settings-capable users while every key opens; more
 * while one does not (each secret is tried, and the labels take a second pass).
 */
final class KeyNotice {

	/**
	 * Constructor.
	 *
	 * @param KeyVault $vault The key store.
	 */
	public function __construct( private KeyVault $vault ) {
	}

	/**
	 * Registers the admin hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'show_notice' ) );
	}

	/**
	 * Prints the notices that apply.
	 *
	 * @return void
	 */
	public function show_notice(): void {
		if ( 'rejected' === $this->vault->encryption_key_state() ) {
			echo '<div class="gtm4wp-notice notice notice-error" data-href="?google-encryption-key-rejected"><p><strong>';
			printf(
				/* translators: 1: the constant name, 2: the minimum length. */
				esc_html__( '%1$s in wp-config.php is ignored: it must be a text of at least %2$d characters. Google service-account keys are encrypted with the WordPress security keys until it is fixed.', 'duracelltomi-google-tag-manager' ),
				esc_html( KeyVault::ENCRYPTION_KEY_CONSTANT ),
				(int) KeyVault::ENCRYPTION_KEY_MIN_LENGTH
			);
			echo '</strong></p></div>';
		}

		$by_cause = $this->vault->unreadable_by_cause();
		if ( array() === $by_cause ) {
			return;
		}

		$labels = array();
		foreach ( $this->vault->all() as $account ) {
			$labels[ $account['id'] ] = (string) $account['label'];
		}

		echo '<div class="gtm4wp-notice notice notice-error" data-href="?google-key-unreadable"><p><strong>';

		// One sentence per cause: each has its own remedy (#469). With the
		// constant defined (in use or refused) it is no advice to define it (#562).
		$defined   = in_array( $this->vault->encryption_key_state(), array( 'active', 'rejected' ), true );
		$sentences = array();
		foreach ( $by_cause as $cause => $ids ) {
			$names = array_map( static fn ( $id ) => $labels[ $id ] ?? $id, $ids );

			$sentences[] = sprintf(
				esc_html( self::message( $cause, count( $names ), $defined ) ),
				esc_html( implode( ', ', $names ) ),
				esc_html( KeyVault::ENCRYPTION_KEY_CONSTANT ),
				// The fragment is the settings app's module bookmark.
				'<a href="' . esc_url( SettingsPage::url() . '#' . GoogleAuthModule::ID ) . '">',
				'</a>'
			);
		}

		echo implode( ' ', $sentences ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each sentence is escaped above.
		echo '</strong></p></div>';
	}

	/**
	 * The sentence for one cause, with placeholders 1: labels, 2: the constant
	 * name, 3/4: the link to the accounts panel.
	 *
	 * @param string $cause   A KeyVault::CAUSE_* value.
	 * @param int    $count   How many accounts it names.
	 * @param bool   $defined Whether the constant is defined (in use or refused).
	 * @return string
	 */
	private static function message( string $cause, int $count, bool $defined = false ): string {
		switch ( $cause ) {
			case KeyVault::CAUSE_CONSTANT_MISSING:
				/* translators: 1: comma separated list of service-account labels. 2: the constant name. 3: opening anchor element pointing to the GTM4WP options page. 4: closing anchor element. */
				return _n(
					'The stored key of the Google service account %1$s can no longer be decrypted because %2$s is no longer defined in wp-config.php, or is ignored. Restore it, or use %3$sReplace key%4$s on the account with its key file.',
					'The stored keys of the Google service accounts %1$s can no longer be decrypted because %2$s is no longer defined in wp-config.php, or is ignored. Restore it, or use %3$sReplace key%4$s on each account with its key file.',
					$count,
					'duracelltomi-google-tag-manager'
				);
			case KeyVault::CAUSE_CONSTANT_CHANGED:
				/* translators: 1: comma separated list of service-account labels. 2: the constant name. 3: opening anchor element pointing to the GTM4WP options page. 4: closing anchor element. */
				return _n(
					'The stored key of the Google service account %1$s can no longer be decrypted because the value of %2$s in wp-config.php changed. Restore the previous value, or use %3$sReplace key%4$s on the account with its key file.',
					'The stored keys of the Google service accounts %1$s can no longer be decrypted because the value of %2$s in wp-config.php changed. Restore the previous value, or use %3$sReplace key%4$s on each account with its key file.',
					$count,
					'duracelltomi-google-tag-manager'
				);
			default:
				if ( $defined ) {
					/* translators: 1: comma separated list of service-account labels. 2: the constant name (not used in this sentence). 3: opening anchor element pointing to the GTM4WP options page. 4: closing anchor element. */
					return _n(
						'The stored key of the Google service account %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed before it moved to the encryption key. Use %3$sReplace key%4$s on the account with its key file.',
						'The stored keys of the Google service accounts %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed before they moved to the encryption key. Use %3$sReplace key%4$s on each account with its key file.',
						$count,
						'duracelltomi-google-tag-manager'
					);
				}

				/* translators: 1: comma separated list of service-account labels. 2: the constant name. 3: opening anchor element pointing to the GTM4WP options page. 4: closing anchor element. */
				return _n(
					'The stored key of the Google service account %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed (some security plugins change them on a schedule; defining %2$s prevents this). Use %3$sReplace key%4$s on the account with its key file.',
					'The stored keys of the Google service accounts %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed (some security plugins change them on a schedule; defining %2$s prevents this). Use %3$sReplace key%4$s on each account with its key file.',
					$count,
					'duracelltomi-google-tag-manager'
				);
		}
	}
}
