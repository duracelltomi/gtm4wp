import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_mixcloud_percentage_tracking = 10;
const gtm4wp_mixcloud_percentage_tracking_marks = {};

function gtm4wp_initMixcloudTracking() {
	// Wire every Mixcloud iframe already on the page and any inserted later
	// (popup/lightbox, AJAX). The Mixcloud Widget API
	// (widget.mixcloud.com/media/js/widgetApi.js) is enqueued as a dependency but
	// can still be missing at runtime (consent manager, ad blocker, network
	// error), so it is re-checked per element: a frame is only wired once the SDK
	// is available.
	const gtm4wp_wireMixcloudFrame = function ( mixcloud_frame ) {
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
					element: mixcloud_frame,
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
				...gtm4wpNativeVideoParams( {
					provider: 'mixcloud',
					// The error event is not a playback state GTM models.
					status: '',
					url: mediaurl,
					title: mediaid,
					currentTime: lastPosition,
					duration: lastDuration,
					element: mixcloud_frame,
				} ),
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
							element: mixcloud_frame,
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
				...gtm4wpNativeVideoParams( {
					provider: 'mixcloud',
					// "Ready" has no native GTM video status.
					status: '',
					url: mediaurl,
					title: mediaid,
					currentTime: 0,
					// No progress event has arrived yet, so the duration is
					// still unknown (as it is in mediaData above).
					duration: lastDuration,
					element: mixcloud_frame,
				} ),
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
	};

	gtm4wpObserveMedia(
		'iframe[src*="mixcloud.com"]',
		gtm4wp_wireMixcloudFrame,
		function () {
			return (
				typeof Mixcloud !== 'undefined' &&
				typeof Mixcloud.PlayerWidget !== 'undefined'
			);
		}
	);
}

gtm4wpOnReady( gtm4wp_initMixcloudTracking );
