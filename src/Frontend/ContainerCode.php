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

		// Allow the inline styles of the noscript iframe through wp_kses().
		add_filter(
			'safe_style_css',
			function ( $styles ) {
				$styles[] = 'display';
				$styles[] = 'visibility';
				return $styles;
			}
		);

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
		$_gtm_top_content = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
' . $this->script_tag->opening_tag() . '
	var gtm4wp_datalayer_name = "' . esc_js( $datalayer_name ) . '";
	var ' . esc_js( $datalayer_name ) . ' = ' . esc_js( $datalayer_name ) . ' || [];';

		// Load in the global variables from the gtm4wp_add_global_vars_array / GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY filter.
		$added_global_js_vars = (array) apply_filters( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array() );
		foreach ( $added_global_js_vars as $js_var_name => $js_var_value ) {
			if ( is_string( $js_var_value ) ) {
				$js_var_value = "'" . esc_js( $js_var_value ) . "'";
			}

			if ( is_bool( $js_var_value ) || ( empty( $js_var_value ) && ( 0 !== $js_var_value ) ) ) {
				$js_var_value = $js_var_value ? 'true' : 'false';
			}

			if ( is_array( $js_var_value ) ) {
				$js_var_value = wp_json_encode( $js_var_value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
			}

			if ( is_null( $js_var_value ) ) {
				$js_var_value = 'null';
			}

			$_gtm_top_content .= '
	const ' . esc_js( $js_var_name ) . ' = ' . $js_var_value . ';';
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
				echo wp_kses(
					$_gtm_top_content,
					array(
						'script' => array(
							'data-cfasync'            => array(),
							'data-pagespeed-no-defer' => array(),
							'data-cookieconsent'      => array(),
							'nonce'                   => array(),
						),
					)
				);
			} else {
				return $_gtm_top_content;
			}
		}
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

		$disabled_roles = explode( ',', (string) $this->options->get( GTM4WP_OPTION_NOGTMFORLOGGEDIN ) );
		$current_user   = wp_get_current_user();
		foreach ( $current_user->roles as $user_role ) {
			if ( in_array( $user_role, $disabled_roles, true ) ) {
				$output_container_code = false;

				if ( ! $no_console_log ) {
					$this->script_tag->print_script_block( $this->disabled_role_warning( $user_role ) );
				}

				break;
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
			$script_tag .= '
	var dataLayer_content = ' . wp_json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';';

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
		return '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . esc_js( $user_role ) . ' !!!");
	console.warn && console.warn("[GTM4WP] Logout or login with a user having a different user role!");
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
		echo wp_kses(
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

		if ( ! preg_match( '/^[a-zA-Z0-9\.\-\_\/]+$/', $_gtm_domain_path ) ) {
			return 'gtm.js';
		}

		return $_gtm_domain_path;
	}
}
