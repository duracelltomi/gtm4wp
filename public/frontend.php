<?php
define( 'GTM4WP_WPFILTER_COMPILE_DATALAYER', 'gtm4wp_compile_datalayer' );
define( 'GTM4WP_WPFILTER_COMPILE_REMARKTING', 'gtm4wp_compile_remarkering' );
define( 'GTM4WP_WPFILTER_AFTER_DATALAYER', 'gtm4wp_after_datalayer' );
define( 'GTM4WP_WPFILTER_GETTHEGTMTAG', 'gtm4wp_get_the_gtm_tag' );
define( 'GTM4WP_WPACTION_ADDGLOBALVARS', 'gtm4wp_add_global_vars' );

$GLOBALS['gtm4wp_container_code_written'] = false;

// check for empty is needed to prevent error in WP CLI
// bugfix by Patrick Holberg Hesselberg
if ( empty( $GLOBALS['gtm4wp_options'] ) || ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ] == '' ) ) {
	$GLOBALS['gtm4wp_datalayer_name'] = 'dataLayer';
} else {
	$GLOBALS['gtm4wp_datalayer_name'] = $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_DATALAYER_NAME ];
}

// Setting Global Variable to Store JSON based Datalayer for Intergrations
$GLOBALS['gtm4wp_datalayer_json']       = '';
$GLOBALS['gtm4wp_datalayer_globalvars'] = '';

// Moving include to top due to hierarchy of includes
if ( isset( $GLOBALS['gtm4wp_options'] ) && ( '' != $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_AMPID ] ) ) {
	require_once dirname( __FILE__ ) . '/../integration/amp.php';
}
if ( ! function_exists( 'gtm4wp_amp_running' ) ) {
	function gtm4wp_amp_running() {
		return false;
	}
}

/**
 * Converts bool false to string false for JS
 *
 * @author Vincent Koc <https://github.com/koconder/>
 * @return mixed Returs object or string false
 */
function gtm4wp_escjs_boolean( $obj ) {
	if ( empty( $obj ) || is_null( $obj ) || ! $obj ) {
		return 'false';
	} else {
		return $obj;
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
		foreach ( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) as $ip ) {
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
		'REMOTE_ADDR'
	);

	foreach( $possible_ip_variables as $one_ip_variable ) {
		if (
		  ! empty( $_SERVER[ $one_ip_variable ] )
		  && ( filter_var( $_SERVER[ $one_ip_variable ], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false )
		) {
			return $_SERVER[ $one_ip_variable ];
		}
	}

	return '';
}

if ( ! function_exists( 'getallheaders' ) ) {
	function getallheaders() {
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
				$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
			}
		}

		return $headers;
	}
}

