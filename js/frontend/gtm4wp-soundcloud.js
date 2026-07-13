import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
} from './lib/native-video-params';

const gtm4wp_soundclound_percentage_tracking = 10;
const gtm4wp_soundclound_percentage_tracking_marks = {};

function gtm4wp_initSoundCloudTracking() {
	// The SoundCloud Widget API (w.soundcloud.com/player/api.js) is enqueued as
	// a dependency of this tracker, but it can still be missing at runtime if a
	// consent manager, an ad blocker or a network error stopped it from
	// loading. Without it there is nothing to hook into, so bail out gracefully
	// instead of throwing on `SC.Widget()`.
	if ( typeof SC === 'undefined' || typeof SC.Widget === 'undefined' ) {
		return;
	}

	const gtm4wp_soundcloud_frames = document.querySelectorAll(
		'iframe[src*="soundcloud.com"]'
	);
	if ( ! gtm4wp_soundcloud_frames || gtm4wp_soundcloud_frames.length == 0 ) {
		return;
	}

	gtm4wp_soundcloud_frames.forEach( function ( soundcloud_frame ) {
		const widget = SC.Widget( soundcloud_frame );
		let sound = {};

		// Reads the widget's current sound, refreshes the cached `sound` and the
		// data-player_* attributes, then runs `done`. SoundCloud widgets can host
		// a playlist, so the current sound changes as playback advances between
		// tracks; refreshing on READY and on every PLAY keeps each push tied to
		// the track that is actually playing (and keeps the percentage marks
		// keyed by the right sound id).
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
				} );
			} ); // end of api call getCurrentSound

			widget.bind(
				SC.Widget.Events.PLAY_PROGRESS,
				function ( eventData ) {
					gtm4wp_onSoundCloudPercentageChange( eventData );
				}
			);

			widget.bind( SC.Widget.Events.PLAY, function ( eventData ) {
				// Refresh the current sound first so a playlist advancing to the
				// next track reports that track's metadata instead of the first
				// sound's, then push the state change.
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
				} ),
			} );
		};

		const gtm4wp_onSoundCloudPercentageChange = function ( eventData ) {
			const mediaPercentage = Math.floor(
				( eventData.currentPosition / sound.duration ) * 100
			);

			if (
				typeof gtm4wp_soundclound_percentage_tracking_marks[
					sound.id
				] === 'undefined'
			) {
				gtm4wp_soundclound_percentage_tracking_marks[ sound.id ] = [];
			}

			for (
				let i = 0;
				i < 100;
				i += gtm4wp_soundclound_percentage_tracking
			) {
				if (
					mediaPercentage > i &&
					gtm4wp_soundclound_percentage_tracking_marks[
						sound.id
					].indexOf( i ) == -1
				) {
					gtm4wp_soundclound_percentage_tracking_marks[
						sound.id
					].push( i );

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
						} ),
					} );
				}
			}
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
				} );
			} );
		};
	} );
}

// The tracker bundle may execute before or after the DOM has finished parsing
// (e.g. when loaded with a defer/async strategy or injected late by a tag
// manager). Guard against a DOMContentLoaded event that has already fired,
// which would otherwise leave the tracking silently uninitialized.
if ( document.readyState === 'loading' ) {
	window.addEventListener(
		'DOMContentLoaded',
		gtm4wp_initSoundCloudTracking
	);
} else {
	gtm4wp_initSoundCloudTracking();
}
