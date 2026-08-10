import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_spotify_percentage_tracking = 10;
// Keyed by the media id the provider reports, so a null prototype: on a plain
// object a key of `__proto__` resolves to Object.prototype instead of a missing
// entry, and writing it back sets the store's prototype instead of a property.
const gtm4wp_spotify_percentage_tracking_marks = Object.create( null );

// The Spotify iFrame API exposes no discrete play/pause/seek/end events — only a
// periodic `playback_update` carrying isPaused/isBuffering/position/duration. The
// player state is derived from those updates, and the last state pushed per URI
// is tracked so the repeated updates collapse to a single state change. A null
// prototype for the same reason as the title cache above: a URI must never read
// an inherited Object member back as if it were a stored state.
const gtm4wp_spotify_last_state = Object.create( null );

// Resolved titles, keyed by Spotify URI. A null prototype so a URI can never
// read an inherited Object member back as if it were a cached title.
//
// Keyed by URI rather than by embed because a playlist or album advances: the
// embed markup describes the playlist, while each track that starts playing
// needs a title of its own.
//
// A stored '' means "resolved to nothing" and is deliberate. playback_update
// repeats every few hundred milliseconds, so a lookup whose failure is not
// remembered would be re-issued several times a second.
const gtm4wp_spotify_titles = Object.create( null );

// Spotify's oEmbed markup prefixes the iframe title attribute with this exact
// literal: title="Spotify Embed: Never Gonna Give You Up". The string is
// Spotify's own, not one WordPress composes — core's
// wp_filter_oembed_iframe_title_attribute() keeps an existing title attribute
// rather than building one — so it is the same English literal on every locale.
// Registered as an upstream coupling (U104).
const gtm4wp_spotify_title_prefix = 'Spotify Embed: ';

// Public oEmbed endpoint, used only when an embed carries no title attribute.
// Answers with access-control-allow-origin: * (U105).
const gtm4wp_spotify_oembed_endpoint = 'https://open.spotify.com/oembed?url=';

/**
 * Builds the public URL of a Spotify URI (spotify:type:id).
 *
 * @param {string} uri The Spotify URI.
 * @return {string} The open.spotify.com URL, or '' when the URI cannot be split.
 */
function gtm4wp_spotifyContentUrl( uri ) {
	const parts = String( uri ).split( ':' );
	const type = parts[ 1 ] || '';
	const id = parts[ 2 ] || '';

	return type && id ? 'https://open.spotify.com/' + type + '/' + id : '';
}

/**
 * Builds the mediaData object from a Spotify URI (spotify:type:id).
 *
 * The Spotify embed API carries no title of its own, so the title is whatever
 * gtm4wp_resolveSpotifyTitle() has resolved for this URI. Until (or unless) that
 * lands, the URI itself is reported, exactly as it always was.
 *
 * @param {string} uri      The Spotify URI.
 * @param {number} duration Duration in seconds.
 * @return {Object} The mediaData object.
 */
function gtm4wp_spotifyMediaData( uri, duration ) {
	const parts = String( uri ).split( ':' );
	const id = parts[ 2 ] || uri;

	return {
		id,
		author: '',
		title: gtm4wp_spotify_titles[ uri ] || uri,
		url: gtm4wp_spotifyContentUrl( uri ),
		duration,
	};
}

/**
 * Reads the human readable title out of a Spotify embed iframe.
 *
 * @param {HTMLElement} frame The Spotify embed iframe.
 * @return {string} The title without Spotify's prefix, or '' when absent.
 */
function gtm4wp_spotifyTitleFromFrame( frame ) {
	if ( ! frame || 'function' !== typeof frame.getAttribute ) {
		return '';
	}

	// getAttribute() reports the decoded value, so a title carrying &amp; in the
	// markup arrives here as & and needs no further work.
	const title = ( frame.getAttribute( 'title' ) || '' ).trim();

	if ( 0 === title.indexOf( gtm4wp_spotify_title_prefix ) ) {
		return title.slice( gtm4wp_spotify_title_prefix.length ).trim();
	}

	// A hand-written embed may carry a title of its own with no Spotify prefix.
	return title;
}

