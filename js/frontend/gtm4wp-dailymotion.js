import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_dailymotion_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_dailymotion_percentage_tracking_marks = Object.create( null );

/**
 * Percent-decodes one path segment (so path forms match the searchParams
 * forms), returning it unchanged when decodeURIComponent() throws.
 *
 * @param {string} value The raw path segment.
 * @return {string} The decoded segment, or the input when it cannot be decoded.
 */
function gtm4wp_dailymotionDecode( value ) {
	try {
		return decodeURIComponent( value );
	} catch ( e ) {
		return value;
	}
}

/**
 * Reads the video id, and any player id, out of a Dailymotion embed src.
 *
 * Four forms reach this; the first is what WordPress' oEmbed emits today:
 *   https://geo.dailymotion.com/player.html?video=<id>&
 *   https://geo.dailymotion.com/player/<playerid>.html?video=<id>
 *   https://www.dailymotion.com/embed/video/<id>        (legacy, 301s to the 1st)
 *   https://dai.ly/<id>                                 (short link)
 *
 * The id is located by the shape of the PATH, never validated by its own
 * shape (UC-5: a regex on somebody else's id grammar rejects their next one).
 *
 * @param {string} src The iframe's src attribute.
 * @return {{videoid: string, playerid: string}|null} The parsed ids, or null.
 */
function gtm4wp_dailymotionEmbedInfo( src ) {
	let url;
	try {
		url = new URL( src, window.location.href );
	} catch ( e ) {
		return null;
	}

	let videoid = url.searchParams.get( 'video' ) || '';

	if ( ! videoid ) {
		// NOT a generic "last path segment" fallback: for the geo forms that
		// segment is `player.html` / `<playerid>.html`.
		const embedded = url.pathname.match( /\/embed\/video\/([^/]+)\/?$/ );

		if ( embedded ) {
			videoid = gtm4wp_dailymotionDecode( embedded[ 1 ] );
		} else if ( 'dai.ly' === url.host ) {
			videoid = gtm4wp_dailymotionDecode(
				url.pathname.replace( /^\/+/, '' ).replace( /\/+$/, '' )
			);
		}
	}

	// No video id (`?playlist=`, or not a player URL): bail rather than guess,
	// or a working embed is replaced with a player for a non-existent video.
	if ( ! videoid ) {
		return null;
	}

	// Carry over the site's configured player (/player/<playerid>.html), since
	// replacing the embed means WE decide which player the visitor sees.
	const player = url.pathname.match( /\/player\/([^/]+)\.html$/ );

	return {
		videoid,
		playerid: player ? gtm4wp_dailymotionDecode( player[ 1 ] ) : '',
	};
}

/**
 * Turns an iframe dimension attribute ("640" or "100%") into a CSS length,
 * falling back to the measured box for an embed sized purely by CSS.
 *
 * @param {string|null} attribute The width/height attribute value.
 * @param {number}      measured  The measured box dimension, in pixels.
 * @return {string} A CSS length, or '' when neither source has one.
 */
function gtm4wp_dailymotionLength( attribute, measured ) {
	const value = ( attribute || '' ).trim();

	if ( /^\d+(\.\d+)?$/.test( value ) ) {
		return value + 'px';
	}
	if ( '' !== value ) {
		return value;
	}
	if ( measured > 0 ) {
		return measured + 'px';
	}

	return '';
}

/**
 * Builds the div the Dailymotion SDK fills, carrying over the box the iframe
 * occupied: createPlayer() takes no width/height, so without the transplant
 * the player collapses to zero height. Written as inline style (the HTML
 * attributes mean nothing on a div) and measured BEFORE the caller's
 * replaceChild (a removed node measures 0x0).
 *
 * @param {HTMLElement} frame The embed iframe being replaced.
 * @return {HTMLElement} The container, not yet inserted into the document.
 */
function gtm4wp_dailymotionContainerFor( frame ) {
	// Counter on window, not module scope: a re-executed bundle would restart
	// at 0 while the earlier container is still in the DOM.
	window.gtm4wp_dailymotion_frame_index =
		window.gtm4wp_dailymotion_frame_index || 0;

	const container = document.createElement( 'div' );
	container.id =
		'gtm4wp-dailymotion-' + window.gtm4wp_dailymotion_frame_index++;

	// class/style copied so a theme rule still matches; src/allow/title not
	// (the SDK builds its own iframe).
	const className = frame.getAttribute( 'class' );
	if ( className ) {
		container.setAttribute( 'class', className );
	}
	const inlineStyle = frame.getAttribute( 'style' );
	if ( inlineStyle ) {
		container.setAttribute( 'style', inlineStyle );
	}

	const rect =
		typeof frame.getBoundingClientRect === 'function'
			? frame.getBoundingClientRect()
			: null;

	const width = gtm4wp_dailymotionLength(
		frame.getAttribute( 'width' ),
		rect && rect.width
	);
	const height = gtm4wp_dailymotionLength(
		frame.getAttribute( 'height' ),
		rect && rect.height
	);

	// Only ADDS a dimension the copied style did not set (responsive embeds).
	if ( width && '' === container.style.width ) {
		container.style.width = width;
	}
	if ( height && '' === container.style.height ) {
		container.style.height = height;
	}

	return container;
}

