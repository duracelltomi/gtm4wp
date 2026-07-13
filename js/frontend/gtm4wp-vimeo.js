import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
} from './lib/native-video-params';

const gtm4wp_vimeo_percentage_tracking = 10;
const gtm4wp_vimeo_percentage_tracking_marks = {};

function gtm4wp_initVimeoTracking() {
	// The Vimeo Player SDK (player.vimeo.com/api/player.js) is enqueued as a
	// dependency of this tracker, but it can still be missing at runtime if a
	// consent manager, an ad blocker or a network error stopped it from
	// loading. Without it there is nothing to hook into, so bail out
	// gracefully instead of throwing on `new Vimeo.Player()`.
	if ( typeof Vimeo === 'undefined' || typeof Vimeo.Player === 'undefined' ) {
		return;
	}

	const gtm4wp_vimeo_frames = document.querySelectorAll(
		'iframe[src*="vimeo.com"]'
	);
	if ( ! gtm4wp_vimeo_frames || gtm4wp_vimeo_frames.length == 0 ) {
		return;
	}

	gtm4wp_vimeo_frames.forEach( function ( vimeo_frame ) {
		const vimeoapi = new Vimeo.Player( vimeo_frame );
		const videourl = vimeo_frame.getAttribute( 'src' ).split( '?' ).shift();
		const videoid = videourl.split( '/' ).pop();

		vimeo_frame.setAttribute( 'data-player_id', videoid );
		vimeo_frame.setAttribute( 'data-player_url', videourl );

		vimeoapi
			.getVideoTitle()
			.then( function ( title ) {
				vimeo_frame.setAttribute( 'data-player_title', title );

				vimeoapi
					.getDuration()
					.then( function ( duration ) {
						vimeo_frame.setAttribute(
							'data-player_duration',
							duration
						);

						window[ gtm4wp_datalayer_name ].push( {
							event: 'gtm4wp.mediaPlayerReady',
							mediaType: 'vimeo',
							mediaData: {
								id: videoid,
								// The Vimeo Player SDK exposes no owner/author
								// name (only getVideoTitle/getVideoId/getDuration
								// and friends), so `author` is intentionally left
								// empty here and in every push below.
								author: '',
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								url: videourl,
								duration,
							},
							mediaCurrentTime: 0,
						} );
					} )
					.catch( function ( error ) {
						window[ gtm4wp_datalayer_name ].push( {
							event: 'gtm4wp.mediaPlayerEvent',
							mediaType: 'vimeo',
							mediaData: {
								id: videoid,
								author: '',
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								url: videourl,
								duration: 0,
							},
							mediaCurrentTime: 0,
							mediaPlayerEvent: 'error',
							mediaPlayerEventParam: error,
						} );
					} ); // end of api call getDuration
			} )
			.catch( function ( error ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'vimeo',
					mediaData: {
						id: videoid,
						author: '',
						title: 'Unknown title',
						url: videourl,
						duration: 0,
					},
					mediaCurrentTime: 0,
					mediaPlayerEvent: 'error',
					mediaPlayerEventParam: error,
				} );
			} ); // end of api call getVideoTitle

		// Vimeo fires "play" as soon as playback is requested and "playing"
		// once it actually starts (after any initial buffering). We track
		// "playing" so the start signal matches the YouTube and SoundCloud
		// trackers, which report on the real playing state, and pair it with
		// the bufferstart/bufferend handlers below.
		vimeoapi.on( 'playing', function ( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'play', data );
		} );

		vimeoapi.on( 'pause', function ( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'pause', data );
		} );

		vimeoapi.on( 'ended', function ( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'ended', data );
		} );

		vimeoapi.on( 'seeked', function ( data ) {
			gtm4wp_onVimeoPlayerStateChange( 'seeked', data );
		} );

		// bufferstart/bufferend carry no data payload, so the current time is
		// read from the player. "bufferstart" maps to GTM's built-in
		// "buffering" video status (matching the YouTube tracker); "bufferend"
		// has no native equivalent and reports an empty native status.
		vimeoapi.on( 'bufferstart', function () {
			gtm4wp_onVimeoBufferStateChange( 'buffering' );
		} );

		vimeoapi.on( 'bufferend', function () {
			gtm4wp_onVimeoBufferStateChange( 'bufferend' );
		} );

		vimeoapi.on( 'texttrackchange', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent( 'texttrackchange', data );
		} );

		vimeoapi.on( 'volumechange', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent( 'volumechange', data.volume );
		} );

		vimeoapi.on( 'playbackratechange', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent(
				'playbackratechange',
				data.playbackRate
			);
		} );

		vimeoapi.on( 'qualitychange', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent( 'qualitychange', data.quality );
		} );

		vimeoapi.on( 'fullscreenchange', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent( 'fullscreenchange', data.fullscreen );
		} );

		vimeoapi.on( 'enterpictureinpicture', function () {
			gtm4wp_pushVimeoPlayerEvent( 'enterpictureinpicture', true );
		} );

		vimeoapi.on( 'leavepictureinpicture', function () {
			gtm4wp_pushVimeoPlayerEvent( 'leavepictureinpicture', true );
		} );

		vimeoapi.on( 'error', function ( data ) {
			gtm4wp_pushVimeoPlayerEvent( 'error', data );
		} );

		vimeoapi.on( 'timeupdate', function ( data ) {
			gtm4wp_onVimeoPercentageChange( data );
		} );

		// Pushes a gtm4wp.mediaPlayerEvent for player events that are not state
		// changes. Most of these events do not report the current position, so
		// it is fetched from the player before the push.
		const gtm4wp_pushVimeoPlayerEvent = function ( eventName, eventParam ) {
			vimeoapi
				.getCurrentTime()
				.then( function ( seconds ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlayerEvent',
						mediaType: 'vimeo',
						mediaData: {
							id: videoid,
							author: '',
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
						},
						mediaPlayerEvent: eventName,
						mediaPlayerEventParam: eventParam,
						mediaCurrentTime: seconds,
					} );
				} )
				.catch( function ( error ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlayerEvent',
						mediaType: 'vimeo',
						mediaData: {
							id: videoid,
							author: '',
							title: 'Unknown title',
							url: videourl,
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
						},
						mediaCurrentTime: 0,
						mediaPlayerEvent: 'error',
						mediaPlayerEventParam: error,
					} );
				} ); // end call api getCurrentTime()
		};

		const gtm4wp_onVimeoPlayerStateChange = function (
			player_state,
			data
		) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'vimeo',
				mediaData: {
					id: videoid,
					author: '',
					title: vimeo_frame.getAttribute( 'data-player_title' ),
					url: vimeo_frame.getAttribute( 'data-player_url' ),
					duration: data.duration,
				},
				mediaPlayerState: player_state,
				mediaCurrentTime: data.seconds,
				...gtm4wpNativeVideoParams( {
					provider: 'vimeo',
					status: gtm4wpNativeVideoStatus( player_state ),
					url: vimeo_frame.getAttribute( 'data-player_url' ),
					title: vimeo_frame.getAttribute( 'data-player_title' ),
					currentTime: data.seconds,
					duration: data.duration,
				} ),
			} );
		};

		// bufferstart/bufferend arrive without a data payload, so the same
		// mediaPlayerStateChange shape is rebuilt from the fetched current time
		// and the duration stored on the iframe once the player became ready.
		const gtm4wp_onVimeoBufferStateChange = function ( player_state ) {
			vimeoapi
				.getCurrentTime()
				.then( function ( seconds ) {
					const duration = Number(
						vimeo_frame.getAttribute( 'data-player_duration' )
					);

					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlayerStateChange',
						mediaType: 'vimeo',
						mediaData: {
							id: videoid,
							author: '',
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							duration,
						},
						mediaPlayerState: player_state,
						mediaCurrentTime: seconds,
						...gtm4wpNativeVideoParams( {
							provider: 'vimeo',
							status: gtm4wpNativeVideoStatus( player_state ),
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							currentTime: seconds,
							duration,
						} ),
					} );
				} )
				.catch( function ( error ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlayerEvent',
						mediaType: 'vimeo',
						mediaData: {
							id: videoid,
							author: '',
							title: 'Unknown title',
							url: videourl,
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
						},
						mediaCurrentTime: 0,
						mediaPlayerEvent: 'error',
						mediaPlayerEventParam: error,
					} );
				} ); // end call api getCurrentTime()
		};

		const gtm4wp_onVimeoPercentageChange = function ( data ) {
			const videoDuration = data.duration;
			const videoPercentage = Math.floor(
				( data.seconds / videoDuration ) * 100
			);

			if (
				typeof gtm4wp_vimeo_percentage_tracking_marks[ videoid ] ===
				'undefined'
			) {
				gtm4wp_vimeo_percentage_tracking_marks[ videoid ] = [];
			}

			for ( let i = 0; i < 100; i += gtm4wp_vimeo_percentage_tracking ) {
				if (
					videoPercentage > i &&
					gtm4wp_vimeo_percentage_tracking_marks[ videoid ].indexOf(
						i
					) == -1
				) {
					gtm4wp_vimeo_percentage_tracking_marks[ videoid ].push( i );

					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'vimeo',
						mediaData: {
							id: videoid,
							author: '',
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							duration: videoDuration,
						},
						mediaCurrentTime: data.seconds,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'vimeo',
							status: 'progress',
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							currentTime: data.seconds,
							duration: videoDuration,
							percent: i,
						} ),
					} );
				}
			}
		};
	} );
}

// The tracker bundle may execute before or after the DOM has finished parsing
// (e.g. when loaded with a defer/async strategy or injected late by a tag
// manager). Guard against a DOMContentLoaded event that has already fired,
// which would otherwise leave the tracking silently uninitialized.
if ( document.readyState === 'loading' ) {
	window.addEventListener( 'DOMContentLoaded', gtm4wp_initVimeoTracking );
} else {
	gtm4wp_initVimeoTracking();
}
