import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_spotify_percentage_tracking = 10;
const gtm4wp_spotify_percentage_tracking_marks = {};

// The Spotify iFrame API exposes no discrete play/pause/seek/end events — only a
// periodic `playback_update` carrying isPaused/isBuffering/position/duration. The
// player state is derived from those updates, and the last state pushed per URI
// is tracked so the repeated updates collapse to a single state change.
const gtm4wp_spotify_last_state = {};

/**
 * Builds the mediaData object from a Spotify URI (spotify:type:id).
 *
 * @param {string} uri      The Spotify URI.
 * @param {number} duration Duration in seconds.
 * @return {Object} The mediaData object.
 */
function gtm4wp_spotifyMediaData( uri, duration ) {
	const parts = String( uri ).split( ':' );
	const type = parts[ 1 ] || '';
	const id = parts[ 2 ] || uri;
	const url =
		type && parts[ 2 ]
			? 'https://open.spotify.com/' + type + '/' + parts[ 2 ]
			: '';

	return {
		id,
		author: '',
		title: uri,
		url,
		duration,
	};
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
			spotifyApi.createController(
				spotify_frame,
				{ uri: gtm4wp_spotifyUriFromSrc( spotify_frame ) },
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
