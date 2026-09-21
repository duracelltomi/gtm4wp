import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
} from './lib/native-video-params';

const gtm4wp_wistia_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_wistia_percentage_tracking_marks = Object.create( null );

function gtm4wp_initWistiaTracking() {
	// Double-init guard: a second `_wq` push would double every event.
	if ( window.gtm4wp_wistia_inited ) {
		return;
	}
	window.gtm4wp_wistia_inited = true;

	// The Player API is the global `_wq` ready queue ('_all' = every video),
	// processed by Wistia's own runtime whenever it loads; if it never loads,
	// nothing fires. Populated immediately so it is in place before ready.
	window._wq = window._wq || [];
	window._wq.push( {
		id: '_all',
		onReady( video ) {
			const videoid = video.hashedId();
			const videourl = 'https://fast.wistia.net/embed/iframe/' + videoid;

			// Element for gtm.videoVisible: the undocumented elem() when
			// present (exact for two embeds of one video), else the documented
			// `wistia_async_<hashedId>` container. Resolved per push: a later
			// inserted player is not in the DOM when onReady runs.
			const gtm4wp_wistiaElement = function () {
				if ( typeof video.elem === 'function' ) {
					const element = video.elem();

					if ( element && 1 === element.nodeType ) {
						return element;
					}
				}

				try {
					return document.querySelector( '.wistia_async_' + videoid );
				} catch ( e ) {
					// A hashed id that is not a valid class selector.
					return null;
				}
			};

			const gtm4wp_wistiaMediaData = function () {
				return {
					id: videoid,
					author: '',
					title: video.name(),
					url: videourl,
					duration: video.duration(),
				};
			};

			const gtm4wp_onWistiaPlayerStateChange = function ( playerState ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerStateChange',
					mediaType: 'wistia',
					mediaData: gtm4wp_wistiaMediaData(),
					mediaCurrentTime: video.time(),
					mediaPlayerState: playerState,
					...gtm4wpNativeVideoParams( {
						provider: 'wistia',
						status: gtm4wpNativeVideoStatus( playerState ),
						url: videourl,
						title: video.name(),
						currentTime: video.time(),
						duration: video.duration(),
						element: gtm4wp_wistiaElement,
					} ),
				} );
			};

			const gtm4wp_onWistiaPlayerEvent = function (
				eventName,
				eventParam
			) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'wistia',
					mediaData: gtm4wp_wistiaMediaData(),
					mediaCurrentTime: video.time(),
					mediaPlayerEvent: eventName,
					mediaPlayerEventParam: eventParam,
					...gtm4wpNativeVideoParams( {
						provider: 'wistia',
						// These events are not playback states GTM models.
						status: '',
						url: videourl,
						title: video.name(),
						currentTime: video.time(),
						duration: video.duration(),
						element: gtm4wp_wistiaElement,
					} ),
				} );
			};

			const gtm4wp_onWistiaPercentageChange = function ( percent ) {
				const videoPercentage = Math.floor( percent * 100 );

				gtm4wpMediaMilestones(
					gtm4wp_wistia_percentage_tracking_marks,
					videoid,
					videoPercentage,
					gtm4wp_wistia_percentage_tracking,
					function ( i ) {
						window[ gtm4wp_datalayer_name ].push( {
							event: 'gtm4wp.mediaPlaybackPercentage',
							mediaType: 'wistia',
							mediaData: gtm4wp_wistiaMediaData(),
							mediaCurrentTime: video.time(),
							mediaPercentage: i,
							...gtm4wpNativeVideoParams( {
								provider: 'wistia',
								status: 'progress',
								url: videourl,
								title: video.name(),
								currentTime: video.time(),
								duration: video.duration(),
								percent: i,
								element: gtm4wp_wistiaElement,
							} ),
						} );
					}
				);
			};

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'wistia',
				mediaData: gtm4wp_wistiaMediaData(),
				mediaCurrentTime: video.time(),
				...gtm4wpNativeVideoParams( {
					provider: 'wistia',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: video.name(),
					currentTime: video.time(),
					duration: video.duration(),
					element: gtm4wp_wistiaElement,
				} ),
			} );

			video.bind( 'play', function () {
				gtm4wp_onWistiaPlayerStateChange( 'play' );
			} );

			video.bind( 'pause', function () {
				gtm4wp_onWistiaPlayerStateChange( 'pause' );
			} );

			video.bind( 'end', function () {
				gtm4wp_onWistiaPlayerStateChange( 'ended' );
			} );

			video.bind( 'seek', function () {
				gtm4wp_onWistiaPlayerStateChange( 'seeked' );
			} );

			video.bind( 'percentwatchedchanged', function ( percent ) {
				gtm4wp_onWistiaPercentageChange( percent );
			} );

			video.bind( 'playbackratechange', function ( rate ) {
				gtm4wp_onWistiaPlayerEvent( 'playbackratechange', rate );
			} );

			video.bind( 'volumechange', function ( volume ) {
				gtm4wp_onWistiaPlayerEvent( 'volumechange', volume );
			} );
		},
	} );
}

gtm4wp_initWistiaTracking();
