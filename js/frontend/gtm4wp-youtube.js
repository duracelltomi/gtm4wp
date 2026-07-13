import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
} from './lib/native-video-params';

const gtm4wp_youtube_percentage_tracking = 10;
const gtm4wp_youtube_percentage_tracking_timeouts = {};
const gtm4wp_youtube_percentage_tracking_marks = {};

if ( typeof onYouTubeIframeAPIReady === 'undefined' ) {
	window.onYouTubeIframeAPIReady = function () {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaApiReady',
			mediaType: 'youtube',
		} );

		const gtm4wp_youtube_frames = document.querySelectorAll(
			"iframe[src^='https://www.youtube.com/embed']"
		);
		if ( ! gtm4wp_youtube_frames || gtm4wp_youtube_frames.length == 0 ) {
			return;
		}

		gtm4wp_youtube_frames.forEach( function ( youtube_frame ) {
			let playerID = youtube_frame.getAttribute( 'id' );

			if (
				playerID === null ||
				playerID === undefined ||
				playerID === ''
			) {
				const _gtm4wp_temp = youtube_frame
					.getAttribute( 'src' )
					.split( '?' );
				const _gtm4wp_temp2 = _gtm4wp_temp[ 0 ].split( '/' );

				playerID =
					'youtubeplayer_' +
					_gtm4wp_temp2[ _gtm4wp_temp2.length - 1 ];
				youtube_frame.setAttribute( 'id', playerID );
			}

			let gtm4wp_yturl = youtube_frame.getAttribute( 'src' );
			if ( gtm4wp_yturl.indexOf( 'enablejsapi=1' ) == -1 ) {
				// Use the correct query separator: '?' when the src carries no
				// query yet, '&' otherwise. (The previous code always appended
				// '?' and then '&enablejsapi=1', producing a stray '?&'.) The
				// origin is a scheme://host built from location, which the
				// YouTube API expects raw and un-encoded - matching the
				// server-side enable_youtube_js_api() oEmbed filter.
				const gtm4wp_ytsep =
					gtm4wp_yturl.indexOf( '?' ) == -1 ? '?' : '&';

				gtm4wp_yturl +=
					gtm4wp_ytsep +
					'enablejsapi=1&origin=' +
					document.location.protocol +
					'//' +
					document.location.hostname;

				youtube_frame.setAttribute( 'src', gtm4wp_yturl );
			}

			new YT.Player( playerID, {
				events: {
					onReady: gtm4wp_onYouTubePlayerReady,
					onStateChange: gtm4wp_onYouTubePlayerStateChange,
					onPlaybackQualityChange:
						gtm4wp_onYouTubePlaybackQualityChange,
					onPlaybackRateChange: gtm4wp_onYouTubePlaybackRateChange,
					onError: gtm4wp_onYouTubeError,
					onApiChange: gtm4wp_onYouTubeApiChange,
				},
			} );
		} );
	};

	const tag = document.createElement( 'script' );
	tag.src = '//www.youtube.com/iframe_api';
	const firstScriptTag = document.getElementsByTagName( 'script' )[ 0 ];
	firstScriptTag.parentNode.insertBefore( tag, firstScriptTag );
} else {
	const gtm4wp_err = new Error(
		'Another code is already utilizing YouTube API, GTM4WP plugin can not load YouTube tracking!'
	);
	throw gtm4wp_err;
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
		} ),
	} );
}

function gtm4wp_onYouTubePlaybackQualityChange( event ) {
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
		mediaPlayerEvent: 'quality-change',
		mediaPlayerEventParam: event.data,
	} );
}

function gtm4wp_onYouTubePlaybackRateChange( event ) {
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
		mediaPlayerEvent: 'ratechange',
		mediaPlayerEventParam: event.data,
	} );
}

function gtm4wp_onYouTubeError( event ) {
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
		mediaPlayerEvent: 'error',
		mediaPlayerEventParam: event.data,
	} );
}

function gtm4wp_onYouTubeApiChange( event ) {
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
		mediaPlayerEvent: 'api-change',
		mediaPlayerEventParam: event.data,
	} );
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
				} ),
			} );
		}
	);
}