function gtm4wp_add_basic_datalayer_data( $dataLayer ) {
	global $wp_query, $gtm4wp_options, $gtm4wp_entity_ids;

	if ( $gtm4wp_options[ GTM4WP_OPTION_DONOTTRACK ] ) {
		if ( ! empty( $_SERVER['HTTP_DNT'] ) ) {
			$dataLayer['visitorDoNotTrack'] = (int) ( $_SERVER['HTTP_DNT'] );
		} else {
			$dataLayer['visitorDoNotTrack'] = 0;
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_SITEID ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_SITENAME ] ) {
		$dataLayer['siteID']   = 0;
		$dataLayer['siteName'] = '';

		if ( function_exists( 'get_blog_details' ) ) {
			$gtm4wp_blogdetails = get_blog_details();

			$dataLayer['siteID']   = $gtm4wp_blogdetails->blog_id;
			$dataLayer['siteName'] = $gtm4wp_blogdetails->blogname;
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_LOGGEDIN ] ) {
		if ( is_user_logged_in() ) {
			$dataLayer['visitorLoginState'] = 'logged-in';
		} else {
			$dataLayer['visitorLoginState'] = 'logged-out';
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERROLE ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USEREMAIL ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERREGDATE ] ) {
		$current_user = wp_get_current_user();

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERROLE ] ) {
			$dataLayer['visitorType'] = ( empty( $current_user->roles[0] ) ? 'visitor-logged-out' : $current_user->roles[0] );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USEREMAIL ] ) {
			$dataLayer['visitorEmail'] = ( empty( $current_user->user_email ) ? '' : $current_user->user_email );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERREGDATE ] ) {
			$dataLayer['visitorRegistrationDate'] = ( empty( $current_user->user_registered ) ? '' : strtotime( $current_user->user_registered ) );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERNAME ] ) {
			$dataLayer['visitorUsername'] = ( empty( $current_user->user_login ) ? '' : $current_user->user_login );
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_USERID ] ) {
		$_gtm4wp_userid = get_current_user_id();
		if ( $_gtm4wp_userid > 0 ) {
			$dataLayer['visitorId'] = $_gtm4wp_userid;
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_VISITOR_IP ] ) {
		$dataLayer['visitorIP'] = esc_js( gtm4wp_get_user_ip() );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTITLE ] ) {
		$dataLayer['pageTitle'] = strip_tags( wp_title( '|', false, 'right' ) );
	}

	if ( is_singular() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
			$dataLayer['pagePostType']  = get_post_type();
			$dataLayer['pagePostType2'] = 'single-' . get_post_type();
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_CATEGORIES ] ) {
			$_post_cats = get_the_category();
			if ( $_post_cats ) {
				$dataLayer['pageCategory'] = array();
				foreach ( $_post_cats as $_one_cat ) {
					$dataLayer['pageCategory'][] = $_one_cat->slug;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_TAGS ] ) {
			$_post_tags = get_the_tags();
			if ( $_post_tags ) {
				$dataLayer['pageAttributes'] = array();
				foreach ( $_post_tags as $_one_tag ) {
					$dataLayer['pageAttributes'][] = $_one_tag->slug;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) {
			$postuser = get_userdata( $GLOBALS['post']->post_author );

			if ( false !== $postuser ) {
				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] ) {
					$dataLayer['pagePostAuthorID'] = $postuser->ID;
				}

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) {
					$dataLayer['pagePostAuthor'] = $postuser->display_name;
				}
			}
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
			$dataLayer['pagePostDate']      = get_the_date();
			$dataLayer['pagePostDateYear']  = get_the_date( 'Y' );
			$dataLayer['pagePostDateMonth'] = get_the_date( 'm' );
			$dataLayer['pagePostDateDay']   = get_the_date( 'd' );
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTERMLIST ] ) {
			$dataLayer["pagePostTerms"] = array();
			$object_taxonomies = get_object_taxonomies( get_post_type() );
			foreach( $object_taxonomies as $one_object_taxonomy ) {
				$post_taxonomy_values = get_the_terms( $GLOBALS[ "post" ]->ID, $one_object_taxonomy );
				if ( is_array( $post_taxonomy_values ) ) {
					$dataLayer["pagePostTerms"][$one_object_taxonomy] = array();
					foreach( $post_taxonomy_values as $one_taxonomy_value ) {
						$dataLayer["pagePostTerms"][$one_object_taxonomy][] = $one_taxonomy_value->name;
					}
				}
			}
		}
	}

	if ( is_archive() || is_post_type_archive() ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
			$dataLayer['pagePostType'] = get_post_type();

			if ( is_category() ) {
				$dataLayer['pagePostType2'] = 'category-' . get_post_type();
			} elseif ( is_tag() ) {
				$dataLayer['pagePostType2'] = 'tag-' . get_post_type();
			} elseif ( is_tax() ) {
				$dataLayer['pagePostType2'] = 'tax-' . get_post_type();
			} elseif ( is_author() ) {
				$dataLayer['pagePostType2'] = 'author-' . get_post_type();
			} elseif ( is_year() ) {
				$dataLayer['pagePostType2'] = 'year-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$dataLayer['pagePostDateYear'] = get_the_date( 'Y' );
				}
			} elseif ( is_month() ) {
				$dataLayer['pagePostType2'] = 'month-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$dataLayer['pagePostDateYear']  = get_the_date( 'Y' );
					$dataLayer['pagePostDateMonth'] = get_the_date( 'm' );
				}
			} elseif ( is_day() ) {
				$dataLayer['pagePostType2'] = 'day-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$dataLayer['pagePostDate']      = get_the_date();
					$dataLayer['pagePostDateYear']  = get_the_date( 'Y' );
					$dataLayer['pagePostDateMonth'] = get_the_date( 'm' );
					$dataLayer['pagePostDateDay']   = get_the_date( 'd' );
				}
			} elseif ( is_time() ) {
				$dataLayer['pagePostType2'] = 'time-' . get_post_type();
			} elseif ( is_date() ) {
				$dataLayer['pagePostType2'] = 'date-' . get_post_type();

				if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTDATE ] ) {
					$dataLayer['pagePostDate']      = get_the_date();
					$dataLayer['pagePostDateYear']  = get_the_date( 'Y' );
					$dataLayer['pagePostDateMonth'] = get_the_date( 'm' );
					$dataLayer['pagePostDateDay']   = get_the_date( 'd' );
				}
			}
		}

		if ( ( is_tax() || is_category() ) && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_CATEGORIES ] ) {
			$_post_cats                = get_the_category();
			$dataLayer['pageCategory'] = array();
			foreach ( $_post_cats as $_one_cat ) {
				$dataLayer['pageCategory'][] = $_one_cat->slug;
			}
		}

		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHORID ] ) && ( is_author() ) ) {
			global $authordata;
			$dataLayer['pagePostAuthorID'] = isset( $authordata->ID ) ? $authordata->ID : 0;
		}

		if ( ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_AUTHOR ] ) && ( is_author() ) ) {
			$dataLayer['pagePostAuthor'] = get_the_author();
		}
	}

	if ( is_search() ) {
		$dataLayer['siteSearchTerm'] = get_search_query();
		$dataLayer['siteSearchFrom'] = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer_url_parts = explode( '?', $_SERVER['HTTP_REFERER'] );
			if ( count( $referer_url_parts ) > 1 ) {
				$dataLayer['siteSearchFrom'] = $referer_url_parts[0] . '?' . rawurlencode( $referer_url_parts[1] );
			} else {
				$dataLayer['siteSearchFrom'] = $referer_url_parts[0];
			}
		}
		$dataLayer['siteSearchResults'] = $wp_query->post_count;
	}

	if ( is_front_page() && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
		$dataLayer['pagePostType'] = 'frontpage';
	}

	if ( ! is_front_page() && is_home() && $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTTYPE ] ) {
		$dataLayer['pagePostType'] = 'bloghome';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_BROWSERDATA ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_OSDATA ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_DEVICEDATA ] ) {
		spl_autoload_register(
			function( $class ) {
				$class_parts = explode( '\\', $class );
				if ( 'WhichBrowser' == $class_parts[0] ) {
					include dirname( __FILE__ ) . '/../integration/whichbrowser/' . str_replace( array( 'WhichBrowser', '\\' ), array( 'src', '/' ), $class ) . '.php';
				}
			}
		);

		require_once dirname( __FILE__ ) . '/../integration/whichbrowser/src/Parser.php';

		$gtp4wp_headers = getallheaders();
		if ( ( false === $gtp4wp_headers ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$gtp4wp_headers = $_SERVER['HTTP_USER_AGENT'];
		}
		if ( false !== $gtp4wp_headers ) {
			$detected = new WhichBrowser\Parser( $gtp4wp_headers );

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_BROWSERDATA ] ) {
				$dataLayer['browserName']    = isset( $detected->browser->name ) ? $detected->browser->name : '';
				$dataLayer['browserVersion'] = isset( $detected->browser->version->value ) ? $detected->browser->version->value : '';

				$dataLayer['browserEngineName']    = isset( $detected->engine->name ) ? $detected->engine->name : '';
				$dataLayer['browserEngineVersion'] = isset( $detected->engine->version->value ) ? $detected->engine->version->value : '';
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_OSDATA ] ) {
				$dataLayer['osName']    = isset( $detected->os->name ) ? $detected->os->name : '';
				$dataLayer['osVersion'] = isset( $detected->os->version->value ) ? $detected->os->version->value : '';
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_DEVICEDATA ] ) {
				$dataLayer['deviceType']         = isset( $detected->device->type ) ? $detected->device->type : '';
				$dataLayer['deviceManufacturer'] = isset( $detected->device->manufacturer ) ? $detected->device->manufacturer : '';
				$dataLayer['deviceModel']        = isset( $detected->device->model ) ? $detected->device->model : '';
			}
		}
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTCOUNT ] ) {
		$dataLayer['postCountOnPage'] = (int) $wp_query->post_count;
		$dataLayer['postCountTotal']  = (int) $wp_query->found_posts;
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTID ] && is_singular() === true ) {
		$dataLayer['postID'] = (int) get_the_ID();
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_POSTFORMAT ] && is_singular() === true ) {
		$dataLayer['postFormat'] = get_post_format() ?: 'standard';
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] > 0 ) {
		$_gtmrestrictlistitems = array();

		// because of security reasons, we loop through each stored entity in the options and validate them
		// to make sure nobody has entered some 'funny' item manually
		$valid_entity_ids = array_merge(
			array_keys( $gtm4wp_entity_ids[ 'tags' ] ),
			array_keys( $gtm4wp_entity_ids[ 'triggers' ] ),
			array_keys( $gtm4wp_entity_ids[ 'variables' ] )
		);
		foreach( $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_STATUS ] as $listed_entity ) {
			if ( in_array( $listed_entity, $valid_entity_ids ) ) {
				$_gtmrestrictlistitems[] = $listed_entity;
			}
		}

		$_gtmwhitelist = array();
		$_gtmblacklist = array();
		if ( $gtm4wp_options[ GTM4WP_OPTION_BLACKLIST_ENABLE ] == 1 ) {
			$_gtmblacklist = array_merge( $_gtmblacklist, $_gtmrestrictlistitems );
		} else {
			$_gtmwhitelist = array_merge( $_gtmwhitelist, $_gtmrestrictlistitems );
		}

		$dataLayer['gtm.whitelist'] = $_gtmwhitelist;
		$dataLayer['gtm.blacklist'] = $_gtmblacklist;
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEOCF ] && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$dataLayer['geoCloudflareCountryCode'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] ) {
			$dataLayer['weatherCategory']    = __( '(no weather data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['weatherDescription'] = __( '(no weather data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['weatherTemp']        = 0;
			$dataLayer['weatherPressure']    = 0;
			$dataLayer['weatherWindSpeed']   = 0;
			$dataLayer['weatherWindDeg']     = 0;
			$dataLayer['weatherDataStatus']  = 'Initialized with empty data';
		}

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
			$dataLayer['geoCountryCode'] = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoCountryName'] = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoRegionCode']  = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoRegionName']  = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoCity']        = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoZipcode']     = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoLatitude']    = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
			$dataLayer['geoLongitude']   = __( '(no geo data available)', 'duracelltomi-google-tag-manager' );
		}

		$client_ip = gtm4wp_get_user_ip();

		if ( '' !== $client_ip ) {
			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] ) {
				$weatherdata = get_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ) );

				if ( false !== $weatherdata ) {
					$dataLayer['weatherCategory']        = $weatherdata->weather[0]->main;
					$dataLayer['weatherDescription']     = $weatherdata->weather[0]->description;
					$dataLayer['weatherTemp']            = $weatherdata->main->temp;
					$dataLayer['weatherPressure']        = $weatherdata->main->pressure;
					$dataLayer['weatherWindSpeed']       = $weatherdata->wind->speed;
					$dataLayer['weatherWindDeg']         = ( isset($weatherdata->wind->deg) ? $weatherdata->wind->deg : '' );
					$dataLayer['weatherFullWeatherData'] = $weatherdata;
					$dataLayer['weatherDataStatus']      = 'Read from cache';
				} else {
					$dataLayer['weatherDataStatus'] = 'No weather data in cache (' . esc_attr( $client_ip ) . ')';
				}
			}

			if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
				$geodata = get_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ) );

				if ( false !== $geodata ) {
					$dataLayer['geoFullGeoData'] = $geodata;

					if ( isset( $geodata->latitude ) ) {
						$dataLayer['geoCountryCode'] = $geodata->country_code;
						$dataLayer['geoCountryName'] = $geodata->country_name;
						$dataLayer['geoRegionCode']  = $geodata->region_code;
						$dataLayer['geoRegionName']  = $geodata->region_name;
						$dataLayer['geoCity']        = $geodata->city;
						$dataLayer['geoZipcode']     = $geodata->zip;
						$dataLayer['geoLatitude']    = $geodata->latitude;
						$dataLayer['geoLongitude']   = $geodata->longitude;
					}
				}
			}
		}
	}

	return $dataLayer;
}

