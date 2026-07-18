import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_twitch_percentage_tracking = 10;
const gtm4wp_twitch_percentage_tracking_marks = {};

function gtm4wp_bindTwitchPlayer( player, channel, video, collection ) {
	const mediaid = video || channel || collection || '';
	let mediaurl = '';
	if ( video ) {
		mediaurl = 'https://www.twitch.tv/videos/' + video;
	} else if ( channel ) {
		mediaurl = 'https://www.twitch.tv/' + channel;
	}

	let percentageInterval = null;

	const gtm4wp_twitchCurrentTime = function () {
		const t =
			typeof player.getCurrentTime === 'function'
				? player.getCurrentTime()
				: 0;
		return isNaN( t ) ? 0 : t || 0;
	};

	const gtm4wp_twitchDuration = function () {
		const d =
			typeof player.getDuration === 'function' ? player.getDuration() : 0;
		return isNaN( d ) ? 0 : d || 0;
	};

	const gtm4wp_twitchMediaData = function () {
		return {
			id: mediaid,
			author: channel || '',
			title: mediaid,
			url: mediaurl,
			duration: gtm4wp_twitchDuration(),
		};
	};

	const gtm4wp_onTwitchPlayerStateChange = function ( playerState ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'twitch',
			mediaData: gtm4wp_twitchMediaData(),
			mediaCurrentTime: gtm4wp_twitchCurrentTime(),
			mediaPlayerState: playerState,
			...gtm4wpNativeVideoParams( {
				provider: 'twitch',
				status: gtm4wpNativeVideoStatus( playerState ),
				url: mediaurl,
				title: mediaid,
				currentTime: gtm4wp_twitchCurrentTime(),
				duration: gtm4wp_twitchDuration(),
			} ),
		} );
	};

	const gtm4wp_onTwitchPlayerEvent = function ( eventName ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'twitch',
			mediaData: gtm4wp_twitchMediaData(),
			mediaCurrentTime: gtm4wp_twitchCurrentTime(),
			mediaPlayerEvent: eventName,
		} );
	};

	// Twitch has no periodic time event, so percentage milestones are polled
	// while playing (like the YouTube tracker). Live streams report no duration,
	// so the percentage helper simply does nothing for them.
	const gtm4wp_onTwitchPercentageChange = function () {
		const videoDuration = gtm4wp_twitchDuration();
		if ( ! videoDuration ) {
			return;
		}

		const videoCurrentTime = gtm4wp_twitchCurrentTime();
		const videoPercentage = Math.floor(
			( videoCurrentTime / videoDuration ) * 100
		);

		gtm4wpMediaMilestones(
			gtm4wp_twitch_percentage_tracking_marks,
			mediaid,
			videoPercentage,
			gtm4wp_twitch_percentage_tracking,
			function ( i ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlaybackPercentage',
					mediaType: 'twitch',
					mediaData: gtm4wp_twitchMediaData(),
					mediaCurrentTime: videoCurrentTime,
					mediaPercentage: i,
					...gtm4wpNativeVideoParams( {
						provider: 'twitch',
						status: 'progress',
						url: mediaurl,
						title: mediaid,
						currentTime: videoCurrentTime,
						duration: videoDuration,
						percent: i,
					} ),
				} );
			}
		);
	};

	const gtm4wp_startTwitchPercentageTracking = function () {
		if ( percentageInterval ) {
			return;
		}
		percentageInterval = setInterval(
			gtm4wp_onTwitchPercentageChange,
			1000
		);
	};

	const gtm4wp_stopTwitchPercentageTracking = function () {
		if ( percentageInterval ) {
			clearInterval( percentageInterval );
			percentageInterval = null;
		}
	};

	player.addEventListener( Twitch.Player.READY, function () {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'twitch',
			mediaData: gtm4wp_twitchMediaData(),
			mediaCurrentTime: gtm4wp_twitchCurrentTime(),
		} );
	} );

	player.addEventListener( Twitch.Player.PLAY, function () {
		gtm4wp_onTwitchPlayerStateChange( 'play' );
		gtm4wp_startTwitchPercentageTracking();
	} );

	player.addEventListener( Twitch.Player.PAUSE, function () {
		gtm4wp_onTwitchPlayerStateChange( 'pause' );
		gtm4wp_stopTwitchPercentageTracking();
	} );

	player.addEventListener( Twitch.Player.ENDED, function () {
		gtm4wp_onTwitchPlayerStateChange( 'ended' );
		gtm4wp_stopTwitchPercentageTracking();
	} );

	player.addEventListener( Twitch.Player.SEEK, function () {
		gtm4wp_onTwitchPlayerStateChange( 'seeked' );
	} );

	player.addEventListener( Twitch.Player.ONLINE, function () {
		gtm4wp_onTwitchPlayerEvent( 'online' );
	} );

	player.addEventListener( Twitch.Player.OFFLINE, function () {
		gtm4wp_onTwitchPlayerEvent( 'offline' );
	} );
}

