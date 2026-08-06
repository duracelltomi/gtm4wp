<?php
/**
 * Google Tag Manager container code output.
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
 * Outputs the GTM container snippet (head), the noscript/iframe part (body)
 * and the data layer initialization. Port of gtm4wp_wp_header_top(),
 * gtm4wp_wp_header_begin(), gtm4wp_get_the_gtm_tag(), gtm4wp_the_gtm_tag(),
 * gtm4wp_wp_body_open() and gtm4wp_wp_footer() (placement part) from 1.x.
 *
 * The "container code written" state lives in the backward compatible
 * $GLOBALS['gtm4wp_container_code_written'] global so that themes calling
 * gtm4wp_the_gtm_tag() manually keep their double-output protection.
 */
final class ContainerCode {

	/**
	 * Internal filter that modules use to append inline JavaScript to the
	 * data layer initialization block in the <head> (e.g. consent tool
	 * callbacks).
	 *
	 * @since 2.0.0
	 */
	public const FILTER_HEADER_TOP_JS = 'gtm4wp_header_top_inline_js';

	/**
	 * Filter reporting whether an AMP page is being generated. The AMP
	 * module makes this return true on AMP requests to suppress the
	 * standard HTML container output.
	 *
	 * @since 2.0.0
	 */
	public const FILTER_AMP_RUNNING = 'gtm4wp_amp_running';

	/**
	 * Constructor.
	 *
	 * @param Options         $options    The plugin options service.
	 * @param DataLayer       $datalayer  The data layer service.
	 * @param ScriptTag       $script_tag The script tag helper.
	 * @param ConsentDefaults $consent    The consent mode default state service.
	 */
	public function __construct(
		private Options $options,
		private DataLayer $datalayer,
		private ScriptTag $script_tag,
		private ConsentDefaults $consent
	) {
	}