/**
 * Resolves the title for one Spotify URI into the cache, at most once per URI.
 *
 * Nothing waits on this. The seed (the embed's own title attribute) is available
 * synchronously and covers the normal case, so every push carries a real title.
 * The oEmbed lookup is the fallback for an embed with no title attribute, and is
 * started as the embed is wired — a whole iframe load before the SDK reports
 * ready — so it has landed by the time anything is pushed in all but the slowest
 * case. Deferring an event on it instead would risk losing that event entirely
 * when a consent manager or an ad blocker leaves the request hanging.
 *
 * @param {string} uri         The Spotify URI.
 * @param {string} [seedTitle] Title already known from the embed markup.
 * @return {void}
 */
function gtm4wp_resolveSpotifyTitle( uri, seedTitle ) {
	if ( ! uri || uri in gtm4wp_spotify_titles ) {
		return;
	}

	if ( seedTitle ) {
		gtm4wp_spotify_titles[ uri ] = seedTitle;
		return;
	}

	const contentUrl = gtm4wp_spotifyContentUrl( uri );

	// Claim the URI before the request goes out, and leave that '' in place on
	// every failure path below: that is what stops a blocked, failing or
	// unparseable endpoint from being asked again on the next playback_update.
	gtm4wp_spotify_titles[ uri ] = '';

	// A URI that did not split into a type and an id cannot address anything, so
	// no request is built from it.
	if ( ! contentUrl || 'function' !== typeof fetch ) {
		return;
	}

	// credentials: 'omit' — the endpoint answers with a Set-Cookie for
	// .spotify.com, and looking up a title is not a reason to store it.
	fetch( gtm4wp_spotify_oembed_endpoint + encodeURIComponent( contentUrl ), {
		credentials: 'omit',
	} )
		.then( function ( response ) {
			return response && response.ok ? response.json() : null;
		} )
		.then( function ( data ) {
			if ( data && 'string' === typeof data.title && data.title.trim() ) {
				gtm4wp_spotify_titles[ uri ] = data.title.trim();
			}
		} )
		.catch( function () {
			// Network error, blocked request or an unparseable body: the ''
			// written above stands, so the URI is reported and not retried.
		} );
}

/**
 * Derives the Spotify URI from an embed iframe src (…/embed/type/id).
 *
 * @param {HTMLElement} frame The Spotify embed iframe.
 * @return {string} The spotify:type:id URI, or '' when it cannot be parsed.
 */
function gtm4wp_spotifyUriFromSrc( frame ) {
	try {
		const path = new URL(
			frame.getAttribute( 'src' ),
			window.location.href
		).pathname;
		const match = path.match( /\/embed\/([^/]+)\/([^/]+)/ );
		if ( match ) {
			return 'spotify:' + match[ 1 ] + ':' + match[ 2 ];
		}
	} catch ( e ) {
		// Fall through to the empty default below.
	}
	return '';
}

function gtm4wp_onSpotifyPercentageChange(
	uri,
	currentTime,
	duration,
	liveFrame
) {
	if ( ! duration ) {
		return;
	}

	const videoPercentage = Math.floor( ( currentTime / duration ) * 100 );

	gtm4wpMediaMilestones(
		gtm4wp_spotify_percentage_tracking_marks,
		uri,
		videoPercentage,
		gtm4wp_spotify_percentage_tracking,
		function ( i ) {
			const info = gtm4wp_spotifyMediaData( uri, duration );
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlaybackPercentage',
				mediaType: 'spotify',
				mediaData: info,
				mediaCurrentTime: currentTime,
				mediaPercentage: i,
				...gtm4wpNativeVideoParams( {
					provider: 'spotify',
					status: 'progress',
					url: info.url,
					title: info.title,
					currentTime,
					duration,
					percent: i,
					element: liveFrame,
				} ),
			} );
		}
	);
}

/**
 * Binds the data layer pushes to one Spotify embed controller.
 *
 * @param {Object}      controller The Spotify EmbedController.
 * @param {HTMLElement} frame      The iframe handed to createController. Read for
 *                                 its src only: the SDK replaces this node, so it
 *                                 is detached by the time playback starts.
 * @param {Function}    liveFrame  Resolves the iframe that took the original's
 *                                 place, so gtm.videoVisible measures the embed
 *                                 that is actually on screen.
 */