function gtm4wp_wp_loaded() {
	global $gtm4wp_options;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHER ] || $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEO ] ) {
		$client_ip = gtm4wp_get_user_ip();
		$geodata   = get_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ) );

		if ( false === $geodata ) {
			$gtm4wp_geodata = @wp_remote_get( sprintf( 'http://api.ipstack.com/%s?access_key=%s&format=1', urlencode( $client_ip ), $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_MISCGEOAPI ] ) );

			if ( is_array( $gtm4wp_geodata ) && ( 200 == $gtm4wp_geodata['response']['code'] ) ) {
				$gtm4wp_geodata = @json_decode( $gtm4wp_geodata['body'] );

				if ( is_object( $gtm4wp_geodata ) ) {
					set_transient( 'gtm4wp-geodata-' . esc_attr( $client_ip ), $gtm4wp_geodata, 60 * 60 );

					$weatherdata = get_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ) );
					if ( false === $weatherdata && isset( $gtm4wp_geodata->latitude ) ) {

						$weatherdata = wp_remote_get( 'http://api.openweathermap.org/data/2.5/weather?appid=' . $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI ] . '&lat=' . $gtm4wp_geodata->latitude . '&lon=' . $gtm4wp_geodata->longitude . '&units=' . ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_WEATHERUNITS ] == 0 ? 'metric' : 'imperial' ) );

						if ( is_array( $weatherdata ) && ( 200 == $weatherdata['response']['code'] ) ) {
							$weatherdata = @json_decode( $weatherdata['body'] );

							if ( is_object( $weatherdata ) ) {
								set_transient( 'gtm4wp-weatherdata-' . esc_attr( $client_ip ), $weatherdata, 60 * 60 );
								setcookie( 'gtm4wp_last_weatherstatus', 'Weather data loaded.' );
							} else {
								setcookie( 'gtm4wp_last_weatherstatus', 'Openweathermap.org did not return processable data: ' . var_export( $weatherdata, true ) );
							}
						} else {
							if ( is_wp_error( $weatherdata ) ) {
								setcookie( 'gtm4wp_last_weatherstatus', 'Openweathermap.org request error: ' . $weatherdata->get_error_message() );
							} else {
								setcookie( 'gtm4wp_last_weatherstatus', 'Openweathermap.org returned status code: ' . $weatherdata['response']['code'] );
							}
						}
					}
				} else {
					setcookie( 'gtm4wp_last_weatherstatus', 'ipstack.com did not return lat-lng data: ' . var_export( $gtm4wp_geodata, true ) );
				}
			} else {
				if ( is_wp_error( $gtm4wp_geodata ) ) {
					setcookie( 'gtm4wp_last_weatherstatus', 'ipstack.com request error: ' . $gtm4wp_geodata->get_error_message() );
				} else {
					setcookie( 'gtm4wp_last_weatherstatus', 'ipstack.com returned status code: ' . $gtm4wp_geodata['response']['code'] );
				}
			}
		}
	}
}

