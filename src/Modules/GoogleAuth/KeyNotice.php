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
 * Names the accounts whose stored key stopped decrypting - after a salt
 * rotation, typically - so the admin learns about it before a consumer's
 * sends start failing quietly. Not dismissible: it describes a live gap and
 * clears itself once the key file is uploaded again or the account removed.
 *
 * The vault row is not autoloaded, so this costs one option read per admin
 * pageview for users who hold the settings capability. That is the price of
 * a notice that is actually seen; a notice shown only on the plugin's own
 * screen would be seen by whoever already knows something is wrong.
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
	 * Prints the notice when at least one account needs a fresh upload.
	 *
	 * @return void
	 */
	public function show_notice(): void {
		$unreadable = $this->vault->unreadable();
		if ( array() === $unreadable ) {
			return;
		}

		$labels = array();
		foreach ( $unreadable as $id ) {
			$account  = $this->vault->get( $id );
			$labels[] = (string) ( $account['label'] ?? $id );
		}

		echo '<div class="gtm4wp-notice notice notice-error" data-href="?google-key-unreadable"><p><strong>';
		printf(
			esc_html(
				/* translators: 1: comma separated list of service-account labels. 2: opening anchor element pointing to the GTM4WP options page. 3: closing anchor element. */
				_n(
					'The stored key of the Google service account %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed. Features using it cannot reach Google until you %2$supload its key file again%3$s.',
					'The stored keys of the Google service accounts %1$s can no longer be decrypted, usually because the security keys in wp-config.php changed. Features using them cannot reach Google until you %2$supload their key files again%3$s.',
					count( $labels ),
					'duracelltomi-google-tag-manager'
				)
			),
			esc_html( implode( ', ', $labels ) ),
			// The fragment is the settings app's bookmark for a module, so the
			// link lands on the service-accounts panel rather than on the
			// first module of the page.
			'<a href="' . esc_url( SettingsPage::url() . '#' . GoogleAuthModule::ID ) . '">',
			'</a>'
		);
		echo '</strong></p></div>';
	}
}
