<?php
/**
 * Load Google Tag Manager container code on frontend and output data layer variables.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

/**
 * Constant used by GTM4WP as a WordPress filter to allow itself 3rd party plugins
 * to add their own data into the data layer.
 */
define( 'GTM4WP_WPFILTER_COMPILE_DATALAYER', 'gtm4wp_compile_datalayer' );

/**
 * Constant used by GTM4WP as a WordPress action to allow itself and 3rd party plugins
 * to add their own code after the first data layer push command.
 */
define( 'GTM4WP_WPACTION_AFTER_DATALAYER', 'gtm4wp_output_after_datalayer' );

/**
 * Constant used by GTM4WP as a WordPress action to allow itself and 3rd party plugins
 * to fire additional data layer events after the main GTM container code.
 */
define( 'GTM4WP_WPACTION_AFTER_CONTAINER_CODE', 'gtm4wp_after_container_code' );

/**
 * Constant that GTM4WP itself and 3rd party plugins can use to add JavaScript
 * variable declarations above the first data layer push command.
 */
define( 'GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY', 'gtm4wp_add_global_vars_array' );

/**
 * Constant that can be used to add nonce value to script tags for
 * Content Security Policy (CSP) setups.
 */
define( 'GTM4WP_WPFILTER_GET_CSP_NONCE', 'gtm4wp_get_csp_nonce' );

/**
 * Constant that can be used to overwrite the stored default value of a consent mode flag.
 */
define( 'GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG', 'gtm4wp_overwrite_consent_mode_flag' );

/**
 * Stores whether the container code has been outputed or not.
 * Helps preventing double output of the GTM container code if the WordPress
 * theme is using the gtm4wp_get_the_gtm_tag() function in a wrong way
 * (next to the automatic code injection using wp_body_open)
 *
 * @var bool
 */
$GLOBALS['gtm4wp_container_code_written'] = false;

/**
 * Check for empty is needed to prevent error in WP CLI
 * bugfix by Patrick Holberg Hesselberg
 *
 * @since 1.2
 */
$GLOBALS['gtm4wp_datalayer_name'] = $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ];

if ( empty( $GLOBALS['gtm4wp_options'] ) || ( '' === $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ] ) ) {
	$GLOBALS['gtm4wp_datalayer_name'] = 'dataLayer';
}

/**
 * Stores the data layer content to give access to this data
 * for the AMP integration.
 *
 * @var array
 */
$GLOBALS['gtm4wp_datalayer_data'] = array();

/**
 * Stores additional data layer push commands that will be fired after the main GTM container code.
 *
 * @var array
 */
$GLOBALS['gtm4wp_additional_datalayer_pushes'] = array();

/**
 * Include AMP integration
 */
if ( isset( $GLOBALS['gtm4wp_options'] ) && ( '' !== $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_AMPID ] ) ) {
	require_once dirname( __FILE__ ) . '/../integration/amp.php';
}
if ( ! function_exists( 'gtm4wp_amp_running' ) ) {
	/**
	 * Declare function if AMP is not runnig to allow gtm4wp_wp_header_top() to function properly.
	 *
	 * @return bool
	 */
	function gtm4wp_amp_running() {
		return false;
	}
}

/**
 * Returns the IP address of the user either from REMOVE_ADDR server variable or a custom HTTP header specified in the parameter of the funcion.
 *
 * Originally this function iterated through many commonly used custom headers however since they are unprotected, one could send a bogus
 * IP address for tracking purposes. Therefore function has been changed to only use the safe server variable and a user option to allow one
 * specific custom HTTP header.
 *
 * The function will translate the given custom header to a PHP server varibale, no need to directly input the PHP form of the header.
 * If custom the header is not found, the function will fall back to REMOTE_ADDR.
 *
 * @param string $use_custom_header A custom HTTP header to use instead of the default REMOTE_ADDR server variable.
 * @return string IP address of the user if found, empty string otherwise.
 */
function gtm4wp_get_user_ip( $use_custom_header = '' ) {
	$custom_header = '';

	if ( '' !== $use_custom_header ) {
		$custom_header = strtoupper( str_replace( '-', '_', $use_custom_header ) );
		if ( preg_match( '/[A-Z0-9_]+/', $custom_header ) ) {
			$custom_header = 'HTTP_' . $custom_header;
		} else {
			$custom_header = '';
		}
	}

	if ( ( '' !== $custom_header ) && ( ! empty( $_SERVER[ $custom_header ] ) ) ) {
		if ( 'HTTP_X_FORWARDED_FOR' === $custom_header ) {
			// X-Forwarded-For is a comma+space separated list of IPs.
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $custom_header ] ) ) ) as $ip ) {
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
					return $ip;
				}
			}
		} else {
			$ip = filter_var( wp_unslash( $_SERVER[ $custom_header ] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( false !== $ip ) {
				return $ip;
			}
		}
	}

	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		return filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	return '';
}

if ( ! function_exists( 'getallheaders' ) ) {
	/**
	 * Fetches all HTTP headers from the current request.
	 * Fallback function for nginx servers where this function is not available.
	 *
	 * @return array An associative array of all the HTTP headers in the current request, or false on failure.
	 */
	function getallheaders() {
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			if ( substr( $name, 0, 5 ) === 'HTTP_' ) {
				$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
			}
		}

		return $headers;
	}
}

/**
 * Generates an opening <script> tag that includes all the necessary attributes.
 * Adds data-cfasync="false" amd data-pagespeed-no-defer.
 * Calls current_theme_supports( 'html5' ) to detect wether to add type attribute.
 * Checks whether Cookiebot integration is active to add data-cookieconsent="ignore" attribute.
 * Uses the GTM4WP_WPFILTER_GET_CSP_NONCE gtm4wp_get_csp_nonce filter to use the nonce attribute to support Content Security Policy setups.
 *
 * @return string
 */
function gtm4wp_generate_script_opening_tag() {
	global $gtm4wp_options;

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];
	$csp_nonce            = apply_filters( GTM4WP_WPFILTER_GET_CSP_NONCE, '' );

	return '<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . ( $csp_nonce ? ' nonce="' . esc_attr( $csp_nonce ) . '"' : '' ) . '>';
}

