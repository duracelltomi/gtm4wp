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
	 * data layer initialization block in the <head> (scroll tracking
	 * constants, consent tool callbacks).
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
	 * @param boolean $echo If set to true and AMP is not generating the page content, the HTML is output immediately.
	 * @return string|void Returns the HTML if the $echo parameter is set to false or when AMP page generation is running.
	 */
	public function header_top( $echo = true ) {
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
				$js_var_value = wp_json_encode( $js_var_value );
			}

			if ( is_null( $js_var_value ) ) {
				$js_var_value = 'null';
			}

			$_gtm_top_content .= '
	const ' . esc_js( $js_var_name ) . ' = ' . $js_var_value . ';';
		}

		/**
		 * Filters the additional inline JavaScript appended to the data layer
		 * initialization block. Modules (scroll tracking, consent tool
		 * integrations) add their 1.x-identical code snippets here.
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
			if ( $echo ) {
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
	 * @param boolean $echo Present for 1.x signature compatibility (AMP integration).
	 * @return void
	 */
	public function header_begin( $echo = true ) {
		$no_console_log = (bool) $this->options->get( GTM4WP_OPTION_NOCONSOLELOG );
		$datalayer_name = $this->datalayer->name();
		$gtm_code       = (string) $this->options->get( GTM4WP_OPTION_GTM_CODE );

		$script_tag = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<!-- GTM Container placement set to ' . esc_html( $this->placement_string() ) . ' -->
' . $this->script_tag->opening_tag();

		if ( '' !== $gtm_code ) {
			$gtm4wp_datalayer_data = $this->datalayer->compile();

			$script_tag .= '
	var dataLayer_content = ' . wp_json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG ) . ';';

			$script_tag .= '
	' . esc_js( $datalayer_name ) . '.push( dataLayer_content );';
		}

		$script_tag .= '
</script>';

		$this->script_tag->print_script_block( $script_tag );

		do_action( GTM4WP_WPACTION_AFTER_DATALAYER );

		$output_container_code = true;
		if ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) ) {
			$output_container_code = false;
		}

		if ( ! $no_console_log && ! $output_container_code ) {
			$script_tag = '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';

			$this->script_tag->print_script_block( $script_tag );
		}

		$disabled_roles = explode( ',', (string) $this->options->get( GTM4WP_OPTION_NOGTMFORLOGGEDIN ) );
		if ( count( $disabled_roles ) > 0 ) {
			$current_user = wp_get_current_user();
			foreach ( $current_user->roles as $user_role ) {
				if ( in_array( $user_role, $disabled_roles, true ) ) {
					$output_container_code = false;

					if ( ! $no_console_log ) {
						$script_tag = '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . esc_js( $user_role ) . ' !!!");
	console.warn && console.warn("[GTM4WP] Logout or login with a user having a different user role!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container code is omitted !!!");
</script>';

						$this->script_tag->print_script_block( $script_tag );
					}

					break;
				}
			}
		}

		if ( $this->consent->enabled() ) {
			$this->script_tag->print_script_block( $this->consent->script_block( $this->script_tag ) );
		}

		if ( ( '' !== $gtm_code ) && $output_container_code ) {
			$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm_code ) );

			foreach ( $_gtm_codes as $one_gtm_id ) {
				if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
					continue;
				}

				$script_tag = '
' . $this->script_tag->opening_tag() . '
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':
new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=
\'//' . esc_js( $this->container_domain() ) . '/' . esc_js( $this->container_path() ) . '?id=\'+i+dl' .
				( $this->has_environment() ? "+'&gtm_auth=" . esc_attr( $this->options->get( GTM4WP_OPTION_ENV_GTM_AUTH ) ) . '&gtm_preview=' . esc_attr( $this->options->get( GTM4WP_OPTION_ENV_GTM_PREVIEW ) ) . "&gtm_cookies_win=x'" : '' ) . ';f.parentNode.insertBefore(j,f);
})(window,document,\'script\',\'' . esc_js( $datalayer_name ) . '\',\'' . esc_js( $one_gtm_id ) . '\');
</script>';

				$this->script_tag->print_script_block( $script_tag );
			}
		}

		do_action( GTM4WP_WPACTION_AFTER_CONTAINER_CODE );

		echo '
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';
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
		$gtm_code       = (string) $this->options->get( GTM4WP_OPTION_GTM_CODE );

		$_gtm_tag = '
<!-- GTM Container placement set to ' . esc_html( $this->placement_string() ) . ' -->
<!-- Google Tag Manager (noscript) -->';

		if ( ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) ) && ( ! $no_console_log ) ) {
			$GLOBALS['gtm4wp_container_code_written'] = true;

			$_gtm_tag .= '
' . $this->script_tag->opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';
		}

		if ( ( '' !== $gtm_code ) && ( ! ( $GLOBALS['gtm4wp_container_code_written'] ?? false ) ) ) {
			$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm_code ) );

			$_gtm_env = '';
			if ( $this->has_environment() ) {
				$_gtm_env = '&gtm_auth=' . esc_attr( $this->options->get( GTM4WP_OPTION_ENV_GTM_AUTH ) ) . '&gtm_preview=' . esc_attr( $this->options->get( GTM4WP_OPTION_ENV_GTM_PREVIEW ) ) . '&gtm_cookies_win=x';
			}

			$_gtm_domain_name = $this->container_domain();

			foreach ( $_gtm_codes as $one_gtm_id ) {
				if ( preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
					$_gtm_tag .= '
				<noscript><iframe src="https://' . $_gtm_domain_name . '/ns.html?id=' . $one_gtm_id . $_gtm_env . '"
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
	 * Whether GTM environment parameters (auth + preview) are configured.
	 *
	 * @return bool
	 */
	private function has_environment(): bool {
		return ( '' !== $this->options->get( GTM4WP_OPTION_ENV_GTM_AUTH ) ) &&
			( '' !== $this->options->get( GTM4WP_OPTION_ENV_GTM_PREVIEW ) );
	}

	/**
	 * Returns the validated GTM container domain, falling back to the
	 * default Google domain on invalid custom values.
	 *
	 * @return string
	 */
	private function container_domain(): string {
		$custom_domain    = (string) $this->options->get( GTM4WP_OPTION_GTMDOMAIN );
		$_gtm_domain_test = ( '' === $custom_domain ) ? 'www.googletagmanager.com' : strtolower( $custom_domain );
		$_gtm_domain_name = filter_var( $_gtm_domain_test, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );

		if ( false === $_gtm_domain_name ) {
			return 'www.googletagmanager.com';
		}

		return $_gtm_domain_name;
	}

	/**
	 * Returns the validated GTM container script path, falling back to
	 * gtm.js on invalid custom values.
	 *
	 * @return string
	 */
	private function container_path(): string {
		$custom_path      = (string) $this->options->get( GTM4WP_OPTION_GTMCUSTOMPATH );
		$_gtm_domain_path = ( '' === $custom_path ) ? 'gtm.js' : $custom_path;

		if ( ! preg_match( '/^[a-zA-Z0-9\.\-\_\/]+$/', $_gtm_domain_path ) ) {
			return 'gtm.js';
		}

		return $_gtm_domain_path;
	}
}
