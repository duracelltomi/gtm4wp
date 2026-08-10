import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_videopress_percentage_tracking = 10;

// Keyed by the media id the provider reports, so a null prototype: on a plain
// object a key of `__proto__` resolves to Object.prototype instead of a missing
// entry, and writing it back sets the store's prototype instead of a property.
// Every store in this file is declared that way; the one below carries the case
// where it matters most.
const gtm4wp_videopress_percentage_tracking_marks = Object.create( null );

// Tracks the last player state pushed per video so the duplicate signals the
// VideoPress player emits (play + playing, seeking + seeked) collapse to a
// single state change.
const gtm4wp_videopress_last_state = Object.create( null );

// Playback position and duration per video, carried from the one message that
// reports each to the many that do not.
//
// VideoPress does not repeat these on every message the way the other embedded
// players do. `videopress_timeupdate` carries the position and NO duration,
// `videopress_durationchange` carries the duration and NO position, and the
// state messages (`videopress_playing`, `_pause`, `_ended`, `_seeking`,
// `_volumechange`) carry neither — only `event` and `id`. Reading the values off
// whichever message happens to be in hand therefore reports 0 for both on every
// state change, and leaves the percentage tracking with no duration to divide
// by, which its zero-duration guard turns into total silence. Registered as
// upstream claim U109.
//
// Both stores below are keyed on a guid taken from the message, and this one is
// written back THROUGH that lookup, so they are null-prototype: on a plain
// object a guid of `__proto__` resolves to `Object.prototype` — truthy, so the
// "not seen yet" branch is skipped — and the next assignment would write a
// playback position onto every object on the page. The origin check upstream
// makes that a VideoPress-only reach rather than an open one, which is a reason
// to keep it cheap to close, not a reason to leave it open.
const gtm4wp_videopress_playback = Object.create( null );

// Videos whose gtm4wp.mediaPlayerReady has already been pushed. The message
// reporting the duration is what stands in for a "ready" signal here, and
// VideoPress can report the duration more than once for one video (a quality
// switch changes the source), so the push is held to once per video.
const gtm4wp_videopress_ready = Object.create( null );

// The player messages that arrive WITHOUT the `videopress_` prefix every other
// one carries. Documented as `toggle_fullscreen`; the prefixed spelling is
// handled by the normal path, because Jetpack's own player bridge relays it
// under that name instead.
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
 * Reads one playback value out of a message, in seconds.
 *
 * VideoPress reports both a millisecond and a second form (`currentTimeMs`
 * alongside `currentTime`, `durationMs` alongside `duration`); the millisecond
 * one is preferred as the more precise of the two.
 *
 * Returning null rather than 0 for a message that carries neither is the whole
 * point: 0 is also a perfectly real playback position, so a caller that cannot
 * tell the two apart has no way to keep the last reported value — which is what
 * this tracker needs to do on every message VideoPress sends without one.
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
 * Finds the embed a VideoPress message came from, so the push can report whether
 * that player is in the viewport (gtm.videoVisible).
 *
 * This tracker never wires an element — it listens on the window — so the frame
 * is resolved per message: first by the message's own source window, which is
 * exact even with several embeds of the same video on the page, then by the guid
 * in the embed URL for a player whose message arrives from a nested frame.
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
	// No SDK is enqueued: VideoPress players emit their state to the parent
	// window via postMessage, so a single window 'message' listener serves every
	// embed. It is attached only once a VideoPress embed is present (see the
	// gtm4wpObserveMedia call below), so pages without one pay nothing.
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
			// Accepting an unprefixed name widens nothing: the origin was
			// already checked above, so this only reaches messages a VideoPress
			// player sent. Without it the fullscreen message — the one player
			// message that carries no prefix — is dropped on the floor.
			eventName = data.event;
		} else {
			return;
		}

		const guid = data.id || '';
		// Times come from the running record rather than from this message: see
		// gtm4wp_videopress_playback for why the message alone is never enough.
		const playback = gtm4wp_videoPressPlayback( guid, data );
		const currentTime = playback.currentTime;
		const duration = playback.duration;

		// Resolved lazily and at most once per message: only a push that carries
		// gtm.videoVisible reads it, while timeupdate messages — most of them —
		// arrive several times a second and usually push nothing.
		let resolvedFrame;
		const frame = function () {
			if ( undefined === resolvedFrame ) {
				resolvedFrame = gtm4wp_videoPressFrame( event.source, guid );
			}

			return resolvedFrame;
		};

		switch ( eventName ) {
			// VideoPress has no "player ready" message of its own. The duration
			// arriving is the first moment there is a player worth describing,
			// which makes `durationchange` the signal — held to one push per
			// video, because the duration is reported again on a quality switch.
			// `loadedmetadata` is not a name VideoPress sends today; it is kept
			// so a player build that adopts the HTML5-native spelling still maps
			// here rather than falling through to a generic player event.
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

			// VideoPress documents `playing`, `pause` and `seeking`. The
			// HTML5-native spellings beside each of them are tolerated aliases,
			// not names the player is known to send: `play` is the one another
			// Automattic consumer (Sensei's player adapter) also listens for,
			// and the duplicate-state guard above collapses the pair anyway if
			// a build ever sends both.
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
				// The two player messages carrying a value worth reporting,
				// under the same key the HTML5 tracker uses for its own
				// fullscreenchange and ratechange pushes. The key is added only
				// when there is something to put in it, so the other player
				// events keep the shape they have always had.
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

	// Attach the window 'message' listener as soon as a VideoPress embed is
	// present — at init or inserted later (popup/AJAX). Attaching is idempotent:
	// the tracker can be enqueued more than once (a tag manager re-injects it),
	// so a previously bound handler is removed before binding the current one.
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