function gtm4wp_get_the_gtm_tag() {
	global $gtm4wp_options, $gtm4wp_datalayer_name, $gtm4wp_container_code_written;

	$_gtm_tag = '
<!-- Google Tag Manager (noscript) -->';

	if ( GTM4WP_PLACEMENT_OFF == $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		$gtm4wp_container_code_written = true;
	}

	if ( ( $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] != '' ) && ( ! $gtm4wp_container_code_written ) ) {
		$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) );

		if ( ( '' != $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' != $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) ) {
			$_gtm_env = '&gtm_auth=' . $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] . '&gtm_preview=' . $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] . '&gtm_cookies_win=x';
		} else {
			$_gtm_env = '';
		}

		foreach ( $_gtm_codes as $one_gtm_code ) {
			$_gtm_tag .= '
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $one_gtm_code . $_gtm_env . '"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
		}

		$_gtm_tag .= '
<!-- End Google Tag Manager (noscript) -->';

		$_gtm_tag                      = apply_filters( GTM4WP_WPFILTER_GETTHEGTMTAG, $_gtm_tag );
		$gtm4wp_container_code_written = true;
	}

	return $_gtm_tag;
}

function gtm4wp_the_gtm_tag() {
	echo gtm4wp_get_the_gtm_tag();
}

