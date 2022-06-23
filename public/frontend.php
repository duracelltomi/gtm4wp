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
 * Constant used by GTM4WP as a WordPress filter to allow itself and 3rd party plugins
 * to add their own code after the first data layer push command.
 *
 * @deprecated 1.16 Use GTM4WP_WPACTION_AFTER_DATALAYER/gtm4wp_output_after_datalayer instead.
 */
define( 'GTM4WP_WPFILTER_AFTER_DATALAYER', 'gtm4wp_after_datalayer' );

/**
 * Constant used by GTM4WP as a WordPress action to allow itself and 3rd party plugins
 * to add their own code after the first data layer push command.
 */
define( 'GTM4WP_WPACTION_AFTER_DATALAYER', 'gtm4wp_output_after_datalayer' );

/**
 * Constant that 3rd party plugins can use as a WordPress filter to alter the generated
 * Google Tag Manager container code (both the regular and iframe/noscript code).
 *
 * @deprecated 1.16 Instead of manipulating the GTM container code through this filter,
 *                  turn off the container code in plugin options and add your modified code manually.
 */
define( 'GTM4WP_WPFILTER_GETTHEGTMTAG', 'gtm4wp_get_the_gtm_tag' );

// TODO: change this hook to use an associative array instead of full script content.
/**
 * Constant that GTM4WP itself and 3rd party plugins can use to add JavaScript
 * variable declarations above the first data layer push command.
 *
 * This hook was used by allowing any HTML/JS content to be added in hook functions.
 * The new GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY / gtm4wp_add_global_vars_array is now
 * required which is a safer way to add code.
 *
 * @deprecated 1.16
 */
define( 'GTM4WP_WPFILTER_ADDGLOBALVARS', 'gtm4wp_add_global_vars' );

/**
 * Constant that GTM4WP itself and 3rd party plugins can use to add JavaScript
 * variable declarations above the first data layer push command.
 */
define( 'GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY', 'gtm4wp_add_global_vars_array' );

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
if ( empty( $GLOBALS['gtm4wp_options'] ) || ( '' === $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ] ) ) {
	$GLOBALS['gtm4wp_datalayer_name'] = 'dataLayer';
} else {
	$GLOBALS['gtm4wp_datalayer_name'] = $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ];
}

/**
 * Stores the data layer content to give access to this data
 * for the AMP integration.
 *
 * @var string
 */
$GLOBALS['gtm4wp_datalayer_data'] = array();

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
 * Original copyright:
 * By Grant Burton @ BURTONTECH.COM
 *
 * Code improved by Thomas Geiger
 */
