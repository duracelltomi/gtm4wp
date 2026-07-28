<?php
/**
 * Unit tests for the consent-aware event queue handler of the consent module.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Modules\ConsentMode\AdminSchema;
use GTM4WP\Modules\ConsentMode\ConsentModeModule;
use GTM4WP\Modules\ConsentMode\ConsentQueue;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\Frontend\FrontendTestCase;

/**
 * Covers the ConsentQueue handler: hook gating on the enabling option plus an
 * allow-listed consent tool, the emitted head-block JS (hex-flag encoded
 * config + inlined runtime), the sink-side allow-list re-validation, and the
 * two structural contracts of the inlined runtime file — it must contain no
 * angle bracket character (the wp_kses() pass inside
 * ScriptTag::print_script_block() would entity-encode it with no restore) and
 * it must survive the kses-style encode/amp-restore round trip byte for byte.
 */
final class ConsentModeQueueTest extends FrontendTestCase {

	private const HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS;

	/**
	 * Builds a ConsentQueue handler with the given stored options merged over
	 * the consent module defaults (which own the queue option keys).
	 *
	 * @param array<string, mixed> $stored Stored option values.
	 * @return ConsentQueue
	 */
	private function make_queue( array $stored = array() ): ConsentQueue {
		Functions\when( 'get_option' )->justReturn( $stored );

		return new ConsentQueue( new Options( ( new ConsentModeModule() )->defaults() ) );
	}

	public function test_registers_head_js_at_priority_4_when_enabled_with_supported_cmp(): void {
		$queue = $this->make_queue(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE     => true,
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => 'cookiebot',
			)
		);
		$queue->register_hooks();

		// Priority 4: before the Axeptio SDK (5) and the WebToffee/CookieYes
		// bridges (20), so the push entry point exists first.
		$this->assertSame(
			4,
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $queue, 'add_head_js' ) )
		);
	}

	public function test_inactive_when_disabled(): void {
		$queue = $this->make_queue(
			array( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => 'cookiebot' )
		);
		$queue->register_hooks();

		$this->assertFalse(
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $queue, 'add_head_js' ) )
		);
	}

	public function test_inactive_when_no_cmp_selected(): void {
		$queue = $this->make_queue(
			array( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE => true )
		);
		$queue->register_hooks();

		$this->assertFalse(
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $queue, 'add_head_js' ) )
		);
	}

	public function test_inactive_with_unsupported_cmp_value(): void {
		$queue = $this->make_queue(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE     => true,
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => 'not-a-real-tool',
			)
		);
		$queue->register_hooks();

		$this->assertFalse(
			has_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $queue, 'add_head_js' ) )
		);
	}

	public function test_head_js_emits_hex_flag_encoded_config_and_the_runtime(): void {
		$queue = $this->make_queue(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE     => true,
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => 'wp-consent-api',
			)
		);

		$js = $queue->add_head_js( '/* earlier */', 'dataLayer' );

		// The accumulated inline JS of earlier filters is preserved.
		$this->assertStringStartsWith( '/* earlier */', $js );

		// Expected config built with the same wp_json_encode() flags the
		// source uses (TC-2).
		$expected_config = json_encode( array( 'cmp' => 'wp-consent-api' ), self::HEX_FLAGS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringContainsString(
			'window.gtm4wp_consent_queue_config = ' . $expected_config . ';',
			$js
		);

		// The runtime landed: its public API markers are present.
		$this->assertStringContainsString( 'window.gtm4wp_datalayer_push', $js );
		$this->assertStringContainsString( 'window.gtm4wp_consent_unlock', $js );
		$this->assertStringContainsString( 'gtm4wp_consent_ready', $js );
	}

	public function test_head_js_returns_inline_js_unchanged_for_unknown_stored_cmp(): void {
		// A value bypassing save-time sanitization (e.g. a crafted settings
		// import) must be stopped by the sink-side allow-list re-validation.
		$queue = $this->make_queue(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE     => true,
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => "evil\x22;alert(1);//",
			)
		);

		$this->assertSame( '/* earlier */', $queue->add_head_js( '/* earlier */', 'dataLayer' ) );
	}

	public function test_runtime_file_contains_no_angle_bracket_characters(): void {
		// The runtime is inlined into the head block, which is sanitized by
		// wp_kses() with an ampersand-only restore step: any angle bracket in
		// the source would be entity-encoded, never decoded back, and break
		// the whole block. This is the regression guard for that contract.
		$source = (string) file_get_contents( GTM4WP_PATH . 'js/frontend/lib/gtm4wp-consent-queue-runtime.js' );

		$this->assertNotSame( '', $source );
		$this->assertDoesNotMatchRegularExpression( '/[<>]/', $source );
	}

	public function test_emitted_block_survives_the_kses_encode_and_amp_restore_round_trip(): void {
		// Model the relevant wp_kses() behavior: entity-encode every bare
		// &, < and > (ENT_NOQUOTES leaves quotes alone, like kses does for
		// text outside tags). print_script_block() then restores only &amp;,
		// so the block only survives unchanged when the runtime keeps its
		// no-angle-bracket contract while && operators still work.
		Functions\when( 'wp_kses' )->alias(
			static fn ( $content, $allowed_html ) => htmlspecialchars( (string) $content, ENT_NOQUOTES )
		);

		$queue = $this->make_queue(
			array(
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE     => true,
				GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP => 'complianz',
			)
		);

		$block = $queue->add_head_js( '', 'dataLayer' );

		ob_start();
		( new ScriptTag( $this->make_options() ) )->print_script_block( $block );
		$printed = (string) ob_get_clean();

		$this->assertSame( $block, $printed );
		// The runtime genuinely relies on the restored ampersands.
		$this->assertStringContainsString( '&&', $printed );
	}

	public function test_admin_schema_choices_match_the_supported_cmp_allow_list(): void {
		// SUPPORTED_CMPS is the single source of truth for the sink-side
		// validation; the admin select must offer exactly those values plus
		// the empty "not selected" choice.
		$cmp_field = null;
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP === $field->key ) {
				$cmp_field = $field;
			}
		}

		$this->assertNotNull( $cmp_field );
		$this->assertSame(
			array_merge( array( '' ), ConsentQueue::SUPPORTED_CMPS ),
			array_keys( $cmp_field->choices )
		);
	}
}
