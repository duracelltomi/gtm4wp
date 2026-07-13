import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
} from './lib/native-video-params';

const gtm4wp_dailymotion_percentage_tracking = 10;
const gtm4wp_dailymotion_percentage_tracking_marks = {};

function gtm4wp_initDailymotionTracking() {
	// The Dailymotion JS SDK (api.dmcdn.net/all.js) is enqueued as a dependency
	// of this tracker, but it can still be missing at runtime if a consent
	// manager, an ad blocker or a network error stopped it from loading. Without
	// it there is nothing to hook into, so bail out gracefully instead of
	// throwing on `DM.player()`.
	if ( typeof DM === 'undefined' || typeof DM.player === 'undefined' ) {
		return;
	}

	const gtm4wp_dailymotion_frames = document.querySelectorAll(
		'iframe[src*="dailymotion.com"],iframe[src*="dai.ly"]'
	);
	if (
		! gtm4wp_dailymotion_frames ||
		gtm4wp_dailymotion_frames.length == 0
	) {
		return;
	}

	gtm4wp_dailymotion_frames.forEach( function ( dailymotion_frame ) {
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
	} );
}

gtm4wpOnReady( gtm4wp_initDailymotionTracking );