function gtm4wp_get_user_ip() {
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
				return $ip;
			}
		}
	}

	$possible_ip_variables = array(
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED',
		'REMOTE_ADDR',
	);

	foreach ( $possible_ip_variables as $one_ip_variable ) {
		if ( ! empty( $_SERVER[ $one_ip_variable ] ) ) {
			$ip = filter_var( wp_unslash( $_SERVER[ $one_ip_variable ] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( false !== $ip ) {
				return $ip;
			}
		}
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
 * Populate main data layer outputted in the <head> before the GTM container snippet.
 *
 * @param array $data_layer Array of key-value pairs that will be outputed as a JSON object into the dataLayer global JavaScript variable.
 * @return array
 */
function gtm4wp_add_basic_datalayer_data( $data_layer ) {
	global $wp_query, $gtm4wp_options, $gtm4wp_entity_ids;

	if ( $gtm4wp_options[ GTM4WP_OPTION_DONOTTRACK ] ) {
		if ( ! empty( $_SERVER['HTTP_DNT'] ) ) {
			$data_layer['visitorDoNotTrack'] = (int) ( sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) );
		} else {
			$data_layer['visitorDoNotTrack'] = 0;
		}
	}

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
		if ( is_user_logged_in() ) {
			$data_layer['visitorLoginState'] = 'logged-in';
		} else {
			$data_layer['visitorLoginState'] = 'logged-out';
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
		$data_layer['visitorIP'] = esc_js( gtm4wp_get_user_ip() );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTITLE ] ) {
		$data_layer['pageTitle'] = wp_strip_all_tags( wp_title( '|', false, 'right' ) );
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

		$data_layer['siteSearchTerm'] = get_search_query();
		$data_layer['siteSearchFrom'] = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_url_parts = explode( '?', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
			if ( count( $referer_url_parts ) > 1 ) {
				$data_layer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
			} else {
				$data_layer['siteSearchFrom'] = $referer_url_parts[0];
			}
		}
		$data_layer['siteSearchResults'] = $wp_query->post_count;
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

		$client_ip = gtm4wp_get_user_ip();

		if ( '' !== $client_ip ) {
			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] ) {
				$weatherdata = get_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ) );

				if ( false !== $weatherdata ) {
					$data_layer['weatherCategory']        = $weatherdata->weather[0]->main;
					$data_layer['weatherDescription']     = $weatherdata->weather[0]->description;
					$data_layer['weatherTemp']            = $weatherdata->main->temp;
					$data_layer['weatherPressure']        = $weatherdata->main->pressure;
					$data_layer['weatherWindSpeed']       = $weatherdata->wind->speed;
					$data_layer['weatherWindDeg']         = ( isset( $weatherdata->wind->deg ) ? $weatherdata->wind->deg : '' );
					$data_layer['weatherFullWeatherData'] = $weatherdata;
					$data_layer['weatherDataStatus']      = 'Read from cache';
				} else {
					$data_layer['weatherDataStatus'] = 'No weather data in cache (' . esc_attr( $client_ip ) . ')';
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

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
		$client_ip = gtm4wp_get_user_ip();
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

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

	$_gtm_tag = '
<!-- GTM Container placement set to ' . esc_html( gtm4wp_get_container_placement_string() ) . ' -->
<!-- Google Tag Manager (noscript) -->';

	if ( GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		$gtm4wp_container_code_written = true;

		$_gtm_tag .= '
<script' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
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

		$_gtm_domain_name = 'www.googletagmanager.com';
		if (
			( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) &&
			( preg_match( '/^[a-z0-9\.:]+$/', strtolower( $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) ) )
		) {
			$_gtm_domain_name = $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ];
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

		$_gtm_tag                      = apply_filters( GTM4WP_WPFILTER_GETTHEGTMTAG, $_gtm_tag );
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
	global $gtm4wp_options, $gtp4wp_plugin_url;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WPCF7 ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WPCF7, true );
		wp_enqueue_script( 'gtm4wp-contact-form-7-tracker', $gtp4wp_plugin_url . 'js/gtm4wp-contact-form-7-tracker.js', array(), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_FORMMOVE ] ) {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_EVENTS_FORMMOVE, true );
		wp_enqueue_script( 'gtm4wp-form-move-tracker', $gtp4wp_plugin_url . 'js/gtm4wp-form-move-tracker.js', array(), GTM4WP_VERSION, $in_footer );
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
		wp_enqueue_script( 'gtm4wp-scroll-tracking', $gtp4wp_plugin_url . 'js/analytics-talk-content-tracking.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}
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

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_NEWUSERREG ] ) {
		$user_logged_in = array_key_exists( 'gtm4wp_user_logged_in', $_COOKIE ) ?
			filter_var( wp_unslash( $_COOKIE['gtm4wp_user_logged_in'] ), FILTER_VALIDATE_INT )
			: 0;

		if ( $user_logged_in ) {
			echo '
<script' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
	if ( window.' . esc_js( $gtm4wp_datalayer_name ) . ' ) {
		window.' . esc_js( $gtm4wp_datalayer_name ) . ".push({
			'event': 'gtm4wp.userLoggedIn'
		});
	}
</script>";

			unset( $_COOKIE['gtm4wp_user_logged_in'] );
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_USERLOGIN ] ) {
		$user_registered = array_key_exists( 'gtm4wp_user_registered', $_COOKIE ) ?
			filter_var( wp_unslash( $_COOKIE['gtm4wp_user_registered'] ), FILTER_VALIDATE_INT )
			: 0;

		if ( $user_registered ) {
			echo '
<script' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
	if ( window.' . esc_js( $gtm4wp_datalayer_name ) . ' ) {
		window.' . esc_js( $gtm4wp_datalayer_name ) . ".push({
			'event': 'gtm4wp.userRegistered'
		});
	}