/**
 * Returns an array that can be used to sanitize a <script> block using wp_kses().
 *
 * @return array
 */
function gtm4wp_get_sanitize_script_block_rules() {
	return array(
		'script' => array(
			'data-cfasync'            => array(),
			'data-pagespeed-no-defer' => array(),
			'data-cookieconsent'      => array(),
			'type'                    => array(),
			'nonce'                   => array(),
		),
	);
}

/**
 * Populate main data layer outputted in the <head> before the GTM container snippet.
 *
 * @param array $data_layer Array of key-value pairs that will be outputed as a JSON object into the dataLayer global JavaScript variable.
 * @return array
 */
function gtm4wp_add_basic_datalayer_data( $data_layer ) {
	global $wp_query, $gtm4wp_options, $gtm4wp_entity_ids;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_SITEID ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_SITENAME ] ) {
		$data_layer['siteID']   = 0;
		$data_layer['siteName'] = '';

		if ( function_exists( 'get_blog_details' ) ) {
			$gtm4wp_blogdetails = get_blog_details();

			$data_layer['siteID']   = $gtm4wp_blogdetails->blog_id;
			$data_layer['siteName'] = $gtm4wp_blogdetails->blogname;
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] ) {
		$data_layer['visitorLoginState'] = 'logged-out';

		if ( is_user_logged_in() ) {
			$data_layer['visitorLoginState'] = 'logged-in';
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERROLE ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USEREMAIL ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERREGDATE ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERNAME ] ) {
		$current_user = wp_get_current_user();

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERROLE ] ) {
			$data_layer['visitorType'] = ( 0 === $current_user->ID ? 'visitor-logged-out' : implode( ',', $current_user->roles ) );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USEREMAIL ] ) {
			$data_layer['visitorEmail']     = ( empty( $current_user->user_email ) ? '' : $current_user->user_email );
			$data_layer['visitorEmailHash'] = ( empty( $current_user->user_email ) ? '' : hash( 'sha256', $current_user->user_email ) );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERREGDATE ] ) {
			$data_layer['visitorRegistrationDate'] = ( empty( $current_user->user_registered ) ? '' : strtotime( $current_user->user_registered ) );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERNAME ] ) {
			$data_layer['visitorUsername'] = ( empty( $current_user->user_login ) ? '' : $current_user->user_login );
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERID ] ) {
		$_gtm4wp_userid = get_current_user_id();
		if ( $_gtm4wp_userid > 0 ) {
			$data_layer['visitorId'] = $_gtm4wp_userid;
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_VISITOR_IP ] ) {
		$data_layer['visitorIP'] = esc_js( gtm4wp_get_user_ip( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ] ) );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTITLE ] ) {
		$data_layer['pageTitle'] = htmlspecialchars( wp_strip_all_tags( wp_title( '|', false, 'right' ) ) );
	}

	if ( is_singular() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
			$data_layer['pagePostType']  = get_post_type();
			$data_layer['pagePostType2'] = 'single-' . get_post_type();
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_CATEGORIES ] ) {
			$_post_cats = get_the_category();
			if ( $_post_cats ) {
				$data_layer['pageCategory'] = array();
				foreach ( $_post_cats as $_one_cat ) {
					$data_layer['pageCategory'][] = $_one_cat->slug;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_TAGS ] ) {
			$_post_tags = get_the_tags();
			if ( $_post_tags ) {
				$data_layer['pageAttributes'] = array();
				foreach ( $_post_tags as $_one_tag ) {
					$data_layer['pageAttributes'][] = $_one_tag->slug;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) {
			$postuser = get_userdata( $GLOBALS['post']->post_author );

			if ( false !== $postuser ) {
				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] ) {
					$data_layer['pagePostAuthorID'] = $postuser->ID;
				}

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) {
					$data_layer['pagePostAuthor'] = $postuser->display_name;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
			$data_layer['pagePostDate']        = get_the_date();
			$data_layer['pagePostDateYear']    = get_the_date( 'Y' );
			$data_layer['pagePostDateMonth']   = get_the_date( 'm' );
			$data_layer['pagePostDateDay']     = get_the_date( 'd' );
			$data_layer['pagePostDateDayName'] = get_the_date( 'l' );
			$data_layer['pagePostDateHour']    = get_the_date( 'H' );
			$data_layer['pagePostDateMinute']  = get_the_date( 'i' );
			$data_layer['pagePostDateIso']     = get_the_date( 'c' );
			$data_layer['pagePostDateUnix']    = get_the_date( 'U' );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTERMLIST ] ) {
			$data_layer['pagePostTerms'] = array();

			$object_taxonomies = get_object_taxonomies( get_post_type() );

			foreach ( $object_taxonomies as $one_object_taxonomy ) {
				$post_taxonomy_values = get_the_terms( $GLOBALS['post']->ID, $one_object_taxonomy );
				if ( is_array( $post_taxonomy_values ) ) {
					$data_layer['pagePostTerms'][ $one_object_taxonomy ] = array();
					foreach ( $post_taxonomy_values as $one_taxonomy_value ) {
						$data_layer['pagePostTerms'][ $one_object_taxonomy ][] = $one_taxonomy_value->name;
					}
				}
			}

			$post_meta = get_post_meta( $GLOBALS['post']->ID );
			if ( is_array( $post_meta ) ) {
				$data_layer['pagePostTerms']['meta'] = array();
				foreach ( $post_meta as $post_meta_key => $post_meta_value ) {
					if ( '_' !== substr( $post_meta_key, 0, 1 ) ) {

						/**
						 * Applies a filter to determine if post meta should be included in the data layer.
						 * This function allows other plugins or themes to modify whether post meta should be included in the data layer
						 * by applying a filter to the variable $include_post_meta_in_datalayer.
						 *
						 * @since 1.17
						 *
						 * @param string $gtm4wp_post_meta_in_datalayer The name of the filter to be applied.
						 * @param bool $true_false_default The default value of $include_post_meta_in_datalayer (true).
						 * @param string $post_meta_key The name of the post meta key to be included in the data layer.
						 *
						 * @return bool The final value of $include_post_meta_in_datalayer after the filter has been applied.
						*/
						$include_post_meta_in_datalayer = (bool) apply_filters( 'gtm4wp_post_meta_in_datalayer', true, $post_meta_key );

						if ( $include_post_meta_in_datalayer ) {
							if ( is_array( $post_meta_value ) && ( 1 === count( $post_meta_value ) ) ) {
								$post_meta_dl_value = $post_meta_value[0];
							} else {
								$post_meta_dl_value = $post_meta_value;
							}
							$data_layer['pagePostTerms']['meta'][ $post_meta_key ] = $post_meta_dl_value;
						}
					}
				}
			}
		}
	}

	if ( is_archive() || is_post_type_archive() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
			$data_layer['pagePostType'] = get_post_type();

			if ( is_category() ) {
				$data_layer['pagePostType2'] = 'category-' . get_post_type();
			} elseif ( is_tag() ) {
				$data_layer['pagePostType2'] = 'tag-' . get_post_type();
			} elseif ( is_tax() ) {
				$data_layer['pagePostType2'] = 'tax-' . get_post_type();
			} elseif ( is_author() ) {
				$data_layer['pagePostType2'] = 'author-' . get_post_type();
			} elseif ( is_year() ) {
				$data_layer['pagePostType2'] = 'year-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$data_layer['pagePostDateYear'] = get_the_date( 'Y' );
				}
			} elseif ( is_month() ) {
				$data_layer['pagePostType2'] = 'month-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
				}
			} elseif ( is_day() ) {
				$data_layer['pagePostType2'] = 'day-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$data_layer['pagePostDate']      = get_the_date();
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
					$data_layer['pagePostDateDay']   = get_the_date( 'd' );
				}
			} elseif ( is_time() ) {
				$data_layer['pagePostType2'] = 'time-' . get_post_type();
			} elseif ( is_date() ) {
				$data_layer['pagePostType2'] = 'date-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$data_layer['pagePostDate']      = get_the_date();
					$data_layer['pagePostDateYear']  = get_the_date( 'Y' );
					$data_layer['pagePostDateMonth'] = get_the_date( 'm' );
					$data_layer['pagePostDateDay']   = get_the_date( 'd' );
				}
			}
		}

		if ( ( is_tax() || is_category() ) && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_CATEGORIES ] ) {
			$_post_cats                 = get_the_category();
			$data_layer['pageCategory'] = array();
			foreach ( $_post_cats as $_one_cat ) {
				$data_layer['pageCategory'][] = $_one_cat->slug;
			}
		}

		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] ) && ( is_author() ) ) {
			global $authordata;
			$data_layer['pagePostAuthorID'] = isset( $authordata->ID ) ? $authordata->ID : 0;
		}

		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) && ( is_author() ) ) {
			$data_layer['pagePostAuthor'] = get_the_author();
		}
	}

	if ( is_search() ) {
		$data_layer['pagePostType'] = 'search-results';

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_SEARCHDATA ] ) {
			$data_layer['siteSearchTerm'] = htmlspecialchars( get_search_query() );
			$data_layer['siteSearchFrom'] = '';
			if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$referer_url_parts            = explode( '?', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
				$data_layer['siteSearchFrom'] = $referer_url_parts[0];

				if ( count( $referer_url_parts ) > 1 ) {
					$data_layer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
				}
			}
			$data_layer['siteSearchResults'] = $wp_query->post_count;
		}
	}

	if ( is_front_page() && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
		$data_layer['pagePostType'] = 'frontpage';
	}

	if ( ! is_front_page() && is_home() && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
		$data_layer['pagePostType'] = 'bloghome';
	}

	if ( is_404() ) {
		$data_layer['pagePostType'] = '404-error';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_BROWSERDATA ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_OSDATA ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_DEVICEDATA ] ) {
		spl_autoload_register(
			function( $class ) {
				$class_parts = explode( '\\', $class );
				if ( 'WhichBrowser' === $class_parts[0] ) {
					include dirname( __FILE__ ) . '/../integration/whichbrowser/' . str_replace( array( 'WhichBrowser', '\\' ), array( 'src', '/' ), $class ) . '.php';
				}
			}
		);

		require_once dirname( __FILE__ ) . '/../integration/whichbrowser/src/Parser.php';

		$gtp4wp_headers = getallheaders();
		if ( ( false === $gtp4wp_headers ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$gtp4wp_headers = wp_strip_all_tags( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		if ( false !== $gtp4wp_headers ) {
			$detected = new WhichBrowser\Parser( $gtp4wp_headers );

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_BROWSERDATA ] ) {
				$data_layer['browserName']    = isset( $detected->browser->name ) ? $detected->browser->name : '';
				$data_layer['browserVersion'] = isset( $detected->browser->version->value ) ? $detected->browser->version->value : '';

				$data_layer['browserEngineName']    = isset( $detected->engine->name ) ? $detected->engine->name : '';
				$data_layer['browserEngineVersion'] = isset( $detected->engine->version->value ) ? $detected->engine->version->value : '';
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_OSDATA ] ) {
				$data_layer['osName']    = isset( $detected->os->name ) ? $detected->os->name : '';
				$data_layer['osVersion'] = isset( $detected->os->version->value ) ? $detected->os->version->value : '';
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_DEVICEDATA ] ) {
				$data_layer['deviceType']         = isset( $detected->device->type ) ? $detected->device->type : '';
				$data_layer['deviceManufacturer'] = isset( $detected->device->manufacturer ) ? $detected->device->manufacturer : '';
				$data_layer['deviceModel']        = isset( $detected->device->model ) ? $detected->device->model : '';
			}
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTCOUNT ] ) {
		$data_layer['postCountOnPage'] = (int) $wp_query->post_count;
		$data_layer['postCountTotal']  = (int) $wp_query->found_posts;
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTID ] && is_singular() === true ) {
		$data_layer['postID'] = (int) get_the_ID();
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTFORMAT ] && is_singular() === true ) {
		$data_layer['postFormat'] = get_post_format() ? '' : 'standard';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] > 0 ) {
		$_gtmrestrictlistitems = array();

		// because of security reasons, we loop through each stored entity in the options and validate them
		// to make sure nobody has entered some 'funny' item manually.
		$valid_entity_ids = array_merge(
			array_keys( $gtm4wp_entity_ids['tags'] ),
			array_keys( $gtm4wp_entity_ids['triggers'] ),
			array_keys( $gtm4wp_entity_ids['variables'] )
		);
		foreach ( $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_STATUS ] as $listed_entity ) {
			if ( in_array( $listed_entity, $valid_entity_ids, true ) ) {
				$_gtmrestrictlistitems[] = $listed_entity;
			}
		}

		$_gtmwhitelist = array();
		$_gtmblacklist = array();
		if ( 1 === $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] ) {
			$_gtmblacklist = array_merge( $_gtmblacklist, $_gtmrestrictlistitems );
		} else {
			$_gtmwhitelist = array_merge( $_gtmwhitelist, $_gtmrestrictlistitems );
		}

		$data_layer['gtm.whitelist'] = $_gtmwhitelist;
		$data_layer['gtm.blacklist'] = $_gtmblacklist;
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEOCF ] && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$data_layer['geoCloudflareCountryCode'] = esc_js( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] ) {
			$data_layer['weatherCategory']    = esc_js( __( '(no weather data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['weatherDescription'] = esc_js( __( '(no weather data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['weatherTemp']        = 0;
			$data_layer['weatherPressure']    = 0;
			$data_layer['weatherWindSpeed']   = 0;
			$data_layer['weatherWindDeg']     = 0;
			$data_layer['weatherDataStatus']  = 'Initialized with empty data';
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
			$data_layer['geoCountryCode'] = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoCountryName'] = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoRegionCode']  = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoRegionName']  = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoCity']        = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoZipcode']     = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoLatitude']    = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
			$data_layer['geoLongitude']   = esc_js( __( '(no geo data available)', 'duracelltomi-google-tag-manager' ) );
		}

		$client_ip = gtm4wp_get_user_ip( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ] );

		if ( '' !== $client_ip ) {
			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] ) {
				$weatherdata                     = get_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ) );
				$data_layer['weatherDataStatus'] = 'No weather data in cache (' . esc_attr( $client_ip ) . ')';

				if ( false !== $weatherdata ) {
					$data_layer['weatherCategory']        = $weatherdata->weather[0]->main;
					$data_layer['weatherDescription']     = $weatherdata->weather[0]->description;
					$data_layer['weatherTemp']            = $weatherdata->main->temp;
					$data_layer['weatherPressure']        = $weatherdata->main->pressure;
					$data_layer['weatherWindSpeed']       = $weatherdata->wind->speed;
					$data_layer['weatherWindDeg']         = ( isset( $weatherdata->wind->deg ) ? $weatherdata->wind->deg : '' );
					$data_layer['weatherFullWeatherData'] = $weatherdata;
					$data_layer['weatherDataStatus']      = 'Read from cache';
				}
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
				$geodata = get_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ) );

				if ( false !== $geodata ) {
					$data_layer['geoFullGeoData'] = $geodata;

					if ( isset( $geodata->latitude ) ) {
						$data_layer['geoCountryCode'] = $geodata->country_code;
						$data_layer['geoCountryName'] = $geodata->country_name;
						$data_layer['geoRegionCode']  = $geodata->region_code;
						$data_layer['geoRegionName']  = $geodata->region_name;
						$data_layer['geoCity']        = $geodata->city;
						$data_layer['geoZipcode']     = $geodata->zip;
						$data_layer['geoLatitude']    = $geodata->latitude;
						$data_layer['geoLongitude']   = $geodata->longitude;
					}
				}
			}
		}
	}

	return $data_layer;
}

