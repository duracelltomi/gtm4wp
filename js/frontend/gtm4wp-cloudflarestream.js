import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
} from './lib/native-video-params';

const gtm4wp_cloudflarestream_percentage_tracking = 10;
const gtm4wp_cloudflarestream_percentage_tracking_marks = {};

function gtm4wp_initCloudflareStreamTracking() {
	// The Cloudflare Stream Player SDK (embed.cloudflarestream.com/embed/sdk.latest.js)
	// is enqueued as a dependency of this tracker, but it can still be missing at
	// runtime if a consent manager, an ad blocker or a network error stopped it
	// from loading. Without it there is nothing to hook into, so bail out
	// gracefully instead of throwing on `Stream()`.
	if ( typeof Stream === 'undefined' ) {
		return;
	}

	const gtm4wp_cloudflarestream_frames = document.querySelectorAll(
		'iframe[src*="cloudflarestream.com"],iframe[src*="videodelivery.net"]'
	);
	if (
		! gtm4wp_cloudflarestream_frames ||
		gtm4wp_cloudflarestream_frames.length == 0
	) {
		return;
	}

	gtm4wp_cloudflarestream_frames.forEach( function ( stream_frame ) {
		const videourl = stream_frame
			.getAttribute( 'src' )
			.split( '?' )
			.shift();

		// The video UID is the last path segment, or the one before it when the
		// embed URL ends in /iframe (…/{uid}/iframe).
		const parts = videourl.split( '/' ).filter( Boolean );
		let videoid = parts[ parts.length - 1 ];
		if ( videoid === 'iframe' && parts.length >= 2 ) {
			videoid = parts[ parts.length - 2 ];
		}

		// The Stream player mirrors the HTML5 media API: events carry no payload,
		// so the current time and duration are read from the player object.
		// Title/author are not exposed, so the UID is reused as the title.
		const player = Stream( stream_frame );

		const gtm4wp_streamCurrentTime = function () {
			return isNaN( player.currentTime ) ? 0 : player.currentTime || 0;
		};

		const gtm4wp_streamDuration = function () {
			return isNaN( player.duration ) ? 0 : player.duration || 0;
		};

		const gtm4wp_streamMediaData = function () {
			return {
				id: videoid,
				author: '',
				title: videoid,
				url: videourl,
				duration: gtm4wp_streamDuration(),
			};
		};

		const gtm4wp_onStreamPlayerStateChange = function ( playerState ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: videourl,
					title: videoid,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
				} ),
			} );
		};

		const gtm4wp_onStreamPlayerEvent = function ( eventName, eventParam ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
			} );
		};

		const gtm4wp_onStreamPercentageChange = function () {
			const videoDuration = gtm4wp_streamDuration();
			if ( ! videoDuration ) {
				return;
			}

			const videoCurrentTime = gtm4wp_streamCurrentTime();
			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);

			gtm4wpMediaMilestones(
				gtm4wp_cloudflarestream_percentage_tracking_marks,
				videoid,
				videoPercentage,
				gtm4wp_cloudflarestream_percentage_tracking,
				function ( i ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'cloudflarestream',
						mediaData: gtm4wp_streamMediaData(),
						mediaCurrentTime: videoCurrentTime,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'cloudflarestream',
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

		player.addEventListener( 'loadedmetadata', function () {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
			} );
		} );

		player.addEventListener( 'play', function () {
			gtm4wp_onStreamPlayerStateChange( 'play' );
		} );

		player.addEventListener( 'pause', function () {
			gtm4wp_onStreamPlayerStateChange( 'pause' );
		} );

		player.addEventListener( 'ended', function () {
			gtm4wp_onStreamPlayerStateChange( 'ended' );
		} );

		player.addEventListener( 'seeked', function () {
			gtm4wp_onStreamPlayerStateChange( 'seeked' );
		} );

		player.addEventListener( 'waiting', function () {
			gtm4wp_onStreamPlayerStateChange( 'buffering' );
		} );

		player.addEventListener( 'timeupdate', function () {
			gtm4wp_onStreamPercentageChange();
		} );

		player.addEventListener( 'ratechange', function () {
			gtm4wp_onStreamPlayerEvent( 'ratechange', player.playbackRate );
		} );

		player.addEventListener( 'volumechange', function () {
			gtm4wp_onStreamPlayerEvent( 'volumechange', player.volume );
		} );

		player.addEventListener( 'error', function () {
			gtm4wp_onStreamPlayerEvent( 'error', player.error );
		} );
	} );
}

gtm4wpOnReady( gtm4wp_initCloudflareStreamTracking );