/**
 * Binds the data layer pushes to one Dailymotion player.
 *
 * @param {Object}      player    The player dailymotion.createPlayer() resolved.
 * @param {string}      videoid   The video id parsed from the embed src.
 * @param {string}      videourl  The canonical watch URL of the video.
 * @param {HTMLElement} container The div the SDK fills with its player iframe.
 */
function gtm4wp_bindDailymotionPlayer( player, videoid, videourl, container ) {
	// Every Player Embeds event delivers the FULL player state; cached on
	// arrival, every push reads it from here.
	let lastState = {};

	const gtm4wp_dailymotionCurrentTime = function () {
		const currentTime = Number( lastState.videoTime );
		return isNaN( currentTime ) ? 0 : currentTime || 0;
	};

	const gtm4wp_dailymotionDuration = function () {
		const duration = Number( lastState.videoDuration );
		return isNaN( duration ) ? 0 : duration || 0;
	};

	// Title and owner arrive with the metadata, after the first events; the id
	// stands in until then (an empty title would read as "no title").
	const gtm4wp_dailymotionMediaData = function () {
		return {
			id: videoid,
			author: lastState.videoOwnerScreenname || '',
			title: lastState.videoTitle || videoid,
			url: videourl,
			duration: gtm4wp_dailymotionDuration(),
		};
	};

	const gtm4wp_onDailymotionPlayerStateChange = function ( playerState ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'dailymotion',
			mediaData: gtm4wp_dailymotionMediaData(),
			mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
			mediaPlayerState: playerState,
			...gtm4wpNativeVideoParams( {
				provider: 'dailymotion',
				status: gtm4wpNativeVideoStatus( playerState ),
				url: videourl,
				title: lastState.videoTitle || videoid,
				currentTime: gtm4wp_dailymotionCurrentTime(),
				duration: gtm4wp_dailymotionDuration(),
				element: container,
			} ),
		} );
	};

	const gtm4wp_onDailymotionPlayerEvent = function ( eventName, eventParam ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'dailymotion',
			mediaData: gtm4wp_dailymotionMediaData(),
			mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
			mediaPlayerEvent: eventName,
			mediaPlayerEventParam: eventParam,
			...gtm4wpNativeVideoParams( {
				provider: 'dailymotion',
				// These events are not playback states GTM models.
				status: '',
				url: videourl,
				title: lastState.videoTitle || videoid,
				currentTime: gtm4wp_dailymotionCurrentTime(),
				duration: gtm4wp_dailymotionDuration(),
				element: container,
			} ),
		} );
	};

	const gtm4wp_onDailymotionPercentageChange = function () {
		const videoDuration = gtm4wp_dailymotionDuration();
		if ( ! videoDuration ) {
			return;
		}

		const videoCurrentTime = gtm4wp_dailymotionCurrentTime();
		const videoPercentage = Math.floor(
			( videoCurrentTime / videoDuration ) * 100
		);

		gtm4wpMediaMilestones(
			gtm4wp_dailymotion_percentage_tracking_marks,
			videoid,
			videoPercentage,
			gtm4wp_dailymotion_percentage_tracking,
			function ( i ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlaybackPercentage',
					mediaType: 'dailymotion',
					mediaData: gtm4wp_dailymotionMediaData(),
					mediaCurrentTime: videoCurrentTime,
					mediaPercentage: i,
					...gtm4wpNativeVideoParams( {
						provider: 'dailymotion',
						status: 'progress',
						url: videourl,
						title: lastState.videoTitle || videoid,
						currentTime: videoCurrentTime,
						duration: videoDuration,
						percent: i,
						element: container,
					} ),
				} );
			}
		);
	};

	// Refresh the cache BEFORE the handler reads it; a handler registered
	// directly on player.on() would report the PREVIOUS event's time.
	const gtm4wp_dailymotionOn = function ( eventName, callback ) {
		player.on( eventName, function ( state ) {
			if ( state ) {
				lastState = state;
			}
			callback( state || {} );
		} );
	};

	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_CRITICALPATHREADY,
		function () {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'dailymotion',
				mediaData: gtm4wp_dailymotionMediaData(),
				mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: lastState.videoTitle || videoid,
					currentTime: gtm4wp_dailymotionCurrentTime(),
					duration: gtm4wp_dailymotionDuration(),
					element: container,
				} ),
			} );
		}
	);

	// VIDEO_PLAY, not VIDEO_START: 'play' is every transition INTO playback,
	// resumes included; subscribing to both doubles the first play.
	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_PLAY, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'play' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_PAUSE, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'pause' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_END, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'ended' );
	} );

	// VIDEO_SEEKEND, not VIDEO_SEEKSTART: 'seeked' is a COMPLETED seek at the
	// destination; both would double every scrub.
	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_SEEKEND, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'seeked' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_BUFFERING, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'buffering' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_TIMECHANGE, function () {
		gtm4wp_onDailymotionPercentageChange();
	} );

	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_VOLUMECHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'volumechange',
				state.playerVolume
			);
		}
	);

	gtm4wp_dailymotionOn(
		dailymotion.events.VIDEO_QUALITYCHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'qualitychange',
				state.videoQuality
			);
		}
	);

	// Fullscreen and PiP are one presentation mode (the event parameter); the
	// event NAME stays 'fullscreenchange', the data layer contract.
	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_PRESENTATIONMODECHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'fullscreenchange',
				state.playerPresentationMode
			);
		}
	);

	gtm4wp_dailymotionOn( dailymotion.events.PLAYER_ERROR, function ( state ) {
		gtm4wp_onDailymotionPlayerEvent( 'error', state.playerError );
	} );
}