function gtm4wp_bindSpotifyController( controller, frame, liveFrame ) {
	const fallbackUri = gtm4wp_spotifyUriFromSrc( frame );

	controller.addListener( 'ready', function () {
		const info = gtm4wp_spotifyMediaData( fallbackUri, 0 );
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'spotify',
			mediaData: info,
			mediaCurrentTime: 0,
			...gtm4wpNativeVideoParams( {
				provider: 'spotify',
				// "Ready" has no native GTM video status.
				status: '',
				url: info.url,
				title: info.title,
				currentTime: 0,
				// The controller reports no duration until the first
				// playback_update, so ready carries 0 (as mediaData does).
				duration: 0,
				element: liveFrame,
			} ),
		} );
	} );

	controller.addListener( 'playback_update', function ( e ) {
		const data = ( e && e.data ) || {};
		const uri = data.playingURI || fallbackUri;

		// A playlist or album embed advances to a URI the embed markup says
		// nothing about, so that one is resolved on its own. Cached after the
		// first update, which is what keeps the repeated updates silent.
		gtm4wp_resolveSpotifyTitle( uri );

		// Spotify reports times in milliseconds; gtm4wp media events and the
		// gtm.video* variables use seconds.
		const currentTime = ( data.position || 0 ) / 1000;
		const duration = ( data.duration || 0 ) / 1000;

		gtm4wp_onSpotifyPercentageChange(
			uri,
			currentTime,
			duration,
			liveFrame
		);

		// Derive a discrete player state from the update flags.
		let playerState;
		if ( data.isBuffering ) {
			playerState = 'buffering';
		} else if ( duration > 0 && currentTime >= duration * 0.99 ) {
			playerState = 'ended';
		} else if ( data.isPaused ) {
			playerState = 'pause';
		} else {
			playerState = 'play';
		}

		if ( gtm4wp_spotify_last_state[ uri ] === playerState ) {
			return;
		}
		gtm4wp_spotify_last_state[ uri ] = playerState;

		const info = gtm4wp_spotifyMediaData( uri, duration );
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'spotify',
			mediaData: info,
			mediaCurrentTime: currentTime,
			mediaPlayerState: playerState,
			...gtm4wpNativeVideoParams( {
				provider: 'spotify',
				status: gtm4wpNativeVideoStatus( playerState ),
				url: info.url,
				title: info.title,
				currentTime,
				duration,
				element: liveFrame,
			} ),
		} );
	} );
}

function gtm4wp_initSpotifyTracking() {
	// Register once: if this bundle is executed twice (e.g. re-injected by a tag
	// manager) it would chain its own onSpotifyIframeApiReady onto itself and
	// create a controller per iframe twice, doubling every data layer push.
	if ( window.gtm4wp_spotify_inited ) {
		return;
	}
	window.gtm4wp_spotify_inited = true;

	// Spotify hands its controller factory to the global onSpotifyIframeApiReady
	// callback instead of exposing a global object, so "the SDK is ready" here
	// means "that callback has fired" and the IFrameAPI it was given IS the SDK.
	// That is why the SDK is described to gtm4wpObserveMedia in the object form:
	// the script's own load event fires too early to wire anything.
	let spotifyApi = null;

	// Wire the Spotify embeds present now and any inserted later (popup/AJAX).
	// The observer scans first and only fetches the iFrame API if this page
	// actually has an embed, so a page without one never calls open.spotify.com.
	gtm4wpObserveMedia(
		'iframe[src*="open.spotify.com/embed"]',
		function ( spotify_frame, liveFrame ) {
			const uri = gtm4wp_spotifyUriFromSrc( spotify_frame );

			// Read the title attribute BEFORE createController: the SDK
			// replaces this node with its own iframe, and the attribute leaves
			// with it. This is also the earliest point the oEmbed fallback can
			// start, giving it the whole embed load to finish in.
			gtm4wp_resolveSpotifyTitle(
				uri,
				gtm4wp_spotifyTitleFromFrame( spotify_frame )
			);

			spotifyApi.createController(
				spotify_frame,
				{ uri },
				function ( controller ) {
					gtm4wp_bindSpotifyController(
						controller,
						spotify_frame,
						liveFrame
					);
				}
			);
		},
		function () {
			return null !== spotifyApi;
		},
		{
			src: 'https://open.spotify.com/embed/iframe-api/v1',
			subscribe( rescan ) {
				// A previously registered callback (another integration, or the
				// site loading the API for its own reasons) is preserved and
				// chained so this tracker does not clobber it. If the API never
				// loads (consent manager / ad blocker) the callback never fires
				// and nothing is pushed — graceful by design.
				const previous = window.onSpotifyIframeApiReady;

				window.onSpotifyIframeApiReady = function ( IFrameAPI ) {
					if ( typeof previous === 'function' ) {
						previous( IFrameAPI );
					}

					spotifyApi = IFrameAPI;
					rescan();
				};
			},
		}
	);
}

gtm4wp_initSpotifyTracking();
