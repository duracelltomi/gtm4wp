import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpMediaSrcUrl,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';
import { gtm4wp_push_dl_event } from './lib/gtm4wp-datalayer';

const gtm4wp_cloudflarestream_percentage_tracking = 10;
// Keyed by the media id the provider reports, so a null prototype: on a plain
// object a key of `__proto__` resolves to Object.prototype instead of a missing
// entry, and writing it back sets the store's prototype instead of a property.
const gtm4wp_cloudflarestream_percentage_tracking_marks = Object.create( null );

function gtm4wp_initCloudflareStreamTracking() {
	// Wire every Cloudflare Stream iframe already on the page and any inserted
	// later (popup/lightbox, AJAX). The Stream Player SDK
	// (embed.cloudflarestream.com/embed/sdk.latest.js) is handed to
	// gtm4wpObserveMedia rather than enqueued by PHP, so a page with no Stream
	// embed never requests it. It can still be missing at runtime (consent
	// manager, ad blocker, network error), so it is re-checked per element: a
	// frame is only wired once the SDK is available.
	const gtm4wp_wireStreamFrame = function ( stream_frame ) {
		const videourl = gtm4wpMediaSrcUrl( stream_frame );

		// The video UID is the last path segment, or the one before it when the
		// embed URL ends in /iframe (…/{uid}/iframe). That is why the src is read
		// through gtm4wpMediaSrcUrl(): WordPress renders a Stream embed as
		// …/{uid}/iframe#?secret=… (U106), and a '#' left on the last segment makes
		// it miss the /iframe test, so every event reports 'iframe#' as the video.
		const parts = videourl.split( '/' ).filter( Boolean );
		let videoid = parts[ parts.length - 1 ];
		if ( videoid === 'iframe' && parts.length >= 2 ) {
			videoid = parts[ parts.length - 2 ];
		}

		// The Stream player mirrors the HTML5 media API: events carry no payload,
		// so the current time and duration are read from the player object.
		//
		// The Player SDK exposes no title and no author, so the UID stands in as
		// the title - unless the embed carries a title attribute, which is the one
		// real title available client-side. WordPress fills that attribute from the
		// oEmbed response (`wp_filter_oembed_iframe_title_attribute()`) and its
		// oEmbed sanitizer keeps it, so on a site whose provider returns a title
		// the embed has one; Cloudflare's own copy-paste snippet does not.
		const frametitle = (
			stream_frame.getAttribute( 'title' ) || ''
		).trim();
		const videotitle = frametitle || videoid;

		const player = Stream( stream_frame );

		const gtm4wp_streamCurrentTime = function () {
			return isNaN( player.currentTime ) ? 0 : player.currentTime || 0;
		};

		const gtm4wp_streamDuration = function () {
			return isNaN( player.duration ) ? 0 : player.duration || 0;
		};

		const gtm4wp_streamMediaData = function () {
			return {
				id: videoid,
				author: '',
				title: videotitle,
				url: videourl,
				duration: gtm4wp_streamDuration(),
			};
		};

		const gtm4wp_onStreamPlayerStateChange = function ( playerState ) {
			gtm4wp_push_dl_event( {
				event: 'gtm4wp.mediaPlayerStateChange',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
				mediaPlayerState: playerState,
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					status: gtm4wpNativeVideoStatus( playerState ),
					url: videourl,
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
				} ),
			} );
		};

		const gtm4wp_onStreamPlayerEvent = function ( eventName, eventParam ) {
			gtm4wp_push_dl_event( {
				event: 'gtm4wp.mediaPlayerEvent',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
				mediaPlayerEvent: eventName,
				mediaPlayerEventParam: eventParam,
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					// These events are not playback states GTM models.
					status: '',
					url: videourl,
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
				} ),
			} );
		};

		const gtm4wp_onStreamPercentageChange = function () {
			const videoDuration = gtm4wp_streamDuration();
			if ( ! videoDuration ) {
				return;
			}

			const videoCurrentTime = gtm4wp_streamCurrentTime();
			const videoPercentage = Math.floor(
				( videoCurrentTime / videoDuration ) * 100
			);

			gtm4wpMediaMilestones(
				gtm4wp_cloudflarestream_percentage_tracking_marks,
				videoid,
				videoPercentage,
				gtm4wp_cloudflarestream_percentage_tracking,
				function ( i ) {
					gtm4wp_push_dl_event( {
						event: 'gtm4wp.mediaPlaybackPercentage',
						mediaType: 'cloudflarestream',
						mediaData: gtm4wp_streamMediaData(),
						mediaCurrentTime: videoCurrentTime,
						mediaPercentage: i,
						...gtm4wpNativeVideoParams( {
							provider: 'cloudflarestream',
							status: 'progress',
							url: videourl,
							title: videotitle,
							currentTime: videoCurrentTime,
							duration: videoDuration,
							percent: i,
							element: stream_frame,
						} ),
					} );
				}
			);
		};

		player.addEventListener( 'loadedmetadata', function () {
			gtm4wp_push_dl_event( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'cloudflarestream',
				mediaData: gtm4wp_streamMediaData(),
				mediaCurrentTime: gtm4wp_streamCurrentTime(),
				...gtm4wpNativeVideoParams( {
					provider: 'cloudflarestream',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: videotitle,
					currentTime: gtm4wp_streamCurrentTime(),
					duration: gtm4wp_streamDuration(),
					element: stream_frame,
				} ),
			} );
		} );

		player.addEventListener( 'play', function () {
			gtm4wp_onStreamPlayerStateChange( 'play' );
		} );

		player.addEventListener( 'pause', function () {
			gtm4wp_onStreamPlayerStateChange( 'pause' );
		} );

		player.addEventListener( 'ended', function () {
			gtm4wp_onStreamPlayerStateChange( 'ended' );
		} );

		player.addEventListener( 'seeked', function () {
			gtm4wp_onStreamPlayerStateChange( 'seeked' );
		} );

		player.addEventListener( 'waiting', function () {
			gtm4wp_onStreamPlayerStateChange( 'buffering' );
		} );

		player.addEventListener( 'timeupdate', function () {
			gtm4wp_onStreamPercentageChange();
		} );

		player.addEventListener( 'ratechange', function () {
			gtm4wp_onStreamPlayerEvent( 'ratechange', player.playbackRate );
		} );

		player.addEventListener( 'volumechange', function () {
			gtm4wp_onStreamPlayerEvent( 'volumechange', player.volume );
		} );

		player.addEventListener( 'error', function () {
			gtm4wp_onStreamPlayerEvent( 'error', player.error );
		} );
	};

	gtm4wpObserveMedia(
		'iframe[src*="cloudflarestream.com"],iframe[src*="videodelivery.net"]',
		gtm4wp_wireStreamFrame,
		function () {
			return typeof Stream !== 'undefined';
		},
		'https://embed.cloudflarestream.com/embed/sdk.latest.js'
	);
}

gtm4wpOnReady( gtm4wp_initCloudflareStreamTracking );