/**
 * Function executed during wp_loaded.
 * Loads geo and weather data to be processed later.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_loaded/
 *
 * @return void
 */
function gtm4wp_wp_loaded() {
	global $gtm4wp_options;

	/**
	 * GeoIP functionality can be disabled per user by setting the block_gtm4wp_geoip cookie to either "true", "on", "yes" or "1".
	 * Use this to integrate the feature with your consent manager tool. When user do not accept a specific cookie category, place
	 * this cookie and for that particular user the GeoIP (and weather API) feature will be not activated.
	 */
	$blocking_cookie = false;
	if ( isset( $_COOKIE['block_gtm4wp_geoip'] ) ) {
		$blocking_cookie = filter_var( wp_unslash( $_COOKIE['block_gtm4wp_geoip'] ), FILTER_VALIDATE_BOOLEAN );
	}

	if (
		( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] )
		&& ( ! $blocking_cookie )
	) {
		$client_ip = gtm4wp_get_user_ip( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ] );
		$geodata   = get_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ) );

		if ( false === $geodata ) {
			$gtm4wp_geodata = wp_remote_get(
				esc_url(
					'http://api.ipstack.com/' .
					rawurlencode( $client_ip ) .
					'?access_key=' . $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEOAPI ] .
					'&format=1'
				)
			);

			if ( is_array( $gtm4wp_geodata ) && ( 200 === $gtm4wp_geodata['response']['code'] ) ) {
				$gtm4wp_geodata = json_decode( $gtm4wp_geodata['body'] );

				if ( is_object( $gtm4wp_geodata ) ) {
					set_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ), $gtm4wp_geodata, 60 * 60 );

					$weatherdata = get_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ) );
					if ( false === $weatherdata && isset( $gtm4wp_geodata->latitude ) ) {

						$weatherdata = wp_remote_get(
							esc_url(
								'http://api.openweathermap.org/data/2.5/weather?appid=' .
								$gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI ] .
								'&lat=' . $gtm4wp_geodata->latitude .
								'&lon=' . $gtm4wp_geodata->longitude .
								'&units=' . ( 0 === $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHERUNITS ] ? 'metric' : 'imperial' )
							)
						);

						if ( is_array( $weatherdata ) && ( 200 === $weatherdata['response']['code'] ) ) {
							$weatherdata = json_decode( $weatherdata['body'] );

							if ( is_object( $weatherdata ) ) {
								set_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ), $weatherdata, 60 * 60 );
								setcookie( 'gtm4wp_last_weatherstatus', 'Weather data loaded.', 0, '/', '', false, true );
							}
						} else {
							if ( is_wp_error( $weatherdata ) ) {
								setcookie( 'gtm4wp_last_weatherstatus', 'Openweathermap.org request error: ' . $weatherdata->get_error_message(), 0, '/', '', false, true );
							} else {
								setcookie( 'gtm4wp_last_weatherstatus', 'Openweathermap.org returned status code: ' . $weatherdata['response']['code'], 0, '/', '', false, true );
							}
						}
					}
				}
			} else {
				if ( is_wp_error( $gtm4wp_geodata ) ) {
					setcookie( 'gtm4wp_last_weatherstatus', 'ipstack.com request error: ' . $gtm4wp_geodata->get_error_message(), 0, '/', '', false, true );
				} else {
					setcookie( 'gtm4wp_last_weatherstatus', 'ipstack.com returned status code: ' . $gtm4wp_geodata['response']['code'], 0, '/', '', false, true );
				}
			}
		}
	}
}

