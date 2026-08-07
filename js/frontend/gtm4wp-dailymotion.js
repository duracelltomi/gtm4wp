import {
	gtm4wpNativeVideoStatus,
	gtm4wpNativeVideoParams,
	gtm4wpMediaMilestones,
	gtm4wpOnReady,
	gtm4wpObserveMedia,
} from './lib/native-video-params';

const gtm4wp_dailymotion_percentage_tracking = 10;
const gtm4wp_dailymotion_percentage_tracking_marks = {};

/**
 * Percent-decodes one path segment, returning it unchanged when it is not valid
 * percent-encoding.
 *
 * searchParams.get() decodes for the query-string forms, so the path forms are
 * decoded too and an id means the same thing whichever embed shape produced it.
 * decodeURIComponent() throws on a malformed sequence, which must not take the
 * whole tracker down.
 *
 * @param {string} value The raw path segment.
 * @return {string} The decoded segment, or the input when it cannot be decoded.
 */
function gtm4wp_dailymotionDecode( value ) {
	try {
		return decodeURIComponent( value );
	} catch ( e ) {
		return value;
	}
}

/**
 * Reads the video id, and any player id, out of a Dailymotion embed src.
 *
 * Four forms reach this; the first is what WordPress' oEmbed emits today:
 *   https://geo.dailymotion.com/player.html?video=<id>&
 *   https://geo.dailymotion.com/player/<playerid>.html?video=<id>
 *   https://www.dailymotion.com/embed/video/<id>        (legacy, 301s to the 1st)
 *   https://dai.ly/<id>                                 (short link)
 *
 * The id is located by the shape of the PATH and never by the shape of the id
 * itself: whatever occupies the id slot is taken verbatim, because a regex
 * validating somebody else's identifier grammar rejects their next one.
 *
 * @param {string} src The iframe's src attribute.
 * @return {{videoid: string, playerid: string}|null} The parsed ids, or null.
 */
function gtm4wp_dailymotionEmbedInfo( src ) {
	let url;
	try {
		url = new URL( src, window.location.href );
	} catch ( e ) {
		return null;
	}

	// The geo player carries the id in the query string; searchParams decodes it.
	let videoid = url.searchParams.get( 'video' ) || '';

	if ( ! videoid ) {
		// Deliberately NOT a generic "last path segment" fallback: for the two
		// geo forms that segment is `player.html` / `<playerid>.html`.
		const embedded = url.pathname.match( /\/embed\/video\/([^/]+)\/?$/ );

		if ( embedded ) {
			videoid = gtm4wp_dailymotionDecode( embedded[ 1 ] );
		} else if ( 'dai.ly' === url.host ) {
			videoid = gtm4wp_dailymotionDecode(
				url.pathname.replace( /^\/+/, '' ).replace( /\/+$/, '' )
			);
		}
	}

	// No video id: a `?playlist=` embed, or a Dailymotion URL that is not a
	// player at all. Bail rather than guess. The previous "last path segment"
	// fallback would have handed createPlayer() the literal string
	// "player.html", which replaces a working embed with a player for a video
	// that does not exist - a tracking failure turned into a content failure.
	if ( ! videoid ) {
		return null;
	}

	// A /player/<playerid>.html embed names the player configuration the site
	// chose. Replacing the embed means WE decide which player the visitor sees,
	// so the one the embed asked for is carried over - otherwise switching
	// tracking on silently swaps the site's configured player for the default.
	const player = url.pathname.match( /\/player\/([^/]+)\.html$/ );

	return {
		videoid,
		playerid: player ? gtm4wp_dailymotionDecode( player[ 1 ] ) : '',
	};
}

/**
 * Turns an iframe dimension into a CSS length.
 *
 * The HTML attribute is a bare pixel count ("640"); some embeds write a
 * percentage ("100%"). Falls back to the box the iframe actually occupies when
 * the attribute is absent, so an embed sized purely by CSS still hands the
 * container something.
 *
 * @param {string|null} attribute The width/height attribute value.
 * @param {number}      measured  The measured box dimension, in pixels.
 * @return {string} A CSS length, or '' when neither source has one.
 */
