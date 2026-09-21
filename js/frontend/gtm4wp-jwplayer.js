import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_jwplayer_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_jwplayer_percentage_tracking_marks = Object.create( null );

function gtm4wp_initJWPlayerTracking() {
	// No SDK: the site loads its own JW library, so only the existing global
	// `jwplayer` is hooked, re-checked per element. JW Player marks its
	// container with the `jwplayer`/`jw-player` class and its id fetches the
	// instance. `seen` guards two containers sharing one id (the marker guards
	// the element); null prototype since the id comes off the page.
	const gtm4wp_jwplayer_seen = Object.create( null );

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

		// From the current playlist item on every push; no author exposed.
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
					element: container,
				} ),
			} );
		};

		const gtm4wp_onJWPlayerEvent = function ( eventName, eventParam ) {
			const mediaData = gtm4wp_jwMediaData();

			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'jwplayer',
				mediaData,
				mediaCurrentTime: gtm4wp_jwCurrentTime(),
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
				...gtm4wpNativeVideoParams( {
					provider: 'jwplayer',
					// These events are not playback states GTM models.
					status: '',
					url: mediaData.url,
					title: mediaData.title,
					currentTime: gtm4wp_jwCurrentTime(),
					duration: mediaData.duration,
					element: container,
				} ),
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
							element: container,
						} ),
					} );
				}
			);
		};

		// Players found in the DOM are already set up: ready is pushed now.
		const gtm4wp_jwReadyMediaData = gtm4wp_jwMediaData();

		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerReady',
			mediaType: 'jwplayer',
			mediaData: gtm4wp_jwReadyMediaData,
			mediaCurrentTime: gtm4wp_jwCurrentTime(),
			...gtm4wpNativeVideoParams( {
				provider: 'jwplayer',
				// "Ready" has no native GTM video status.
				status: '',
				url: gtm4wp_jwReadyMediaData.url,
				title: gtm4wp_jwReadyMediaData.title,
				currentTime: gtm4wp_jwCurrentTime(),
				duration: gtm4wp_jwReadyMediaData.duration,
				element: container,
			} ),
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