function gtm4wp_enqueue_scripts() {
	global $gtm4wp_options, $gtp4wp_plugin_url;

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WPCF7 ] ) {
		$in_footer = apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WPCF7, false );
		wp_enqueue_script( 'gtm4wp-contact-form-7-tracker', $gtp4wp_plugin_url . 'js/gtm4wp-contact-form-7-tracker.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_EVENTS_FORMMOVE ] ) {
		$in_footer = apply_filters( 'gtm4wp_' . GTM4WP_OPTION_EVENTS_FORMMOVE, false );
		wp_enqueue_script( 'gtm4wp-form-move-tracker', $gtp4wp_plugin_url . 'js/gtm4wp-form-move-tracker.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
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
		$in_footer = apply_filters( 'gtm4wp_' . GTM4WP_OPTION_SCROLLER_ENABLED, false );
		wp_enqueue_script( 'gtm4wp-scroll-tracking', $gtp4wp_plugin_url . 'js/analytics-talk-content-tracking.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
	}
}

function gtm4wp_wp_footer() {
	global $gtm4wp_options;

	if ( GTM4WP_PLACEMENT_FOOTER == $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) {
		gtm4wp_the_gtm_tag();
	}
}

function gtm4wp_wp_body_open() {
	global $gtm4wp_options;

	if ( ( GTM4WP_PLACEMENT_BODYOPEN == $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) || ( GTM4WP_PLACEMENT_BODYOPEN_AUTO == $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) ) {
		gtm4wp_the_gtm_tag();
	}
}

function gtm4wp_filter_visitor_keys( $dataLayer ) {
	foreach ( $dataLayer as $dl_key => $dl_value ) {
		if ( strpos( $dl_key, 'visitor' ) !== false ) {
			unset( $dataLayer[ $dl_key ] );
		}
	}

	return $dataLayer;
}

/**
 * GTM4WP global JS variables WordPress filter
 *
 * @author Vincent Koc <https://github.com/koconder/>
 * @link https://github.com/duracelltomi/gtm4wp/issues/34
 * @return mixed returns the
 */
function gtm4wp_add_global_vars( $vars, $return = false ) {
	if ( ! $return ) {
		if ( function_exists( $vars ) ) {
			$vars = $vars();
		}
		$GLOBALS['gtm4wp_datalayer_globalvars'] = $GLOBALS['gtm4wp_datalayer_globalvars'] . ' ' . $vars;
	}
	return $GLOBALS['gtm4wp_datalayer_globalvars'];
}

function gtm4wp_wp_header_top( $echo = true ) {
	global $gtm4wp_options, $gtm4wp_datalayer_name;

	$has_html5_support = current_theme_supports( 'html5' );

	$_gtm_top_content = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? ' type="text/javascript"' : '' ) . '>//<![CDATA[
	var gtm4wp_datalayer_name = "' . $gtm4wp_datalayer_name . '";
	var ' . $gtm4wp_datalayer_name . ' = ' . $gtm4wp_datalayer_name . ' || [];';

	// Load in the global variables from gtm4wp_add_global_vars / GTM4WP_WPACTION_ADDGLOBALVARS filter
	$_gtm_top_content .= apply_filters( GTM4WP_WPACTION_ADDGLOBALVARS, '', true );

	if ( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_ENABLED ] ) {
		$_gtm_top_content .= '

	var gtm4wp_scrollerscript_debugmode         = ' . ( $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_DEBUGMODE ] ? 'true' : 'false' ) . ';
	var gtm4wp_scrollerscript_callbacktime      = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_CALLBACKTIME ] . ';
	var gtm4wp_scrollerscript_readerlocation    = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_DISTANCE ] . ';
	var gtm4wp_scrollerscript_contentelementid  = "' . $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_CONTENTID ] . '";
	var gtm4wp_scrollerscript_scannertime       = ' . (int) $gtm4wp_options[ GTM4WP_OPTION_SCROLLER_READERTIME ] . ';';
	}

	$_gtm_top_content .= '
