import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_videopress_percentage_tracking = 10;

// Every store here is keyed by a guid taken from the message, so null
// prototypes: a guid of `__proto__` on a plain object resolves to
// Object.prototype (truthy, skipping the "not seen yet" branch) and a write
// back through it would land on every object on the page.
const gtm4wp_videopress_percentage_tracking_marks = Object.create( null );

// Last state pushed per video: the player's duplicate signals (play +
// playing, seeking + seeked) collapse to one state change.
const gtm4wp_videopress_last_state = Object.create( null );

// Position and duration per video, carried from the one message reporting
// each to the many that do not: `videopress_timeupdate` carries position and
// NO duration, `videopress_durationchange` duration and NO position, the state
// messages neither (U109). Reading them off the message in hand reports 0 on
// every state change and silences the percentage tracking.
const gtm4wp_videopress_playback = Object.create( null );

// Videos whose mediaPlayerReady was pushed: the duration message stands in for
// "ready" and can repeat (quality switch), so once per video.
const gtm4wp_videopress_ready = Object.create( null );

// Player messages that arrive WITHOUT the `videopress_` prefix (Jetpack's own
// bridge relays the prefixed spelling, handled by the normal path).
const gtm4wp_videopress_unprefixed_events = [ 'toggle_fullscreen' ];

/**
 * Reads a number out of a player message, telling "not reported" apart from 0.
 *
 * @param {*} value The raw property value.
 * @return {number|null} The number, or null when the message did not carry one.
 */
function gtm4wp_videoPressNumber( value ) {
	if ( undefined === value || null === value || '' === value ) {
		return null;
	}

	const number = Number( value );

	return Number.isFinite( number ) ? number : null;
}

/**
 * Reads one playback value out of a message, in seconds, preferring the
 * millisecond form (`currentTimeMs` / `durationMs`). Null, not 0, when the
 * message carries neither: 0 is a real position, and the caller must keep the
 * last reported value.
 *
 * @param {*} ms      The millisecond form, when the message carried one.
 * @param {*} seconds The second form, when the message carried one.
 * @return {number|null} The value in seconds, or null when the message reported
 *                       neither.
 */
function gtm4wp_videoPressSeconds( ms, seconds ) {
	const fromMs = gtm4wp_videoPressNumber( ms );

	if ( null !== fromMs ) {
		return fromMs / 1000;
	}

	return gtm4wp_videoPressNumber( seconds );
}

/**
 * Records whatever playback values a message carried, and returns everything
 * currently known about that video's playback.
 *
 * @param {string} guid The VideoPress guid the message reported.
 * @param {Object} data The player message payload.
 * @return {{currentTime: number, duration: number}} The video's known position
 *                                                   and duration, in seconds.
 */
function gtm4wp_videoPressPlayback( guid, data ) {
	const playback = gtm4wp_videopress_playback[ guid ] || {
		currentTime: 0,
		duration: 0,
	};

	const currentTime = gtm4wp_videoPressSeconds(
		data.currentTimeMs,
		data.currentTime
	);
	if ( null !== currentTime ) {
		playback.currentTime = currentTime;
	}

	const duration = gtm4wp_videoPressSeconds( data.durationMs, data.duration );
	if ( null !== duration ) {
		playback.duration = duration;
	}

	gtm4wp_videopress_playback[ guid ] = playback;

	return playback;
}

/**
 * Validates that a postMessage originated from a VideoPress player.
 *
 * @param {string} origin The message event origin.
 * @return {boolean} True when the origin is a VideoPress/WordPress.com host.
 */
function gtm4wp_isVideoPressOrigin( origin ) {
	if ( typeof origin !== 'string' ) {
		return false;
	}

	try {
		const host = new URL( origin ).host;
		return (
			host === 'videopress.com' ||
			host.endsWith( '.videopress.com' ) ||
			host === 'video.wordpress.com'
		);
	} catch ( e ) {
		return false;
	}
}

