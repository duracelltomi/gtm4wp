<?php
/**
 * Abilities of the Google service accounts module.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Abilities\Meta;
use GTM4WP\Abilities\ProviderInterface;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Capability;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Google\WpTransport;

defined( 'ABSPATH' ) || exit;

/**
 * The module's abilities, handed to the plugin-wide Registrar through
 * AdminSchema::abilities() (Module\AbilitiesInterface) the way the Data
 * Manager module hands over its own.
 *
 * The read, gtm4wp/get-service-accounts, lists the stored accounts; the
 * write, gtm4wp/test-service-account, mints a token with one of them the
 * way the panel's Test button does (TokenService::test_account(), shared
 * with the REST route).
 *
 * ⛔ Disclosure: everything returned here goes into an assistant's transcript
 * on somebody else's servers. An account is named by its id, its label,
 * its status and when it was last tested - never its e-mail address or key
 * id (identifiers of the owner's Cloud project, which the settings screen
 * shows because the admin uploaded them, but an assistant needs neither to
 * pick an account), and never key material, which leaves the vault through
 * no read path at all. Upload, relabel and delete are deliberately not
 * abilities: key material must not transit a transcript, and the delete
 * veto lives in the panel's flow.
 */
final class Abilities implements ProviderInterface {

	public const GET_ACCOUNTS = Registrar::NAMESPACE_PREFIX . 'get-service-accounts';
	public const TEST_ACCOUNT = Registrar::NAMESPACE_PREFIX . 'test-service-account';

	/**
	 * The key store.
	 *
	 * @var KeyVault
	 */
	private KeyVault $vault;

	/**
	 * The token minter behind the test.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param KeyVault|null     $vault  The key store; the stored one when null.
	 * @param TokenService|null $tokens The token minter; one over the vault and the live transport when null.
	 */
	public function __construct( ?KeyVault $vault = null, ?TokenService $tokens = null ) {
		$this->vault  = $vault ?? new KeyVault();
		$this->tokens = $tokens ?? new TokenService( $this->vault, new WpTransport() );
	}

	/**
	 * Registers the abilities. The test is registered only while the site
	 * allows writes: it changes nothing stored beyond the account's status,
	 * but it contacts Google with the site's credentials, which is the
	 * class of action the write switch withholds.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			self::GET_ACCOUNTS,
			array(
				'label'               => __( 'Get the Google service accounts', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Lists the Google Cloud service accounts stored by Google Tag Manager for WordPress: for each one its id, the label the admin gave it, its status (unverified: never tested; ok: Google accepted the key on the last test; error: Google refused it; reupload-required: the stored key can no longer be read, usually because the security keys in wp-config.php changed) and when it was last tested (a Unix timestamp, 0 for never). The id is what test-service-account takes and what a Google Data Manager destination names as its account. The account\'s e-mail address, key id and key are never returned; an assistant does not need them to pick an account. Read-only; the list is empty on a site that uploaded no account.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => Registrar::no_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'accounts' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'        => array( 'type' => 'string' ),
									'label'     => array( 'type' => 'string' ),
									'status'    => array(
										'type' => 'string',
										'enum' => array( KeyVault::STATUS_UNVERIFIED, KeyVault::STATUS_OK, KeyVault::STATUS_ERROR, KeyVault::STATUS_REUPLOAD ),
									),
									'tested_at' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_service_accounts' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);

		if ( Registrar::writes_allowed() ) {
			$this->register_test_account();
		}
	}

	/**
	 * Registers gtm4wp/test-service-account.
	 *
	 * @return void
	 */
	private function register_test_account(): void {
		wp_register_ability(
			self::TEST_ACCOUNT,
			array(
				'label'               => __( 'Test a Google service account', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Checks that the stored key of one Google service account still works, the way the Test button of the settings screen does: the site signs a request with the key and asks Google\'s OAuth token endpoint for an access token, bypassing the cache, so a revoked key, a deleted service account or a damaged stored key surfaces now rather than at the next send. Call get-service-accounts first for the id. The answer says whether Google accepted the key (ok) and, when it did not, why, in Google\'s words; the token itself is never returned. Nothing is sent to any Google product and nothing changes on the site except the account\'s status and last-tested time, so repeating the call is harmless. It does contact Google with the site\'s credentials, so tell the user before calling it unless they asked for the test themselves. An unknown id is refused with 404 and nothing is sent.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'required'             => array( 'id' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => __( 'The account id as get-service-accounts lists it.', 'duracelltomi-google-tag-manager' ),
							'pattern'     => '^' . KeyVault::ID_PATTERN . '$',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ok'      => array(
							'type'        => 'boolean',
							'description' => __( 'True when Google issued an access token for the key.', 'duracelltomi-google-tag-manager' ),
						),
						'message' => array(
							'type'        => 'string',
							'description' => __( 'What happened, in one sentence; Google\'s reason when the key was refused.', 'duracelltomi-google-tag-manager' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'test_service_account' ),
				'permission_callback' => array( Registrar::class, 'can_write' ),
				'meta'                => Meta::write( false, true, true ),
			)
		);
	}

	/**
	 * The gtm4wp/get-service-accounts ability: the vault's public view
	 * reduced to the four members an assistant needs (see the class doc
	 * block for what is left out and why).
	 *
	 * @return array<string, mixed>
	 */
	public function get_service_accounts(): array {
		$accounts = array();

		foreach ( $this->vault->all() as $account ) {
			$accounts[] = array(
				'id'        => (string) $account['id'],
				'label'     => (string) $account['label'],
				'status'    => (string) $account['status'],
				'tested_at' => (int) $account['last_checked'],
			);
		}

		return array( 'accounts' => $accounts );
	}

	/**
	 * The gtm4wp/test-service-account ability. The write switch is checked
	 * again here (the permission callback's first check, repeated so a caller
	 * that reaches the method directly gets the named 403), then the shared
	 * test runs: an unknown id is refused before anything leaves the site.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function test_service_account( $input = null ) {
		if ( ! Registrar::writes_allowed() ) {
			return Registrar::write_disabled_error();
		}

		$id = is_array( $input ) && isset( $input['id'] ) && is_string( $input['id'] ) ? trim( $input['id'] ) : '';

		return $this->tokens->test_account( $id );
	}
}