/**
 * Helper function to translate GTM container code placement value into readable string.
 *
 * @return string Readable form of a GTM container code placement option.
 */
function gtm4wp_get_container_placement_string() {
	global $gtm4wp_options;

	switch ( $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		case GTM4WP_PLACEMENT_FOOTER:
			return 'footer';

		case GTM4WP_PLACEMENT_BODYOPEN:
			return 'manual';

		case GTM4WP_PLACEMENT_BODYOPEN_AUTO:
			return 'automatic';

		case GTM4WP_PLACEMENT_OFF:
			return 'off';

		default:
			return 'unknown (' . $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] . ')';
	}
}

/**
 * Returns a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
 * Can be used to manually place the snippet next to the opening body tag if the installed template
 * does not support the wp_body_open hook.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_body_open/
 *
 * @return string The HTML code that includes the noscript/iframe part of the GTM container code.
 */
function gtm4wp_get_the_gtm_tag() {
	global $gtm4wp_options, $gtm4wp_container_code_written;

	$no_console_log = (bool) $gtm4wp_options[ GTM4WP_OPTION_NOCONSOLELOG ];

	$_gtm_tag = '
<!-- GTM Container placement set to ' . esc_html( gtm4wp_get_container_placement_string() ) . ' -->
<!-- Google Tag Manager (noscript) -->';

	if ( ( GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) && ( ! $no_console_log ) ) {
		$gtm4wp_container_code_written = true;

		$_gtm_tag .= '
' . gtm4wp_generate_script_opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';
	}

	if ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) && ( ! $gtm4wp_container_code_written ) ) {
		$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) );

		if ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) ) {
			$_gtm_env = '&gtm_auth=' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) . '&gtm_preview=' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) . '&gtm_cookies_win=x';
		} else {
			$_gtm_env = '';
		}

		// for PHP 7- compatibility.
		if ( ! defined( 'FILTER_FLAG_HOSTNAME' ) ) {
			define( 'FILTER_FLAG_HOSTNAME', 0 );
		}

		$_gtm_domain_test = ( '' === $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) ? 'www.googletagmanager.com' : strtolower( $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] );
		$_gtm_domain_name = filter_var( $_gtm_domain_test, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
		if ( false === $_gtm_domain_name ) {
			$_gtm_domain_name = 'www.googletagmanager.com';
		}

		foreach ( $_gtm_codes as $one_gtm_id ) {
			if ( preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
				$_gtm_tag .= '
				<noscript><iframe src="https://' . $_gtm_domain_name . '/ns.html?id=' . $one_gtm_id . $_gtm_env . '"
				height="0" width="0" style="display:none;visibility:hidden" aria-hidden="true"></iframe></noscript>';
			}
		}

		$_gtm_tag .= '
<!-- End Google Tag Manager (noscript) -->';

		$gtm4wp_container_code_written = true;
	}

	return $_gtm_tag;
}

