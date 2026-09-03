<?php
/**
 * Unit tests for the service-accounts REST controller.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Modules\GoogleAuth\RestController;
use GTM4WP\RestCors;
use GTM4WP\Tests\unit\Google\FakeTransport;
use GTM4WP\Tests\unit\Google\KeyFileFixture;
use GTM4WP\Tests\unit\Google\OptionStoreTrait;
use GTM4WP\Tests\unit\TestCase;

/**
 * The five routes of the service-accounts panel. Three lenses:
 *
 * - Access control (TS-12/TC-13): every route is registered with can_manage()
 *   as its permission_callback, and can_manage() is proven in the grant, deny
 *   and filtered-capability directions like the settings controller's.
 * - Custody: no response carries the private key, its ciphertext, or a
 *   token. Each handler's payload is flattened and searched for all three.
 * - Behaviour: the 400/404/409/500 branches and what each success does
 *   beyond answering - the delete route must also drop the cached token.
 *
 * The transport is the recording fake, so the test route's request shape is
 * observable and no test reaches the network.
 */
final class GoogleAuthRestControllerTest extends TestCase {

	use OptionStoreTrait;

	private const SECRET    = 'unit-test-site-secret-do-not-reuse';
	private const FILTER    = 'gtm4wp_admin_page_capability';
	private const CUSTOMCAP = 'manage_gtm4wp';

	private KeyVault $vault;

	private FakeTransport $transport;

	/**
	 * Names passed to delete_transient().
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// FakeTransport enforces the real allow-list via WpTransport::is_allowed_url().
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->deleted_transients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				$this->deleted_transients[] = $name;
				return true;
			}
		);

		$this->stub_option_store();

		$this->vault     = new KeyVault( self::SECRET );
		$this->transport = new FakeTransport();
	}

	/**
	 * The controller over the shared vault and fake transport.
	 *
	 * @return RestController
	 */
	private function make_controller(): RestController {
		return new RestController( $this->vault, new TokenService( $this->vault, $this->transport, static fn () => 1_800_000_000 ) );
	}

	/**
	 * Uploads the fixture through the controller and returns the new id.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private function upload_fixture( string $label = 'Production' ): string {
		$response = $this->make_controller()->upload_account(
			new \WP_REST_Request(
				array(
					'label'    => $label,
					'key_file' => KeyFileFixture::key_file(),
				)
			)
		);
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		return $response->get_data()['account']['id'];
	}

	/**
	 * Asserts a payload carries none of the three secrets.
	 *
	 * @param mixed  $payload Response data.
	 * @param string $token   The token the fake would have issued, if any.
	 * @return void
	 */
	private function assert_no_secret_in( $payload, string $token = '' ): void {
		$flat = serialize( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- flattening to search.

		$this->assertStringNotContainsString( 'BEGIN', $flat, 'No PEM.' );
		$this->assertStringNotContainsString( 'ciphertext', $flat, 'No sealed blob either.' );
		foreach ( $this->options[ KeyVault::OPTION_NAME ] ?? array() as $row ) {
			if ( isset( $row['key']['ciphertext'] ) ) {
				$this->assertStringNotContainsString( $row['key']['ciphertext'], $flat );
			}
		}
		if ( '' !== $token ) {
			$this->assertStringNotContainsString( $token, $flat, 'No access token.' );
		}
	}

	// ---- Access control ----------------------------------------------------

	public function test_can_manage_checks_manage_options_by_default(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( $this->make_controller()->can_manage() );
	}

	public function test_can_manage_grants_when_the_filtered_capability_is_held(): void {
		Filters\expectApplied( self::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( true );

		$this->assertTrue( $this->make_controller()->can_manage() );
	}

	public function test_can_manage_denies_when_the_filtered_capability_is_missing(): void {
		Filters\expectApplied( self::FILTER )->once()->with( 'manage_options' )->andReturn( self::CUSTOMCAP );
		Functions\expect( 'current_user_can' )->once()->with( self::CUSTOMCAP )->andReturn( false );

		$this->assertFalse( $this->make_controller()->can_manage() );
	}

	/**
	 * Every route, every method, gated by can_manage(). Pinned by the recorded
	 * register_rest_route() arguments: a route added without a permission
	 * callback, or with __return_true, fails here.
	 */
	public function test_every_route_is_registered_under_the_namespace_with_the_capability_gate(): void {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$registered ) {
				$registered[] = array( $ns, $route, $args );
				return true;
			}
		);

		$controller = $this->make_controller();
		$controller->register_routes();

		$this->assertCount( 3, $registered );

		$endpoints = array();
		foreach ( $registered as list( $ns, $route, $args ) ) {
			$this->assertSame( RestCors::REST_NAMESPACE, $ns );
			$this->assertStringStartsWith( RestController::REST_ROUTE, $route );

			// One route may carry one endpoint or a list of them.
			$list = isset( $args['methods'] ) ? array( $args ) : $args;
			foreach ( $list as $endpoint ) {
				$this->assertSame( array( $controller, 'can_manage' ), $endpoint['permission_callback'], "{$endpoint['methods']} {$route} is gated by can_manage()." );
				$endpoints[] = $endpoint['methods'] . ' ' . $route;
			}
		}

		$this->assertSame(
			array(
				'GET ' . RestController::REST_ROUTE,
				'POST ' . RestController::REST_ROUTE,
				'POST, PUT, PATCH ' . RestController::REST_ROUTE . '/(?P<id>' . KeyVault::ID_PATTERN . ')',
				'DELETE ' . RestController::REST_ROUTE . '/(?P<id>' . KeyVault::ID_PATTERN . ')',
				'POST ' . RestController::REST_ROUTE . '/(?P<id>' . KeyVault::ID_PATTERN . ')/test',
			),
			$endpoints,
			'The id in a route is constrained to the vault\'s own id format.'
		);

		$upload = $registered[0][2][1];
		$this->assertTrue( $upload['args']['key_file']['required'] );
		$this->assertSame( 'string', $upload['args']['key_file']['type'] );

		$relabel = $registered[1][2][0];
		$this->assertTrue( $relabel['args']['label']['required'] );
		$this->assertSame( 'string', $relabel['args']['label']['type'] );
	}