</script>";

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
<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? ' type="text/javascript"' : '' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
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
 * Outputs the main Google Tag Manager container code and if WooCommerce is active, it removes the
 * purchase data from the data layer if the order ID has been already tracked before and
 * double tracking prevention option is active.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_head/
 *
 * @param boolean $echo If set to true and AMP is currently generating the page content, the HTML is outputed immediately.
 * @return string|void Returns the HTML if the $echo parameter is set to false or when not AMP page generation is running.
 */
function gtm4wp_wp_header_begin( $echo = true ) {
	global $gtm4wp_datalayer_name, $gtm4wp_datalayer_data, $gtm4wp_options, $woocommerce;

	$has_html5_support    = current_theme_supports( 'html5' );
	$add_cookiebot_ignore = (bool) $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_COOKIEBOT ];

	echo '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<!-- GTM Container placement set to ' . esc_html( gtm4wp_get_container_placement_string() ) . ' -->
<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? ' type="text/javascript"' : '' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>';

	if ( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) {
		$gtm4wp_datalayer_data = array();
		$gtm4wp_datalayer_data = (array) apply_filters( GTM4WP_WPFILTER_COMPILE_DATALAYER, $gtm4wp_datalayer_data );

		echo '
	var dataLayer_content = ' . wp_json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE ) . ';';

		// fire WooCommerce order double tracking protection only if WooCommerce is active and user is on the order received page.
		if ( isset( $gtm4wp_options ) && ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] || $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) && isset( $woocommerce ) && is_order_received_page() ) {
			echo '
	// if dataLayer contains ecommerce purchase data, check whether it has been already tracked
	if ( dataLayer_content.transactionId || ( dataLayer_content.ecommerce && dataLayer_content.ecommerce.purchase ) ) {
		// read order id already tracked from cookies
		var gtm4wp_orderid_tracked = "";

		if ( !window.localStorage ) {
			var gtm4wp_cookie = "; " + document.cookie;
			var gtm4wp_cookie_parts = gtm4wp_cookie.split( "; gtm4wp_orderid_tracked=" );
			if ( gtm4wp_cookie_parts.length == 2 ) {
				gtm4wp_orderid_tracked = gtm4wp_cookie_parts.pop().split(";").shift();
			}
		} else {
			gtm4wp_orderid_tracked = window.localStorage.getItem( "gtm4wp_orderid_tracked" );
		}

		// check enhanced ecommerce
		if ( dataLayer_content.ecommerce && dataLayer_content.ecommerce.purchase ) {
			if ( gtm4wp_orderid_tracked && ( dataLayer_content.ecommerce.purchase.actionField.id == gtm4wp_orderid_tracked ) ) {
				delete dataLayer_content.ecommerce.purchase;
			} else {
				gtm4wp_orderid_tracked = dataLayer_content.ecommerce.purchase.actionField.id;
			}
		}

		// check app+web ecommerce
		if ( dataLayer_content.ecommerce && dataLayer_content.ecommerce.items ) {
			if ( gtm4wp_orderid_tracked && ( dataLayer_content.ecommerce.transaction_id == gtm4wp_orderid_tracked ) ) {
				delete dataLayer_content.ecommerce.affiliation;
				delete dataLayer_content.ecommerce.value;
				delete dataLayer_content.ecommerce.currency;
				delete dataLayer_content.ecommerce.tax;
				delete dataLayer_content.ecommerce.shipping;
				delete dataLayer_content.ecommerce.transaction_id;

				delete dataLayer_content.ecommerce.items;
			} else {
				gtm4wp_orderid_tracked = dataLayer_content.ecommerce.purchase.actionField.id;
			}
		}

		// check standard ecommerce
		if ( dataLayer_content.transactionId ) {
			if ( gtm4wp_orderid_tracked && ( dataLayer_content.transactionId == gtm4wp_orderid_tracked ) ) {
				delete dataLayer_content.transactionId;
				delete dataLayer_content.transactionDate;
				delete dataLayer_content.transactionType;
				delete dataLayer_content.transactionAffiliation;
				delete dataLayer_content.transactionTotal;
				delete dataLayer_content.transactionShipping;
				delete dataLayer_content.transactionTax;
				delete dataLayer_content.transactionPaymentType;
				delete dataLayer_content.transactionCurrency;
				delete dataLayer_content.transactionShippingMethod;
				delete dataLayer_content.transactionPromoCode;
				delete dataLayer_content.transactionProducts;
			} else {
				gtm4wp_orderid_tracked = dataLayer_content.transactionId;
			}
		}

		if ( gtm4wp_orderid_tracked ) {
			if ( !window.localStorage ) {
				var gtm4wp_orderid_cookie_expire = new Date();
				gtm4wp_orderid_cookie_expire.setTime( gtm4wp_orderid_cookie_expire.getTime() + (365*24*60*60*1000) );
				var gtm4wp_orderid_cookie_expires_part = "expires=" + gtm4wp_orderid_cookie_expire.toUTCString();
				document.cookie = "gtm4wp_orderid_tracked=" + gtm4wp_orderid_tracked + ";" + gtm4wp_orderid_cookie_expires_part + ";path=/";
			} else {
				window.localStorage.setItem( "gtm4wp_orderid_tracked", gtm4wp_orderid_tracked );
			}
		}

	}';
		}

		echo '
	' . esc_js( $gtm4wp_datalayer_name ) . '.push( dataLayer_content );';
	}

	echo '
