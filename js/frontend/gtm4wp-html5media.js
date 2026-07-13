import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
} from './lib/native-video-params';

const gtm4wp_html5media_percentage_tracking = 10;
const gtm4wp_html5media_percentage_tracking_marks = {};

function gtm4wp_initHTML5MediaTracking() {
	// Native <video>/<audio> players. Provider embeds (YouTube, Vimeo,
	// SoundCloud) are iframes, not media elements, so there is nothing to
	// exclude here — they are tracked by their own dedicated trackers.
	const gtm4wp_html5_media_elements =
		document.querySelectorAll( 'video, audio' );
	if (
		! gtm4wp_html5_media_elements ||
		gtm4wp_html5_media_elements.length == 0
	) {
		return;
	}

	gtm4wp_html5_media_elements.forEach( function ( media_element ) {
		// The filename is the only stable identifier a local media file
		// exposes, so it stands in for both the id and the title. It is read
		// from the live currentSrc on every push because a source can still be
		// resolving when the element is first seen, and <source>-based players
		// only settle currentSrc after resource selection.
		const gtm4wp_getHTML5MediaFilename = function () {
			return media_element.currentSrc
				? media_element.currentSrc.split( '/' ).pop()
				: '';
		};

		// Pushes gtm4wp.mediaPlayerReady once the element's metadata (and thus
		// its real duration and resolved currentSrc) is available, matching the
		// "ready carries a real duration" contract of the YouTube, Vimeo and
		// SoundCloud trackers.
		const gtm4wp_pushHTML5MediaReady = function () {
			const html5media_filename = gtm4wp_getHTML5MediaFilename();

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'html5media',
				mediaData: {
					id: html5media_filename,
					// Native media elements expose no author metadata, so
					// `author` is intentionally left empty in every push.
					author: '',
					title: html5media_filename,
					url: media_element.currentSrc,
					duration: isNaN( media_element.duration )
						? 0
						: media_element.duration,
				},
				mediaCurrentTime: 0,
			} );
		};

		// HAVE_METADATA (1) or more means duration/currentSrc are known already
		// (e.g. preload="metadata"/"auto" that finished before this ran), so
		// fire now; otherwise wait for loadedmetadata. `once` guards against a
		// second ready push if the resource is later reloaded.
		if ( media_element.readyState >= 1 ) {
			gtm4wp_pushHTML5MediaReady();
		} else {
			media_element.addEventListener(
				'loadedmetadata',
				gtm4wp_pushHTML5MediaReady,
				{ once: true }
			);
		}

		// Pushes a gtm4wp.mediaPlayerStateChange. `state` is the normalized
		// GTM4WP state (e.g. 'play', 'buffering'), which can differ from the
		// DOM event name (the 'playing' event maps to the 'play' state), so it
		// is what feeds gtm4wpNativeVideoStatus for the built-in Video variables.
		const gtm4wp_pushHTML5MediaStateChange = function ( state ) {
			const html5media_filename = gtm4wp_getHTML5MediaFilename();
			const duration = isNaN( media_element.duration )
				? 0
				: media_element.duration;
			const currentTime = isNaN( media_element.currentTime )
				? 0
				: media_element.currentTime;

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'html5media',
				mediaData: {
					id: html5media_filename,
					author: '',
					title: html5media_filename,
					url: media_element.currentSrc,
					duration,
				},
				mediaPlayerState: state,
				mediaCurrentTime: currentTime,
				...gtm4wpNativeVideoParams( {
					provider: 'html5',
					status: gtm4wpNativeVideoStatus( state ),
					url: media_element.currentSrc,
					title: html5media_filename,
					currentTime,
					duration,
				} ),
			} );
		};

		// Pushes a gtm4wp.mediaPlayerEvent for interactions that are not state
		// changes (errors, rate/volume/PiP/fullscreen changes).
		const gtm4wp_pushHTML5MediaPlayerEvent = function (
			eventName,
			eventParam
		) {
			const html5media_filename = gtm4wp_getHTML5MediaFilename();

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'html5media',
				mediaData: {
					id: html5media_filename,
					author: '',
					title: html5media_filename,
					url: media_element.currentSrc,
					duration: isNaN( media_element.duration )
						? 0
						: media_element.duration,
				},
				mediaCurrentTime: isNaN( media_element.currentTime )
					? 0
					: media_element.currentTime,
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
			} );
		};

		// The DOM "playing" event (real playback, after any initial buffering)
		// is used as the start signal so it matches the YouTube, Vimeo and
		// SoundCloud trackers; it still reports mediaPlayerState "play" to keep
		// the data layer contract. "waiting" is HTML5's buffering signal and
		// maps to GTM's built-in "buffering" video status, matching the Vimeo
		// bufferstart handler.
		media_element.addEventListener( 'playing', function () {
			gtm4wp_pushHTML5MediaStateChange( 'play' );
		} );

		media_element.addEventListener( 'pause', function () {
			gtm4wp_pushHTML5MediaStateChange( 'pause' );
		} );

		media_element.addEventListener( 'seeked', function () {
			gtm4wp_pushHTML5MediaStateChange( 'seeked' );
		} );

		media_element.addEventListener( 'ended', function () {
			gtm4wp_pushHTML5MediaStateChange( 'ended' );
		} );

		media_element.addEventListener( 'waiting', function () {
			gtm4wp_pushHTML5MediaStateChange( 'buffering' );
		} );

		media_element.addEventListener( 'error', function () {
			// See https://developer.mozilla.org/en-US/docs/Web/API/MediaError/code
			gtm4wp_pushHTML5MediaPlayerEvent(
				'error',
				media_element.error?.code
			);
		} );

		media_element.addEventListener( 'ratechange', function () {
			gtm4wp_pushHTML5MediaPlayerEvent(
				'ratechange',
				media_element.playbackRate
			);
		} );

		media_element.addEventListener( 'volumechange', function () {
			gtm4wp_pushHTML5MediaPlayerEvent(
				'volumechange',
				media_element.volume
			);
		} );

		// Picture-in-Picture and fullscreen only apply to <video>. These mirror
		// the enter/leave picture-in-picture and fullscreenchange events the
		// Vimeo tracker already reports.
		if ( media_element.tagName === 'VIDEO' ) {
			media_element.addEventListener(
				'enterpictureinpicture',
				function () {
					gtm4wp_pushHTML5MediaPlayerEvent(
						'enterpictureinpicture',
						true
					);
				}
			);

			media_element.addEventListener(
				'leavepictureinpicture',
				function () {
					gtm4wp_pushHTML5MediaPlayerEvent(
						'leavepictureinpicture',
						true
					);
				}
			);

			media_element.addEventListener( 'fullscreenchange', function () {
				gtm4wp_pushHTML5MediaPlayerEvent(
					'fullscreenchange',
					!! document.fullscreenElement
				);
			} );
		}

		media_element.addEventListener( 'timeupdate', function () {
			if (
				isNaN( media_element.duration ) ||
				isNaN( media_element.currentTime )
			) {
				return;
			}

			const videoDuration = media_element.duration;
			const videoCurrentTime = media_element.currentTime;
			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);
			const html5media_filename = gtm4wp_getHTML5MediaFilename();
			// Keyed by the full currentSrc (not just the filename) so two
			// different files that happen to share a basename do not share
			// milestone state.
			const videoid = media_element.currentSrc;

			if (
				typeof gtm4wp_html5media_percentage_tracking_marks[
					videoid
				] === 'undefined'
			) {
				gtm4wp_html5media_percentage_tracking_marks[ videoid ] = [];
			}

			for (
				let i = 0;
				i < 100;
				i += gtm4wp_html5media_percentage_tracking
			) {
				if (
					videoPercentage > i &&
					gtm4wp_html5media_percentage_tracking_marks[
						videoid
					].indexOf( i ) == -1
				) {
					gtm4wp_html5media_percentage_tracking_marks[ videoid ].push(
						i
					);

					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'html5media',
						mediaData: {
							id: html5media_filename,
							author: '',
							title: html5media_filename,
							url: media_element.currentSrc,
							duration: videoDuration,
						},
						mediaCurrentTime: videoCurrentTime,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'html5',
							status: 'progress',
							url: media_element.currentSrc,
							title: html5media_filename,
							currentTime: videoCurrentTime,
							duration: videoDuration,
							percent: i,
						} ),
					} );
				}
			}
		} );
	} ); // end each media element
}

// The tracker bundle may execute before or after the DOM has finished parsing
// (e.g. when loaded with a defer/async strategy or injected late by a tag
// manager). Guard against a DOMContentLoaded event that has already fired,
// which would otherwise leave the tracking silently uninitialized.
if ( document.readyState === 'loading' ) {
	window.addEventListener(
		'DOMContentLoaded',
		gtm4wp_initHTML5MediaTracking
	);
} else {
	gtm4wp_initHTML5MediaTracking();
}
