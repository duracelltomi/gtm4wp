<?php
/**
 * Site Health surfaces of the Google service accounts module.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Admin\SettingsPage;
use GTM4WP\Admin\SiteHealthRows;
use GTM4WP\Google\KeyVault;

defined( 'ABSPATH' ) || exit;

/**
 * The key-status rows of the Info section and the test that reports a key
 * which no longer decrypts (a wp-config.php salt rotation). Both try each key
 * through KeyVault (#506), which hands out no key material here.
 *
 * ⛔ The Info section is pasted into public threads: an account is named by
 * KeyVault::safe_label() and its status word, never by its address, key id
 * or any key material.
 */
final class SiteHealth {

	/**
	 * Id of the keys test, as Admin\SiteHealthTests derives it.
	 */
	public const TEST_ID = 'gtm4wp_google_auth_keys';

	/**
	 * Module-local key of the keys test.
	 */
	public const TEST_KEY = 'keys';

	/**
	 * Constructor.
	 *
	 * @param KeyVault $vault The service-account store.
	 */
	public function __construct( private KeyVault $vault ) {
	}

	/**
	 * The keys test: critical when a stored key can no longer be read, because
	 * every feature using that account has stopped reaching Google.
	 *
	 * @return array<string, mixed>
	 */
	public function run_test(): array {
		if ( array() === $this->vault->unreadable() ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Google service account keys are readable', 'duracelltomi-google-tag-manager' ),
				'description' => '<p>' . esc_html__( 'Every stored Google service account key can be decrypted, or no account is stored.', 'duracelltomi-google-tag-manager' ) . '</p>',
				'actions'     => '',
			);
		}

		return array(
			'status'      => 'critical',
			'label'       => __( 'A Google service account key can no longer be read', 'duracelltomi-google-tag-manager' ),
			'description' => '<p>' . esc_html__( 'The stored key of at least one Google service account can no longer be decrypted, which happens when the security keys in wp-config.php or GTM4WP_ENCRYPTION_KEY change. Every feature using that account has stopped reaching Google. Use Replace key on the account with its key file to fix it.', 'duracelltomi-google-tag-manager' ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				// The fragment is the settings app's module bookmark.
				esc_url( SettingsPage::url() . '#' . GoogleAuthModule::ID ),
				esc_html__( 'Open the Google service accounts', 'duracelltomi-google-tag-manager' )
			),
		);
	}

	/**
	 * This module's rows of the plugin's Site Health Info section: one per
	 * account, or a "none" row.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function debug_fields(): array {
		$accounts = $this->vault->all();
		$fields   = array();

		if ( array() === $accounts ) {
			$fields['accounts'] = SiteHealthRows::none( __( 'Google service accounts', 'duracelltomi-google-tag-manager' ) );
		}

		foreach ( $accounts as $index => $account ) {
			$fields[ 'account_' . $index ] = SiteHealthRows::text(
				__( 'Google service account', 'duracelltomi-google-tag-manager' ),
				KeyVault::safe_label( $account ) . ': ' . (string) $account['status']
			);
		}

		$fields['key_encryption'] = $this->key_encryption_row();

		return $fields;
	}

	/**
	 * Which secret new keys are encrypted with (#469); never its value.
	 *
	 * @return array<string, string>
	 */
	private function key_encryption_row(): array {
		$label = __( 'Google service account key encryption', 'duracelltomi-google-tag-manager' );

		switch ( $this->vault->encryption_key_state() ) {
			case 'active':
				return SiteHealthRows::text( $label, KeyVault::ENCRYPTION_KEY_CONSTANT );
			case 'rejected':
				return SiteHealthRows::text(
					$label,
					/* translators: 1: the constant name, 2: the minimum number of characters. */
					sprintf( __( 'WordPress security keys (%1$s is ignored: not a text of %2$d or more characters)', 'duracelltomi-google-tag-manager' ), KeyVault::ENCRYPTION_KEY_CONSTANT, KeyVault::ENCRYPTION_KEY_MIN_LENGTH ),
					'WordPress security keys (' . KeyVault::ENCRYPTION_KEY_CONSTANT . ' is ignored: not a text of ' . KeyVault::ENCRYPTION_KEY_MIN_LENGTH . ' or more characters)'
				);
			default:
				return SiteHealthRows::text( $label, __( 'WordPress security keys', 'duracelltomi-google-tag-manager' ), 'WordPress security keys' );
		}
	}
}