function gtm4wp_dailymotionLength( attribute, measured ) {
	const value = ( attribute || '' ).trim();

	if ( /^\d+(\.\d+)?$/.test( value ) ) {
		return value + 'px';
	}
	if ( '' !== value ) {
		return value;
	}
	if ( measured > 0 ) {
		return measured + 'px';
	}

	return '';
}

/**
 * Builds the div the Dailymotion SDK fills, carrying over the box the iframe
 * occupied.
 *
 * dailymotion.createPlayer() takes no width/height - the player fills its
 * container - so the iframe's dimensions have to be transplanted or the player
 * collapses to a zero-height block and the video silently disappears.
 * `width`/`height` are HTML dimension attributes, which mean nothing on a div,
 * so they are written as inline style instead. That is also why the box is
 * measured here, before the caller's replaceChild: a removed node measures 0x0.
 *
 * @param {HTMLElement} frame The embed iframe being replaced.
 * @return {HTMLElement} The container, not yet inserted into the document.
 */
function gtm4wp_dailymotionContainerFor( frame ) {
	// Unique-container-id counter, kept on window (not module scope) so ids stay
	// unique when the bundle is re-executed: a tag-manager re-injection restarts
	// module scope at 0 while the earlier gtm4wp-dailymotion-0 container is still
	// in the DOM, and createPlayer() would bind to that stale one.
	window.gtm4wp_dailymotion_frame_index =
		window.gtm4wp_dailymotion_frame_index || 0;

	const container = document.createElement( 'div' );
	container.id =
		'gtm4wp-dailymotion-' + window.gtm4wp_dailymotion_frame_index++;

	// Copied so a theme/plugin rule written against the embed still has
	// something to match. Not copied: src, allow, allowfullscreen, frameborder,
	// title - the SDK builds its own iframe with its own permission set, and
	// those attributes are inert on a div.
	const className = frame.getAttribute( 'class' );
	if ( className ) {
		container.setAttribute( 'class', className );
	}
	const inlineStyle = frame.getAttribute( 'style' );
	if ( inlineStyle ) {
		container.setAttribute( 'style', inlineStyle );
	}

	const rect =
		typeof frame.getBoundingClientRect === 'function'
			? frame.getBoundingClientRect()
			: null;

	const width = gtm4wp_dailymotionLength(
		frame.getAttribute( 'width' ),
		rect && rect.width
	);
	const height = gtm4wp_dailymotionLength(
		frame.getAttribute( 'height' ),
		rect && rect.height
	);

	// Only ever ADDS a dimension the copied style did not already set, so a
	// responsive embed keeps its own rule.
	if ( width && '' === container.style.width ) {
		container.style.width = width;
	}
	if ( height && '' === container.style.height ) {
		container.style.height = height;
	}

	return container;
}

/**
 * Binds the data layer pushes to one Dailymotion player.
 *
 * @param {Object}      player    The player dailymotion.createPlayer() resolved.
 * @param {string}      videoid   The video id parsed from the embed src.
 * @param {string}      videourl  The canonical watch URL of the video.
 * @param {HTMLElement} container The div the SDK fills with its player iframe.
 */
