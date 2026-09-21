import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_soundclound_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_soundclound_percentage_tracking_marks = Object.create( null );

function gtm4wp_initSoundCloudTracking() {
	// The Widget API is handed to gtm4wpObserveMedia (fetched only when an
	// embed exists) and re-checked per element, since it can still be missing.
	const gtm4wp_wireSoundCloudFrame = function ( soundcloud_frame ) {
		const widget = SC.Widget( soundcloud_frame );
		let sound = {};

		// Refreshes the cached `sound` and the data-player_* attributes, then
		// runs `done`. A playlist advances, so refreshed on READY and every PLAY.
		const gtm4wp_refreshSoundCloudCurrentSound = function ( done ) {
			widget.getCurrentSound( function ( soundData ) {
				if ( soundData ) {
					sound = soundData;

					soundcloud_frame.setAttribute(
						'data-player_id',
						soundData.id
					);
					soundcloud_frame.setAttribute(
						'data-player_author',
						soundData.user?.username
					);
					soundcloud_frame.setAttribute(
						'data-player_title',
						soundData.title
					);
					soundcloud_frame.setAttribute(
						'data-player_url',
						soundData.permalink_url
					);
					soundcloud_frame.setAttribute(
						'data-player_duration',
						soundData.duration
					);
				}

				if ( done ) {
					done();
				}
			} );
		};

		widget.bind( SC.Widget.Events.READY, function () {
			gtm4wp_refreshSoundCloudCurrentSound( function () {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerReady',
					mediaType: 'soundcloud',
					mediaData: {
						id: sound.id,
						author: sound.user?.username,
						title: sound.title,
						url: sound.permalink_url,
						duration: sound.duration,
					},
					mediaCurrentTime: 0,
					...gtm4wpNativeVideoParams( {
						provider: 'soundcloud',
						// "Ready" has no native GTM video status.
						status: '',
						url: sound.permalink_url,
						title: sound.title,
						currentTime: 0,
						// SoundCloud reports ms; gtm.video* wants seconds.
						duration: sound.duration / 1000,
						element: soundcloud_frame,
					} ),
				} );
			} ); // end of api call getCurrentSound

			widget.bind(
				SC.Widget.Events.PLAY_PROGRESS,
				function ( eventData ) {
					gtm4wp_onSoundCloudPercentageChange( eventData );
				}
			);

			widget.bind( SC.Widget.Events.PLAY, function ( eventData ) {
				// Refresh first so a playlist reports the track now playing.
				gtm4wp_refreshSoundCloudCurrentSound( function () {
					gtm4wp_onSoundCloudPlayerStateChange( eventData, 'play' );
				} );
			} );

			widget.bind( SC.Widget.Events.PAUSE, function ( eventData ) {
				gtm4wp_onSoundCloudPlayerStateChange( eventData, 'pause' );
			} );

			widget.bind( SC.Widget.Events.FINISH, function ( eventData ) {
				gtm4wp_onSoundCloudPlayerStateChange( eventData, 'ended' );
			} );

			widget.bind( SC.Widget.Events.SEEK, function ( eventData ) {
				gtm4wp_onSoundCloudPlayerStateChange( eventData, 'seeked' );
			} );

			widget.bind( SC.Widget.Events.CLICK_DOWNLOAD, function () {
				gtm4wp_onSoundCloudPlayerEvent( 'click-download' );
			} );

			widget.bind( SC.Widget.Events.CLICK_BUY, function () {
				gtm4wp_onSoundCloudPlayerEvent( 'click-buy' );
			} );

			widget.bind( SC.Widget.Events.OPEN_SHARE_PANEL, function () {
				gtm4wp_onSoundCloudPlayerEvent( 'open-share-panel' );
			} );

			widget.bind( SC.Widget.Events.ERROR, function () {
				gtm4wp_onSoundCloudPlayerEvent( 'error' );
			} );
		} );

		const gtm4wp_onSoundCloudPlayerStateChange = function (
			eventData,
			playerState
		) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'soundcloud',
				mediaData: {
					id: sound.id,
					author: sound.user?.username,
					title: sound.title,
					url: sound.permalink_url,
					duration: sound.duration,
				},
				mediaCurrentTime: eventData.currentPosition,
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'soundcloud',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: sound.permalink_url,
					title: sound.title,
					// SoundCloud reports milliseconds; gtm.video* wants seconds.
					currentTime: eventData.currentPosition / 1000,
					duration: sound.duration / 1000,
					element: soundcloud_frame,
				} ),
			} );
		};

		const gtm4wp_onSoundCloudPercentageChange = function ( eventData ) {
			if ( ! sound.duration ) {
				return;
			}
			const mediaPercentage = Math.floor(
				( eventData.currentPosition / sound.duration ) * 100
			);

			gtm4wpMediaMilestones(
				gtm4wp_soundclound_percentage_tracking_marks,
				sound.id,
				mediaPercentage,
				gtm4wp_soundclound_percentage_tracking,
				function ( i ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'soundcloud',
						mediaData: {
							id: sound.id,
							author: sound.user?.username,
							title: sound.title,
							url: sound.permalink_url,
							duration: sound.duration,
						},
						mediaCurrentTime: eventData.currentPosition,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'soundcloud',
							status: 'progress',
							url: sound.permalink_url,
							title: sound.title,
							// SoundCloud reports ms; gtm.video* wants seconds.
							currentTime: eventData.currentPosition / 1000,
							duration: sound.duration / 1000,
							percent: i,
							element: soundcloud_frame,
						} ),
					} );
				}
			);
		};

		const gtm4wp_onSoundCloudPlayerEvent = function ( eventName ) {
			widget.getPosition( function ( currentPosition ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'soundcloud',
					mediaData: {
						id: sound.id,
						author: sound.user?.username,
						title: sound.title,
						url: sound.permalink_url,
						duration: sound.duration,
					},
					mediaCurrentTime: currentPosition,
					mediaPlayerEvent: eventName,
					...gtm4wpNativeVideoParams( {
						provider: 'soundcloud',
						// These events are not playback states GTM models.
						status: '',
						url: sound.permalink_url,
						title: sound.title,
						// SoundCloud reports ms; gtm.video* wants seconds.
						currentTime: currentPosition / 1000,
						duration: sound.duration / 1000,
						element: soundcloud_frame,
					} ),
				} );
			} );
		};
	};

	gtm4wpObserveMedia(
		'iframe[src*="soundcloud.com"]',
		gtm4wp_wireSoundCloudFrame,
		function () {
			return (
				typeof SC !== 'undefined' && typeof SC.Widget !== 'undefined'
			);
		},
		'https://w.soundcloud.com/player/api.js'
	);
}

gtm4wpOnReady( gtm4wp_initSoundCloudTracking );
