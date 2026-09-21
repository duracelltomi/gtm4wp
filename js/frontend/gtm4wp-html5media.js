import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaBareUrl,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_html5media_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_html5media_percentage_tracking_marks = Object.create( null );

function gtm4wp_initHTML5MediaTracking() {
	// Native <video>/<audio>; provider embeds are iframes and never match.
	const gtm4wp_wireHTML5MediaElement = function ( media_element ) {
		// The filename is the only stable identifier (id and title), read from
		// the live currentSrc on every push (<source> players settle it late).
		// Query and fragment are cut BEFORE the last segment (U106): a `?ver=`
		// or a signed CDN token would make the same file a different video and
		// put the token into the data layer. The reported `url` keeps them.
		const gtm4wp_getHTML5MediaFilename = function () {
			return gtm4wpMediaBareUrl( media_element.currentSrc )
				.split( '/' )
				.pop();
		};

		// mediaPlayerReady once metadata (real duration, resolved currentSrc)
		// is available, the family contract.
		const gtm4wp_pushHTML5MediaReady = function () {
			const html5media_filename = gtm4wp_getHTML5MediaFilename();
			const duration = isNaN( media_element.duration )
				? 0
				: media_element.duration;

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'html5media',
				mediaData: {
					id: html5media_filename,
					// Native media elements expose no author metadata.
					author: '',
					title: html5media_filename,
					url: media_element.currentSrc,
					duration,
				},
				mediaCurrentTime: 0,
				...gtm4wpNativeVideoParams( {
					provider: 'html5',
					// "Ready" has no native GTM video status.
					status: '',
					url: media_element.currentSrc,
					title: html5media_filename,
					currentTime: 0,
					duration,
					element: media_element,
				} ),
			} );
		};

		// readyState >= HAVE_METADATA: fire now, else wait for loadedmetadata
		// (`once`: no second push on a reload).
		if ( media_element.readyState >= 1 ) {
			gtm4wp_pushHTML5MediaReady();
		} else {
			media_element.addEventListener(
				'loadedmetadata',
				gtm4wp_pushHTML5MediaReady,
				{ once: true }
			);
		}

		// `state` is the normalized GTM4WP state ('playing' event -> 'play').
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
					element: media_element,
				} ),
			} );
		};

		// Non-state interactions (errors, rate/volume/PiP/fullscreen).
		const gtm4wp_pushHTML5MediaPlayerEvent = function (
			eventName,
			eventParam
		) {
			const html5media_filename = gtm4wp_getHTML5MediaFilename();
			const duration = isNaN( media_element.duration )
				? 0
				: media_element.duration;
			const currentTime = isNaN( media_element.currentTime )
				? 0
				: media_element.currentTime;

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'html5media',
				mediaData: {
					id: html5media_filename,
					author: '',
					title: html5media_filename,
					url: media_element.currentSrc,
					duration,
				},
				mediaCurrentTime: currentTime,
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
				...gtm4wpNativeVideoParams( {
					provider: 'html5',
					// These events are not playback states GTM models.
					status: '',
					url: media_element.currentSrc,
					title: html5media_filename,
					currentTime,
					duration,
					element: media_element,
				} ),
			} );
		};

		// "playing" (real playback, after buffering) is the start signal, still
		// reported as state "play"; "waiting" is HTML5's buffering signal.
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

		// PiP and fullscreen only apply to <video> (same events as Vimeo).
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
			const videoDuration = media_element.duration;
			const videoCurrentTime = media_element.currentTime;

			// Zero-duration guard (#20): x / 0 is Infinity and fires every mark.
			if ( ! videoDuration || isNaN( videoCurrentTime ) ) {
				return;
			}

			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);
			const html5media_filename = gtm4wp_getHTML5MediaFilename();
			// Keyed by the full currentSrc: two files may share a basename.
			const videoid = media_element.currentSrc;

			gtm4wpMediaMilestones(
				gtm4wp_html5media_percentage_tracking_marks,
				videoid,
				videoPercentage,
				gtm4wp_html5media_percentage_tracking,
				function ( i ) {
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
							element: media_element,
						} ),
					} );
				}
			);
		} );
	}; // end wire media element

	gtm4wpObserveMedia( 'video, audio', gtm4wp_wireHTML5MediaElement );
}

gtm4wpOnReady( gtm4wp_initHTML5MediaTracking );
