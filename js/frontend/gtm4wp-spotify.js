import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_spotify_percentage_tracking = 10;
// All three stores are keyed by a provider-reported URI, so null prototypes
// (`__proto__` key).
const gtm4wp_spotify_percentage_tracking_marks = Object.create( null );

// The iFrame API has no discrete play/pause/seek/end events, only a periodic
// `playback_update` (isPaused/isBuffering/position/duration); the state is
// derived and the last one pushed per URI collapses the repeats.
const gtm4wp_spotify_last_state = Object.create( null );

// Resolved titles by URI (a playlist advances to tracks the embed markup
// says nothing about). A stored '' = "resolved to nothing", so a failed
// lookup is not re-issued on every playback_update.
const gtm4wp_spotify_titles = Object.create( null );

// Spotify's own oEmbed title literal (title="Spotify Embed: …"), the same on
// every locale since core keeps an existing title attribute (U104).
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
 * Builds the mediaData object from a Spotify URI (spotify:type:id). The embed
 * API carries no title; the resolved one is used, else the URI.
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

	// getAttribute() reports the decoded value (&amp; arrives as &).
	const title = ( frame.getAttribute( 'title' ) || '' ).trim();

	if ( 0 === title.indexOf( gtm4wp_spotify_title_prefix ) ) {
		return title.slice( gtm4wp_spotify_title_prefix.length ).trim();
	}

	// A hand-written embed may carry an unprefixed title.
	return title;
}

/**
 * Resolves the title for one Spotify URI into the cache, at most once per
 * URI. Nothing waits on it: the seed (the embed's title attribute) is
 * synchronous, and the oEmbed fallback starts as the embed is wired; deferring
 * an event on it would lose the event when a blocker leaves the request
 * hanging.
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

	// Claimed before the request; the '' stays on every failure path so a
	// failing endpoint is not asked again.
	gtm4wp_spotify_titles[ uri ] = '';

	if ( ! contentUrl || 'function' !== typeof fetch ) {
		return;
	}

	// credentials: 'omit': the endpoint sets a .spotify.com cookie.
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
			// The '' written above stands.
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
 * @param {HTMLElement} frame      The iframe handed to createController, read for
 *                                 its src only (the SDK replaces the node).
 * @param {Function}    liveFrame  Resolves the iframe that took its place, for
 *                                 gtm.videoVisible.
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
				// No duration before the first playback_update.
				duration: 0,
				element: liveFrame,
			} ),
		} );
	} );

	controller.addListener( 'playback_update', function ( e ) {
		const data = ( e && e.data ) || {};
		const uri = data.playingURI || fallbackUri;

		// A playlist advances to a URI the markup does not know; cached after
		// the first update.
		gtm4wp_resolveSpotifyTitle( uri );

		// Spotify reports milliseconds.
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
	// Double-init guard: a re-executed bundle would chain onSpotifyIframeApiReady
	// onto itself and double every push.
	if ( window.gtm4wp_spotify_inited ) {
		return;
	}
	window.gtm4wp_spotify_inited = true;

	// The SDK is the IFrameAPI handed to the global onSpotifyIframeApiReady
	// callback (no global object), hence the object form for gtm4wpObserveMedia:
	// the script's load event fires too early.
	let spotifyApi = null;

	gtm4wpObserveMedia(
		'iframe[src*="open.spotify.com/embed"]',
		function ( spotify_frame, liveFrame ) {
			const uri = gtm4wp_spotifyUriFromSrc( spotify_frame );

			// BEFORE createController: the SDK replaces this node and the title
			// attribute leaves with it.
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
				// A previously registered callback (another integration) is
				// chained, not clobbered. If the API never loads, nothing fires.
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
