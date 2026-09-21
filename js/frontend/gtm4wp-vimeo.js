import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaSrcUrl,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_vimeo_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_vimeo_percentage_tracking_marks = Object.create( null );

function gtm4wp_initVimeoTracking() {
	// The Player SDK is handed to gtm4wpObserveMedia (fetched only when an
	// embed exists) and re-checked per element, since it can still be missing.
	const gtm4wp_wireVimeoFrame = function ( vimeo_frame ) {
		const vimeoapi = new Vimeo.Player( vimeo_frame );
		// gtm4wpMediaSrcUrl() strips the fragment (`#t=30`, WordPress' own
		// `#?secret=`, U106) that would otherwise end up in the id.
		const videourl = gtm4wpMediaSrcUrl( vimeo_frame );
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
								// The SDK exposes no owner/author name.
								author: '',
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								url: videourl,
								duration,
							},
							mediaCurrentTime: 0,
							...gtm4wpNativeVideoParams( {
								provider: 'vimeo',
								// "Ready" has no native GTM video status.
								status: '',
								url: videourl,
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								currentTime: 0,
								duration,
								element: vimeo_frame,
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
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								url: videourl,
								duration: 0,
							},
							mediaCurrentTime: 0,
							mediaPlayerEvent: 'error',
							mediaPlayerEventParam: error,
							...gtm4wpNativeVideoParams( {
								provider: 'vimeo',
								status: '',
								url: videourl,
								title: vimeo_frame.getAttribute(
									'data-player_title'
								),
								currentTime: 0,
								duration: 0,
								element: vimeo_frame,
							} ),
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
					...gtm4wpNativeVideoParams( {
						provider: 'vimeo',
						status: '',
						url: videourl,
						title: 'Unknown title',
						currentTime: 0,
						duration: 0,
						element: vimeo_frame,
					} ),
				} );
			} ); // end of api call getVideoTitle

		// "playing" (real start, after buffering), not "play" (requested),
		// matching the YouTube and SoundCloud trackers.
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

		// No data payload: the current time is read from the player.
		// "bufferend" has no native GTM status.
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

		// Non-state player events; the position is fetched from the player.
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
						...gtm4wpNativeVideoParams( {
							provider: 'vimeo',
							// These events are not playback states GTM models.
							status: '',
							url: vimeo_frame.getAttribute( 'data-player_url' ),
							title: vimeo_frame.getAttribute(
								'data-player_title'
							),
							currentTime: seconds,
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
							element: vimeo_frame,
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
						...gtm4wpNativeVideoParams( {
							provider: 'vimeo',
							status: '',
							url: videourl,
							title: 'Unknown title',
							currentTime: 0,
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
							element: vimeo_frame,
						} ),
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
					element: vimeo_frame,
				} ),
			} );
		};

		// Rebuilt from the fetched time and the duration stored on the iframe.
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
							element: vimeo_frame,
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
						...gtm4wpNativeVideoParams( {
							provider: 'vimeo',
							status: '',
							url: videourl,
							title: 'Unknown title',
							currentTime: 0,
							duration: vimeo_frame.getAttribute(
								'data-player_duration'
							),
							element: vimeo_frame,
						} ),
					} );
				} ); // end call api getCurrentTime()
		};

		const gtm4wp_onVimeoPercentageChange = function ( data ) {
			const videoDuration = data.duration;
			if ( ! videoDuration ) {
				return;
			}
			const videoPercentage = Math.floor(
				( data.seconds / videoDuration ) * 100
			);

			gtm4wpMediaMilestones(
				gtm4wp_vimeo_percentage_tracking_marks,
				videoid,
				videoPercentage,
				gtm4wp_vimeo_percentage_tracking,
				function ( i ) {
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
							element: vimeo_frame,
						} ),
					} );
				}
			);
		};
	};

	gtm4wpObserveMedia(
		'iframe[src*="vimeo.com"]',
		gtm4wp_wireVimeoFrame,
		function () {
			return (
				typeof Vimeo !== 'undefined' &&
				typeof Vimeo.Player !== 'undefined'
			);
		},
		'https://player.vimeo.com/api/player.js'
	);
}

gtm4wpOnReady( gtm4wp_initVimeoTracking );
