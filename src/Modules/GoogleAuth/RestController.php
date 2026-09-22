<?php
/**
 * Service-accounts REST controller.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleAuth;

use GTM4WP\Capability;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\ServiceAccountKey;
use GTM4WP\Google\TokenService;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes of the service-accounts panel under gtm4wp/v2/google:
 *
 * - GET    service-accounts           list (metadata and status only)
 * - POST   service-accounts           upload a key file
 * - POST   service-accounts/<id>      change the label (PUT/PATCH accepted too)
 * - DELETE service-accounts/<id>      remove (refused while a consumer references it)
 * - POST   service-accounts/<id>/test mint a token now and report the outcome
 *
 * Every route requires the settings capability and the REST nonce. No
 * response carries key material: the listing is built by KeyVault from an
 * allow-list of fields, the test route reports success or failure and never
 * the token.
 */
final class RestController {

	public const REST_ROUTE = '/google/service-accounts';

	/**
	 * Route pattern of a single account. The id format is the vault's own.
	 */
	private const REST_ROUTE_ONE = self::REST_ROUTE . '/(?P<id>' . KeyVault::ID_PATTERN . ')';

	/**
	 * Hard upper bound on an uploaded key file, in bytes. A Google Cloud key
	 * file is a little over 2 KB; this refuses an abusive upload before it is
	 * decoded or parsed by OpenSSL.
	 */
	public const KEY_FILE_MAX_BYTES = 16 * 1024;

	/**
	 * Constructor.
	 *
	 * @param KeyVault     $vault  The key store.
	 * @param TokenService $tokens The token minter, for the test route.
	 */
	public function __construct( private KeyVault $vault, private TokenService $tokens ) {
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_accounts' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_account' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'label'    => array(
							'type'     => 'string',
							'required' => false,
						),
						'key_file' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REST_ROUTE_ONE,
			array(
				array(
					// POST alongside PUT/PATCH (core's EDITABLE set) because some
					// hosts refuse the two verbs at the proxy; the panel sends POST.
					'methods'             => 'POST, PUT, PATCH',
					'callback'            => array( $this, 'relabel_account' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'label' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_account' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			RestCors::REST_NAMESPACE,
			self::REST_ROUTE_ONE . '/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_account' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission check, using the same capability filter as the settings page.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return Capability::can_manage_settings();
	}

	/**
	 * GET handler: every account, metadata and status only.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_accounts(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'accounts' => $this->vault->all() ) );
	}

	/**
	 * POST handler: parses and stores an uploaded key file. The contents arrive
	 * as the `key_file` string of the JSON body (not slashed, unlike $_POST);
	 * the raw upload is parsed once and discarded.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_account( \WP_REST_Request $request ) {
		$key_file = $request->get_param( 'key_file' );

		if ( ! is_string( $key_file ) || ( '' === trim( $key_file ) ) ) {
			return new \WP_Error(
				'gtm4wp_google_key_missing',
				__( 'No key file was uploaded.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $key_file ) > self::KEY_FILE_MAX_BYTES ) {
			return new \WP_Error(
				'gtm4wp_google_key_too_large',
				__( 'The uploaded file is too large to be a service-account key file.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 400 )
			);
		}

		$key = ServiceAccountKey::from_json( $key_file );
		if ( $key instanceof \WP_Error ) {
			return new \WP_Error( $key->get_error_code(), $key->get_error_message(), array( 'status' => 400 ) );
		}

		$label = $request->get_param( 'label' );
		$id    = $this->vault->add( $key, is_string( $label ) ? $label : '' );

		if ( $id instanceof \WP_Error ) {
			return new \WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'account'  => $this->vault->get( $id ),
				'accounts' => $this->vault->all(),
			),
			201
		);
	}

	/**
	 * Relabel handler: the label is the one mutable stored field; everything
	 * else is immutable per upload.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function relabel_account( \WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$label = $request->get_param( 'label' );

		$account = $this->vault->relabel( $id, is_string( $label ) ? $label : '' );

		if ( $account instanceof \WP_Error ) {
			return new \WP_Error( $account->get_error_code(), $account->get_error_message(), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'account'  => $account,
				'accounts' => $this->vault->all(),
			)
		);
	}

	/**
	 * DELETE handler.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_account( \WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		$deleted = $this->vault->delete( $id );

		if ( $deleted instanceof \WP_Error ) {
			$status = ( 'gtm4wp_google_account_unknown' === $deleted->get_error_code() ) ? 404 : 409;

			return new \WP_Error( $deleted->get_error_code(), $deleted->get_error_message(), array( 'status' => $status ) );
		}

		// The cached tokens of the deleted key are purged inside
		// KeyVault::delete(), per minted scope (#226).
		return new \WP_REST_Response( array( 'accounts' => $this->vault->all() ) );
	}

	/**
	 * POST handler of the test action: TokenService::test_account(), shared
	 * with the gtm4wp/test-service-account ability, plus the refreshed
	 * account row for the panel. The token itself stays on the server.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_account( \WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$result = $this->tokens->test_account( $id );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$result['account'] = $this->vault->get( $id );

		return new \WP_REST_Response( $result );
	}
}
