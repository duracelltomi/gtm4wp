import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
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

		if (
			typeof gtm4wp_twitch_percentage_tracking_marks[ mediaid ] ===
			'undefined'
		) {
			gtm4wp_twitch_percentage_tracking_marks[ mediaid ] = [];
		}

		for ( let i = 0; i < 100; i += gtm4wp_twitch_percentage_tracking ) {
			if (
				videoPercentage > i &&
				gtm4wp_twitch_percentage_tracking_marks[ mediaid ].indexOf(
					i
				) == -1
			) {
				gtm4wp_twitch_percentage_tracking_marks[ mediaid ].push( i );

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
		}
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
	// The Twitch Embed API (embed.twitch.tv/embed/v1.js) is enqueued as a
	// dependency of this tracker, but it can still be missing at runtime if a
	// consent manager, an ad blocker or a network error stopped it from loading.
	// Without it there is nothing to hook into, so bail out gracefully instead of
	// throwing on `new Twitch.Player()`.
	if (
		typeof Twitch === 'undefined' ||
		typeof Twitch.Player === 'undefined'
	) {
		return;
	}

	const gtm4wp_twitch_frames = document.querySelectorAll(
		'iframe[src*="player.twitch.tv"]'
	);
	if ( ! gtm4wp_twitch_frames || gtm4wp_twitch_frames.length == 0 ) {
		return;
	}

	// A plain Twitch player iframe cannot be wrapped after the fact: the events
	// are only available on a player created through the Embed API. Each iframe
	// is therefore replaced with a Twitch.Player pointing at the same
	// channel/video, which re-creates the embed under our control so its events
	// can be tracked.
	gtm4wp_twitch_frames.forEach( function ( twitch_frame, index ) {
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

		const container = document.createElement( 'div' );
		container.id = 'gtm4wp-twitch-' + index;
		if ( ! twitch_frame.parentNode ) {
			return;
		}
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
	} );
}

// The tracker bundle may execute before or after the DOM has finished parsing
// (e.g. when loaded with a defer/async strategy or injected late by a tag
// manager). Guard against a DOMContentLoaded event that has already fired,
// which would otherwise leave the tracking silently uninitialized.
if ( document.readyState === 'loading' ) {
	window.addEventListener( 'DOMContentLoaded', gtm4wp_initTwitchTracking );
} else {
	gtm4wp_initTwitchTracking();
}
