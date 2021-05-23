function gtm4wp_set_cookie( cookiename, cookievalue, expiredays ) {
	let d = new Date();
	d.setTime(d.getTime() + (expiredays*24*60*60*1000));
	const expires = "expires="+ d.toUTCString();

	document.cookie = cookiename + "=" + cookievalue + ";" + expires + ";path=/";
}

function gtm4wp_get_cookie( cookiename ) {
	const decoded_cookie_list = decodeURIComponent(document.cookie).split(';');
	let onecookie = '';

	decoded_cookie_list.forEach(function(cookie) {
		onecookie = cookie.trim();
		if ( 0 == onecookie.indexOf( cookiename ) ) {
			return onecookie.substring( cookiename.length+1, onecookie.length );
		}
	});

	return "";
}

const gtm4wp_user_logged_in = gtm4wp_get_cookie( 'gtm4wp_user_logged_in' );
if ( gtm4wp_user_logged_in === "1" ) {
	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.userLoggedIn',
	});

	gtm4wp_set_cookie( 'gtm4wp_user_logged_in', '', -1 );
}

const gtm4wp_new_user_registered = gtm4wp_get_cookie( 'gtm4wp_user_registered' );
if ( gtm4wp_new_user_registered === "1" ) {
	window[ gtm4wp_datalayer_name ].push({
		'event': 'gtm4wp.userRegistered',
	});

	gtm4wp_set_cookie( 'gtm4wp_user_registered', '', -1 );
}