function gtm4wp_bindDailymotionPlayer( player, videoid, videourl, container ) {
	// Every Player Embeds event delivers the FULL player state - the shape
	// getState() resolves to - rather than a per-event payload, so the latest
	// state is cached on arrival and every push reads it from here.
	let lastState = {};

	const gtm4wp_dailymotionCurrentTime = function () {
		const currentTime = Number( lastState.videoTime );
		return isNaN( currentTime ) ? 0 : currentTime || 0;
	};

	const gtm4wp_dailymotionDuration = function () {
		const duration = Number( lastState.videoDuration );
		return isNaN( duration ) ? 0 : duration || 0;
	};

	// The title and the owner only appear in the state once the player has
	// fetched the video's metadata, which is after the first events arrive. The
	// id stands in until then, as it does permanently in the Twitch tracker - an
	// empty gtm.videoTitle reads as "this video has no title" rather than "not
	// known yet".
	const gtm4wp_dailymotionMediaData = function () {
		return {
			id: videoid,
			author: lastState.videoOwnerScreenname || '',
			title: lastState.videoTitle || videoid,
			url: videourl,
			duration: gtm4wp_dailymotionDuration(),
		};
	};

	const gtm4wp_onDailymotionPlayerStateChange = function ( playerState ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerStateChange',
			mediaType: 'dailymotion',
			mediaData: gtm4wp_dailymotionMediaData(),
			mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
			mediaPlayerState: playerState,
			...gtm4wpNativeVideoParams( {
				provider: 'dailymotion',
				status: gtm4wpNativeVideoStatus( playerState ),
				url: videourl,
				title: lastState.videoTitle || videoid,
				currentTime: gtm4wp_dailymotionCurrentTime(),
				duration: gtm4wp_dailymotionDuration(),
				element: container,
			} ),
		} );
	};

	const gtm4wp_onDailymotionPlayerEvent = function ( eventName, eventParam ) {
		window[ gtm4wp_datalayer_name ].push( {
			event: 'gtm4wp.mediaPlayerEvent',
			mediaType: 'dailymotion',
			mediaData: gtm4wp_dailymotionMediaData(),
			mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
			mediaPlayerEvent: eventName,
			mediaPlayerEventParam: eventParam,
			...gtm4wpNativeVideoParams( {
				provider: 'dailymotion',
				// These events are not playback states GTM models.
				status: '',
				url: videourl,
				title: lastState.videoTitle || videoid,
				currentTime: gtm4wp_dailymotionCurrentTime(),
				duration: gtm4wp_dailymotionDuration(),
				element: container,
			} ),
		} );
	};

	const gtm4wp_onDailymotionPercentageChange = function () {
		const videoDuration = gtm4wp_dailymotionDuration();
		if ( ! videoDuration ) {
			return;
		}

		const videoCurrentTime = gtm4wp_dailymotionCurrentTime();
		const videoPercentage = Math.floor(
			( videoCurrentTime / videoDuration ) * 100
		);

		gtm4wpMediaMilestones(
			gtm4wp_dailymotion_percentage_tracking_marks,
			videoid,
			videoPercentage,
			gtm4wp_dailymotion_percentage_tracking,
			function ( i ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlaybackPercentage',
					mediaType: 'dailymotion',
					mediaData: gtm4wp_dailymotionMediaData(),
					mediaCurrentTime: videoCurrentTime,
					mediaPercentage: i,
					...gtm4wpNativeVideoParams( {
						provider: 'dailymotion',
						status: 'progress',
						url: videourl,
						title: lastState.videoTitle || videoid,
						currentTime: videoCurrentTime,
						duration: videoDuration,
						percent: i,
						element: container,
					} ),
				} );
			}
		);
	};

	// Refresh the cache BEFORE the handler reads it. Wrapping every subscription
	// is what makes that unmissable: a handler registered directly on player.on()
	// would report the PREVIOUS event's time and duration.
	const gtm4wp_dailymotionOn = function ( eventName, callback ) {
		player.on( eventName, function ( state ) {
			if ( state ) {
				lastState = state;
			}
			callback( state || {} );
		} );
	};

	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_CRITICALPATHREADY,
		function () {
			window[ gtm4wp_datalayer_name ].push( {
				event: 'gtm4wp.mediaPlayerReady',
				mediaType: 'dailymotion',
				mediaData: gtm4wp_dailymotionMediaData(),
				mediaCurrentTime: gtm4wp_dailymotionCurrentTime(),
				...gtm4wpNativeVideoParams( {
					provider: 'dailymotion',
					// "Ready" has no native GTM video status.
					status: '',
					url: videourl,
					title: lastState.videoTitle || videoid,
					currentTime: gtm4wp_dailymotionCurrentTime(),
					duration: gtm4wp_dailymotionDuration(),
					element: container,
				} ),
			} );
		}
	);

	// VIDEO_PLAY, not VIDEO_START. VIDEO_START marks the beginning of the content
	// video; what this whole tracker family reports as 'play' -> gtm.videoStatus
	// 'start' is every transition INTO playback, resumes included ("no longer
	// paused"). Subscribing to both would push two identical state-change events
	// on the first play, which GTM counts twice.
	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_PLAY, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'play' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_PAUSE, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'pause' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_END, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'ended' );
	} );

	// VIDEO_SEEKEND, not VIDEO_SEEKSTART: 'seeked' -> gtm.videoStatus 'seek' is a
	// COMPLETED seek everywhere in the family, and its videoTime is the
	// destination rather than the position the user left. Dragging a scrub handle
	// fires both repeatedly, so subscribing to both would double every scrub.
	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_SEEKEND, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'seeked' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_BUFFERING, function () {
		gtm4wp_onDailymotionPlayerStateChange( 'buffering' );
	} );

	gtm4wp_dailymotionOn( dailymotion.events.VIDEO_TIMECHANGE, function () {
		gtm4wp_onDailymotionPercentageChange();
	} );

	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_VOLUMECHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'volumechange',
				state.playerVolume
			);
		}
	);

	gtm4wp_dailymotionOn(
		dailymotion.events.VIDEO_QUALITYCHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'qualitychange',
				state.videoQuality
			);
		}
	);

	// Player Embeds folds fullscreen and Picture-in-Picture into one presentation
	// mode, which travels as the event parameter. The event NAME stays
	// 'fullscreenchange': that string is the data layer contract sites already
	// trigger on.
	gtm4wp_dailymotionOn(
		dailymotion.events.PLAYER_PRESENTATIONMODECHANGE,
		function ( state ) {
			gtm4wp_onDailymotionPlayerEvent(
				'fullscreenchange',
				state.playerPresentationMode
			);
		}
	);

	gtm4wp_dailymotionOn( dailymotion.events.PLAYER_ERROR, function ( state ) {
		gtm4wp_onDailymotionPlayerEvent( 'error', state.playerError );
	} );
}

