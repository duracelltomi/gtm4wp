import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_videopress_percentage_tracking = 10;
const gtm4wp_videopress_percentage_tracking_marks = {};

// Tracks the last player state pushed per video so the duplicate signals the
// VideoPress player emits (play + playing, seeking + seeked) collapse to a
// single state change.
const gtm4wp_videopress_last_state = {};

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
		duration
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
					} ),
				} );
			}
		);
	};

	const gtm4wp_onVideoPressStateChange = function (
		guid,
		playerState,
		currentTime,
		duration
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

		if (
			! data ||
			typeof data.event !== 'string' ||
			data.event.indexOf( 'videopress_' ) !== 0
		) {
			return;
		}

		const eventName = data.event.substring( 'videopress_'.length );
		const guid = data.id || '';
		// VideoPress reports times in milliseconds; gtm4wp media events and the
		// gtm.video* variables use seconds.
		const currentTime = ( data.currentTimeMs || 0 ) / 1000;
		const duration = ( data.durationMs || 0 ) / 1000;

		switch ( eventName ) {
			case 'loadedmetadata':
			case 'durationchange':
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerReady',
					mediaType: 'videopress',
					mediaData: gtm4wp_videoPressMediaData( guid, duration ),
					mediaCurrentTime: currentTime,
				} );
				break;

			case 'play':
			case 'playing':
				gtm4wp_onVideoPressStateChange(
					guid,
					'play',
					currentTime,
					duration
				);
				break;

			case 'pause':
			case 'paused':
				gtm4wp_onVideoPressStateChange(
					guid,
					'pause',
					currentTime,
					duration
				);
				break;

			case 'ended':
				gtm4wp_onVideoPressStateChange(
					guid,
					'ended',
					currentTime,
					duration
				);
				break;

			case 'seeking':
			case 'seeked':
				gtm4wp_onVideoPressStateChange(
					guid,
					'seeked',
					currentTime,
					duration
				);
				break;

			case 'timeupdate':
				gtm4wp_onVideoPressPercentageChange(
					guid,
					currentTime,
					duration
				);
				break;

			default:
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'videopress',
					mediaData: gtm4wp_videoPressMediaData( guid, duration ),
					mediaCurrentTime: currentTime,
					mediaPlayerEvent: eventName,
				} );
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
