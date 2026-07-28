<?php
/**
 * Consent-aware data layer event queue handler.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ConsentMode;

use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the consent-aware data layer event queue runtime into the GTM4WP
 * head block.
 *
 * The runtime (js/frontend/lib/gtm4wp-consent-queue-runtime.js) holds GTM4WP
 * event pushes in a JavaScript queue until the selected consent management
 * tool signals - via its browser event - that the visitor's consent choice is
 * available, then replays them in order. The queue never inspects the
 * granted/denied state; Google Consent Mode and the tag settings inside GTM
 * keep gating what actually fires.
 *
 * Like Axeptio, this is a plain frontend handler wired up by the
 * ConsentModeModule rather than a module of its own. It appends to the head
 * block via ContainerCode::FILTER_HEADER_TOP_JS at priority 4 - before the
 * Axeptio SDK (5) and the WebToffee/CookieYes bridges (20) - so the canonical
 * window.gtm4wp_datalayer_push() entry point exists before any other GTM4WP
 * inline JS or tracker bundle runs. On AMP pages ContainerCode suppresses the
 * whole head block, so the runtime is simply absent and every guarded caller
 * falls back to a direct push.
 */
final class ConsentQueue {

	/**
	 * Allow-listed values of the consent tool option. Single source of truth
	 * for the sink-side validation below; the AdminSchema select choices must
	 * offer exactly these values (a unit test keeps the two in sync).
	 *
	 * @var string[]
	 */
	public const SUPPORTED_CMPS = array(
		'cookiebot',
		'cookieyes',
		'complianz',
		'borlabs',
		'wp-consent-api',
		'custom',
	);

	/**
	 * Cached runtime JS source. The ConsentModeModule creates one instance
	 * per request, so an instance cache avoids re-reading the file while
	 * keeping unit tests free of static state (TS-8).
	 *
	 * @var string|null
	 */
	private ?string $runtime_js = null;

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Registers the frontend hooks. Nothing is registered unless the queue is
	 * enabled and a supported consent tool is selected.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE ) ) {
			return;
		}

		if ( ! in_array( (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP ), self::SUPPORTED_CMPS, true ) ) {
			return;
		}

		add_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $this, 'add_head_js' ), 4, 2 );
	}

	/**
	 * Appends the queue config object and the runtime source to the GTM4WP
	 * head block.
	 *
	 * The config is JSON encoded with the full hex flag set so no value can
	 * break out of the inline script (the tool id is additionally re-validated
	 * against the allow-list here at the sink, independent of save-time
	 * sanitization). The runtime file itself contains no angle bracket
	 * characters by contract, so it survives the wp_kses() pass inside
	 * ScriptTag::print_script_block() unchanged - a unit test guards that
	 * property of the file.
	 *
	 * @param string $inline_js      Inline JS collected so far.
	 * @param string $datalayer_name Name of the data layer JS variable (unused, the runtime reads the inline global).
	 * @return string
	 */
	public function add_head_js( $inline_js, $datalayer_name ) {
		$cmp = (string) $this->options->get( GTM4WP_OPTION_INTEGRATE_CONSENT_QUEUE_CMP );
		if ( ! in_array( $cmp, self::SUPPORTED_CMPS, true ) ) {
			return $inline_js;
		}

		$runtime_js = $this->runtime_js();
		if ( '' === $runtime_js ) {
			return $inline_js;
		}

		$config = wp_json_encode(
			array( 'cmp' => $cmp ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		return $inline_js . '
	window.gtm4wp_consent_queue_config = ' . $config . ';
' . $runtime_js;
	}

	/**
	 * Reads the runtime JS source from disk, cached for the request.
	 *
	 * @return string The runtime source, or '' when the file is unreadable.
	 */
	private function runtime_js(): string {
		if ( null !== $this->runtime_js ) {
			return $this->runtime_js;
		}

		$runtime_file = GTM4WP_PATH . 'js/frontend/lib/gtm4wp-consent-queue-runtime.js';

		$this->runtime_js = is_readable( $runtime_file )
			? (string) file_get_contents( $runtime_file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file bundled with the release, not a remote URL.
			: '';

		return $this->runtime_js;
	}
}