add_filter(
	'safe_style_css',
	function( $styles ) {
		$styles[] = 'display';
		$styles[] = 'visibility';
		return $styles;
	}
);

/**
 * Outputs a HTML code that includes the noscript/iframe part of the Google Tag Manager container.
 * Can be used to manually place the snippet next to the opening body tag if the installed template
 * does not support the wp_body_open hook.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_body_open/
 *
 * @return void
 */
function gtm4wp_the_gtm_tag() {
	echo wp_kses(
		gtm4wp_get_the_gtm_tag(),
		array_merge(
			gtm4wp_get_sanitize_script_block_rules(),
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
 * Function executed during wp_enqueue_scripts.
 * Loads JavaScript files based on plugin options that are turned on to prevent bloating the frontend.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_enqueue_scripts/
 *
 * @return void
 */
function gtm4wp_enqueue_scripts() {
	global $gtm4wp_options, $gtp4wp_script_path;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WPCF7 ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WPCF7, true );
		wp_enqueue_script( 'gtm4wp-contact-form-7-tracker', $gtp4wp_script_path . 'gtm4wp-contact-form-7-tracker.js', array(), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_FORMMOVE ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_EVENTS_FORMMOVE, true );
		wp_enqueue_script( 'gtm4wp-form-move-tracker', $gtp4wp_script_path . 'gtm4wp-form-move-tracker.js', array(), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_YOUTUBE ] ) {
		require_once dirname( __FILE__ ) . '/../integration/youtube.php';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_VIMEO ] ) {
		require_once dirname( __FILE__ ) . '/../integration/vimeo.php';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_SOUNDCLOUD ] ) {
		require_once dirname( __FILE__ ) . '/../integration/soundcloud.php';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_ENABLED ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_SCROLLER_ENABLED, false );
		wp_enqueue_script( 'gtm4wp-scroll-tracking', $gtp4wp_script_path . 'analytics-talk-content-tracking.js', array(), GTM4WP_VERSION, $in_footer );
	}

	wp_register_script( 'gtm4wp-additional-datalayer-pushes', '', array(), GTM4WP_VERSION, true );
	wp_enqueue_script( 'gtm4wp-additional-datalayer-pushes' );

	gtm4wp_fire_additional_datalayer_pushes();
}

/**
 * Function executed during wp_footer.
 * Inserts the GTM noscript/iframe code if code placement set to Footer.
 * Outputs scripts that fire GTM tags on new user registration and login.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_footer/
 *
 * @return void
 */
