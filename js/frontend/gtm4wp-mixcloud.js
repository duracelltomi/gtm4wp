import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
	gtm4wpMediaSrcUrl,
} from './lib/native-video-params';

const gtm4wp_mixcloud_percentage_tracking = 10;
// Keyed by a provider-reported id, so a null prototype (`__proto__` key).
const gtm4wp_mixcloud_percentage_tracking_marks = Object.create( null );

/**
 * The show path of a `feed` value: the share dialog sends a path, WordPress's
 * oEmbed provider the full show URL (#643), so one show gets one id.
 *
 * @param {string} feed The decoded `feed` query parameter.
 * @return {string} The decoded show path, or the value as given.
 */
function gtm4wp_mixcloudShowPath( feed ) {
	try {
		const show = new URL( feed );
		if ( /(^|\.)mixcloud\.com$/i.test( show.hostname ) ) {
			return decodeURIComponent( show.pathname );
		}
	} catch ( e ) {
		// Not an absolute URL: already the path form.
	}
	return feed;
}

function gtm4wp_initMixcloudTracking() {
	// The Widget API (the largest SDK of the family) is handed to
	// gtm4wpObserveMedia (fetched only when an embed exists) and re-checked
	// per element, since it can still be missing.
	const gtm4wp_wireMixcloudFrame = function ( mixcloud_frame ) {
		const widget = Mixcloud.PlayerWidget( mixcloud_frame );

		// No metadata getter: the show is the `feed` query parameter of the
		// embed URL, reused as the title; no author. The id keeps the raw src
		// when there is no feed: milestones are keyed by it.
		let mediaid = mixcloud_frame.getAttribute( 'src' );
		let mediaurl = gtm4wpMediaSrcUrl( mixcloud_frame ) || mediaid;
		try {
			const feed = new URL(
				mixcloud_frame.getAttribute( 'src' ),
				window.location.href
			).searchParams.get( 'feed' );
			if ( feed ) {
				mediaid = gtm4wp_mixcloudShowPath( feed );
				mediaurl = 'https://www.mixcloud.com' + mediaid;
			}
		} catch ( e ) {
			// Malformed src: fall back to the raw attribute set above.
		}

		mixcloud_frame.setAttribute( 'data-player_id', mediaid );
		mixcloud_frame.setAttribute( 'data-player_url', mediaurl );

		// State events carry no position; cached from the progress event.
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
					// Unknown before the first progress event.
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
		},
		'https://widget.mixcloud.com/media/js/widgetApi.js'
	);
}

gtm4wpOnReady( gtm4wp_initMixcloudTracking );
