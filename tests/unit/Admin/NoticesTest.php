<?php
/**
 * Unit tests for the dismissible admin notices AJAX handler.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\Notices;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers Notices::dismiss_notice(), the state-changing wp_ajax handler:
 * nonce verification (PA-1), the capability re-check (finding #18) and the
 * basename()/allow-list guard on the attacker-controlled notice id (RI-6).
 */
final class NoticesTest extends TestCase {

	/**
	 * Snapshot of $_POST, restored in tearDown (TS-7).
	 *
	 * @var array<string, mixed>
	 */
	private array $post_backup = array();

	/**
	 * Recorded update_user_meta() calls: list of [ key, value ] pairs.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $saved_meta = array();

	protected function setUp(): void {
		parent::setUp();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test isolation snapshot; the handler's own check_ajax_referer() is asserted below.
		$this->post_backup = $_POST;
		$this->saved_meta  = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Empty stored meta -> user_dismisses() returns the defaults.
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->saved_meta[] = array( $key, $value );
				return true;
			}
		);

		// Escaping/i18n helpers used by show_notices() output.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'menu_page_url' )->justReturn( 'options-general.php?page=gtm4wp' );
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		parent::tearDown();
	}

	/**
	 * Builds a Notices instance (its Options dependency is unused by
	 * dismiss_notice() but required by the constructor).
	 *
	 * @return Notices
	 */
	private function make_notices(): Notices {
		Functions\when( 'get_option' )->justReturn( array() );

		return new Notices( new Options( array() ) );
	}

	public function test_dismiss_notice_persists_valid_notice_id(): void {
		// Nonce must be verified with the expected action + field (PA-1).
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'gtm4wp-notice-dismiss-nonce', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['noticeid'] = 'enter-gtm-code';

		$this->make_notices()->dismiss_notice();

		$this->assertCount( 1, $this->saved_meta, 'A valid notice id must persist one meta write.' );
		$this->assertSame( Notices::USER_NOTICES_KEY, $this->saved_meta[0][0] );

		$stored = json_decode( $this->saved_meta[0][1], true );
		$this->assertTrue( $stored['enter-gtm-code'], 'The dismissed notice must be flagged true.' );
	}

	/**
	 * Regression for finding #18: a user without the settings capability must be
	 * rejected (wp_die 403) even though the hook is only registered for capable
	 * users — the handler re-checks the capability so it is safe on its own.
	 */
	public function test_dismiss_notice_denies_user_without_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		// The real wp_die() exits; the stub throws so we can observe the halt and
		// prove execution never reaches the user-meta write below.
		Functions\expect( 'wp_die' )
			->once()
			->with( -1, 403 )
			->andThrow( \RuntimeException::class, 'wp_die' );

		$_POST['noticeid'] = 'enter-gtm-code';

		try {
			$this->make_notices()->dismiss_notice();
			$this->fail( 'dismiss_notice() must halt via wp_die() for an uncapable user.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage(), 'The halt must come from wp_die().' );
		}

		$this->assertCount( 0, $this->saved_meta, 'No meta may be written when the capability check fails.' );
	}

	/**
	 * An unknown notice id (not in the allow-list) must be ignored — no user
	 * meta is written for arbitrary keys (RI-6 allow-list at the sink).
	 */
	public function test_dismiss_notice_ignores_unknown_notice_id(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST['noticeid'] = 'wp-config.php';

		$this->make_notices()->dismiss_notice();

		$this->assertCount( 0, $this->saved_meta, 'An id outside the allow-list must not be persisted.' );
	}

	/**
	 * A path-traversal-style notice id is reduced by basename() before the
	 * allow-list check, so it cannot smuggle in an unexpected key.
	 */
	public function test_dismiss_notice_basenames_path_traversal_id(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		// basename() reduces this to the allow-listed "enter-gtm-code".
		$_POST['noticeid'] = '../../enter-gtm-code';

		$this->make_notices()->dismiss_notice();

		$this->assertCount( 1, $this->saved_meta );
		$stored = json_decode( $this->saved_meta[0][1], true );
		$this->assertTrue( $stored['enter-gtm-code'] );
	}

	/**
	 * Builds a Notices instance whose options service returns the given values.
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return Notices
	 */
	private function make_notices_with_options( array $stored ): Notices {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new Notices( new Options( $stored ) );
	}

	public function test_show_notices_prompts_for_gtm_id_when_code_empty(): void {
		$notices = $this->make_notices_with_options( array( GTM4WP_OPTION_GTM_CODE => '' ) );

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'gtm4wp-notice', $output );
		$this->assertStringContainsString( 'enter-gtm-code', $output );
	}

	public function test_show_notices_hides_gtm_id_prompt_when_code_set(): void {
		$notices = $this->make_notices_with_options( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'enter-gtm-code', $output );
	}

	public function test_show_notices_hides_gtm_id_prompt_when_dismissed(): void {
		Functions\when( 'get_user_meta' )->justReturn(
			(string) json_encode( array( 'enter-gtm-code' => true ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);

		$notices = $this->make_notices_with_options( array( GTM4WP_OPTION_GTM_CODE => '' ) );

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'enter-gtm-code', $output, 'A dismissed notice must not render.' );
	}

	public function test_show_notices_warns_on_incomplete_container_env_config(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-INCOMPLETE',
						ContainerRows::COLUMN_AUTH    => 'some-auth-token',
						ContainerRows::COLUMN_PREVIEW => '',
					),
				),
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'incomplete-gtm-env-config', $output );
		$this->assertStringContainsString( 'GTM-INCOMPLETE', $output, 'The offending container id is named.' );
	}

	public function test_show_notices_accepts_complete_container_env_config(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_GTM_CODE       => 'GTM-ABC123',
				GTM4WP_OPTION_GTM_CONTAINERS => array(
					array(
						ContainerRows::COLUMN_ID      => 'GTM-COMPLETE',
						ContainerRows::COLUMN_AUTH    => 'some-auth-token',
						ContainerRows::COLUMN_PREVIEW => 'env-1',
					),
				),
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'incomplete-gtm-env-config', $output, 'Both auth and preview set = no warning.' );
	}
}
