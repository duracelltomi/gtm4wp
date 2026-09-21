import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaSrcUrl,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_cloudflarestream_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_cloudflarestream_percentage_tracking_marks = Object.create( null );

function gtm4wp_initCloudflareStreamTracking() {
	// The Player SDK is handed to gtm4wpObserveMedia (fetched only when an
	// embed exists) and re-checked per element, since it can still be missing.
	const gtm4wp_wireStreamFrame = function ( stream_frame ) {
		const videourl = gtm4wpMediaSrcUrl( stream_frame );

		// The UID is the last path segment, or the one before a trailing
		// /iframe. gtm4wpMediaSrcUrl() strips WordPress' `#?secret=` (U106),
		// which would otherwise make the segment 'iframe#'.
		const parts = videourl.split( '/' ).filter( Boolean );
		let videoid = parts[ parts.length - 1 ];
		if ( videoid === 'iframe' && parts.length >= 2 ) {
			videoid = parts[ parts.length - 2 ];
		}

		// Events carry no payload (HTML5-like API): time and duration are read
		// from the player. No title/author in the SDK: the iframe title
		// attribute (filled by WordPress from the oEmbed response, absent from
		// Cloudflare's own snippet) or the UID.
		const frametitle = (
			stream_frame.getAttribute( 'title' ) || ''
		).trim();
		const videotitle = frametitle || videoid;

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
				title: videotitle,
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
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
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
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					// These events are not playback states GTM models.
					status: '',
					url: videourl,
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
				} ),
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
							title: videotitle,
							currentTime: videoCurrentTime,
							duration: videoDuration,
							percent: i,
							element: stream_frame,
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
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
				} ),
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
	};

	gtm4wpObserveMedia(
		'iframe[src*="cloudflarestream.com"],iframe[src*="videodelivery.net"]',
		gtm4wp_wireStreamFrame,
		function () {
			return typeof Stream !== 'undefined';
		},
		'https://embed.cloudflarestream.com/embed/sdk.latest.js'
	);
}

gtm4wpOnReady( gtm4wp_initCloudflareStreamTracking );
