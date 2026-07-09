<?php
/**
 * Data layer service.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Frontend;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles the main data layer object and manages the additional data layer
 * push queue that fires after the main GTM container code.
 *
 * The queue is intentionally stored in the backward compatible global
 * $GLOBALS['gtm4wp_additional_datalayer_pushes'] so that third party code
 * appending to that global keeps working; gtm4wp_datalayer_push() is a thin
 * wrapper around queue_push().
 */
final class DataLayer {

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Returns the name of the data layer JavaScript global variable.
	 *
	 * @return string
	 */
	public function name(): string {
		$datalayer_name = $this->options->get( GTM4WP_OPTION_DATALAYER_NAME );

		if ( empty( $datalayer_name ) || ! is_string( $datalayer_name ) ) {
			return 'dataLayer';
		}

		return $datalayer_name;
	}

	/**
	 * Compiles the main data layer content through the public
	 * GTM4WP_WPFILTER_COMPILE_DATALAYER filter and mirrors the result into
	 * the backward compatible $GLOBALS['gtm4wp_datalayer_data'] global.
	 *
	 * @return array<string, mixed>
	 */
	public function compile(): array {
		$data                             = (array) apply_filters( GTM4WP_WPFILTER_COMPILE_DATALAYER, array() );
		$GLOBALS['gtm4wp_datalayer_data'] = $data;

		return $data;
	}

	/**
	 * Registers the frontend hooks of the data layer service.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_push_handle' ) );

		// Late flush catches events queued during template rendering; the
		// queue is reset on every flush so pushes never fire twice.
		add_action( 'wp_print_footer_scripts', array( $this, 'flush_pushes' ), 1 );
	}

	/**
	 * Registers the empty script handle that carries the additional data
	 * layer push commands as inline scripts, then flushes everything queued
	 * so far. Port of the handle logic of gtm4wp_enqueue_scripts() from 1.x.
	 *
	 * @return void
	 */
	public function enqueue_push_handle(): void {
		wp_register_script( 'gtm4wp-additional-datalayer-pushes', '', array(), GTM4WP_VERSION, true );
		wp_enqueue_script( 'gtm4wp-additional-datalayer-pushes' );

		$this->flush_pushes();
	}

	/**
	 * Queues a data layer event to be fired after the main GTM container code.
	 * Port of gtm4wp_datalayer_push() from 1.x.
	 *
	 * @param string $event_name The name of the GTM event.
	 * @param array  $event_data Additional event parameters to be passed after the event. Optional.
	 * @param string $js_before  Inline JS code to be added before the dataLayer.push() line.
	 * @param string $js_after   Inline JS code to be added after the dataLayer.push() line.
	 * @return bool True when the event was successfully queued.
	 */
	public function queue_push( $event_name, $event_data = array(), $js_before = '', $js_after = '' ): bool {
		if ( ! is_string( $event_name ) ) {
			return false;
		}

		if ( ! is_array( $event_data ) ) {
			return false;
		}

		if ( ! isset( $GLOBALS['gtm4wp_additional_datalayer_pushes'] ) || ! is_array( $GLOBALS['gtm4wp_additional_datalayer_pushes'] ) ) {
			$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array();
		}

		$GLOBALS['gtm4wp_additional_datalayer_pushes'][] = array(
			'datalayer_object' => array_merge(
				$event_data,
				array(
					'event' => $event_name,
				)
			),
			'js_before'        => $js_before,
			'js_after'         => $js_after,
		);

		return true;
	}

	/**
	 * Outputs the necessary JavaScript codes to fire additional data layer
	 * events just after the main GTM container code.
	 * Port of gtm4wp_fire_additional_datalayer_pushes() from 1.x.
	 *
	 * @return void
	 */
	public function flush_pushes(): void {
		$queued = $GLOBALS['gtm4wp_additional_datalayer_pushes'] ?? array();
		if ( ! is_array( $queued ) ) {
			$queued = array();
		}

		$datalayer_name = $this->name();

		foreach ( $queued as $one_event ) {
			$datalayer_push_code = '';

			if ( array_key_exists( 'js_before', $one_event ) ) {
				$datalayer_push_code .= $one_event['js_before'];
			}

			if ( array_key_exists( 'datalayer_object', $one_event ) ) {
				$datalayer_push_code .= '
	' . esc_js( $datalayer_name ) . '.push(' . wp_json_encode( $one_event['datalayer_object'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . ');';
			}

			if ( array_key_exists( 'js_after', $one_event ) ) {
				$datalayer_push_code .= $one_event['js_after'];
			}

			wp_add_inline_script( 'gtm4wp-additional-datalayer-pushes', $datalayer_push_code, 'after' );
		}

		// Reset the queue so this method can re-run without double output.
		$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array();
	}
}