/**
 * Finds the embed a VideoPress message came from (for gtm.videoVisible): by
 * the source window first (exact with several embeds of one video), then by
 * the guid in the embed URL (message from a nested frame).
 *
 * @param {Window} source The message event's source window.
 * @param {string} guid   The VideoPress guid the message reported.
 * @return {HTMLElement|null} The embed iframe, or null when it cannot be found.
 */
function gtm4wp_videoPressFrame( source, guid ) {
	const frames = document.querySelectorAll(
		'iframe[src*="videopress.com"],iframe[src*="video.wordpress.com"]'
	);

	if ( source ) {
		for ( let i = 0; i < frames.length; i++ ) {
			if ( frames[ i ].contentWindow === source ) {
				return frames[ i ];
			}
		}
	}

	if ( guid ) {
		for ( let i = 0; i < frames.length; i++ ) {
			if (
				( frames[ i ].getAttribute( 'src' ) || '' ).indexOf( guid ) > -1
			) {
				return frames[ i ];
			}
		}
	}

	return null;
}

function gtm4wp_initVideoPressTracking() {
	// No SDK: the players postMessage their state, so one window 'message'
	// listener serves every embed, attached only once an embed is present.
	const gtm4wp_videoPressMediaData = function ( guid, duration ) {
		return {
			id: guid,
			author: '',
			title: guid,
			url: 'https://videopress.com/v/' + guid,
			duration,
		};
	};

	const gtm4wp_onVideoPressPercentageChange = function (
		guid,
		currentTime,
		duration,
		frame
	) {
		if ( ! duration ) {
			return;
		}

		const videoPercentage = Math.floor( ( currentTime / duration ) * 100 );

		gtm4wpMediaMilestones(
			gtm4wp_videopress_percentage_tracking_marks,
			guid,
			videoPercentage,
			gtm4wp_videopress_percentage_tracking,
			function ( i ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlaybackPercentage',
					mediaType: 'videopress',
					mediaData: gtm4wp_videoPressMediaData( guid, duration ),
					mediaCurrentTime: currentTime,
					mediaPercentage: i,
					...gtm4wpNativeVideoParams( {
						provider: 'videopress',
						status: 'progress',
						url: 'https://videopress.com/v/' + guid,
						title: guid,
						currentTime,
						duration,
						percent: i,
						element: frame,
					} ),
				} );
			}
		);
	};

	const gtm4wp_onVideoPressStateChange = function (
		guid,
		playerState,
		currentTime,
		duration,
		frame
	) {
		if ( gtm4wp_videopress_last_state[ guid ] === playerState ) {
			return;
		}
		gtm4wp_videopress_last_state[ guid ] = playerState;

		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'videopress',
			mediaData: gtm4wp_videoPressMediaData( guid, duration ),
			mediaCurrentTime: currentTime,
			mediaPlayerState: playerState,
			...gtm4wpNativeVideoParams( {
				provider: 'videopress',
				status: gtm4wpNativeVideoStatus( playerState ),
				url: 'https://videopress.com/v/' + guid,
				title: guid,
				currentTime,
				duration,
				element: frame,
			} ),
		} );
	};

	const gtm4wp_onVideoPressMessage = function ( event ) {
		if ( ! gtm4wp_isVideoPressOrigin( event.origin ) ) {
			return;
		}

		let data = event.data;
		if ( typeof data === 'string' ) {
			try {
				data = JSON.parse( data );
			} catch ( e ) {
				return;
			}
		}

		if ( ! data || typeof data.event !== 'string' ) {
			return;
		}

		let eventName;
		if ( data.event.indexOf( 'videopress_' ) === 0 ) {
			eventName = data.event.substring( 'videopress_'.length );
		} else if (
			gtm4wp_videopress_unprefixed_events.indexOf( data.event ) > -1
		) {
			// Widens nothing: the origin was checked above.
			eventName = data.event;
		} else {
			return;
		}

		const guid = data.id || '';
		// Times from the running record, never the message alone (see
		// gtm4wp_videopress_playback).
		const playback = gtm4wp_videoPressPlayback( guid, data );
		const currentTime = playback.currentTime;
		const duration = playback.duration;

		// Resolved lazily, once per message: timeupdate arrives several times a
		// second and usually pushes nothing.
		let resolvedFrame;
		const frame = function () {
			if ( undefined === resolvedFrame ) {
				resolvedFrame = gtm4wp_videoPressFrame( event.source, guid );
			}

			return resolvedFrame;
		};

		switch ( eventName ) {
			// No "ready" message: `durationchange` is the signal, once per video.
			// `loadedmetadata` is not sent today; kept so the HTML5-native
			// spelling maps here rather than to a generic player event.
			case 'loadedmetadata':
			case 'durationchange':
				if ( gtm4wp_videopress_ready[ guid ] ) {
					break;
				}
				gtm4wp_videopress_ready[ guid ] = true;

				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerReady',
					mediaType: 'videopress',
					mediaData: gtm4wp_videoPressMediaData( guid, duration ),
					mediaCurrentTime: currentTime,
					...gtm4wpNativeVideoParams( {
						provider: 'videopress',
						// "Ready" has no native GTM video status.
						status: '',
						url: 'https://videopress.com/v/' + guid,
						title: guid,
						currentTime,
						duration,
						element: frame,
					} ),
				} );
				break;

			// Documented: `playing`, `pause`, `seeking`. The HTML5-native
			// spellings are tolerated aliases (Sensei's adapter listens for
			// `play` too); the duplicate-state guard collapses a pair.
			case 'play':
			case 'playing':
				gtm4wp_onVideoPressStateChange(
					guid,
					'play',
					currentTime,
					duration,
					frame
				);
				break;

			case 'pause':
			case 'paused':
				gtm4wp_onVideoPressStateChange(
					guid,
					'pause',
					currentTime,
					duration,
					frame
				);
				break;

			case 'ended':
				gtm4wp_onVideoPressStateChange(
					guid,
					'ended',
					currentTime,
					duration,
					frame
				);
				break;

			case 'seeking':
			case 'seeked':
				gtm4wp_onVideoPressStateChange(
					guid,
					'seeked',
					currentTime,
					duration,
					frame
				);
				break;

			case 'timeupdate':
				gtm4wp_onVideoPressPercentageChange(
					guid,
					currentTime,
					duration,
					frame
				);
				break;

			default: {
				// mediaPlayerEventParam (the HTML5 tracker's key) only for the two
				// messages carrying a value; the other events keep their shape.
				const eventParam = {};
				if ( 'toggle_fullscreen' === eventName ) {
					eventParam.mediaPlayerEventParam = !! data.isFullScreen;
				} else if (
					'toggle-source' === eventName &&
					data.qualityLevelInternalName
				) {
					eventParam.mediaPlayerEventParam =
						data.qualityLevelInternalName;
				}

				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'videopress',
					mediaData: gtm4wp_videoPressMediaData( guid, duration ),
					mediaCurrentTime: currentTime,
					mediaPlayerEvent: eventName,
					...eventParam,
					...gtm4wpNativeVideoParams( {
						provider: 'videopress',
						// Not a playback state GTM models.
						status: '',
						url: 'https://videopress.com/v/' + guid,
						title: guid,
						currentTime,
						duration,
						element: frame,
					} ),
				} );
			}
		}
	};

	// Idempotent: a re-injected bundle removes the previous handler first.
	const gtm4wp_attachVideoPressListener = function () {
		if ( window.gtm4wp_videopress_handler ) {
			window.removeEventListener(
				'message',
				window.gtm4wp_videopress_handler
			);
		}
		window.gtm4wp_videopress_handler = gtm4wp_onVideoPressMessage;
		window.addEventListener( 'message', gtm4wp_onVideoPressMessage );
	};

	gtm4wpObserveMedia(
		'iframe[src*="videopress.com"],iframe[src*="video.wordpress.com"]',
		gtm4wp_attachVideoPressListener
	);
}

gtm4wpOnReady( gtm4wp_initVideoPressTracking );