function gtm4wp_initDailymotionTracking() {
	// The library URL is built by PHP (MediaEventsModule::enqueue_scripts): it
	// carries the configured player ID, url-encoded once, server side. No
	// config = nothing to fetch, embeds untouched and untracked.
	const config = window.gtm4wp_dailymotion_config || {};
	const sdk = 'string' === typeof config.sdk ? config.sdk : '';

	// The legacy player integration (api.dmcdn.net/all.js) was sunset on
	// 2026-02-03, and the Player Embeds API cannot attach to an existing
	// ID-less oEmbed iframe, so each embed is replaced with a container div the
	// SDK fills (same shape as gtm4wp-twitch.js).
	const gtm4wp_wireDailymotionFrame = function ( dailymotion_frame ) {
		const info = gtm4wp_dailymotionEmbedInfo(
			dailymotion_frame.getAttribute( 'src' ) || ''
		);
		if ( ! info ) {
			return;
		}
		if ( ! dailymotion_frame.parentNode ) {
			return;
		}

		// The canonical watch URL, not the embed src (every video shares the
		// same geo.dailymotion.com/player.html).
		const videourl = 'https://www.dailymotion.com/video/' + info.videoid;
		const container = gtm4wp_dailymotionContainerFor( dailymotion_frame );

		// Marked BEFORE insertion so the iframe the SDK injects (which matches
		// this selector) is skipped by the shared observer instead of replaced
		// in an unbounded loop.
		container.setAttribute( 'data-gtm4wp-media-wired', '1' );
		dailymotion_frame.parentNode.replaceChild(
			container,
			dailymotion_frame
		);

		const options = { video: info.videoid };
		if ( info.playerid ) {
			options.player = info.playerid;
		}

		// Whether createPlayer() handed over a player: the failure handler also
		// covers the bind step, and the two failures need opposite responses.
		let created = false;

		const gtm4wp_onDailymotionFailure = function ( error ) {
			// Player created, only the wiring failed: leave the working video
			// alone. Not created: restore the original iframe, or a failure to
			// TRACK becomes a failure to SHOW. Marked FIRST, or the shared
			// observer would wire, replace, fail and restore it forever.
			if ( ! created ) {
				dailymotion_frame.setAttribute(
					'data-gtm4wp-media-wired',
					'1'
				);
				if ( container.parentNode ) {
					container.parentNode.replaceChild(
						dailymotion_frame,
						container
					);
				}
			}

			// Reported like every unrecoverable player failure in the family
			// (see the Vimeo .catch): a mediaPlayerEvent 'error'.
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'dailymotion',
				mediaData: {
					id: info.videoid,
					author: '',
					title: info.videoid,
					url: videourl,
					duration: 0,
				},
				mediaCurrentTime: 0,
				mediaPlayerEvent: 'error',
				mediaPlayerEventParam: error,
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					status: '',
					url: videourl,
					title: info.videoid,
					currentTime: 0,
					duration: 0,
					// Whichever node is on the page now.
					element: created ? container : dailymotion_frame,
				} ),
			} );
		};

		// The try/catch is load-bearing: the embed is ALREADY GONE, and a
		// synchronous throw from createPlayer() never reaches the .catch.
		try {
			dailymotion
				.createPlayer( container.id, options )
				.then( function ( player ) {
					created = true;
					gtm4wp_bindDailymotionPlayer(
						player,
						info.videoid,
						videourl,
						container
					);
				} )
				.catch( gtm4wp_onDailymotionFailure );
		} catch ( error ) {
			gtm4wp_onDailymotionFailure( error );
		}
	};

	gtm4wpObserveMedia(
		'iframe[src*="dailymotion.com"],iframe[src*="dai.ly"]',
		gtm4wp_wireDailymotionFrame,
		function () {
			// `dailymotion` alone is not enough: the documented bootstrap defines
			// window.dailymotion = { onScriptLoaded } BEFORE the library loads.
			return (
				typeof dailymotion !== 'undefined' &&
				null !== dailymotion &&
				typeof dailymotion.createPlayer === 'function' &&
				!! dailymotion.events
			);
		},
		sdk
	);
}

gtm4wpOnReady( gtm4wp_initDailymotionTracking );
