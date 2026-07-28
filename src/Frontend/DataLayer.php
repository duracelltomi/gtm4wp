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

use GTM4WP\Modules\Container\ContainerRows;
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
	 * Handle of the empty script the queued pushes are attached to as inline
	 * scripts. Public because add_push_handle_dependency() lets a module order
	 * another handle in front of it.
	 */
	public const PUSH_HANDLE = 'gtm4wp-additional-datalayer-pushes';

	/**
	 * The most recently compiled data layer content, or null before the
	 * first compile() call. Lets consumers (e.g. the AMP module) read the
	 * compiled data without re-running the compile filter and its side
	 * effects.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $compiled = null;

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
	 * Resolution (including the re-validation of a stored value that the save
	 * side would no longer accept) lives in ContainerRows so that this reader,
	 * Compat\Globals, the option sanitizer and the admin notice all share one
	 * definition - see ContainerRows::datalayer_name().
	 *
	 * @return string
	 */
	public function name(): string {
		return ContainerRows::datalayer_name( $this->options->get( GTM4WP_OPTION_DATALAYER_NAME ) );
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
		$this->compiled                   = $data;
		$GLOBALS['gtm4wp_datalayer_data'] = $data;

		return $data;
	}

	/**
	 * Returns the data layer content produced by the last compile() call,
	 * or an empty array if compile() has not run yet. Does not re-run the
	 * compile filter, so it is safe to read after the container code has
	 * already been generated.
	 *
	 * @return array<string, mixed>
	 */
	public function compiled(): array {
		return $this->compiled ?? array();
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
		wp_register_script( self::PUSH_HANDLE, '', array(), GTM4WP_VERSION, true );
		wp_enqueue_script( self::PUSH_HANDLE );

		$this->flush_pushes();
	}

	/**
	 * Declares that $handle must be printed before the queued pushes, so a
	 * function a push wraps its payload in is already defined when the inline
	 * push runs (the push handle carries no src, so its inline script executes
	 * at parse time - ahead of every deferred bundle).
	 *
	 * Both handles must already be registered: adding a dependency on an
	 * unregistered handle makes WordPress drop the dependent script entirely,
	 * which would silently remove every data layer push on the page. Callers
	 * therefore hook this after the enqueue pass that registers both.
	 *
	 * @param string $handle The script handle to print first.
	 * @return bool True when the dependency was added or already present.
	 */
	public function add_push_handle_dependency( string $handle ): bool {
		if ( ! wp_script_is( self::PUSH_HANDLE, 'registered' ) || ! wp_script_is( $handle, 'registered' ) ) {
			return false;
		}

		$push_script = wp_scripts()->registered[ self::PUSH_HANDLE ];

		if ( ! in_array( $handle, $push_script->deps, true ) ) {
			$push_script->deps[] = $handle;
		}

		return true;
	}

	/**
	 * Queues a data layer event to be fired after the main GTM container code.
	 * Port of gtm4wp_datalayer_push() from 1.x.
	 *
	 * @param string $event_name      The name of the GTM event.
	 * @param array  $event_data      Additional event parameters to be passed after the event. Optional.
	 * @param string $js_before       Inline JS code to be added before the dataLayer.push() line.
	 * @param string $js_after        Inline JS code to be added after the dataLayer.push() line.
	 * @param string $js_wrapper      Optional. Name of a JavaScript function on `window` the pushed object is passed through before it reaches the data layer, e.g. to add visitor specific data that must not be baked into cacheable HTML. Must be a plain identifier; anything else is dropped and the object is pushed unwrapped. The emitted call falls back to an identity function when the named function is not loaded, so an unavailable wrapper can never cost the event.
	 * @param array  $js_wrapper_args Optional. Extra arguments passed to $js_wrapper after the pushed object. JSON encoded, so only scalars/arrays.
	 * @return bool True when the event was successfully queued.
	 */
	public function queue_push( $event_name, $event_data = array(), $js_before = '', $js_after = '', $js_wrapper = '', $js_wrapper_args = array() ): bool {
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
			// Serialize `event` first so server-pushed events match what the
			// client-side gtm4wp_push_ecommerce() already emits (event before
			// ecommerce). Key order is irrelevant to GTM/GA4; this is consistency
			// only. array_merge keeps an existing `event` key (e.g. the purchase
			// data layer already sets it first) in place (#348).
			'datalayer_object' => array_merge(
				array(
					'event' => $event_name,
				),
				$event_data
			),
			'js_before'        => $js_before,
			'js_after'         => $js_after,
			'js_wrapper'       => is_string( $js_wrapper ) ? $js_wrapper : '',
			'js_wrapper_args'  => is_array( $js_wrapper_args ) ? $js_wrapper_args : array(),
		);

		return true;
	}

	/**
	 * Builds the "(" ... ")" pair that wraps a pushed object in a JavaScript
	 * function call, or two empty strings when the queue entry asks for no
	 * wrapper or names something that is not a plain identifier.
	 *
	 * The opening half resolves the function off `window` with an identity
	 * fallback, so a wrapper that is not loaded yet - or not loaded at all -
	 * degrades to an unwrapped push instead of a ReferenceError that would
	 * take the whole event with it.
	 *
	 * The name is written unquoted into a <script> body, so what keeps this
	 * parameter from being a script-injection sink is the identifier grammar -
	 * and that grammar has exactly ONE definition in this plugin
	 * (ContainerRows::is_valid_js_identifier(), PA-2). It is deliberately not
	 * re-stated here: this class held its own copy until 2026-08-10 and the two
	 * had already drifted apart in the modifier that anchors the pattern, which
	 * is the drift PA-2 exists to stop.
	 *
	 * @param array $one_event A single entry of the push queue.
	 * @return string[] The opening and closing fragment, in that order.
	 */
	private function wrapper_fragments( array $one_event ): array {
		$wrapper = $one_event['js_wrapper'] ?? '';

		if ( ! is_string( $wrapper ) || ! ContainerRows::is_valid_js_identifier( $wrapper ) ) {
			return array( '', '' );
		}

		$args      = ( isset( $one_event['js_wrapper_args'] ) && is_array( $one_event['js_wrapper_args'] ) ) ? $one_event['js_wrapper_args'] : array();
		$args_code = '';

		foreach ( $args as $one_arg ) {
			$encoded_arg = wp_json_encode( $one_arg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

			if ( false === $encoded_arg ) {
				return array( '', '' );
			}

			$args_code .= ',' . $encoded_arg;
		}

		return array(
			'(window.' . $wrapper . '||function(d){return d;})(',
			$args_code . ')',
		);
	}

	/**
	 * Builds a self-contained JavaScript statement pushing one data layer
	 * event object, consent-aware when the queue runtime is on the page.
	 *
	 * The snippet routes through window.gtm4wp_datalayer_push() — the queue
	 * runtime installed into the head block by ConsentQueue::add_head_js()
	 * (js/frontend/lib/gtm4wp-consent-queue-runtime.js) — so the event can be
	 * held until the visitor's consent choice is available. When the runtime
	 * is absent (feature off, AMP page, head block stripped by a cache
	 * plugin) the inline fallback is a plain direct push. A guarded twin of
	 * this caller lives in JS as gtm4wp_push_dl_event()
	 * (js/frontend/lib/gtm4wp-datalayer.js) — keep the two in sync.
	 *
	 * The object is JSON encoded with the full hex flag set so no value can
	 * break out of the inline script; the data layer name goes through
	 * esc_js() (identifier interpolation, the established contract).
	 *
	 * @param array<string, mixed> $datalayer_object The data layer event object.
	 * @return string
	 */
	public function push_snippet( array $datalayer_object ): string {
		$json = wp_json_encode( $datalayer_object, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

		return $this->guarded_push_call( (string) $json );
	}

	/**
	 * Wraps an already-encoded JavaScript expression in the consent-aware
	 * guarded push call (see push_snippet() for the contract). The expression
	 * must be a self-contained JavaScript value: JSON from wp_json_encode(),
	 * optionally wrapped in the wrapper_fragments() call pair.
	 *
	 * @param string $js_expression JavaScript expression evaluating to the object to push.
	 * @return string
	 */
	private function guarded_push_call( string $js_expression ): string {
		$name = esc_js( $this->name() );

		return '(window.gtm4wp_datalayer_push||function(o){window.' . $name . '=window.' . $name . '||[];window.' . $name . '.push(o);})(' . $js_expression . ');';
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

		foreach ( $queued as $one_event ) {
			$datalayer_push_code = '';

			if ( array_key_exists( 'js_before', $one_event ) ) {
				$datalayer_push_code .= $one_event['js_before'];
			}

			if ( array_key_exists( 'datalayer_object', $one_event ) ) {
				// Same false-return guard wrapper_fragments() applies to the wrapper
				// ARGUMENTS a few lines above, which is where this one was missing
				// until 2026-08-10 (#141): an unencodable object emitted `.push()`,
				// a call with no arguments that silently pushes nothing - or, with a
				// wrapper, pushes undefined into the data layer. Skip the statement
				// instead, so a queued event that cannot be serialized is absent
				// rather than present-and-meaningless (RI-13).
				$encoded_object = wp_json_encode( $one_event['datalayer_object'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );

				if ( false !== $encoded_object ) {
					list( $wrapper_open, $wrapper_close ) = $this->wrapper_fragments( $one_event );

					// The wrapper runs at script-execution time (its job is adding
					// visitor data the cached HTML cannot carry); only the resulting
					// object is handed to the consent-aware guarded push, which may
					// hold it until the consent signal arrives.
					$datalayer_push_code .= '
	' . $this->guarded_push_call( $wrapper_open . $encoded_object . $wrapper_close );
				}
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