function gtm4wp_initTwitchTracking() {
	// A plain Twitch player iframe cannot be wrapped after the fact: the events
	// are only available on a player created through the Embed API. Each iframe is
	// therefore replaced with a Twitch.Player pointing at the same channel/video,
	// which re-creates the embed under our control so its events can be tracked.
	// The Twitch Embed API (embed.twitch.tv/embed/v1.js) is enqueued as a
	// dependency but can still be missing at runtime (consent manager, ad blocker,
	// network error), so it is re-checked per element: a frame is only wired once
	// the SDK is available, and this also covers iframes inserted later
	// (popup/AJAX).
	const gtm4wp_wireTwitchFrame = function ( twitch_frame ) {
		let params;
		try {
			params = new URL(
				twitch_frame.getAttribute( 'src' ),
				window.location.href
			).searchParams;
		} catch ( e ) {
			return;
		}

		const channel = params.get( 'channel' );
		const video = params.get( 'video' );
		const collection = params.get( 'collection' );
		if ( ! channel && ! video && ! collection ) {
			return;
		}

		if ( ! twitch_frame.parentNode ) {
			return;
		}

		// Unique-container-id counter for the Twitch.Player replacements. Kept on
		// window (not module scope) so ids stay unique even when the bundle is
		// re-executed (a tag-manager re-injection restarts module scope at 0 while the
		// earlier gtm4wp-twitch-0 container is still in the DOM, which would collide).
		window.gtm4wp_twitch_frame_index =
			window.gtm4wp_twitch_frame_index || 0;

		const container = document.createElement( 'div' );
		container.id = 'gtm4wp-twitch-' + window.gtm4wp_twitch_frame_index++;
		// Mark the container so the iframe the Twitch Embed SDK injects into it —
		// which also matches iframe[src*="player.twitch.tv"] — is skipped by the
		// shared MutationObserver instead of being replaced again in a loop.
		container.setAttribute( 'data-gtm4wp-media-wired', '1' );
		twitch_frame.parentNode.replaceChild( container, twitch_frame );

		const options = {
			width: twitch_frame.getAttribute( 'width' ) || '100%',
			height: twitch_frame.getAttribute( 'height' ) || '100%',
		};
		if ( channel ) {
			options.channel = channel;
		}
		if ( video ) {
			options.video = video;
		}
		if ( collection ) {
			options.collection = collection;
		}

		const player = new Twitch.Player( container.id, options );
		gtm4wp_bindTwitchPlayer( player, channel, video, collection );
	};

	gtm4wpObserveMedia(
		'iframe[src*="player.twitch.tv"]',
		gtm4wp_wireTwitchFrame,
		function () {
			return (
				typeof Twitch !== 'undefined' &&
				typeof Twitch.Player !== 'undefined'
			);
		}
	);
}

gtm4wpOnReady( gtm4wp_initTwitchTracking );
