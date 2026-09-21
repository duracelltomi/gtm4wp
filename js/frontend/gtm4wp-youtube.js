import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaSrcUrl,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_youtube_percentage_tracking = 10;
// Keyed by the video id, so null prototypes (a `__proto__` key would hand
// clearInterval() Object.prototype and never stop the poll).
const gtm4wp_youtube_percentage_tracking_timeouts = Object.create( null );
const gtm4wp_youtube_percentage_tracking_marks = Object.create( null );

if ( typeof onYouTubeIframeAPIReady === 'undefined' ) {
	const gtm4wp_wireYouTubeFrame = function ( youtube_frame ) {
		let playerID = youtube_frame.getAttribute( 'id' );

		if ( playerID === null || playerID === undefined || playerID === '' ) {
			const _gtm4wp_temp2 =
				gtm4wpMediaSrcUrl( youtube_frame ).split( '/' );

			playerID =
				'youtubeplayer_' + _gtm4wp_temp2[ _gtm4wp_temp2.length - 1 ];
			youtube_frame.setAttribute( 'id', playerID );
		}

		const gtm4wp_ytsrc = youtube_frame.getAttribute( 'src' );

		// Fragment held aside: a '?' inside it is not a query, and a parameter
		// appended after '#' is never seen by YouTube, silently (U106).
		const gtm4wp_ythashpos = gtm4wp_ytsrc.indexOf( '#' );
		const gtm4wp_ytbase =
			-1 === gtm4wp_ythashpos
				? gtm4wp_ytsrc
				: gtm4wp_ytsrc.slice( 0, gtm4wp_ythashpos );
		const gtm4wp_ythash =
			-1 === gtm4wp_ythashpos
				? ''
				: gtm4wp_ytsrc.slice( gtm4wp_ythashpos );

		if ( gtm4wp_ytbase.indexOf( 'enablejsapi=1' ) == -1 ) {
			// '?' or '&' by whether the src has a query. The origin is a raw,
			// un-encoded scheme://host, matching enable_youtube_js_api().
			const gtm4wp_ytsep = gtm4wp_ytbase.indexOf( '?' ) == -1 ? '?' : '&';

			youtube_frame.setAttribute(
				'src',
				gtm4wp_ytbase +
					gtm4wp_ytsep +
					'enablejsapi=1&origin=' +
					document.location.protocol +
					'//' +
					document.location.hostname +
					gtm4wp_ythash
			);
		}

		new YT.Player( playerID, {
			events: {
				onReady: gtm4wp_onYouTubePlayerReady,
				onStateChange: gtm4wp_onYouTubePlayerStateChange,
				onPlaybackQualityChange: gtm4wp_onYouTubePlaybackQualityChange,
				onPlaybackRateChange: gtm4wp_onYouTubePlaybackRateChange,
				onError: gtm4wp_onYouTubeError,
				onApiChange: gtm4wp_onYouTubeApiChange,
			},
		} );
	};

	// Object form: the API's load event fires too early (YT is defined, then
	// onYouTubeIframeAPIReady is called), so that callback re-runs the scan.
	gtm4wpObserveMedia(
		"iframe[src^='https://www.youtube.com/embed']",
		gtm4wp_wireYouTubeFrame,
		function () {
			return (
				typeof YT !== 'undefined' && typeof YT.Player !== 'undefined'
			);
		},
		{
			src: '//www.youtube.com/iframe_api',
			subscribe( rescan ) {
				window.onYouTubeIframeAPIReady = function () {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaApiReady',
						mediaType: 'youtube',
					} );

					rescan();
				};
			},
		}
	);
} else {
	const gtm4wp_err = new Error(
		'Another code is already utilizing YouTube API, GTM4WP plugin can not load YouTube tracking!'
	);
	throw gtm4wp_err;
}

/**
 * The player's iframe, for gtm.videoVisible; guarded so a player without
 * getIframe() omits that one key.
 *
 * @param {Object} target The YT.Player the event carries.
 * @return {HTMLElement|null} The embed iframe, or null when unavailable.
 */
function gtm4wp_youtubeIframe( target ) {
	return typeof target.getIframe === 'function' ? target.getIframe() : null;
}

function gtm4wp_onYouTubePlayerReady( event ) {
	const videodata = event.target.getVideoData();

	window[ gtm4wp_datalayer_name ].push( {
		event: 'gtm4wp.mediaPlayerReady',
		mediaType: 'youtube',
		mediaData: {
			id: videodata.video_id,
			author: videodata.author,
			title: videodata.title,
			url: event.target.getVideoUrl(),
			duration: event.target.getDuration(),
		},
		mediaCurrentTime: event.target.getCurrentTime(),
		...gtm4wpNativeVideoParams( {
			provider: 'youtube',
			// "Ready" has no native GTM video status.
			status: '',
			url: event.target.getVideoUrl(),
			title: videodata.title,
			currentTime: event.target.getCurrentTime(),
			duration: event.target.getDuration(),
			element: gtm4wp_youtubeIframe( event.target ),
		} ),
	} );
}

