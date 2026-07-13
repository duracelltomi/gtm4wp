import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
} from './lib/native-video-params';

const gtm4wp_mixcloud_percentage_tracking = 10;
const gtm4wp_mixcloud_percentage_tracking_marks = {};

function gtm4wp_initMixcloudTracking() {
	// The Mixcloud Widget API (widget.mixcloud.com/media/js/widgetApi.js) is
	// enqueued as a dependency of this tracker, but it can still be missing at
	// runtime if a consent manager, an ad blocker or a network error stopped it
	// from loading. Without it there is nothing to hook into, so bail out
	// gracefully instead of throwing on `Mixcloud.PlayerWidget()`.
	if (
		typeof Mixcloud === 'undefined' ||
		typeof Mixcloud.PlayerWidget === 'undefined'
	) {
		return;
	}

	const gtm4wp_mixcloud_frames = document.querySelectorAll(
		'iframe[src*="mixcloud.com"]'
	);
	if ( ! gtm4wp_mixcloud_frames || gtm4wp_mixcloud_frames.length == 0 ) {
		return;
	}

	gtm4wp_mixcloud_frames.forEach( function ( mixcloud_frame ) {
		const widget = Mixcloud.PlayerWidget( mixcloud_frame );

		// The Mixcloud widget exposes no getCurrentSound-style metadata getter,
		// so the show is identified from the `feed` query parameter of the embed
		// URL (e.g. ?feed=%2Fartist%2Fshow%2F). Title/author are not available;
		// the feed path is the best identifier and is reused as the title, with
		// an empty author, to keep the mediaData shape consistent with the other
		// trackers.
		let mediaid = mixcloud_frame.getAttribute( 'src' );
		let mediaurl = mediaid;
		try {
			const feed = new URL(
				mixcloud_frame.getAttribute( 'src' ),
				window.location.href
			).searchParams.get( 'feed' );
			if ( feed ) {
				mediaid = feed;
				mediaurl = 'https://www.mixcloud.com' + feed;
			}
		} catch ( e ) {
			// Malformed src: fall back to the raw attribute set above.
		}

		mixcloud_frame.setAttribute( 'data-player_id', mediaid );
		mixcloud_frame.setAttribute( 'data-player_url', mediaurl );

		// Mixcloud's play/pause/ended events carry no position payload, so the
		// latest position and duration are cached from the progress event (which
		// reports both, in seconds) and reused by the state handlers.
		let lastPosition = 0;
		let lastDuration = 0;

		const gtm4wp_mixcloudMediaData = function () {
			return {
				id: mediaid,
				author: '',
				title: mediaid,
				url: mediaurl,
				duration: lastDuration,
			};
		};

		const gtm4wp_onMixcloudPlayerStateChange = function ( playerState ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'mixcloud',
				mediaData: gtm4wp_mixcloudMediaData(),
				mediaCurrentTime: lastPosition,
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'mixcloud',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: mediaurl,
					title: mediaid,
					currentTime: lastPosition,
					duration: lastDuration,
				} ),
			} );
		};

		const gtm4wp_onMixcloudPlayerEvent = function ( eventName ) {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'mixcloud',
				mediaData: gtm4wp_mixcloudMediaData(),
				mediaCurrentTime: lastPosition,
				mediaPlayerEvent: eventName,
			} );
		};

		const gtm4wp_onMixcloudPercentageChange = function (
			position,
			duration
		) {
			if ( ! duration ) {
				return;
			}

			const mediaPercentage = Math.floor( ( position / duration ) * 100 );

			gtm4wpMediaMilestones(
				gtm4wp_mixcloud_percentage_tracking_marks,
				mediaid,
				mediaPercentage,
				gtm4wp_mixcloud_percentage_tracking,
				function ( i ) {
					window[ gtm4wp_datalayer_name ].push( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'mixcloud',
						mediaData: gtm4wp_mixcloudMediaData(),
						mediaCurrentTime: position,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'mixcloud',
							status: 'progress',
							url: mediaurl,
							title: mediaid,
							currentTime: position,
							duration,
							percent: i,
						} ),
					} );
				}
			);
		};

		widget.ready.then( function () {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'mixcloud',
				mediaData: gtm4wp_mixcloudMediaData(),
				mediaCurrentTime: 0,
			} );

			widget.events.play.on( function () {
				gtm4wp_onMixcloudPlayerStateChange( 'play' );
			} );

			widget.events.pause.on( function () {
				gtm4wp_onMixcloudPlayerStateChange( 'pause' );
			} );

			widget.events.ended.on( function () {
				gtm4wp_onMixcloudPlayerStateChange( 'ended' );
			} );

			widget.events.buffering.on( function () {
				gtm4wp_onMixcloudPlayerStateChange( 'buffering' );
			} );

			widget.events.progress.on( function ( position, duration ) {
				lastPosition = position;
				lastDuration = duration;
				gtm4wp_onMixcloudPercentageChange( position, duration );
			} );

			widget.events.error.on( function () {
				gtm4wp_onMixcloudPlayerEvent( 'error' );
			} );
		} );
	} );
}

gtm4wpOnReady( gtm4wp_initMixcloudTracking );
