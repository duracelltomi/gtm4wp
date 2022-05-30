<?php
/**
 * GTM4WP options on the Basic data tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

$GLOBALS['gtm4wp_includefieldtexts'] = array(
	GTM4WP_OPTION_INCLUDE_POSTTYPE      => array(
		'label'       => esc_html__( 'Posttype of current post/archive', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the type of the current post or archive page (post, page or any custom post type).', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_CATEGORIES    => array(
		'label'       => esc_html__( 'Category list of current post/archive', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the category names of the current post or archive page', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_TAGS          => array(
		'label'       => esc_html__( 'Tags of current post', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the tags of the current post.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_AUTHORID      => array(
		'label'       => esc_html__( 'Post author ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the ID of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_AUTHOR        => array(
		'label'       => esc_html__( 'Post author name', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the name of the author on the current post or author page.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTDATE      => array(
		'label'       => esc_html__( 'Post date', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the date of the current post. This will include 4 dataLayer variables: full date, post year, post month, post date.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTTITLE     => array(
		'label'       => esc_html__( 'Post title', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the title of the current post.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTCOUNT     => array(
		'label'       => esc_html__( 'Post count', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the count of the posts currently shown on the page and the total number of posts in the category/tag/any taxonomy.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTID        => array(
		'label'       => esc_html__( 'Post ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the post id.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTFORMAT    => array(
		'label'       => esc_html__( 'Post Format', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the post format.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_POSTTERMLIST  => array(
		'label'       => esc_html__( 'Post Terms', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include taxonomy values associated with a given post.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_SEARCHDATA    => array(
		'label'       => esc_html__( 'Search data', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the search term, referring page URL and number of results on the search page.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_LOGGEDIN      => array(
		'label'       => esc_html__( 'Logged in status', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include whether there is a logged in user on your website.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_USERROLE      => array(
		'label'       => esc_html__( 'Logged in user role', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the role of the logged in user.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_USERID        => array(
		'label'       => esc_html__( 'Logged in user ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the ID of the logged in user.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_USERNAME      => array(
		'label'       => esc_html__( 'Logged in user name', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the username of the logged in user.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_USEREMAIL     => array(
		'label'       => esc_html__( 'Logged in user email', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the email address of the logged in user.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_USERREGDATE   => array(
		'label'       => esc_html__( 'Logged in user creation date', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the date of creation (registration) of the logged in user.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_VISITOR_IP    => array(
		'label'       => esc_html__( 'Visitor IP', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the IP address of the visitor. You might use this to filter internal traffic inside your GTM container. Please be aware that per GDPR its not allowed to transmit this full IP address to Google Analytics or to any other measurement system without explicit consent from the visitor.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_BROWSERDATA   => array(
		'label'       => esc_html__( 'Browser data *', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the name, version and engine data of the browser the visitor uses.', 'duracelltomi-google-tag-manager' ),
	),
	GTM4WP_OPTION_INCLUDE_OSDATA        => array(
		'label'       => esc_html__( 'OS data *', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the name and version of the operating system the visitor uses.', 'duracelltomi-google-tag-manager' ),
	),
	GTM4WP_OPTION_INCLUDE_DEVICEDATA    => array(
		'label'       => esc_html__( 'Device data *', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this option to include the type of device the user is currently using (desktop, tablet or mobile) including manufacturer and model data.', 'duracelltomi-google-tag-manager' ),
	),
	GTM4WP_OPTION_INCLUDE_MISCGEO       => array(
		'label'       => esc_html__( 'Geo data', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Add geo data (latitude, longitude, country, city, etc) of the current visitor (provided by ipstack.com)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_MISCGEOAPI    => array(
		'label'       => esc_html__( 'IPStack.com API key', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			// translators: 1: the anchor eleemnt pointing to ipstack.com to register for API keys. 2: closing anchor tag.
			esc_html__(
				'Enter your IPStack.com API key here. %1$sGet a free API key here%2$s.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://ipstack.com/product?utm_source=gtm4wp&utm_medium=link&utm_campaign=gtm4wp-google-tag-manager-for-wordpress" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_MISCGEOCF     => array(
		'label'       => esc_html__( 'Cloudflare country code', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Add the country code of the user provided by Cloudflare (if Cloudflare is used with your site)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_WEATHER       => array(
		'label'       => esc_html__( 'Weather data', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: opening anchor tag linking to ipstack.com product page. 2: closing anchor tag. 3: opening anchor tag linking to OpenWeatherMap pricing page. 4: closing anchor tag.
				__(
					'Check this option to include the current weather conditions around the current visitor.<br /><br />
					 <strong>Attention!</strong> This feature uses %1$sipstack.com%2$s and
					 %3$sopenweathermap.org%4$s to collect data.<br />
					 Depending on your website\'s traffic, additional fees may apply!<br />
					 This plugin caches weather data for 1 hour to lower the need to access those services.<br /><br />
					 If you activate weather data, <strong>you will need</strong> to add an IPStack.com API key regardless of whether you
					 activate the \'Geo data\' option!',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://ipstack.com/product?utm_source=gtm4wp&utm_medium=link&utm_campaign=gtm4wp-google-tag-manager-for-wordpress" target="_blank" rel="noopener">',
			'</a>',
			'<a href="http://openweathermap.org/price?utm_source=gtm4wp&utm_medium=link&utm_campaign=gtm4wp-google-tag-manager-for-wordpress" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_WEATHERUNITS  => array(
		'label'       => esc_html__( 'Weather data units', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Select which temperature units you would like to use.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI => array(
		'label'       => esc_html__( 'OpenWeatherMap API key', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			// translators: 1: opening anchor tag linking to Open Weather Map's pricing page. 2: closing anchor tag.
			esc_html__(
				'Enter your OpenWeatherMap API key here. %1$sGet a free API key here%2$s.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="http://openweathermap.org/price?utm_source=gtm4wp&utm_medium=link&utm_campaign=gtm4wp-google-tag-manager-for-wordpress" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INCLUDE_SITEID        => array(
		'label'       => esc_html__( 'Site ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'ID of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INCLUDE_SITENAME      => array(
		'label'       => esc_html__( 'Site name', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Name of the current site in a WordPress Multisite environment', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
);