function gtm4wp_wp_footer() {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	if ( GTM4WP_PLACEMENT_FOOTER === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		gtm4wp_the_gtm_tag();
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_USERLOGIN ] ) {
		$user_logged_in = array_key_exists( 'gtm4wp_user_logged_in', $_COOKIE ) ?
			filter_var( wp_unslash( $_COOKIE['gtm4wp_user_logged_in'] ), FILTER_VALIDATE_INT )
			: 0;

		if ( $user_logged_in ) {
			$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
	if ( window.' . esc_js( $gtm4wp_datalayer_name ) . ' ) {
		window.' . esc_js( $gtm4wp_datalayer_name ) . ".push({
			'event': 'gtm4wp.userLoggedIn'
		});
	}
</script>";

			echo htmlspecialchars_decode( //phpcs:ignore
				wp_kses(
					$script_tag,
					gtm4wp_get_sanitize_script_block_rules()
				)
			);

			unset( $_COOKIE['gtm4wp_user_logged_in'] );
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_NEWUSERREG ] ) {
		$user_registered = array_key_exists( 'gtm4wp_user_registered', $_COOKIE ) ?
			filter_var( wp_unslash( $_COOKIE['gtm4wp_user_registered'] ), FILTER_VALIDATE_INT )
			: 0;

		if ( $user_registered ) {
			$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
			if ( window.' . esc_js( $gtm4wp_datalayer_name ) . ' ) {
		window.' . esc_js( $gtm4wp_datalayer_name ) . ".push({
			'event': 'gtm4wp.userRegistered'
		});
	}
</script>";

			echo htmlspecialchars_decode( //phpcs:ignore
				wp_kses(
					$script_tag,
					gtm4wp_get_sanitize_script_block_rules()
				)
			);

			unset( $_COOKIE['gtm4wp_user_registered'] );
		}
	}
}

/**
 * Function executed during wp_body_open, genesis_before, generate_before_header,
 * elementor/page_templates/canvas/before_content, ct_before_builder or fl_before_builder.
 *
 * Outputs the noscript/iframe container code.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_body_open/
 * @see https://docs.generatepress.com/article/generate_before_header/
 *
 * @return void
 */
function gtm4wp_wp_body_open() {
	global $gtm4wp_options;

	if ( ( GTM4WP_PLACEMENT_BODYOPEN === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) || ( GTM4WP_PLACEMENT_BODYOPEN_AUTO === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) ) {
		gtm4wp_the_gtm_tag();
	}
}

/**
 * Function executed during wp_head with high priority.
 * Outputs some global JavaScript variables that needs to be accessable by other parts of the plugin.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_head/
 *
 * @param boolean $echo If set to true and AMP is currently generating the page content, the HTML is outputed immediately.
 * @return string|void Returns the HTML if the $echo parameter is set to false or when not AMP page generation is running.
 */
function gtm4wp_wp_header_top( $echo = true ) {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

	// the data layer initialization has to use 'var' instead of 'let' since 'let' can break related browser extension and 3rd party script.
	$_gtm_top_content = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
' . gtm4wp_generate_script_opening_tag() . '
	var gtm4wp_datalayer_name = "' . esc_js( $gtm4wp_datalayer_name ) . '";
	var ' . esc_js( $gtm4wp_datalayer_name ) . ' = ' . esc_js( $gtm4wp_datalayer_name ) . ' || [];';

	// Load in the global variables from gtm4wp_add_global_vars_array / GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY filter.
	$added_global_js_vars = (array) apply_filters( GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY, array() );
	foreach ( $added_global_js_vars as $js_var_name => $js_var_value ) {
		if ( is_string( $js_var_value ) ) {
			$js_var_value = "'" . esc_js( $js_var_value ) . "'";
		}

		if ( is_bool( $js_var_value ) || empty( $js_var_value ) ) {
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

	if ( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_ENABLED ] ) {
		$_gtm_top_content .= '

	const gtm4wp_scrollerscript_debugmode         = ' . ( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_DEBUGMODE ] ? 'true' : 'false' ) . ';
	const gtm4wp_scrollerscript_callbacktime      = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_CALLBACKTIME ] . ';
	const gtm4wp_scrollerscript_readerlocation    = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_DISTANCE ] . ';
	const gtm4wp_scrollerscript_contentelementid  = "' . esc_js( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_CONTENTID ] ) . '";
	const gtm4wp_scrollerscript_scannertime       = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_READERTIME ] . ';';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR ] ) {
		$_gtm_top_content .= '
	var CookieLawInfo_Accept_Callback = (function() {
		var gtm4wp_original_cli_callback = CookieLawInfo_Accept_Callback;
	
		return function() {
			if ( !window.CLI.consent ) {
				return false;
			}
		
			window.' . esc_js( $gtm4wp_datalayer_name ) . ' = window.' . esc_js( $gtm4wp_datalayer_name ) . ' || [];
			window.' . esc_js( $gtm4wp_datalayer_name ) . '.push({
				"event": "cookie_consent_update",
				"consent_data": window.CLI.consent
			});
		
			for(var i in window.CLI.consent) {
				window.' . esc_js( $gtm4wp_datalayer_name ) . '.push({
					"event": "cookie_consent_" + i
				});
			}
	
			if ( "function" == typeof gtm4wp_original_cli_callback ) {
				gtm4wp_original_cli_callback();
			}
		}
	})();';
	}

	$_gtm_top_content .= '
