import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_dailymotion_percentage_tracking = 10;
const gtm4wp_dailymotion_percentage_tracking_marks = {};

function gtm4wp_initDailymotionTracking() {
	// Wire every Dailymotion iframe already on the page and any inserted later
	// (popup/lightbox, AJAX). The Dailymotion JS SDK (api.dmcdn.net/all.js) is
	// handed to gtm4wpObserveMedia rather than enqueued by PHP, so a page with no
	// Dailymotion embed never requests it. It can still be missing at runtime
	// (consent manager, ad blocker, network error), so it is re-checked per
	// element: a frame is only wired once the SDK is available.
	//
	// The gtm.videoVisible measurement uses `liveFrame`, not the iframe itself:
	// DM.player() replaces the element it is given with its own player, which
	// would leave a detached node behind to measure.
	const gtm4wp_wireDailymotionFrame = function (
		dailymotion_frame,
		liveFrame
	) {
		const src = dailymotion_frame.getAttribute( 'src' );
		const videourl = src.split( '?' ).shift();

		// The video id lives either in the `video` query parameter (geo.dailymotion
		// / dai.ly players) or as the last path segment (/embed/video/{id}).
		let videoid = '';
		try {
			videoid =
				new URL( src, window.location.href ).searchParams.get(
					'video'
				) || '';
		} catch ( e ) {
			videoid = '';
		}
		if ( ! videoid ) {
			videoid = videourl.split( '/' ).pop();
		}

		// The Dailymotion player mirrors the HTML5 media API: its events carry no
		// payload, so the current time and duration are read from the player
		// object (like the native HTML5 tracker). Title/author are not exposed by
		// the SDK, so the video id is reused as the title with an empty author.
		const player = DM.player( dailymotion_frame );

		const gtm4wp_dailymotionCurrentTime = function () {
			return isNaN( player.currentTime ) ? 0 : player.currentTime || 0;
		};

		const gtm4wp_dailymotionDuration = function () {
			return isNaN( player.duration ) ? 0 : player.duration || 0;
		};

		const gtm4wp_dailymotionMediaData = function () {
			return {
				id: videoid,
				author: '',
				title: videoid,
				url: videourl,
				duration: gtm4wp_dailymotionDuration(),
			};
		};

		const gtm4wp_onDailymotionPlayerStateChange = function ( playerState ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'dailymotion',
				mediaData: gtm4wp_dailymotionMediaData(),
				mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: videourl,
					title: videoid,
					currentTime: gtm4wp_dailymotionCurrentTime(),
					duration: gtm4wp_dailymotionDuration(),
					element: liveFrame,
				} ),
			} );
		};

		const gtm4wp_onDailymotionPlayerEvent = function (
			eventName,
			eventParam
		) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'dailymotion',
				mediaData: gtm4wp_dailymotionMediaData(),
				mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					// These events are not playback states GTM models.
					status: '',
					url: videourl,
					title: videoid,
					currentTime: gtm4wp_dailymotionCurrentTime(),
					duration: gtm4wp_dailymotionDuration(),
					element: liveFrame,
				} ),
			} );
		};

		const gtm4wp_onDailymotionPercentageChange = function () {
			const videoDuration = gtm4wp_dailymotionDuration();
			if ( ! videoDuration ) {
				return;
			}

			const videoCurrentTime = gtm4wp_dailymotionCurrentTime();
			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);

			gtm4wpMediaMilestones(
				gtm4wp_dailymotion_percentage_tracking_marks,
				videoid,
				videoPercentage,
				gtm4wp_dailymotion_percentage_tracking,
				function ( i ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'dailymotion',
						mediaData: gtm4wp_dailymotionMediaData(),
						mediaCurrentTime: videoCurrentTime,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'dailymotion',
							status: 'progress',
							url: videourl,
							title: videoid,
							currentTime: videoCurrentTime,
							duration: videoDuration,
							percent: i,
							element: liveFrame,
						} ),
					} );
				}
			);
		};

		player.addEventListener( 'apiready', function () {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'dailymotion',
				mediaData: gtm4wp_dailymotionMediaData(),
				mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: videoid,
					currentTime: gtm4wp_dailymotionCurrentTime(),
					duration: gtm4wp_dailymotionDuration(),
					element: liveFrame,
				} ),
			} );
		} );

		player.addEventListener( 'play', function () {
			gtm4wp_onDailymotionPlayerStateChange( 'play' );
		} );

		player.addEventListener( 'pause', function () {
			gtm4wp_onDailymotionPlayerStateChange( 'pause' );
		} );

		player.addEventListener( 'video_end', function () {
			gtm4wp_onDailymotionPlayerStateChange( 'ended' );
		} );

		player.addEventListener( 'seeked', function () {
			gtm4wp_onDailymotionPlayerStateChange( 'seeked' );
		} );

		player.addEventListener( 'waiting', function () {
			gtm4wp_onDailymotionPlayerStateChange( 'buffering' );
		} );

		player.addEventListener( 'timeupdate', function () {
			gtm4wp_onDailymotionPercentageChange();
		} );

		player.addEventListener( 'volumechange', function () {
			gtm4wp_onDailymotionPlayerEvent( 'volumechange', player.volume );
		} );

		player.addEventListener( 'qualitychange', function () {
			gtm4wp_onDailymotionPlayerEvent( 'qualitychange', player.quality );
		} );

		player.addEventListener( 'fullscreenchange', function () {
			gtm4wp_onDailymotionPlayerEvent(
				'fullscreenchange',
				player.fullscreen
			);
		} );

		player.addEventListener( 'error', function () {
			gtm4wp_onDailymotionPlayerEvent( 'error', player.error );
		} );
	};

	gtm4wpObserveMedia(
		'iframe[src*="dailymotion.com"],iframe[src*="dai.ly"]',
		gtm4wp_wireDailymotionFrame,
		function () {
			return (
				typeof DM !== 'undefined' && typeof DM.player !== 'undefined'
			);
		},
		'https://api.dmcdn.net/all.js'
	);
}

gtm4wpOnReady( gtm4wp_initDailymotionTracking );
