<?php
/**
 * Google service accounts module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Abilities\ProviderInterface;
use GTM4WP\Google\KeyVault;
use GTM4WP\Module\AbilitiesInterface;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\PanelSchemaInterface;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * The service-accounts panel: no Field controls, one custom panel backed by
 * the module's own REST routes, plus the module's abilities (Abilities).
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface, PanelSchemaInterface, AbilitiesInterface {

	/**
	 * Documentation page of this module on gtm4wp.com.
	 */
	private const DOC_PAGE = 'setup-gtm4wp-features/google-service-accounts';

	/**
	 * Id of the React component rendering this module, defined in
	 * js/admin/components/panels/index.js.
	 */
	public const PANEL = 'google-service-accounts';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Google service accounts', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return '<p>' . sprintf(
			/* translators: 1: opening anchor tag linking to the Google Cloud documentation on creating service-account keys. 2: closing anchor tag. */
			esc_html__(
				'A service account lets this site call Google APIs on your behalf, for example to send refund events to Google Analytics through the Google Data Manager API. Create a service account in your Google Cloud project, %1$sdownload its JSON key file%2$s and upload that file here. Grant the account access to exactly the Google products it is meant to update and nothing else.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://docs.cloud.google.com/iam/docs/keys-create-delete" target="_blank" rel="noopener">',
			'</a>'
		) . '</p><p>' . esc_html__(
			'The private key inside the file is stored encrypted, using the security keys defined in your wp-config.php file, and is never shown again or included in a settings export. The encryption only helps while your wp-config.php stays private: define the AUTH_KEY, SECURE_AUTH_KEY, AUTH_SALT and SECURE_AUTH_SALT constants there rather than letting WordPress keep generated values in the database. Changing those constants makes every stored key unreadable; you will be asked to upload the key files again.',
			'duracelltomi-google-tag-manager'
		) . '</p><p>' . esc_html__(
			'This section is experimental. Stored accounts are used by the Google Data Manager section, where each destination picks the account it sends with; nothing is sent anywhere until a feature there is turned on.',
			'duracelltomi-google-tag-manager'
		) . '</p>';
	}

	/**
	 * No accordion groups: the panel is not made of Field controls.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array();
	}

	/**
	 * No Field controls: the accounts live outside the settings row on purpose
	 * (see \GTM4WP\Google\KeyVault).
	 *
	 * @return array
	 */
	public function fields(): array {
		return array();
	}

	/**
	 * Always available: an empty account list is the onboarding state, not a
	 * missing dependency.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}

	/**
	 * The React panel component.
	 *
	 * @return string
	 */
	public function panel(): string {
		return self::PANEL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The abilities live in Abilities, next to the REST controller serving
	 * the same accounts. Runs only inside wp_abilities_api_init; the vault
	 * it builds reads nothing until an ability runs.
	 *
	 * @return ProviderInterface
	 */
	public function abilities(): ProviderInterface {
		return new Abilities();
	}

	/**
	 * Boot data of the panel: where its routes live and the limits the upload
	 * form enforces before the request is sent (the routes enforce them again).
	 *
	 * @return array<string, mixed>
	 */
	public function panel_data(): array {
		return array(
			'restPath'        => RestCors::REST_NAMESPACE . RestController::REST_ROUTE,
			'keyFileMaxBytes' => RestController::KEY_FILE_MAX_BYTES,
			'labelMaxLength'  => KeyVault::LABEL_MAX_LENGTH,
		);
	}
}
