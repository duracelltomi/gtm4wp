<?php
/**
 * Unit tests for the dismissible admin notices AJAX handler.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Filters;
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
		// Brain Monkey's stubTranslationFunctions() does not cover _n(); the
		// malformed-constant notice uses it for its singular/plural forms.
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => ( 1 === (int) $number ? $single : $plural )
		);
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
		// Reached through SettingsPage::url(), which every notice anchor below
		// builds its deep link with. Stubbed here rather than borrowed from
		// whichever file ran first (TS-16); the real one encodes the value, so
		// the stand-in does too - it must not be more permissive than WordPress.
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, $url ) => $url . '&' . $key . '=' . rawurlencode( (string) $value )
		);
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

	/**
	 * Finding #110 (PA-4/RI-4). The dismiss handler's nonce is a string VALUE in a
	 * raw <script> body - no wp_kses sink and no entity decode anywhere on that
	 * path - so it takes wp_json_encode() with the hex flags, not esc_js(), whose
	 * entities the browser never decodes inside <script>.
	 *
	 * A real nonce is 10 ASCII characters, so neither encoder can misbehave on it;
	 * the hostile value here is what makes the assertion say something. It fails
	 * with esc_js() (which emits &quot;/&lt; rather than the character) and it fails
	 * with no escaping at all (the raw quote closes the JS string), so it pins the
	 * encoder rather than merely covering the line (TS-1/TS-2).
	 */
	public function test_dismiss_script_hex_encodes_the_nonce_into_the_script_body(): void {
		Functions\when( 'wp_create_nonce' )->justReturn( 'ab"cd</script>&x' );

		ob_start();
		$this->make_notices()->print_dismiss_script();
		$printed = ob_get_clean();

		// Built with the same encoder the source uses rather than hand-typed \uXXXX
		// sequences (TC-2), so the expectation cannot drift from the flag set.
		$expected = wp_json_encode(
			'ab"cd</script>&x',
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		// Present: the safe form, quotes included - the literal is not wrapped.
		$this->assertStringContainsString( 'body.append( "nonce", ' . $expected . ' );', $printed );

		// Absent: the break-out characters, and the entity form esc_js() would emit.
		$this->assertStringNotContainsString( 'ab"cd', $printed, 'A raw quote must never reach the script body.' );
		// Not a bare '</script>' - the block legitimately ends with one. This prefix
		// can only come from the payload.
		$this->assertStringNotContainsString( 'cd</script>', $printed, 'A raw closing tag must never reach the script body.' );
		$this->assertStringNotContainsString( '&quot;', $printed, 'esc_js() entities are inert but wrong here (PA-4).' );
	}

	/**
	 * The ordinary case stays byte-identical to the esc_js() output it replaced, so
	 * the encoder swap is invisible to every real install (the #105 rule: encode the
	 * whole literal, do not concatenate around the value).
	 */
	public function test_dismiss_script_emits_an_ordinary_nonce_unchanged(): void {
		Functions\when( 'wp_create_nonce' )->justReturn( '0a1b2c3d4e' );

		ob_start();
		$this->make_notices()->print_dismiss_script();
		$printed = ob_get_clean();

		$this->assertStringContainsString( 'body.append( "nonce", "0a1b2c3d4e" );', $printed );
	}

	public function test_dismiss_notice_persists_valid_notice_id(): void {
		// Nonce must be verified with the expected action + field (PA-1).
		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( 'gtm4wp-notice-dismiss-nonce', 'nonce' );
		// ->with(), not justReturn(): the capability the handler asks for is the
		// point of the gate, and a bare justReturn() pins nothing. Downgrading the
		// gate to a weaker capability used to leave this whole file green
		// (test-review T31 / TS-12).
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

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
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( false );
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
	 * TC-13, grant side: the handler's capability is the filterable
	 * gtm4wp_admin_page_capability, so a site that delegates the settings to a
	 * non-admin role (issue #143) must let that role dismiss notices too — the
	 * gate is the same one that let them see the notice in the first place.
	 */
	public function test_dismiss_notice_honors_a_filtered_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Filters\expectApplied( 'gtm4wp_admin_page_capability' )
			->once()
			->with( 'manage_options' )
			->andReturn( 'manage_gtm4wp' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_gtm4wp' )
			->andReturn( true );

		$_POST['noticeid'] = 'enter-gtm-code';

		$this->make_notices()->dismiss_notice();

		$this->assertCount( 1, $this->saved_meta, 'The delegated role dismisses the notice.' );
	}

	/**
	 * TC-13, deny side: the filtered capability is the one actually enforced, so
	 * a user who lacks it is refused even though the filter widened the gate.
	 */
	public function test_dismiss_notice_denies_a_user_lacking_the_filtered_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Filters\expectApplied( 'gtm4wp_admin_page_capability' )
			->once()
			->with( 'manage_options' )
			->andReturn( 'manage_gtm4wp' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_gtm4wp' )
			->andReturn( false );
		Functions\expect( 'wp_die' )
			->once()
			->with( -1, 403 )
			->andThrow( \RuntimeException::class, 'wp_die' );

		$_POST['noticeid'] = 'enter-gtm-code';

		try {
			$this->make_notices()->dismiss_notice();
			$this->fail( 'dismiss_notice() must halt for a user lacking the filtered capability.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertCount( 0, $this->saved_meta );
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

	/**
	 * Reading the visitor IP from a custom header with no trusted proxies declared is
	 * the state #75 describes: an HTTP header is sent by the client, so the reported
	 * address is theirs to choose. Both configurations look identical on the settings
	 * screen, so the admin is told rather than left to discover it - the same reason
	 * the discarded-wp-config-constant notice exists. Ships grant AND deny, because a
	 * warning that never turns off is as useless as one that never appears.
	 */
	public function test_show_notices_warns_about_an_unauthenticated_visitor_ip_header(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'visitor-ip-untrusted-header', $output );
	}

	/**
	 * The notice names a setting that lives three levels down - Page variables ->
	 * Visitor data -> the field - so landing the admin on the settings page and
	 * leaving them to find it is only half a pointer. The anchor therefore carries
	 * the option key the text is about. Asserted per notice rather than once,
	 * because each anchor names its own key and pointing at the wrong option is a
	 * silent failure: the page still opens, just not where the text says.
	 */
	public function test_show_notices_deep_links_each_notice_to_the_option_it_names(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
				GTM4WP_OPTION_DATALAYER_NAME             => 'not-an-identifier',
				GTM4WP_OPTION_GTM_CONTAINERS             => array(),
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'gtm4wp-focus=' . GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES . '"',
			$output
		);
		$this->assertStringContainsString(
			'gtm4wp-focus=' . GTM4WP_OPTION_DATALAYER_NAME . '"',
			$output
		);
		// The container list, not the derived 1.x flat key, which has no control.
		$this->assertStringContainsString(
			'gtm4wp-focus=' . GTM4WP_OPTION_GTM_CONTAINERS . '"',
			$output
		);
		$this->assertStringNotContainsString(
			'gtm4wp-focus=' . GTM4WP_OPTION_GTM_CODE . '"',
			$output
		);
	}

	public function test_show_notices_silent_once_trusted_proxies_are_configured(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '10.0.0.0/8',
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'visitor-ip-untrusted-header', $output );
	}

	/**
	 * Finding #114. A stored data layer name that is not a JavaScript identifier
	 * is ignored by the frontend, which falls back to `dataLayer`. 1.x accepted
	 * hyphenated names and the migration stores them verbatim, so an upgrading
	 * site reaches this state without having done anything wrong - and both
	 * states look identical on the settings screen. Silently substituting a
	 * different global would be the same undiagnosable failure the name itself
	 * caused, so it is named (PA-2: validation without a signal is half a fix).
	 * Grant AND deny, per TC-13.
	 *
	 * @return void
	 */
	public function test_show_notices_reports_an_unusable_datalayer_variable_name(): void {
		$notices = $this->make_notices_with_options(
			array( GTM4WP_OPTION_DATALAYER_NAME => 'my-layer' )
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'invalid-datalayer-name', $output );
		$this->assertStringContainsString( 'my-layer', $output, 'The notice names the rejected value so it can be found and corrected.' );
	}

	/**
	 * The deny half: a usable name raises no notice, so the warning above cannot
	 * pass by simply always firing.
	 *
	 * @param string $stored A stored name that needs no notice.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_acceptable_stored_datalayer_names' )]
	public function test_show_notices_silent_for_a_usable_datalayer_variable_name( string $stored ): void {
		$notices = $this->make_notices_with_options(
			array( GTM4WP_OPTION_DATALAYER_NAME => $stored )
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'invalid-datalayer-name', $output );
	}

	/**
	 * Stored names that must not raise the notice - including the empty default,
	 * which means "use dataLayer" rather than "this is broken".
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_acceptable_stored_datalayer_names(): array {
		return array(
			'unset (the default)'  => array( '' ),
			'the GTM default'      => array( 'dataLayer' ),
			'a plain rename'       => array( 'myDataLayer' ),
			'a leading underscore' => array( '_private' ),
		);
	}

	public function test_show_notices_silent_when_no_custom_visitor_ip_header_is_used(): void {
		// REMOTE_ADDR only: nothing is being trusted, so there is nothing to warn about.
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP        => true,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER => '',
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'visitor-ip-untrusted-header', $output );
	}

	public function test_show_notices_silent_when_the_visitor_ip_variable_is_off(): void {
		// A leftover header name on a site that no longer publishes visitorIP reaches
		// no sink, so the warning would be noise.
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_INCLUDE_VISITOR_IP         => false,
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER  => 'HTTP_X_FORWARDED_FOR',
				GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES => '',
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'visitor-ip-untrusted-header', $output );
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

	/**
	 * Container code placement OFF is the deliberate "data layer only" setup: the
	 * container is loaded by custom coding, so the site never enters a container
	 * ID and the empty table is the correct state, not a misconfiguration. The
	 * prompt names a setting that would not change anything, and no save can
	 * clear it - the only escape is a per-user dismissal, so every admin of the
	 * site sees a permanent red error for a supported configuration.
	 *
	 * @return void
	 */
	public function test_show_notices_hides_gtm_id_prompt_when_container_code_is_off(): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_GTM_CODE       => '',
				GTM4WP_OPTION_GTM_CONTAINERS => array(),
				GTM4WP_OPTION_GTM_PLACEMENT  => GTM4WP_PLACEMENT_OFF,
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'enter-gtm-code', $output, 'Data layer only mode needs no container ID.' );
	}

	/**
	 * The grant half of the case above, run over every placement that does emit
	 * the container code. Without it the suppression could be far too broad - an
	 * inverted comparison, or a loose one against the default value 0, would
	 * silence the prompt on every site instead of only the OFF ones, and the deny
	 * test above would stay green throughout.
	 *
	 * The placement is set explicitly in each case rather than left to the
	 * default: make_notices_with_options() passes its array as both the defaults
	 * and the stored row, so an omitted key resolves to null, and a test that
	 * relied on that would not be testing a placement at all.
	 *
	 * @param int $placement A placement value that emits the container code.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_placements_that_output_the_container' )]
	public function test_show_notices_still_prompts_for_gtm_id_for_every_active_placement( int $placement ): void {
		$notices = $this->make_notices_with_options(
			array(
				GTM4WP_OPTION_GTM_CODE       => '',
				GTM4WP_OPTION_GTM_CONTAINERS => array(),
				GTM4WP_OPTION_GTM_PLACEMENT  => $placement,
			)
		);

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'enter-gtm-code', $output );
	}

	/**
	 * Every placement except OFF: the container code is emitted, so a missing
	 * container ID really is a misconfiguration and has to be reported.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function provide_placements_that_output_the_container(): array {
		return array(
			'footer (the default)'          => array( GTM4WP_PLACEMENT_FOOTER ),
			'manually coded after body tag' => array( GTM4WP_PLACEMENT_BODYOPEN ),
			'automatic wp_body_open'        => array( GTM4WP_PLACEMENT_BODYOPEN_AUTO ),
		);
	}

	/**
	 * A malformed GTM4WP_HARDCODED_* constant is ignored while the options are
	 * built, which is invisible from the outside - the operator sees a container
	 * that disregards their wp-config and has nothing to search for. The notice
	 * must name the offending constant so the cause is findable.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_show_notices_warns_about_malformed_hardcoded_constants(): void {
		// The real integration: a typo'd wp-config constant, rejected by Options,
		// surfaced by the notice. 'oops preview' matches neither PREVIEW_PATTERN
		// nor anything else the settings screen would accept.
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', 'oops preview' );

		$notices = $this->make_notices_with_options( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'invalid-hardcoded-constant', $output );
		$this->assertStringContainsString( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', $output, 'The offending constant is named.' );
		$this->assertStringContainsString( 'wp-config.php', $output, 'The operator is told where to fix it.' );
	}

	public function test_show_notices_silent_when_hardcoded_constants_are_valid(): void {
		$notices = $this->make_notices_with_options( array( GTM4WP_OPTION_GTM_CODE => 'GTM-ABC123' ) );

		ob_start();
		$notices->show_notices();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'invalid-hardcoded-constant', $output );
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