</script>
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';

	if ( ! gtm4wp_amp_running() ) {
		if ( $echo ) {
			echo wp_kses(
				$_gtm_top_content,
				array(
					'script' => array(
						'data-cfasync'            => array(),
						'data-pagespeed-no-defer' => array(),
						'data-cookieconsent'      => array(),
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
 * Returns the value of the consent mode flag.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_head/
 *
 * @param string $flag The flag to be set.
 * @return string The value of the flag (granted or denied).
 */
function gtm4wp_get_consent_mode_flag( $flag ) {
	global $gtm4wp_options;

	$flag_value = false;

	if ( in_array(
		$flag,
		array(
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY,
		),
		true
	) ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_CONSENTMODE ] ) {
			$flag_value = (bool) $gtm4wp_options[ $flag ];

			/**
			 * Filter to overwrite the value of the consent mode flag.
			 * Should use boolean true or false. Returned value will be converted to
			 * string "granted" or "denied" afterwards.
			 *
			 * @since 1.22
			 *
			 * @param boolean $flag_value The value of the flag (boolean true or false).
			 * @param string $flag The flag to be set.
			 *
			 * @return boolean The updated value of the flag (boolean true or false).
			 */
			$flag_value = apply_filters( GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG, $flag_value, $flag );
		}
	}

	return ( $flag_value ? 'granted' : 'denied' );
}

/**
 * Function executed during wp_head.
 * Outputs the main Google Tag Manager container code.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_head/
 *
 * @param boolean $echo If set to true and AMP is currently generating the page content, the HTML is outputed immediately.
 * @return string|void Returns the HTML if the $echo parameter is set to false or when not AMP page generation is running.
 */
function gtm4wp_wp_header_begin( $echo = true ) {
	global $gtm4wp_datalayer_name, $gtm4wp_datalayer_data, $gtm4wp_options;

	$no_console_log = (bool) $gtm4wp_options[ GTM4WP_OPTION_NOCONSOLELOG ];

	$script_tag = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<!-- GTM Container placement set to ' . esc_html( gtm4wp_get_container_placement_string() ) . ' -->
' . gtm4wp_generate_script_opening_tag();

	if ( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) {
		$gtm4wp_datalayer_data = array();
		$gtm4wp_datalayer_data = (array) apply_filters( GTM4WP_WPFILTER_COMPILE_DATALAYER, $gtm4wp_datalayer_data );

		$script_tag .= '
	var dataLayer_content = ' . wp_json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK ) . ';';

		$script_tag .= '
	' . esc_js( $gtm4wp_datalayer_name ) . '.push( dataLayer_content );';
	}

	$script_tag .= '
</script>';

	echo htmlspecialchars_decode( //phpcs:ignore
		wp_kses(
			$script_tag,
			gtm4wp_get_sanitize_script_block_rules()
		)
	);

	do_action( GTM4WP_WPACTION_AFTER_DATALAYER );

	$output_container_code = true;
	if ( GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		$output_container_code = false;
	}

	if ( ! $no_console_log && ! $output_container_code ) {
		$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';

		echo htmlspecialchars_decode( //phpcs:ignore
			wp_kses(
				$script_tag,
				gtm4wp_get_sanitize_script_block_rules()
			)
		);
	}

	$disabled_roles = explode( ',', (string) $gtm4wp_options[ GTM4WP_OPTION_NOGTMFORLOGGEDIN ] );
	if ( count( $disabled_roles ) > 0 ) {
		$current_user = wp_get_current_user();
		foreach ( $current_user->roles as $user_role ) {
			if ( in_array( $user_role, $disabled_roles, true ) ) {
				$output_container_code = false;

				if ( ! $no_console_log ) {
					$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . esc_js( $user_role ) . ' !!!");
	console.warn && console.warn("[GTM4WP] Logout or login with a user having a different user role!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container code is omitted !!!");
</script>';

					echo htmlspecialchars_decode( //phpcs:ignore
						wp_kses(
							$script_tag,
							gtm4wp_get_sanitize_script_block_rules()
						)
					);
				}

				break;
			}
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_CONSENTMODE ] ) {
		$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
		if (typeof gtag == "undefined") {
			function gtag(){dataLayer.push(arguments);}
		}

		gtag("consent", "default", {
			"analytics_storage": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS ) . '",
			"ad_storage": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS ) . '",
			"ad_user_data": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA ) . '",
			"ad_personalization": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO ) . '",
			"functionality_storage": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC ) . '",
			"security_storage": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY ) . '",
			"personalization_storage": "' . gtm4wp_get_consent_mode_flag( GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO ) . '",
		});
</script>';

		echo htmlspecialchars_decode( //phpcs:ignore
			wp_kses(
				$script_tag,
				gtm4wp_get_sanitize_script_block_rules()
			)
		);
	}

	if ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) && $output_container_code ) {
		$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) );

		$_gtm_tag = '';
		foreach ( $_gtm_codes as $one_gtm_id ) {
			if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
				continue;
			}

			// for PHP 7- compatibility.
			if ( ! defined( 'FILTER_FLAG_HOSTNAME' ) ) {
				define( 'FILTER_FLAG_HOSTNAME', 0 );
			}

			$_gtm_domain_test = ( '' === $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) ? 'www.googletagmanager.com' : strtolower( $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] );
			$_gtm_domain_name = filter_var( $_gtm_domain_test, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
			if ( false === $_gtm_domain_name ) {
				$_gtm_domain_name = 'www.googletagmanager.com';
			}

			$_gtm_domain_path           = ( '' === $gtm4wp_options[ GTM4WP_OPTION_GTMCUSTOMPATH ] ) ? 'gtm.js' : $gtm4wp_options[ GTM4WP_OPTION_GTMCUSTOMPATH ];
			$_gtm_custom_path_has_error = (bool) preg_match( '/^[a-zA-Z0-9\.\-\_\/]+$/', $_gtm_domain_path );
			if ( false === $_gtm_custom_path_has_error ) {
				$_gtm_domain_path = 'gtm.js';
			}

			$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':
new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=
\'//' . esc_js( $_gtm_domain_name ) . '/' . esc_js( $_gtm_domain_path ) . '?id=\'+i+dl' .
			( ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) ) ? "+'&gtm_auth=" . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) . '&gtm_preview=' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) . "&gtm_cookies_win=x'" : '' ) . ';f.parentNode.insertBefore(j,f);
})(window,document,\'script\',\'' . esc_js( $gtm4wp_datalayer_name ) . '\',\'' . esc_js( $one_gtm_id ) . '\');
</script>';

			echo htmlspecialchars_decode( //phpcs:ignore
				wp_kses(
					$script_tag,
					gtm4wp_get_sanitize_script_block_rules()
				)
			);
		} // end foreach $_gtm_codes
	} // end if container code output possible

	do_action( GTM4WP_WPACTION_AFTER_CONTAINER_CODE );

	echo '
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';
}

/**
 * Function executed during wp_login.
 * Sets a cookie so that the next page load can fire a GTM event after a user has been logged in.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_login/
 *
 * @return void
 */