//]]>
</script>
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';

	if ( ! gtm4wp_amp_running() ) {
		if ( $echo ) {
			echo $_gtm_top_content;
		} else {
			return $_gtm_top_content;
		}
	}
}

function gtm4wp_wp_header_begin( $echo = true ) {
	global $gtm4wp_datalayer_name, $gtm4wp_datalayer_json, $gtm4wp_options, $woocommerce;

	$has_html5_support = current_theme_supports( 'html5' );

	$_gtm_header_content = '
<!-- Google Tag Manager for WordPress by gtm4wp.com -->
<script data-cfasync="false" data-pagespeed-no-defer' . ( $has_html5_support ? ' type="text/javascript"' : '' ) . '>//<![CDATA[';

	if ( $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] != '' ) {
		$gtm4wp_datalayer_data = array();
		$gtm4wp_datalayer_data = (array) apply_filters( GTM4WP_WPFILTER_COMPILE_DATALAYER, $gtm4wp_datalayer_data );

		if ( $gtm4wp_options[ GTM4WP_OPTION_INCLUDE_REMARKETING ] ) {
			// add Google Ads remarketing tags as suggested here:
			// https://support.google.com/tagmanager/answer/3002580?hl=en
			add_filter( GTM4WP_WPFILTER_COMPILE_REMARKTING, 'gtm4wp_filter_visitor_keys' );
			$gtm4wp_remarketing_tags = (array) apply_filters( GTM4WP_WPFILTER_COMPILE_REMARKTING, $gtm4wp_datalayer_data );

			$_gtm_header_content                       .= "\nvar google_tag_params = ";
			$_gtm_header_content                       .= json_encode( $gtm4wp_remarketing_tags );
			$_gtm_header_content                       .= ';';
			$gtm4wp_datalayer_data['google_tag_params'] = '-~-window.google_tag_params-~-';
		}

		if ( version_compare( PHP_VERSION, '5.4.0' ) >= 0 ) {
			$gtm4wp_datalayer_json = json_encode( $gtm4wp_datalayer_data, JSON_UNESCAPED_UNICODE );
		} else {
			$gtm4wp_datalayer_json = json_encode( $gtm4wp_datalayer_data );
		}

		// Clean up and then push datalayer to AMP
		$gtm4wp_datalayer_json = str_replace(
			array( '"-~-', '-~-"' ),
			array( '', '' ),
			str_replace( '', '-', $gtm4wp_datalayer_json )
		);

		$_gtm_header_content .= '
	var dataLayer_content = ' . $gtm4wp_datalayer_json . ';';

		// fire WooCommerce order double tracking protection only if WooCommerce is active and user is on the order received page
		if ( isset( $gtm4wp_options ) && ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ] || $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ] ) && isset( $woocommerce ) && is_order_received_page() ) {

			$_gtm_header_content .= '
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
			window.localStorage.getItem( "gtm4wp_orderid_tracked" );
		}

		// check enhanced ecommerce
		if ( dataLayer_content.ecommerce && dataLayer_content.ecommerce.purchase ) {
			if ( gtm4wp_orderid_tracked && ( dataLayer_content.ecommerce.purchase.actionField.id == gtm4wp_orderid_tracked ) ) {
				delete dataLayer_content.ecommerce.purchase;
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

		$_gtm_header_content .= '
	' . $gtm4wp_datalayer_name . '.push( dataLayer_content );';
	}

	$_gtm_header_content .= '//]]>
</script>';

	$_gtm_header_content .= apply_filters( GTM4WP_WPFILTER_AFTER_DATALAYER, '' );

	if ( ( $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] != '' ) ) {
		$_gtm_codes = explode( ',', str_replace( array( ';', ' ' ), array( ',', '' ), $gtm4wp_options[ GTM4WP_OPTION_GTM_CODE ] ) );

		$_gtm_tag = '';
		foreach ( $_gtm_codes as $one_gtm_code ) {
			if ( ( '' != $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) && ( '' != $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] ) ) {
				$_gtm_env = "+'&gtm_auth=" . $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] . '&gtm_preview=' . $gtm4wp_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] . "&gtm_cookies_win=x'";
			} else {
				$_gtm_env = '';
			}

			$_gtm_tag .= '