	/**
	 * Registers all frontend hooks of the container code output.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$header_begin_priority = $this->options->get( GTM4WP_OPTION_LOADEARLY ) ? 2 : 10;

		add_action( 'wp_head', array( $this, 'header_begin' ), $header_begin_priority, 0 );
		add_action( 'wp_head', array( $this, 'header_top' ), 1, 0 );
		add_action( 'wp_footer', array( $this, 'footer' ) );

		// To be able to easily migrate from other Google Tag Manager plugins.
		add_action( 'body_open', array( $this, 'body_open' ) );

		// Compatibility with existing themes that natively support code injection after the opening body tag.
		add_action( 'genesis_before', array( $this, 'body_open' ) ); // Genesis theme.
		add_action( 'generate_before_header', array( $this, 'body_open' ), 0 ); // GeneratePress theme.
		add_action( 'elementor/page_templates/canvas/before_content', array( $this, 'body_open' ) ); // Elementor.
		add_action( 'ct_before_builder', array( $this, 'body_open' ), 0 ); // Oxygen Builder.
		add_action( 'fl_before_builder', array( $this, 'body_open' ), 0 ); // Beaver Builder Theme.

		// Standard WP theme support for body open tags.
		add_action( 'wp_body_open', array( $this, 'body_open' ) );

		// Exclude the data layer variables from WP Rocket JS minification.
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'rocket_excluded_inline_js_content' ) );
	}

	/**
	 * Helper function to translate the GTM container code placement value into a readable string.
	 *
	 * @return string Readable form of a GTM container code placement option.
	 */
	public function placement_string(): string {
		switch ( $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) ) {
			case GTM4WP_PLACEMENT_FOOTER:
				return 'footer';

			case GTM4WP_PLACEMENT_BODYOPEN:
				return 'manual';

			case GTM4WP_PLACEMENT_BODYOPEN_AUTO:
				return 'automatic';

			case GTM4WP_PLACEMENT_OFF:
				return 'off';

			default:
				return 'unknown (' . $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) . ')';
		}
	}

	/**
	 * Function executed during wp_head with high priority.
	 * Outputs some global JavaScript variables that need to be accessible by other parts of the plugin.
	 *
	 * @param boolean $echo_output If set to true and AMP is not generating the page content, the HTML is output immediately.
	 * @return string|void Returns the HTML if the $echo_output parameter is set to false or when AMP page generation is running.
	 */
	public function header_top( $echo_output = true ) {
		$datalayer_name = $this->datalayer->name();

		// The data layer initialization has to use 'var' instead of 'let' since 'let' can break related browser extensions and 3rd party scripts.
		//
		// Two different jobs on two adjacent lines, which is why the encoders differ
		// (RI-4's two piles). The first is a string VALUE, so it takes json_literal()
		// with the hex flags and supplies its own quotes - byte-identical output for
		// any real name. The second and third are the bare IDENTIFIER, which must not
		// be quoted or encoded at all; what makes those safe is not an escaper but
		// ContainerRows::datalayer_name(), which will not return anything that is not
		// a valid JavaScript identifier (esc_js() could not help here - see the
		// comment on the global-vars allow-list below, which makes the same point).
		$_gtm_top_content = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
' . $this->script_tag->opening_tag() . '
	var gtm4wp_datalayer_name = ' . self::json_literal( $datalayer_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';
	var ' . $datalayer_name . ' = ' . $datalayer_name . ' || [];';

		// Load in the global variables from the gtm4wp_add_global_vars_array / GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY filter.
		$added_global_js_vars = (array) apply_filters( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array() );
		foreach ( $added_global_js_vars as $js_var_name => $js_var_value ) {
			// The name becomes a `const <name>` declaration, so anything that is not
			// a valid JavaScript identifier is a syntax error that would kill this
			// whole <script> block - including the data layer initialization above
			// it. esc_js() does not validate identifiers (it would happily emit
			// `const foo\' = ...`), so the name is allow-listed here instead and a
			// non-conforming entry is skipped rather than allowed to break the page.
			//
			// The shared constant, not a retyped copy (PA-2): this is the same
			// grammar the data layer name has to satisfy, and the two rules were
			// written independently and disagreed - the option's admitted '-'
			// while this one, correctly, never did.
			$js_var_name = (string) $js_var_name;
			if ( ! ContainerRows::is_valid_js_identifier( $js_var_name ) ) {
				continue;
			}

			$_gtm_top_content .= '
	const ' . $js_var_name . ' = ' . self::global_var_literal( $js_var_value ) . ';';
		}

		/**
		 * Filters the additional inline JavaScript appended to the data layer
		 * initialization block. Modules (e.g. consent tool integrations) add
		 * their 1.x-identical code snippets here.
		 *
		 * @since 2.0.0
		 *
		 * @param string $inline_js      Additional JavaScript, empty by default.
		 * @param string $datalayer_name Name of the data layer JS variable.
		 */
		$_gtm_top_content .= (string) apply_filters( self::FILTER_HEADER_TOP_JS, '', $datalayer_name );

		$_gtm_top_content .= '
</script>
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';

		if ( ! apply_filters( self::FILTER_AMP_RUNNING, false ) ) {
			if ( $echo_output ) {
				// Emit through print_script_block() - the same sanitizer the
				// container code in header_begin() uses - so consent-tool JS added
				// via FILTER_HEADER_TOP_JS is sanitized identically and, crucially,
				// gets the ampersand restored. wp_kses() alone turns every bare &
				// into &amp; with no restore step, which would silently break JS
				// operators like && and &-joined loader URLs in the head block.
				// See ScriptTag::print_script_block() (RI-3) for the contract.
				$this->script_tag->print_script_block( $_gtm_top_content );
			} else {
				return $_gtm_top_content;
			}
		}
	}

	/**
	 * Renders one GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY value as a JavaScript literal.
	 *
	 * Dispatches on the ACTUAL type, in order. The previous chain of independent
	 * `if`s tested `empty( $value ) && 0 !== $value` before the array and null
	 * branches, which swallowed three types and rewrote them all to `false`:
	 * null (should be `null`), an empty array (should be `[]`) and the float 0.0
	 * (should be `0`, since `0 !== 0.0` is true under strict comparison). That
	 * also made the trailing is_null() branch unreachable - it could never see a
	 * null, because the empty() test had already turned it into the string
	 * 'false'. Third-party integrators use this filter, so those were live bugs.
	 *
	 * @param mixed $value The filter-supplied value.
	 * @return string A JavaScript literal, safe for an inline <script> body.
	 */
	private static function global_var_literal( $value ): string {
		if ( is_null( $value ) ) {
			return 'null';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_array( $value ) ) {
			return self::json_literal( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return self::json_literal( $value, 0 );
		}

		// Everything else is rendered as a JSON string literal, with the SAME hex
		// flags as the array branch above. It used to use esc_js() in single quotes
		// for 1.x output parity, three lines below a branch that had already broken
		// that parity - and esc_js is an HTML-attribute escaper, so `"`, `<` and `>`
		// reached the integrator as &quot;/&lt;/&gt; text instead of characters
		// (RI-4/PA-4, #72). Verified inert, never a break-out: esc_js backslashed
		// the quotes. This is a data-correctness fix, and it makes every branch of
		// this function agree on one encoder.
		return self::json_literal(
			is_scalar( $value ) ? (string) $value : '',
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);
	}

	/**
	 * Encodes one value as a JavaScript literal, never returning an empty string.
	 *
	 * The encoder returns false when it cannot encode the value at all. Casting that
	 * to a string yields '', which would emit `const someVar = ;` - a SyntaxError
	 * that takes down the WHOLE head <script> block, including the data layer
	 * initialization above the global vars (#85). The filter that supplies these
	 * values is public, so the input is third-party. Falling back to the `null`
	 * literal keeps the block parseable and the failure confined to one variable,
	 * which is the same "skip rather than break the page" principle the JS-identifier
	 * allow-list on the variable NAME already applies in header_top().
	 *
	 * @param mixed $value The value to encode.
	 * @param int   $flags wp_json_encode() flags for this value's context.
	 * @return string A JavaScript literal, never an empty string.
	 */
	private static function json_literal( $value, int $flags ): string {
		$json = wp_json_encode( $value, $flags );

		return false === $json ? 'null' : $json;
	}

	/**
	 * Function executed during wp_head.
	 * Outputs the main Google Tag Manager container code together with the
	 * compiled data layer and the consent mode default block.
	 *
	 * @return void
	 */
	public function header_begin() {
		// On an AMP page the standard GTM container <script> is invalid AMP markup
		// and is stripped by the AMP sanitizer; the AMP module injects an
		// amp-analytics tag instead. Still compile the data layer (so its values,
		// the AFTER_DATALAYER hook and the backward-compatible global stay
		// available to the AMP integration and third-party consumers), then skip
		// emitting the container code.
		if ( apply_filters( self::FILTER_AMP_RUNNING, false ) ) {
			$this->datalayer->compile();

			do_action( GTM4WP_WPACTION_AFTER_DATALAYER );

			return;
		}

		$no_console_log = (bool) $this->options->get( GTM4WP_OPTION_NOCONSOLELOG );
		$datalayer_name = $this->datalayer->name();
		$containers     = $this->containers();

		$this->script_tag->print_script_block( $this->datalayer_block( $containers, $datalayer_name ) );

		do_action( GTM4WP_WPACTION_AFTER_DATALAYER );

		$output_container_code = ( GTM4WP_PLACEMENT_OFF !== $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );

		if ( ! $no_console_log && ! $output_container_code ) {
			$this->script_tag->print_script_block( $this->console_off_warning() );
		}

		// Kill switch: the production-only option or the gtm4wp_output_container
		// filter can suppress the container on a cloned/staging copy. Mirrors the
		// placement-OFF behavior above - the data layer stays active, only the
		// container loader below is skipped.
		if ( $output_container_code && ! $this->should_output_container() ) {
			$output_container_code = false;

			if ( ! $no_console_log ) {
				$this->script_tag->print_script_block( $this->container_suppressed_warning() );
			}
		}

		$excluded_role = $this->excluded_user_role();
		if ( '' !== $excluded_role ) {
			$output_container_code = false;

			if ( ! $no_console_log ) {
				$this->script_tag->print_script_block( $this->disabled_role_warning( $excluded_role ) );
			}
		}

		if ( $this->consent->enabled() ) {
			$this->script_tag->print_script_block( $this->consent->script_block( $this->script_tag ) );
		}

		if ( ( array() !== $containers ) && $output_container_code ) {
			foreach ( $containers as $one_container ) {
				$one_gtm_id = (string) ( $one_container[ ContainerRows::COLUMN_ID ] ?? '' );

				if ( ! preg_match( ContainerRows::GTM_ID_PATTERN, $one_gtm_id ) ) {
					continue;
				}

				$this->script_tag->print_script_block( $this->container_loader( $one_container, $datalayer_name ) );
			}
		}

		do_action( GTM4WP_WPACTION_AFTER_CONTAINER_CODE );

		echo '
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';
	}

	/**
	 * Builds the header comment, the data layer initialization variable and
	 * the first push of the compiled data layer content. Compiling the data
	 * layer here also populates the backward compatible global and the
	 * DataLayer::compiled() cache used by the AMP module.
	 *
	 * @param array<int, array<string, string>> $containers     Normalized container rows.
	 * @param string                            $datalayer_name Data layer JS variable name.
	 * @return string
	 */
	private function datalayer_block( array $containers, string $datalayer_name ): string {
		$script_tag = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<!-- GTM Container placement set to ' . esc_html( $this->placement_string() ) . ' -->
' . $this->script_tag->opening_tag();

		if ( array() !== $containers ) {
			$gtm4wp_datalayer_data = $this->datalayer->compile();

			// Encode <, >, &, " and ' as \uXXXX so the data layer JSON is safe in
			// any inline-script context. This is defense in depth on top of
			// ScriptTag::print_script_block(): even a value that arrives here already
			// HTML-entity encoded (e.g. get_search_query() returns esc_attr'd output,
			// so a " becomes &quot;) can never break out of the JS string literal.
			// No JSON_NUMERIC_CHECK here: it coerced every numeric-looking string
			// anywhere in the structure into a JSON number, silently corrupting
			// identifier-like values (a SKU of "000035180" lost its leading zeros;
			// order numbers, postcodes and phone numbers changed type). Values that
			// really are numbers (prices, totals, counts) are typed at their source
			// instead - the same contract the additional-push and cart-fragments
			// sinks have always had, so all sinks now agree on types.
			$script_tag .= '
	var dataLayer_content = ' . wp_json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';';

			$script_tag .= '
	' . esc_js( $datalayer_name ) . '.push( dataLayer_content );';
		}

		$script_tag .= '
</script>';

		return $script_tag;
	}

	/**
	 * Console warning shown when the container code placement is set to OFF but
	 * the data layer is still active.
	 *
	 * @return string
	 */
	private function console_off_warning(): string {
		return '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';
	}

	/**
	 * Console warning shown when the container code is omitted because the
	 * current user has an excluded role.
	 *
	 * @param string $user_role The excluded role that matched the current user.
	 * @return string
	 */
	private function disabled_role_warning( string $user_role ): string {
		// json_literal(), not esc_js(): this is a string VALUE in a raw <script>
		// body, where esc_js() emits &quot;/&amp;/&lt; entities the browser never
		// decodes, so a role slug containing one of those characters reached the
		// console as an entity instead of the character (PA-4/RI-4). The same swap
		// was made in global_var_literal() for the same reason; this was the last
		// esc_js'd string value left in a script body.
		//
		// The WHOLE message is encoded, not just the role, so the literal supplies
		// its own quotes and the emitted line stays byte-identical to the previous
		// output for an ordinary role slug - which is what keeps 1.x parity and the
		// existing byte assertions (BE-1) meaningful.
		$role_message = self::json_literal(
			'[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . $user_role . ' !!!',
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		return '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn(' . $role_message . ');
	console.warn && console.warn("[GTM4WP] Logout or login with a user having a different user role!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container code is omitted !!!");
</script>';
	}

	/**
	 * Console warning shown when the container code is suppressed by the
	 * production-only option or the gtm4wp_output_container filter (the kill
	 * switch) while the data layer stays active.
	 *
	 * @return string
	 */
	private function container_suppressed_warning(): string {
		return '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code output has been suppressed on this environment !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container code is omitted !!!");
</script>';
	}

	/**
	 * Builds the GTM container loader snippet for one container row. The
	 * domain, path and ID are validated/escaped by their helper methods.
	 *
	 * @param array<string, string> $one_container  One normalized container row.
	 * @param string                $datalayer_name Data layer JS variable name.
	 * @return string
	 */
	private function container_loader( array $one_container, string $datalayer_name ): string {
		$one_gtm_id = (string) ( $one_container[ ContainerRows::COLUMN_ID ] ?? '' );

		$_gtm_env = $this->container_environment( $one_container );

		// Server side GTM containers can be configured to serve a
		// single container from the loader path itself; in that case the
		// container ID is omitted from the query string ("?" instead of
		// "?id=") so no ID leaks into the request.
		$_gtm_loader_query = $this->container_omit_id( $one_container ) ? '?\'+dl' : '?id=\'+i+dl';

		/*
		 * The four esc_js() calls below are string VALUES inside single-quoted JS
		 * literals, so by RI-4's two-pile rule they belong in pile (b) - the pile
		 * whose members normally get migrated to json_literal(). These four stay,
		 * deliberately, and the reason is recorded here so the next re-derivation of
		 * that ledger finds an answer rather than re-deciding:
		 *
		 * - json_literal() emits DOUBLE quotes. This block is Google's own container
		 *   snippet reproduced byte for byte (single quotes throughout, matching 1.x
		 *   and every copy of it Google publishes), and BE-1 keeps byte-exact tests
		 *   over it. Migrating would change the emitted snippet for every site to buy
		 *   nothing.
		 * - Nothing is being reasoned about here on the basis of what the values
		 *   "happen to" contain - which is the filing #110 ruled out. Each of the four
		 *   passes an enforced allow-list on the way to this line, and none of those
		 *   allow-lists admits a quote: the container ID via ContainerRows::GTM_ID_PATTERN
		 *   in header_begin() before this method is called, the domain via
		 *   FILTER_VALIDATE_DOMAIN in container_domain(), the path via
		 *   ContainerRows::PATH_PATTERN in container_path(), and the data layer name via
		 *   ContainerRows::datalayer_name() at the reader. esc_js() is therefore a
		 *   provable no-op on all four, and the allow-lists are the actual control.
		 *
		 * If any of those four allow-lists is ever widened to admit a quote, this
		 * block has to move to json_literal() and the byte-exact tests updated with it.
		 */
		return '
' . $this->script_tag->opening_tag() . '
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':
new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=
\'//' . esc_js( $this->container_domain( $one_container ) ) . '/' . esc_js( $this->container_path( $one_container ) ) . $_gtm_loader_query .
			( '' !== $_gtm_env ? "+'" . $_gtm_env . "'" : '' ) . ';f.parentNode.insertBefore(j,f);
})(window,document,\'script\',\'' . esc_js( $datalayer_name ) . '\',\'' . esc_js( $one_gtm_id ) . '\');
</script>';
	}

	/**
	 * Returns a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
	 * Can be used to manually place the snippet next to the opening body tag if the installed template
	 * does not support the wp_body_open hook.
	 *
	 * @return string The HTML code that includes the noscript/iframe part of the GTM container code.
	 */
	public function get_tag(): string {
		$no_console_log = (bool) $this->options->get( GTM4WP_OPTION_NOCONSOLELOG );
		$containers     = $this->containers();
		$excluded_role  = $this->excluded_user_role();

		$_gtm_tag = '
<!-- GTM Container placement set to ' . esc_html( $this->placement_string() ) . ' -->
<!-- Google Tag Manager (noscript) -->';

		if ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) ) {
			// Placement OFF means the container code (both the head loader and
			// this noscript iframe) is never auto-emitted; only the data layer
			// stays active. Mark the code as "written" regardless of the
			// console-log setting so the iframe block below is always skipped -
			// otherwise, with console logging disabled, the iframe would still
			// be output despite the OFF placement.
			$GLOBALS['gtm4wp_container_code_written'] = true;

			if ( ! $no_console_log ) {
				$_gtm_tag .= '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';
			}
		} elseif ( ! $this->should_output_container() ) {
			// Kill switch (production-only option / gtm4wp_output_container
			// filter): suppress the noscript iframe exactly like placement OFF -
			// mark the code as "written" so the iframe block below is skipped
			// while the data layer stays active.
			$GLOBALS['gtm4wp_container_code_written'] = true;

			if ( ! $no_console_log ) {
				$_gtm_tag .= $this->container_suppressed_warning();
			}
		} elseif ( '' !== $excluded_role ) {
			// The user-role exclusion suppresses the <head> container loader in
			// header_begin(); this iframe is the same container by another route,
			// so it has to go with it. It used to be emitted regardless, which
			// meant an excluded user still loaded the container (and was counted
			// in the reports) whenever JavaScript was unavailable - the exact
			// case the noscript iframe exists for. Mark the code as "written" so
			// the iframe block below is skipped, exactly like placement OFF and
			// the kill switch above; the data layer stays active.
			$GLOBALS['gtm4wp_container_code_written'] = true;

			if ( ! $no_console_log ) {
				$_gtm_tag .= $this->disabled_role_warning( $excluded_role );
			}
		}

		if ( ( array() !== $containers ) && ( ! ( $GLOBALS['gtm4wp_container_code_written'] ?? false ) ) ) {
			foreach ( $containers as $one_container ) {
				$one_gtm_id = (string) ( $one_container[ ContainerRows::COLUMN_ID ] ?? '' );

				if ( preg_match( ContainerRows::GTM_ID_PATTERN, $one_gtm_id ) ) {
					$_gtm_tag .= '
				<noscript><iframe src="https://' . $this->container_domain( $one_container ) . '/ns.html?id=' . $one_gtm_id . $this->container_environment( $one_container ) . '"
				height="0" width="0" style="display:none;visibility:hidden" aria-hidden="true"></iframe></noscript>';
				}
			}

			$_gtm_tag .= '
<!-- End Google Tag Manager (noscript) -->';

			$GLOBALS['gtm4wp_container_code_written'] = true;
		}

		return $_gtm_tag;
	}

	/**
	 * Outputs a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
	 *
	 * @return void
	 */
	public function the_tag(): void {
		// The iframe's inline style needs `display` and `visibility`. Whether
		// safecss_filter_attr() strips those depends on the WordPress version:
		// core added `display` to the safe_style_css default list in 7.0 and
		// `visibility` in 7.1, so on 6.3-6.9 both are stripped and on 7.0
		// `visibility` still is. While the floor is 6.3 this filter is
		// load-bearing, not belt-and-braces - drop it only in the change that
		// raises the floor past 7.1, never as a "core allows it now" cleanup.
		//
		// Widen the CSS allow-list around THIS wp_kses() call only, then put it back:
		// safe_style_css is a global WordPress control, so a filter left
		// registered would relax it for every other wp_kses()/wp_kses_post() call
		// in the same request - including wp_filter_post_kses on content saved by
		// users without unfiltered_html, and a REST save is not is_admin(). One
		// line of our own markup is not a reason to loosen the rule site-wide.
		add_filter( 'safe_style_css', array( self::class, 'allow_iframe_hiding_styles' ) );

		try {
			// print_markup_block(), not a bare wp_kses(): this block mixes the noscript
			// iframe (whose src must keep its &amp; entity form) with the console
			// warning <script> blocks that get_tag() prepends for placement OFF, the
			// kill switch and an excluded user role. wp_kses() encodes every bare
			// ampersand, so a raw echo shipped `console.warn &amp;&amp; console.warn(…)`
			// - a SyntaxError that took out the whole warning block. Only script bodies
			// get the ampersand back; the attribute is left alone.
			$this->script_tag->print_markup_block(
				$this->get_tag(),
				array_merge(
					ScriptTag::sanitize_rules(),
					array(
						'noscript' => array(),
						'iframe'   => array(
							'src'         => array(),
							'height'      => array(),
							'width'       => array(),
							'style'       => array(),
							'aria-hidden' => array(),
						),
					)
				)
			);
		} finally {
			// finally, so an exception inside the sink cannot leave the site's CSS
			// allow-list widened for the rest of the request.
			remove_filter( 'safe_style_css', array( self::class, 'allow_iframe_hiding_styles' ) );
		}
	}

	/**
	 * Adds the two declarations the noscript iframe's inline style needs to the
	 * wp_kses() CSS allow-list.
	 *
	 * Public and static only because add_filter()/remove_filter() must be handed
	 * the same callable, and WordPress invokes it from outside this class. It is
	 * NOT a hook to register anywhere: the_tag() adds it immediately before its
	 * own wp_kses() call and removes it immediately after, which is the whole
	 * point - see the comment there.
	 *
	 * @param mixed $styles The CSS property allow-list wp_kses() is about to use.
	 * @return array The allow-list plus `display` and `visibility`.
	 */
	public static function allow_iframe_hiding_styles( $styles ): array {
		$styles   = is_array( $styles ) ? $styles : array();
		$styles[] = 'display';
		$styles[] = 'visibility';

		return $styles;
	}

	/**
	 * Outputs the noscript/iframe container code right after the opening body tag.
	 *
	 * @return void
	 */
	public function body_open(): void {
		$placement = $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT );

		if ( ( GTM4WP_PLACEMENT_BODYOPEN === $placement ) || ( GTM4WP_PLACEMENT_BODYOPEN_AUTO === $placement ) ) {
			$this->the_tag();
		}
	}

	/**
	 * Inserts the GTM noscript/iframe code in the footer if code placement is set to Footer.
	 *
	 * @return void
	 */
	public function footer(): void {
		if ( GTM4WP_PLACEMENT_FOOTER === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) ) {
			$this->the_tag();
		}
	}

	/**
	 * Excludes the dataLayer variable and gtm4wp* variables from being combined
	 * into the minified JS file by WP Rocket.
	 *
	 * @param array $pattern Patterns to match in inline JS content.
	 * @return array Patterns extended with the GTM4WP entries.
	 */
	public function rocket_excluded_inline_js_content( $pattern ) {
		$pattern[] = 'dataLayer';
		$pattern[] = 'gtm4wp';

		return $pattern;
	}

	/**
	 * Returns the normalized container rows of the GTM4WP_OPTION_GTM_CONTAINERS
	 * option: one row per container with its own environment parameters,
	 * custom domain and custom path.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function containers(): array {
		return ContainerRows::normalize( $this->options->get( GTM4WP_OPTION_GTM_CONTAINERS, array() ) );
	}

	/**
	 * Kill switch: decides whether the GTM container code (the <head> loader
	 * and the <noscript> iframe) may be emitted on the current request. Two
	 * independent gates can suppress it while keeping the data layer active
	 * (like placement OFF), so a cloned/staging copy of a site does not send
	 * hits to the production container without deactivating the plugin:
	 *
	 * 1. The gtm4wp_output_container filter (default true): return false from
	 *    an mu-plugin or wp-config snippet to suppress the container based on
	 *    the host name, WP_ENVIRONMENT_TYPE or any custom condition.
	 * 2. The "Only output on production environments" option: when enabled the
	 *    container is emitted only when wp_get_environment_type() is
	 *    'production' (WordPress derives it from the WP_ENVIRONMENT_TYPE
	 *    constant / environment variable, defaulting to 'production').
	 *
	 * @return bool
	 */
	private function should_output_container(): bool {
		/**
		 * Filters whether the Google Tag Manager container code is output on
		 * the current request. Return false to suppress the container <script>
		 * and the noscript iframe while keeping the data layer active - e.g.
		 * from an mu-plugin on a staging/clone copy of the site.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $output_container Whether to output the container. Default true.
		 */
		if ( ! apply_filters( GTM4WP_WPFILTER_OUTPUT_CONTAINER, true ) ) {
			return false;
		}

		if ( $this->options->get( GTM4WP_OPTION_PRODUCTIONONLY ) ) {
			// wp_get_environment_type() ships with WordPress 5.5+; the guard
			// keeps the unit tests (which never load WordPress) working.
			$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

			if ( 'production' !== $environment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the first role of the current user that the "Exclude user roles"
	 * option turns the container code off for, or an empty string when the
	 * container may be emitted for this user.
	 *
	 * Both container sinks ask this - the <head> loader in header_begin() and
	 * the <noscript> iframe in get_tag() - so an excluded role suppresses the
	 * whole container. The check used to live inline in header_begin() only,
	 * which left the iframe (the very fallback that loads GTM when JavaScript
	 * does not run) firing for an excluded user.
	 *
	 * @return string The matching excluded role, or '' when none matches.
	 */
	private function excluded_user_role(): string {
		$disabled_roles = explode( ',', (string) $this->options->get( GTM4WP_OPTION_NOGTMFORLOGGEDIN ) );
		$current_user   = wp_get_current_user();

		foreach ( (array) ( $current_user->roles ?? array() ) as $user_role ) {
			if ( in_array( $user_role, $disabled_roles, true ) ) {
				return (string) $user_role;
			}
		}

		return '';
	}

	/**
	 * Returns the environment query string fragment of one container row,
	 * empty unless both the gtm_auth and gtm_preview parameters are set.
	 *
	 * @param array<string, string> $container One container row.
	 * @return string
	 */
	private function container_environment( array $container ): string {
		$gtm_auth    = (string) ( $container[ ContainerRows::COLUMN_AUTH ] ?? '' );
		$gtm_preview = (string) ( $container[ ContainerRows::COLUMN_PREVIEW ] ?? '' );

		if ( ( '' === $gtm_auth ) || ( '' === $gtm_preview ) ) {
			return '';
		}

		// Re-validate at the output sink (PA-2), not just on save: these values
		// also reach the row from a 1.x migration and from the
		// GTM4WP_HARDCODED_GTM_ENV_* wp-config constants, neither of which went
		// through the admin schema sanitizer. A stray & would otherwise inject
		// extra query parameters into the container loader URL and the noscript
		// iframe src. Malformed values are dropped whole (both parameters are
		// meaningless alone) - Notices warns the admin so this is never silent.
		if ( ! preg_match( ContainerRows::AUTH_PATTERN, $gtm_auth )
			|| ! preg_match( ContainerRows::PREVIEW_PATTERN, $gtm_preview )
		) {
			return '';
		}

		return '&gtm_auth=' . esc_attr( $gtm_auth ) . '&gtm_preview=' . esc_attr( $gtm_preview ) . '&gtm_cookies_win=x';
	}

	/**
	 * Tells whether the GTM container ID must be left out of the container
	 * loader URL of one container row.
	 *
	 * This is only honored together with a custom loader path: it targets
	 * server side GTM setups where the container is selected by the request
	 * path/domain and the ID is configured on the server. Without a custom
	 * path the checkbox has no effect, so the default www.googletagmanager.com
	 * loader never ends up without an ID.
	 *
	 * @param array<string, string> $container One container row.
	 * @return bool
	 */
	private function container_omit_id( array $container ): bool {
		$no_id       = '' !== (string) ( $container[ ContainerRows::COLUMN_NO_ID ] ?? '' );
		$custom_path = '' !== (string) ( $container[ ContainerRows::COLUMN_PATH ] ?? '' );

		return $no_id && $custom_path;
	}

	/**
	 * Returns the validated GTM container domain of one container row,
	 * falling back to the default Google domain on invalid custom values.
	 *
	 * @param array<string, string> $container One container row.
	 * @return string
	 */
	private function container_domain( array $container ): string {
		$custom_domain    = (string) ( $container[ ContainerRows::COLUMN_DOMAIN ] ?? '' );
		$_gtm_domain_test = ( '' === $custom_domain ) ? 'www.googletagmanager.com' : strtolower( $custom_domain );
		$_gtm_domain_name = filter_var( $_gtm_domain_test, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );

		if ( false === $_gtm_domain_name ) {
			return 'www.googletagmanager.com';
		}

		return $_gtm_domain_name;
	}

	/**
	 * Returns the validated GTM container script path of one container row,
	 * falling back to gtm.js on invalid custom values.
	 *
	 * @param array<string, string> $container One container row.
	 * @return string
	 */
	private function container_path( array $container ): string {
		$custom_path      = (string) ( $container[ ContainerRows::COLUMN_PATH ] ?? '' );
		$_gtm_domain_path = ( '' === $custom_path ) ? 'gtm.js' : $custom_path;

		// Use the shared constant rather than a retyped copy: this allow-list is
		// security relevant, and the inline duplicate had already drifted from it.
		if ( ! preg_match( ContainerRows::PATH_PATTERN, $_gtm_domain_path ) ) {
			return 'gtm.js';
		}

		return $_gtm_domain_path;
	}
}
