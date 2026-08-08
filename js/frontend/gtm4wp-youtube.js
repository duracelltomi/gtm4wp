import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaSrcUrl,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_youtube_percentage_tracking = 10;
const gtm4wp_youtube_percentage_tracking_timeouts = {};
const gtm4wp_youtube_percentage_tracking_marks = {};

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

		// The fragment is held aside for both halves of what follows: a '?' INSIDE
		// a fragment is not a query, and anything appended AFTER a '#' is part of
		// the fragment, so YouTube would never see the parameters and the JS API
		// would stay switched off - which fires no error and fails no test (U106).
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
			// Use the correct query separator: '?' when the src carries no
			// query yet, '&' otherwise. (The previous code always appended
			// '?' and then '&enablejsapi=1', producing a stray '?&'.) The
			// origin is a scheme://host built from location, which the
			// YouTube API expects raw and un-encoded - matching the
			// server-side enable_youtube_js_api() oEmbed filter.
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

	// Wire the YouTube iframes present now and any inserted later (popup/AJAX).
	// The observer scans the page BEFORE it fetches anything, so a page with no
	// YouTube embed never requests the IFrame API.
	//
	// The API is described in the object form because its script load event
	// fires too early to wire against: the API defines YT and only THEN calls
	// onYouTubeIframeAPIReady, so that callback is what re-runs the scan. It is
	// registered whether or not this page has an embed, which keeps the
	// behaviour a site gets when it loads the IFrame API itself.
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
 * The player's iframe, whose viewport position becomes gtm.videoVisible.
 *
 * getIframe() is part of the YouTube IFrame Player API; it is guarded so a
 * player object without it simply omits that one key instead of throwing.
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
 * Pushes a gtm4wp.mediaPlayerEvent for the YouTube events that are not state
 * changes. All four report the same player, so they share one push (matching the
 * single ...PlayerEvent helper every other tracker has).
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