<script data-cfasync="false">//<![CDATA[
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':
new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=
\'//www.googletagmanager.com/gtm.\'' . '+\'js?id=\'+i+dl' . $_gtm_env . ';f.parentNode.insertBefore(j,f);
})(window,document,\'script\',\'' . $gtm4wp_datalayer_name . '\',\'' . $one_gtm_code . '\');//]]>
</script>';
		}

		$_gtm_tag .= '
<!-- End Google Tag Manager -->';

		$_gtm_tag             = apply_filters( GTM4WP_WPFILTER_GETTHEGTMTAG, $_gtm_tag );
		$_gtm_header_content .= $_gtm_tag;
	}

	$_gtm_header_content .= '
<!-- End Google Tag Manager for WordPress by gtm4wp.com -->';

	if ( ! gtm4wp_amp_running() ) {
		if ( $echo ) {
			echo $_gtm_header_content;
		} else {
			return $_gtm_header_content;
		}
	}
}

function gtm4wp_body_class( $classes ) {
	global $gtm4wp_options;

	// solution is based on the code of Yaniv Friedensohn
	// http://www.affectivia.com/blog/placing-the-google-tag-manager-in-wordpress-after-the-body-tag/
	if ( ( GTM4WP_PLACEMENT_BODYOPEN_AUTO == $gtm4wp_options[ GTM4WP_OPTION_GTM_PLACEMENT ] ) && ( !isset($_GET["ct_builder"]) ) ) {
		$classes[] = '">' . gtm4wp_get_the_gtm_tag() . '<br style="display:none;';
	}

	return $classes;
}

