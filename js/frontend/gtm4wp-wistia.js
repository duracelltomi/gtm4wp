import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
} from './lib/native-video-params';

const gtm4wp_wistia_percentage_tracking = 10;
const gtm4wp_wistia_percentage_tracking_marks = {};

function gtm4wp_initWistiaTracking() {
	// Bind once: if this bundle is executed twice (e.g. re-injected by a tag
	// manager) a second `_wq` push would register `onReady` again and every
	// Wistia event would be pushed to the data layer twice.
	if ( window.gtm4wp_wistia_inited ) {
		return;
	}
	window.gtm4wp_wistia_inited = true;

	// Wistia's Player API is consumed through the global `_wq` ready queue rather
	// than a script we enqueue: pushing a handler with id '_all' registers an
	// onReady callback for every Wistia video on the page, and Wistia's own embed
	// runtime processes the queue whenever it loads. If that runtime never loads
	// (consent manager / ad blocker / no Wistia embed present), onReady simply
	// never fires and nothing is pushed — graceful by design, so no SDK guard is
	// needed here. The queue is populated immediately (not on DOMContentLoaded)
	// so it is in place before the player becomes ready.
	window._wq = window._wq || [];
	window._wq.push( {
		id: '_all',
		onReady( video ) {
			const videoid = video.hashedId();
			const videourl = 'https://fast.wistia.net/embed/iframe/' + videoid;

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