	// ---- List --------------------------------------------------------------

	public function test_list_returns_the_public_view_of_every_account_and_no_key_material(): void {
		$id = $this->upload_fixture();

		$response = $this->make_controller()->list_accounts();

		$this->assertSame( 200, $response->get_status() );
		$accounts = $response->get_data()['accounts'];
		$this->assertCount( 1, $accounts );
		$this->assertSame( $id, $accounts[0]['id'] );
		$this->assertSame( 'Production', $accounts[0]['label'] );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $accounts[0]['client_email'] );
		$this->assertSame( KeyVault::STATUS_UNVERIFIED, $accounts[0]['status'] );
		$this->assert_no_secret_in( $response->get_data() );
	}

	public function test_list_is_empty_before_any_upload(): void {
		$this->assertSame( array( 'accounts' => array() ), $this->make_controller()->list_accounts()->get_data() );
	}

	// ---- Upload ------------------------------------------------------------

	public function test_upload_stores_the_key_and_answers_201_with_the_new_account(): void {
		$response = $this->make_controller()->upload_account(
			new \WP_REST_Request(
				array(
					'label'    => 'Production',
					'key_file' => KeyFileFixture::key_file(),
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertMatchesRegularExpression( '/^' . KeyVault::ID_PATTERN . '$/', $data['account']['id'] );
		$this->assertSame( 'Production', $data['account']['label'] );
		$this->assertSame( array( $data['account'] ), $data['accounts'], 'The full listing rides along so the panel can re-render without a second request.' );
		$this->assert_no_secret_in( $data );

		$this->assertTrue( $this->vault->has( $data['account']['id'] ) );
	}

	public function test_upload_without_a_label_labels_the_account_by_its_email(): void {
		$response = $this->make_controller()->upload_account( new \WP_REST_Request( array( 'key_file' => KeyFileFixture::key_file() ) ) );

		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $response->get_data()['account']['label'] );
	}

	/**
	 * Uploads refused with 400 and the code that names the problem.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refused_uploads(): array {
		return array(
			'no key_file'           => array( null, 'gtm4wp_google_key_missing' ),
			'blank key_file'        => array( "  \n", 'gtm4wp_google_key_missing' ),
			'key_file not a string' => array( array( 'type' => 'service_account' ), 'gtm4wp_google_key_missing' ),
			'over the size cap'     => array( '{' . str_repeat( ' ', RestController::KEY_FILE_MAX_BYTES ) . '}', 'gtm4wp_google_key_too_large' ),
			'not a key file'        => array( '{"type":"authorized_user"}', 'gtm4wp_google_key_invalid' ),
			'unusable key'          => array( KeyFileFixture::key_file( array( 'private_key' => 'nope' ) ), 'gtm4wp_google_key_unparseable' ),
			'foreign endpoint'      => array( KeyFileFixture::key_file( array( 'token_uri' => 'https://oauth2.example.com/token' ) ), 'gtm4wp_google_key_token_uri' ),
		);
	}

	/**
	 * A refused upload is a 400 that stores nothing.
	 *
	 * @param mixed  $key_file The key_file parameter.
	 * @param string $code     Expected error code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_uploads' )]
	public function test_upload_refuses_with_400_and_stores_nothing( $key_file, string $code ): void {
		$response = $this->make_controller()->upload_account( new \WP_REST_Request( array( 'key_file' => $key_file ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( array(), $this->option_writes, 'Nothing is written for a refused upload.' );
		$this->assertStringNotContainsString( 'BEGIN', $response->get_error_message() );
	}

	/**
	 * The vault half of #228 pins add() returning a WP_Error on a refused
	 * write; this is the REST half - the handler must translate that error
	 * into a 500 instead of answering 201 with a phantom account.
	 */
	public function test_upload_answers_500_when_the_store_refuses_the_write(): void {
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( false );

		$response = $this->make_controller()->upload_account( new \WP_REST_Request( array( 'key_file' => KeyFileFixture::key_file() ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'gtm4wp_google_key_store_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->vault->all(), 'No phantom account exists after the refused write.' );
	}

	/**
	 * The size cap is checked before the parser: an upload one byte over is
	 * refused as too large even when it would otherwise parse, one at the cap
	 * is parsed.
	 */
	public function test_the_size_cap_is_inclusive_and_applied_before_parsing(): void {
		$file = KeyFileFixture::key_file();
		$this->assertLessThan( RestController::KEY_FILE_MAX_BYTES, strlen( $file ), 'The fixture must fit under the cap for this test to mean anything.' );

		$padded_to_cap = $file . str_repeat( ' ', RestController::KEY_FILE_MAX_BYTES - strlen( $file ) );
		$one_over      = $padded_to_cap . ' ';

		$at_cap = $this->make_controller()->upload_account( new \WP_REST_Request( array( 'key_file' => $padded_to_cap ) ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $at_cap, 'Exactly at the cap is accepted.' );

		$over = $this->make_controller()->upload_account( new \WP_REST_Request( array( 'key_file' => $one_over ) ) );
		$this->assertInstanceOf( \WP_Error::class, $over );
		$this->assertSame( 'gtm4wp_google_key_too_large', $over->get_error_code() );
	}

	// ---- Relabel -----------------------------------------------------------

	public function test_relabel_changes_the_label_and_answers_the_listing(): void {
		$id = $this->upload_fixture( 'Production' );

		$response = $this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => $id,
					'label' => 'Live site',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'Live site', $data['account']['label'] );
		$this->assertSame( array( $data['account'] ), $data['accounts'], 'The full listing rides along so the panel can re-render without a second request.' );
		$this->assertSame( 'Live site', $this->vault->get( $id )['label'] );
		$this->assert_no_secret_in( $data );
	}

	public function test_relabel_changes_nothing_but_the_label(): void {
		$id     = $this->upload_fixture( 'Production' );
		$before = $this->vault->get( $id );

		$this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => $id,
					'label' => 'Live site',
				)
			)
		);

		$after = $this->vault->get( $id );
		unset( $before['label'], $after['label'] );
		$this->assertSame( $before, $after, 'The id, key metadata, status and timestamps survive a relabel untouched.' );
		$this->assertSame( array(), $this->deleted_transients, 'A relabel never drops a cached token - consumers reference the id, not the label.' );
	}

	public function test_relabel_sanitizes_a_hostile_label(): void {
		$id = $this->upload_fixture();

		$response = $this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => $id,
					'label' => '  <script>alert(1)</script>Prod  ',
				)
			)
		);

		$label = $response->get_data()['account']['label'];
		$this->assertStringNotContainsString( '<', $label );
		$this->assertSame( 'alert(1)Prod', $label );
	}

	public function test_relabel_to_an_empty_label_falls_back_to_the_account_email(): void {
		$id = $this->upload_fixture( 'Production' );

		$response = $this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => $id,
					'label' => '   ',
				)
			)
		);

		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $response->get_data()['account']['label'] );
	}

	public function test_relabel_treats_a_non_string_label_as_empty(): void {
		$id = $this->upload_fixture( 'Production' );

		$response = $this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => $id,
					'label' => array( 'nested' => 'value' ),
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( KeyFileFixture::CLIENT_EMAIL, $response->get_data()['account']['label'] );
	}

	public function test_relabel_of_an_unknown_account_is_404(): void {
		$this->upload_fixture();

		$response = $this->make_controller()->relabel_account(
			new \WP_REST_Request(
				array(
					'id'    => 'sa_ffffffffffff',
					'label' => 'Anything',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'gtm4wp_google_account_unknown', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	// ---- Delete ------------------------------------------------------------

	public function test_delete_removes_the_account_and_answers_the_listing(): void {
		$keep = $this->upload_fixture( 'Keep' );
		$drop = $this->upload_fixture( 'Drop' );

		$response = $this->make_controller()->delete_account( new \WP_REST_Request( array( 'id' => $drop ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $keep ), array_column( $response->get_data()['accounts'], 'id' ) );
		$this->assertFalse( $this->vault->has( $drop ) );

		$this->assertSame( array(), $this->deleted_transients, 'Never minted, so no token can be cached - the vault purges by minted scope (#226).' );
		$this->assert_no_secret_in( $response->get_data() );
	}

	/**
	 * The purge lives in KeyVault::delete() (#226), keyed on the scopes the
	 * account actually minted for, so it covers every deletion path and every
	 * future scope without another hardcoded forget call here.
	 */
	public function test_delete_of_a_minted_account_forgets_its_cached_token(): void {
		$id = $this->upload_fixture();
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => 'ya29.short-lived',
				'expires_in'   => 3599,
			)
		);
		$this->make_controller()->test_account( new \WP_REST_Request( array( 'id' => $id ) ) );

		$this->make_controller()->delete_account( new \WP_REST_Request( array( 'id' => $id ) ) );

		$this->assertCount( 1, $this->deleted_transients, 'The cached token of the removed key is dropped.' );
		$this->assertStringStartsWith( 'gtm4wp_google_token_' . $id . '_', $this->deleted_transients[0] );
	}

	public function test_delete_of_an_unknown_account_is_404(): void {
		$this->upload_fixture();

		$response = $this->make_controller()->delete_account( new \WP_REST_Request( array( 'id' => 'sa_ffffffffffff' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'gtm4wp_google_account_unknown', $response->get_error_code() );
		$this->assertSame( array(), $this->deleted_transients );
	}

	public function test_delete_of_an_account_in_use_is_409_and_keeps_the_account(): void {
		$id = $this->upload_fixture();
		Filters\expectApplied( GTM4WP_WPFILTER_GOOGLE_SERVICE_ACCOUNT_IN_USE )->once()->with( false, $id )->andReturn( true );

		$response = $this->make_controller()->delete_account( new \WP_REST_Request( array( 'id' => $id ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'gtm4wp_google_account_in_use', $response->get_error_code() );
		$this->assertTrue( $this->vault->has( $id ) );
		$this->assertSame( array(), $this->deleted_transients, 'A token in use stays cached.' );
	}

	// ---- Test action -------------------------------------------------------

	public function test_the_test_action_mints_fresh_and_reports_success_without_the_token(): void {
		$id = $this->upload_fixture();
		$this->transport->will_respond_json(
			200,
			array(
				'access_token' => 'ya29.secret-token',
				'expires_in'   => 3599,
			)
		);

		$response = $this->make_controller()->test_account( new \WP_REST_Request( array( 'id' => $id ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertSame( KeyVault::STATUS_OK, $data['account']['status'], 'The refreshed account row rides along for the panel.' );
		$this->assert_no_secret_in( $data, 'ya29.secret-token' );

		$this->assertCount( 1, $this->transport->requests );
		$this->assertSame( TokenService::TOKEN_ENDPOINT, $this->transport->requests[0]['url'] );
	}

	public function test_the_test_action_reports_a_refusal_with_googles_reason_as_a_200_payload(): void {
		$id = $this->upload_fixture();
		$this->transport->will_respond_json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Invalid JWT Signature.',
			)
		);

		$response = $this->make_controller()->test_account( new \WP_REST_Request( array( 'id' => $id ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response, 'A refused key is a result the panel renders, not a transport failure.' );
		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'invalid_grant: Invalid JWT Signature.', $data['message'] );
		$this->assertSame( KeyVault::STATUS_ERROR, $data['account']['status'] );
		$this->assertSame( 'invalid_grant: Invalid JWT Signature.', $data['account']['last_error'] );
		$this->assert_no_secret_in( $data );
	}

	public function test_the_test_action_on_an_unknown_account_is_404_and_sends_nothing(): void {
		$response = $this->make_controller()->test_account( new \WP_REST_Request( array( 'id' => 'sa_ffffffffffff' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'gtm4wp_google_account_unknown', $response->get_error_code() );
		$this->assertSame( array(), $this->transport->requests );
	}
}