function gtm4wp_wp_login() {
	setcookie(
		'gtm4wp_user_logged_in',
		'1',
		0,
		'/',
		'',
		( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
		true
	);
}

/**
 * Function executed during user_register.
 * Sets a cookie so that the next page load can fire a GTM event after a new user has been registered.
 *
 * @see https://developer.wordpress.org/reference/hooks/user_register/
 *
 * @return void
 */
function gtm4wp_user_register() {
	setcookie(
		'gtm4wp_user_registered',
		'1',
		0,
		'/',
		'',
		( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
		true
	);
}

/**
 * Function executed during rocket_excluded_inline_js_content if WP-Rocket is active.
 * Excludes the dataLayer variable and gtm4wp* variables from being combined into the minified JS file.
 *
 * @param array $pattern Patterns to match in inline JS content.
 * @return array Patterns to match in inline JS content extended with additional items.
 */
function gtm4wp_rocket_excluded_inline_js_content( $pattern ) {
	$pattern[] = 'dataLayer';
	$pattern[] = 'gtm4wp';

	return $pattern;
}

/**
 * Function executed during init.
 * Removes the cookies set to fire GTM events for new user registration and user login.
 *
 * @see https://developer.wordpress.org/reference/hooks/init/
 *
 * @return void
 */
function gtm4wp_wp_init() {
	if ( array_key_exists( 'gtm4wp_user_logged_in', $_COOKIE ) ) {
		setcookie(
			'gtm4wp_user_logged_in',
			'',
			-10000,
			'/',
			'',
			( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
			true
		);
	}

	if ( array_key_exists( 'gtm4wp_user_registered', $_COOKIE ) ) {
		setcookie(
			'gtm4wp_user_registered',
			'',
			-10000,
			'/',
			'',
			( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
			true
		);
	}
}

/**
 * Outputs the necessary JavaScript codes to fire additional data layer events just after the main GTM container code.
 *
 * @return void
 */
function gtm4wp_fire_additional_datalayer_pushes() {
	global $gtm4wp_options, $gtm4wp_datalayer_name, $gtm4wp_additional_datalayer_pushes;

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

	foreach ( $gtm4wp_additional_datalayer_pushes as $one_event ) {
		$datalayer_push_code = '';

		if ( array_key_exists( 'js_before', $one_event ) ) {
			$datalayer_push_code .= $one_event['js_before'];
		}

		if ( array_key_exists( 'datalayer_object', $one_event ) ) {
			$datalayer_push_code .= '
	' . esc_js( $gtm4wp_datalayer_name ) . '.push(' . wp_json_encode( $one_event['datalayer_object'], JSON_UNESCAPED_UNICODE ) . ');';
		}

		if ( array_key_exists( 'js_after', $one_event ) ) {
			$datalayer_push_code .= $one_event['js_after'];
		}

		wp_add_inline_script( 'gtm4wp-additional-datalayer-pushes', $datalayer_push_code, 'after' );
	}

	// Reset array so that additional data can be added and this function can be re-run without double running code.
	$gtm4wp_additional_datalayer_pushes = array();
}

/**
 * Queuenes a data layer event to be fired after the main GTM container code.
 *
 * @param string $event_name The name of the GTM event.
 * @param array  $event_data Additional event parameters to be passed after the event. Optional.
 * @param string $js_before  Inline JS code to be added before the dataLayer.push() line.
 * @param string $js_after   Inline JS code to be added after the dataLayer.push() line.
 * @return bool Returns true when data layer event was successfully queued to fire after the main GTM container code. Returns false when function parameter types are invalid.
 */
function gtm4wp_datalayer_push( $event_name, $event_data = array(), $js_before = '', $js_after = '' ) {
	global $gtm4wp_additional_datalayer_pushes;

	if ( ! is_string( $event_name ) ) {
		return false;
	}

	if ( ! is_array( $event_data ) ) {
		return false;
	}

	$gtm4wp_additional_datalayer_pushes[] = array(
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

add_action( 'wp_enqueue_scripts', 'gtm4wp_enqueue_scripts' );
$gtm4wp_header_begin_prior = 10;
if ( isset( $GLOBALS['gtm4wp_options'] ) && $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_LOADEARLY ] ) {
	$gtm4wp_header_begin_prior = 2;
}
add_action( 'wp_head', 'gtm4wp_wp_header_begin', $gtm4wp_header_begin_prior, 0 );
add_action( 'wp_head', 'gtm4wp_wp_header_top', 1, 0 );
add_action( 'wp_footer', 'gtm4wp_wp_footer' );
add_action( 'wp_loaded', 'gtm4wp_wp_loaded' );
add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, 'gtm4wp_add_basic_datalayer_data' );
add_action( 'init', 'gtm4wp_wp_init' );

// to be able to easily migrate from other Google Tag Manager plugins.
add_action( 'body_open', 'gtm4wp_wp_body_open' );

// compatibility with existing themes that natively support code injection after opening body tag.
add_action( 'genesis_before', 'gtm4wp_wp_body_open' ); // Genisis theme.
add_action( 'generate_before_header', 'gtm4wp_wp_body_open', 0 ); // GeneratePress theme.
add_action( 'elementor/page_templates/canvas/before_content', 'gtm4wp_wp_body_open' ); // Elementor.
add_action( 'ct_before_builder', 'gtm4wp_wp_body_open', 0 ); // Oxygen Builder.
add_action( 'fl_before_builder', 'gtm4wp_wp_body_open', 0 ); // Beaver Builder Theme.

// standard WP theme support for body open tags.
add_action( 'wp_body_open', 'gtm4wp_wp_body_open' );

add_filter( 'rocket_excluded_inline_js_content', 'gtm4wp_rocket_excluded_inline_js_content' ); // WP Rocket.

// only activate WooCommerce integration for minimum supported WooCommerce version.
if (
	isset( $GLOBALS['gtm4wp_options'] )
	&& $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ]
	&& isset( $GLOBALS['woocommerce'] )
	&& version_compare( WC()->version, '5.0', '>=' )
) {
	require_once dirname( __FILE__ ) . '/../integration/woocommerce.php';
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_USERLOGIN ] ) ) {
	add_action( 'wp_login', 'gtm4wp_wp_login' );
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_NEWUSERREG ] ) ) {
	add_action( 'user_register', 'gtm4wp_user_register' );
}
