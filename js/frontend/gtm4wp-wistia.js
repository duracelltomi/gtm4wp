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

			// The element whose viewport position the pushes report as
			// gtm.videoVisible. Wistia's Player API documents no DOM accessor,
			// so the documented async-embed markup (a container carrying the
			// `wistia_async_<hashedId>` class) is the contract relied on here;
			// the undocumented elem() the runtime also exposes is preferred when
			// present, because it is exact for a page with two embeds of the
			// same video. Resolved per push: a player inserted later (popup /
			// lightbox) is not in the DOM when onReady runs.
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