function gtm4wp_wp_login() {
	setcookie( 'gtm4wp_user_logged_in', '1', 0, '/' );
}

function gtm4wp_user_register() {
	setcookie( 'gtm4wp_user_registered', '1', 0, '/' );
}

function gtm4wp_user_reg_login_script() {
	global $gtp4wp_plugin_url;

	$in_footer = apply_filters( 'gtm4wp_user_reg_login_script', true );
	wp_enqueue_script( 'gtm4wp-user-reg-login-script', $gtp4wp_plugin_url . 'js/gtm4wp-users.js', array( 'jquery' ), GTM4WP_VERSION, $in_footer );
}

function gtm4wp_rocket_excluded_inline_js_content( $pattern ) {
	$pattern[] = 'dataLayer';
	$pattern[] = 'gtm4wp';

	return $pattern;
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
add_filter( 'body_class', 'gtm4wp_body_class', 10000 );
add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, 'gtm4wp_add_basic_datalayer_data' );

// to be able to easily migrate from other Google Tag Manager plugins
add_action( 'body_open', 'gtm4wp_wp_body_open' );

// compatibility with existing themes that natively support code injection after opening body tag
add_action( 'genesis_before', 'gtm4wp_wp_body_open' ); // Genisis theme
add_action( 'generate_before_header', 'gtm4wp_wp_body_open', 0 ); // GeneratePress theme
add_action( 'elementor/page_templates/canvas/before_content', 'gtm4wp_wp_body_open' ); // Elementor
add_action( 'ct_before_builder', 'gtm4wp_wp_body_open', 0 ); // Oxygen Builder
add_action( 'fl_before_builder', 'gtm4wp_wp_body_open', 0 ); // Beaver Builder Theme

// standard WP theme support for body open tags
add_action( 'wp_body_open', 'gtm4wp_wp_body_open' );

add_filter( 'rocket_excluded_inline_js_content', 'gtm4wp_rocket_excluded_inline_js_content' ); // WP Rocket
if (
	isset( $GLOBALS['gtm4wp_options'] )
	&& (
		$GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKCLASSICEC ]
		|| $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKENHANCEDEC ]
	)
	&& isset( $GLOBALS['woocommerce'] )
	&& version_compare( WC()->version, '3.2', '>=' ) // only activate WooCommerce integration for minimum supported WooCommerce version
) {
	require_once dirname( __FILE__ ) . '/../integration/woocommerce.php';
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_GOOGLEOPTIMIZEIDS ] != '' ) ) {
	require_once dirname( __FILE__ ) . '/../integration/google-optimize.php';
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_USERLOGIN ] ) ) {
	add_action( 'wp_login', 'gtm4wp_wp_login' );
	add_action( 'wp_enqueue_scripts', 'gtm4wp_user_reg_login_script' );
}

if ( isset( $GLOBALS['gtm4wp_options'] ) && ( $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_EVENTS_NEWUSERREG ] ) ) {
	add_action( 'user_register', 'gtm4wp_user_register' );
	add_action( 'wp_enqueue_scripts', 'gtm4wp_user_reg_login_script' );
}
