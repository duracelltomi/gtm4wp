import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
} from './lib/native-video-params';

const gtm4wp_jwplayer_percentage_tracking = 10;
const gtm4wp_jwplayer_percentage_tracking_marks = {};

function gtm4wp_initJWPlayerTracking() {
	// No SDK is enqueued for JW Player: the site loads its own JW library, so
	// this tracker only hooks the existing global `jwplayer`. If it is missing
	// (no JW Player on the page, or the library was blocked) there is nothing to
	// hook into, so bail out gracefully instead of throwing on `jwplayer()`.
	if ( typeof jwplayer === 'undefined' ) {
		return;
	}

	// JW Player upgrades its container element with the `jwplayer`/`jw-player`
	// class during setup; each such element carries the id used to fetch the
	// player instance.
	const gtm4wp_jwplayer_containers = document.querySelectorAll(
		'.jwplayer,.jw-player'
	);
	if (
		! gtm4wp_jwplayer_containers ||
		gtm4wp_jwplayer_containers.length == 0
	) {
		return;
	}

	const gtm4wp_jwplayer_seen = {};

	gtm4wp_jwplayer_containers.forEach( function ( container ) {
		const id = container.getAttribute( 'id' );
		if ( ! id || gtm4wp_jwplayer_seen[ id ] ) {
			return;
		}
		gtm4wp_jwplayer_seen[ id ] = true;

		const player = jwplayer( id );
		if ( ! player || typeof player.on !== 'function' ) {
			return;
		}

		// Metadata is read from the current playlist item on every push so a
		// playlist advancing to the next item reports that item. Author is not
		// exposed by JW Player, so it is left empty.
		const gtm4wp_jwMediaData = function () {
			const item =
				( typeof player.getPlaylistItem === 'function' &&
					player.getPlaylistItem() ) ||
				{};
			return {
				id: item.mediaid || item.file || id,
				author: '',
				title: item.title || '',
				url: item.file || '',
				duration:
					typeof player.getDuration === 'function'
						? player.getDuration() || 0
						: 0,
			};
		};

		const gtm4wp_jwCurrentTime = function () {
			return typeof player.getPosition === 'function'
				? player.getPosition() || 0
				: 0;
		};

		const gtm4wp_onJWPlayerStateChange = function ( playerState ) {
			const mediaData = gtm4wp_jwMediaData();

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'jwplayer',
				mediaData,
				mediaCurrentTime: gtm4wp_jwCurrentTime(),
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'jwplayer',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: mediaData.url,
					title: mediaData.title,
					currentTime: gtm4wp_jwCurrentTime(),
					duration: mediaData.duration,
				} ),
			} );
		};

		const gtm4wp_onJWPlayerEvent = function ( eventName, eventParam ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'jwplayer',
				mediaData: gtm4wp_jwMediaData(),
				mediaCurrentTime: gtm4wp_jwCurrentTime(),
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
			} );
		};

		const gtm4wp_onJWPercentageChange = function () {
			const mediaData = gtm4wp_jwMediaData();
			const videoDuration = mediaData.duration;
			if ( ! videoDuration ) {
				return;
			}

			const videoCurrentTime = gtm4wp_jwCurrentTime();
			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);
			const markKey = mediaData.id;

			if (
				typeof gtm4wp_jwplayer_percentage_tracking_marks[ markKey ] ===
				'undefined'
			) {
				gtm4wp_jwplayer_percentage_tracking_marks[ markKey ] = [];
			}

			for (
				let i = 0;
				i < 100;
				i += gtm4wp_jwplayer_percentage_tracking
			) {
				if (
					videoPercentage > i &&
					gtm4wp_jwplayer_percentage_tracking_marks[
						markKey
					].indexOf( i ) == -1
				) {
					gtm4wp_jwplayer_percentage_tracking_marks[ markKey ].push(
						i
					);

					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'jwplayer',
						mediaData,
						mediaCurrentTime: videoCurrentTime,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'jwplayer',
							status: 'progress',
							url: mediaData.url,
							title: mediaData.title,
							currentTime: videoCurrentTime,
							duration: videoDuration,
							percent: i,
						} ),
					} );
				}
			}
		};

		// The player instances found in the DOM are already set up, so the ready
		// signal is pushed immediately rather than waiting for the 'ready' event
		// (which may have fired before this tracker ran).
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'jwplayer',
			mediaData: gtm4wp_jwMediaData(),
			mediaCurrentTime: gtm4wp_jwCurrentTime(),
		} );

		player.on( 'play', function () {
			gtm4wp_onJWPlayerStateChange( 'play' );
		} );

		player.on( 'pause', function () {
			gtm4wp_onJWPlayerStateChange( 'pause' );
		} );

		player.on( 'complete', function () {
			gtm4wp_onJWPlayerStateChange( 'ended' );
		} );

		player.on( 'seeked', function () {
			gtm4wp_onJWPlayerStateChange( 'seeked' );
		} );

		player.on( 'buffer', function () {
			gtm4wp_onJWPlayerStateChange( 'buffering' );
		} );

		player.on( 'time', function () {
			gtm4wp_onJWPercentageChange();
		} );

		player.on( 'playbackRateChanged', function ( e ) {
			gtm4wp_onJWPlayerEvent(
				'playbackRateChanged',
				e && e.playbackRate
			);
		} );

		player.on( 'error', function ( e ) {
			gtm4wp_onJWPlayerEvent( 'error', e && e.message );
		} );
	} );
}

// The tracker bundle may execute before or after the DOM has finished parsing
// (e.g. when loaded with a defer/async strategy or injected late by a tag
// manager). Guard against a DOMContentLoaded event that has already fired,
// which would otherwise leave the tracking silently uninitialized.
if ( document.readyState === 'loading' ) {
	window.addEventListener( 'DOMContentLoaded', gtm4wp_initJWPlayerTracking );
} else {
	gtm4wp_initJWPlayerTracking();
}
