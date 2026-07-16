import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_jwplayer_percentage_tracking = 10;
const gtm4wp_jwplayer_percentage_tracking_marks = {};

function gtm4wp_initJWPlayerTracking() {
	// No SDK is enqueued for JW Player: the site loads its own JW library, so this
	// tracker only hooks the existing global `jwplayer`, re-checked per element
	// (see gtm4wpObserveMedia) so a container inserted later (popup/AJAX) is still
	// wired once the library is present. JW Player upgrades its container element
	// with the `jwplayer`/`jw-player` class during setup; each such element
	// carries the id used to fetch the player instance. `seen` guards against two
	// containers sharing one id (the data-attribute marker guards the element).
	const gtm4wp_jwplayer_seen = {};

	const gtm4wp_wireJWPlayerContainer = function ( container ) {
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

			gtm4wpMediaMilestones(
				gtm4wp_jwplayer_percentage_tracking_marks,
				markKey,
				videoPercentage,
				gtm4wp_jwplayer_percentage_tracking,
				function ( i ) {
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
			);
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
	};

	gtm4wpObserveMedia(
		'.jwplayer,.jw-player',
		gtm4wp_wireJWPlayerContainer,
		function () {
			return typeof jwplayer !== 'undefined';
		}
	);
}

gtm4wpOnReady( gtm4wp_initJWPlayerTracking );