</script>';

	do_action( GTM4WP_WPACTION_AFTER_DATALAYER );

	$output_container_code = true;
	if ( GTM4WP_PLACEMENT_OFF === $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		$output_container_code = false;

		echo '
<script' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code placement set to OFF !!!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container must be loaded using custom coding !!!");
</script>';
	}

	$disabled_roles = explode( ',', (string) $gtm4wp_options[ GTM4WP_OPTION_NOGTMFORLOGGEDIN ] );
	if ( count( $disabled_roles ) > 0 ) {
		$current_user = wp_get_current_user();
		foreach ( $current_user->roles as $user_role ) {
			if ( in_array( $user_role, $disabled_roles, true ) ) {
				$output_container_code = false;

				echo '
<script' . ( $has_html5_support ? '' : ' type="text/javascript"' ) . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
	console.warn && console.warn("[GTM4WP] Google Tag Manager container code was disabled for this user role: ' . esc_js( $user_role ) . ' !!!");
	console.warn && console.warn("[GTM4WP] Logout or login with a user having a different user role!");
	console.warn && console.warn("[GTM4WP] Data layer codes are active but GTM container code is omitted !!!");
</script>';

				break;
			}
		}
	}

	if ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) && $output_container_code ) {
		$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) );

		$_gtm_tag = '';
		foreach ( $_gtm_codes as $one_gtm_id ) {
			if ( ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id ) ) {
				continue;
			}

			$_gtm_domain_name = 'www.googletagmanager.com';
			if (
				( '' !== $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) &&
				( preg_match( '/^[a-z0-9\.:]+$/', strtolower( $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ] ) ) )
			) {
				$_gtm_domain_name = $gtm4wp_options[ GTM4WP_OPTION_GTMDOMAIN ];
			}

			echo '
<script data-cfasync="false"' . ( $add_cookiebot_ignore ? ' data-cookieconsent="ignore"' : '' ) . '>
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':
new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=
\'//' . esc_js( $_gtm_domain_name ) . '/gtm.\'+\'js?id=\'+i+dl' .
			( ( ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' !== $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) ) ? "+'&gtm_auth=" . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) . '&gtm_preview=' . esc_attr( $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) . "&gtm_cookies_win=x'" : '' ) . ';f.parentNode.insertBefore(j,f);
})(window,document,\'script\',\'' . esc_js( $gtm4wp_datalayer_name ) . '\',\'' . esc_js( $one_gtm_id ) . '\');
</script>';
		}

		echo '
<!-- End Google Tag Manager -->';
	}

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
	isset( $GLOBALS['gtm4wp_options'] ) &&
	(
		$GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] ||
		$GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ]
	) &&
	isset( $GLOBALS['woocommerce'] ) &&
	version_compare( WC()->version, '3.2', '>=' )
) {
	require_once dirname( __FILE__ ) . '/../integration/woocommerce.php';
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( '' !== $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] ) ) {
	require_once dirname( __FILE__ ) . '/../integration/google-optimize.php';
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_USERLOGIN ] ) ) {
	add_action( 'wp_login', 'gtm4wp_wp_login' );
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_NEWUSERREG ] ) ) {
	add_action( 'user_register', 'gtm4wp_user_register' );
}