function gtm4wp_initDailymotionTracking() {
	// The library URL is built by PHP (MediaEventsModule::enqueue_scripts) because
	// it can carry the site's configured Dailymotion player ID, which is
	// url-encoded once, server side, where it enters the URL. Written in exactly
	// one place: with no config published there is nothing to fetch, so the
	// embeds are left untouched and simply go untracked.
	const config = window.gtm4wp_dailymotion_config || {};
	const sdk = 'string' === typeof config.sdk ? config.sdk : '';

	// Wire every Dailymotion iframe already on the page and any inserted later
	// (popup/lightbox, AJAX).
	//
	// Dailymotion sunset the legacy player integration (api.dmcdn.net/all.js) on
	// 2026-02-03: the script still loads but only console-warns, and no event ever
	// fires. Its replacement, the Player Embeds API, cannot be attached to an
	// iframe that already exists - WordPress' oEmbed emits an ID-less embed
	// (<iframe src="https://geo.dailymotion.com/player.html?video=<id>&">), which
	// Dailymotion documents as having no API and no way to reach the parent page.
	// So the only way to get events is to build the player ourselves: each embed
	// iframe is replaced with a container div the SDK fills, the same shape
	// gtm4wp-twitch.js uses, for the same reason.
	const gtm4wp_wireDailymotionFrame = function ( dailymotion_frame ) {
		const info = gtm4wp_dailymotionEmbedInfo(
			dailymotion_frame.getAttribute( 'src' ) || ''
		);
		if ( ! info ) {
			return;
		}
		if ( ! dailymotion_frame.parentNode ) {
			return;
		}

		// The canonical watch URL, not the embed's own src: every video on the
		// site is served from the same geo.dailymotion.com/player.html, so the src
		// with its query stripped would identify nothing. Built from the id the
		// way gtm4wp-twitch.js builds https://www.twitch.tv/videos/<id>.
		const videourl = 'https://www.dailymotion.com/video/' + info.videoid;
		const container = gtm4wp_dailymotionContainerFor( dailymotion_frame );

		// Marked BEFORE it goes into the DOM so the iframe the SDK injects into it
		// - which also matches this tracker's own selector - is skipped by the
		// shared MutationObserver (it has a marked ancestor) instead of being
		// replaced again in an unbounded loop.
		container.setAttribute( 'data-gtm4wp-media-wired', '1' );
		dailymotion_frame.parentNode.replaceChild(
			container,
			dailymotion_frame
		);

		const options = { video: info.videoid };
		if ( info.playerid ) {
			options.player = info.playerid;
		}

		// Whether createPlayer() got as far as handing over a player. The catch
		// below covers the bind step too - a promise rejection handler cannot see
		// which half of the .then threw - and the two failures call for opposite
		// responses, so they are told apart here rather than guessed at.
		let created = false;

		dailymotion
			.createPlayer( container.id, options )
			.then( function ( player ) {
				created = true;
				gtm4wp_bindDailymotionPlayer(
					player,
					info.videoid,
					videourl,
					container
				);
			} )
			.catch( function ( error ) {
				// The player exists and the visitor is watching it; only the
				// event wiring failed. Tearing the container out here would
				// destroy a working video and restart it from an iframe, to
				// report a problem that is ours - so report it and stop.
				if ( ! created ) {
					// The original iframe is already gone by the time this runs,
					// so without the restore a failure to TRACK the video becomes
					// a failure to SHOW it: the visitor is left with an empty box
					// where the player was. Put the untouched original back.
					//
					// Marked FIRST, then replaced: the restored iframe matches
					// this tracker's selector again, so with runtime tracking on
					// the shared observer would wire it, replace it, fail,
					// restore it - forever.
					dailymotion_frame.setAttribute(
						'data-gtm4wp-media-wired',
						'1'
					);
					if ( container.parentNode ) {
						container.parentNode.replaceChild(
							dailymotion_frame,
							container
						);
					}
				}

				// Reported like every other unrecoverable player failure in the
				// family (see the Vimeo tracker's .catch): a mediaPlayerEvent named
				// 'error' carrying the reason, measured on whichever node is
				// actually on screen now.
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.mediaPlayerEvent',
					mediaType: 'dailymotion',
					mediaData: {
						id: info.videoid,
						author: '',
						title: info.videoid,
						url: videourl,
						duration: 0,
					},
					mediaCurrentTime: 0,
					mediaPlayerEvent: 'error',
					mediaPlayerEventParam: error,
					...gtm4wpNativeVideoParams( {
						provider: 'dailymotion',
						status: '',
						url: videourl,
						title: info.videoid,
						currentTime: 0,
						duration: 0,
						// Whichever node the branch above left on the page: the
						// container still holding the player, or the restored
						// embed. The other one is detached and would report no
						// position at all.
						element: created ? container : dailymotion_frame,
					} ),
				} );
			} );
	};

	gtm4wpObserveMedia(
		'iframe[src*="dailymotion.com"],iframe[src*="dai.ly"]',
		gtm4wp_wireDailymotionFrame,
		function () {
			// `dailymotion` alone is not enough: the documented bootstrap has the
			// page define window.dailymotion = { onScriptLoaded: ... } BEFORE the
			// library loads, so the global routinely exists with no API on it. The
			// gate is the two members this tracker actually uses - createPlayer,
			// and the events map every subscription indexes into.
			return (
				typeof dailymotion !== 'undefined' &&
				null !== dailymotion &&
				typeof dailymotion.createPlayer === 'function' &&
				!! dailymotion.events
			);
		},
		sdk
	);
}

gtm4wpOnReady( gtm4wp_initDailymotionTracking );