function gtm4wp_onYouTubePlayerStateChange( event ) {
	let playerState = 'unknown';

	switch ( event.data ) {
		case -1:
			playerState = 'unstarted';
			break;
		case YT.PlayerState.ENDED:
			playerState = 'ended';
			break;
		case YT.PlayerState.PLAYING:
			playerState = 'play';
			break;
		case YT.PlayerState.PAUSED:
			playerState = 'pause';
			break;
		case YT.PlayerState.BUFFERING:
			playerState = 'buffering';
			break;
		case YT.PlayerState.CUED:
			playerState = 'cued';
			break;
	}

	const videoId = event.target.getVideoData().video_id;

	if (
		YT.PlayerState.PLAYING == event.data &&
		gtm4wp_youtube_percentage_tracking > 0
	) {
		gtm4wp_youtube_percentage_tracking_timeouts[ videoId ] = setInterval(
			function () {
				gtm4wp_onYouTubePercentageChange( event );
			},
			1000
		);
	} else if ( gtm4wp_youtube_percentage_tracking_timeouts[ videoId ] ) {
		clearInterval( gtm4wp_youtube_percentage_tracking_timeouts[ videoId ] );
	}

	const videodata = event.target.getVideoData();

	window[ gtm4wp_datalayer_name ].push( {
		event: 'gtm4wp.mediaPlayerStateChange',
		mediaType: 'youtube',
		mediaData: {
			id: videodata.video_id,
			author: videodata.author,
			title: videodata.title,
			url: event.target.getVideoUrl(),
			duration: event.target.getDuration(),
		},
		mediaPlayerState: playerState,
		mediaCurrentTime: event.target.getCurrentTime(),
		...gtm4wpNativeVideoParams( {
			provider: 'youtube',
			status: gtm4wpNativeVideoStatus( playerState ),
			url: event.target.getVideoUrl(),
			title: videodata.title,
			currentTime: event.target.getCurrentTime(),
			duration: event.target.getDuration(),
			element: gtm4wp_youtubeIframe( event.target ),
		} ),
	} );
}

/**
 * Pushes a gtm4wp.mediaPlayerEvent for the non-state YouTube events.
 *
 * @param {Object} event     The YT.Player event.
 * @param {string} eventName The gtm4wp media player event name.
 * @return {void}
 */
function gtm4wp_pushYouTubePlayerEvent( event, eventName ) {
	const videodata = event.target.getVideoData();

	window[ gtm4wp_datalayer_name ].push( {
		event: 'gtm4wp.mediaPlayerEvent',
		mediaType: 'youtube',
		mediaData: {
			id: videodata.video_id,
			author: videodata.author,
			title: videodata.title,
			url: event.target.getVideoUrl(),
			duration: event.target.getDuration(),
		},
		mediaCurrentTime: event.target.getCurrentTime(),
		mediaPlayerEvent: eventName,
		mediaPlayerEventParam: event.data,
		...gtm4wpNativeVideoParams( {
			provider: 'youtube',
			// None of these events is a playback state GTM models.
			status: '',
			url: event.target.getVideoUrl(),
			title: videodata.title,
			currentTime: event.target.getCurrentTime(),
			duration: event.target.getDuration(),
			element: gtm4wp_youtubeIframe( event.target ),
		} ),
	} );
}

function gtm4wp_onYouTubePlaybackQualityChange( event ) {
	gtm4wp_pushYouTubePlayerEvent( event, 'quality-change' );
}

function gtm4wp_onYouTubePlaybackRateChange( event ) {
	gtm4wp_pushYouTubePlayerEvent( event, 'ratechange' );
}

function gtm4wp_onYouTubeError( event ) {
	gtm4wp_pushYouTubePlayerEvent( event, 'error' );
}

function gtm4wp_onYouTubeApiChange( event ) {
	gtm4wp_pushYouTubePlayerEvent( event, 'api-change' );
}

function gtm4wp_onYouTubePercentageChange( event ) {
	const videoDuration = event.target.getDuration();
	if ( ! videoDuration ) {
		return;
	}
	const videoId = event.target.getVideoData().video_id;
	const videoCurrentTime = event.target.getCurrentTime();
	const videoPercentage = Math.floor(
		( videoCurrentTime / videoDuration ) * 100
	);

	const videodata = event.target.getVideoData();

	gtm4wpMediaMilestones(
		gtm4wp_youtube_percentage_tracking_marks,
		videoId,
		videoPercentage,
		gtm4wp_youtube_percentage_tracking,
		function ( i ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlaybackPercentage',
				mediaType: 'youtube',
				mediaData: {
					id: videodata.video_id,
					author: videodata.author,
					title: videodata.title,
					url: event.target.getVideoUrl(),
					duration: event.target.getDuration(),
				},
				mediaCurrentTime: event.target.getCurrentTime(),
				mediaPercentage: i,
				...gtm4wpNativeVideoParams( {
					provider: 'youtube',
					status: 'progress',
					url: event.target.getVideoUrl(),
					title: videodata.title,
					currentTime: event.target.getCurrentTime(),
					duration: event.target.getDuration(),
					percent: i,
					element: gtm4wp_youtubeIframe( event.target ),
				} ),
			} );
		}
	);
}
