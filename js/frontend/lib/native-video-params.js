/**
 * Google Tag Manager built-in "Video *" variable support.
 *
 * GTM ships built-in Video variables (Video Status, Video URL, Video Title,
 * Video Provider, Video Duration, Video Current Time, Video Percent, Video
 * Visible) that only read the flat `gtm.video*` data layer keys emitted by
 * GTM's own native YouTube trigger. GTM4WP's media trackers push their own
 * bespoke `gtm4wp.media*` shape, so those variables never resolve.
 *
 * These helpers build the `gtm.video*` keys so each tracker can spread them
 * next to its existing parameters in the same data layer push, letting a Custom
 * Event trigger on the existing `gtm4wp.media*` event names resolve the built-in
 * variables.
 */

/**
 * Maps a GTM4WP media player state to GTM's built-in Video status value.
 *
 * @param {string} state GTM4WP `mediaPlayerState` (e.g. 'play', 'ended').
 * @return {string} The `gtm.videoStatus` value, or '' when there is no native
 *                  equivalent (e.g. 'cued'/'unstarted'/unknown).
 */
export function gtm4wpNativeVideoStatus( state ) {
	switch ( state ) {
		case 'play':
			return 'start';
		case 'pause':
			return 'pause';
		case 'buffering':
			return 'buffering';
		case 'ended':
			return 'complete';
		case 'seeked':
			return 'seek';
		default:
			return '';
	}
}

/**
 * Reports whether a media player sits inside the browser viewport, for GTM's
 * built-in "Video Visible" variable (`gtm.videoVisible`).
 *
 * GTM describes the variable only as "true if the video is visible in the
 * viewport" and publishes no threshold for its own YouTube trigger, so this
 * measures the player's box at the moment of the push: visible means the box
 * overlaps the viewport and is not hidden by CSS. A player scrolled halfway out
 * therefore still counts as visible, which follows the published wording rather
 * than inventing a percentage. Registered as upstream claim U102.
 *
 * The measurement is synchronous (`getBoundingClientRect`) rather than a stored
 * IntersectionObserver ratio, so it can never report a value that a scroll
 * since the last observer callback has already invalidated. Media pushes are
 * infrequent — state changes and percentage milestones, not every time update —
 * so the layout read costs nothing measurable.
 *
 * @param {HTMLElement|Function} [target] The player element, or a function
 *                                        returning it, for a player whose SDK
 *                                        can swap the element out or that is
 *                                        resolved per event (VideoPress).
 * @return {boolean|undefined} Whether the player is in the viewport, or
 *                             undefined when there is nothing to measure (no
 *                             element, or one detached from the document) — the
 *                             caller then omits the key instead of guessing.
 */
export function gtm4wpMediaVisible( target ) {
	let element = target;

	// A resolver reaches into player-SDK or DOM code (Wistia's elem(), the slot
	// lookup): never let it turn a data layer push into an exception.
	if ( typeof target === 'function' ) {
		try {
			element = target();
		} catch ( e ) {
			return undefined;
		}
	}

	if (
		! element ||
		1 !== element.nodeType ||
		! element.isConnected ||
		typeof element.getBoundingClientRect !== 'function'
	) {
		return undefined;
	}

	const view = element.ownerDocument && element.ownerDocument.defaultView;
	if ( ! view ) {
		return undefined;
	}

	// `visibility` is inherited, so this also catches a hidden ancestor. A
	// `display: none` ancestor collapses the box to 0×0, caught by the rect below.
	if ( typeof view.getComputedStyle === 'function' ) {
		const style = view.getComputedStyle( element );

		if (
			style &&
			( 'hidden' === style.visibility || 'none' === style.display )
		) {
			return false;
		}
	}

	const rect = element.getBoundingClientRect();
	if ( ! rect || rect.width <= 0 || rect.height <= 0 ) {
		return false;
	}

	const viewportHeight =
		view.innerHeight || view.document.documentElement.clientHeight || 0;
	const viewportWidth =
		view.innerWidth || view.document.documentElement.clientWidth || 0;

	return (
		rect.top < viewportHeight &&
		rect.bottom > 0 &&
		rect.left < viewportWidth &&
		rect.right > 0
	);
}

/**
 * Builds the flat `gtm.video*` keys that populate GTM's built-in Video
 * variables, ready to be spread into a data layer push.
 *
 * `currentTime` and `duration` are expected in seconds; callers whose player
 * API reports milliseconds (SoundCloud) must convert before calling. `percent`
 * is derived from time / duration when not supplied.
 *
 * @param {Object}               args
 * @param {string}               args.provider    Video provider, e.g. 'youtube', 'vimeo'.
 * @param {string}               args.status      Already-mapped `gtm.videoStatus` (may be '').
 * @param {string}               args.url         Video URL.
 * @param {string}               args.title       Video title.
 * @param {number}               args.currentTime Current playback position, in seconds.
 * @param {number}               args.duration    Total duration, in seconds.
 * @param {number}               [args.percent]   Integer 0-100; computed from time/duration
 *                                                when omitted.
 * @param {HTMLElement|Function} [args.element]   The player element (or a function returning
 *                                                it) whose viewport position becomes
 *                                                `gtm.videoVisible`. Omitting it omits that
 *                                                one key; every other key is unaffected.
 * @return {Object} The `gtm.video*` keys to spread into a data layer push.
 */
export function gtm4wpNativeVideoParams( {
	provider,
	status,
	url,
	title,
	currentTime,
	duration,
	percent,
	element,
} ) {
	const dur = Number( duration ) || 0;
	const cur = Number( currentTime ) || 0;

	let pct = 0;
	if ( typeof percent === 'number' ) {
		pct = percent;
	} else if ( dur > 0 ) {
		pct = Math.floor( ( cur / dur ) * 100 );
	}

	const params = {
		'gtm.videoProvider': provider,
		'gtm.videoUrl': url,
		'gtm.videoTitle': title,
		'gtm.videoStatus': status,
		'gtm.videoCurrentTime': Math.floor( cur ),
		'gtm.videoDuration': Math.floor( dur ),
		'gtm.videoPercent': pct,
	};

	// Left out entirely when there is no element to measure: an absent variable
	// is honest, a guessed false is not.
	const visible = gtm4wpMediaVisible( element );
	if ( typeof visible === 'boolean' ) {
		params[ 'gtm.videoVisible' ] = visible;
	}

	return params;
}

/**
 * Fires each not-yet-reached percentage milestone once.
 *
 * Every media tracker records the milestones it has already pushed per media
 * item and, on each progress tick, fires the callback for each `step`-sized mark
 * (0, 10, 20, …) the current `percentage` has newly crossed. This helper holds
 * that shared bookkeeping so each tracker only supplies its own push payload.
 *
 * Callers must guard against a zero/absent duration before computing
 * `percentage` (e.g. `if ( ! duration ) return;`): `time / 0` is `Infinity`,
 * which is greater than every mark and would fire them all.
 *
 * @param {Object}   marks       Per-key store of already-fired marks (mutated).
 * @param {string}   key         Media item key (video id / uri / currentSrc).
 * @param {number}   percentage  Integer playback percentage (0-100).
 * @param {number}   step        Milestone granularity, e.g. 10.
 * @param {Function} onMilestone Called with each newly crossed mark `i`.
 * @return {void}
 */
export function gtm4wpMediaMilestones(
	marks,
	key,
	percentage,
	step,
	onMilestone
) {
	if ( typeof marks[ key ] === 'undefined' ) {
		marks[ key ] = [];
	}

	for ( let i = 0; i < 100; i += step ) {
		if ( percentage > i && marks[ key ].indexOf( i ) === -1 ) {
			marks[ key ].push( i );
			onMilestone( i );
		}
	}
}

/**
 * Runs a tracker's init once the DOM is ready.
 *
 * A tracker bundle may execute before or after the DOM has finished parsing
 * (defer/async strategy, or late injection by a tag manager). This runs the
 * init immediately when parsing is already done, otherwise on DOMContentLoaded,
 * so nothing is left silently uninitialized.
 *
 * @param {Function} callback The tracker init function.
 * @return {void}
 */
export function gtm4wpOnReady( callback ) {
	if ( document.readyState === 'loading' ) {
		window.addEventListener( 'DOMContentLoaded', callback );
	} else {
		callback();
	}
}

/**
 * Wires every element matching `selector` and, when the site has opted in to
 * runtime tracking, keeps wiring ones inserted later (popup/lightbox, AJAX) so a
 * media player added after page load is tracked exactly like one present at init.
 *
 * The players already in the DOM are always wired. Watching for later insertions
 * is opt-in via `window.gtm4wp_media_observe_dynamic` (set by the media module
 * from the "track dynamically inserted players" setting), because a body-wide
 * MutationObserver has a per-mutation cost that only pays off on sites that
 * inject players after load. When enabled, ALL providers share ONE observer:
 * each tracker registers a (selector, wire) scanner, so enabling N providers
 * does not create N observers on the document. The shared callback inspects only
 * the nodes each mutation adds (never the whole document).
 *
 * Each wired element is marked with a data attribute so a re-report — or a node
 * moved elsewhere in the DOM — never binds it twice. The marker doubles as the
 * guard against a provider SDK that injects its own matching iframe into a
 * container the tracker created (the Twitch embed): mark that container and its
 * descendants are skipped.
 *
 * A marker on the element alone is not enough for an SDK that REPLACES the
 * element it is handed (Spotify): the marker leaves with the replaced node, so
 * the observer would see the SDK's own iframe as a fresh, unmarked match and
 * wire it — replacing it again, forever. wireOnce therefore re-marks whatever
 * takes the element's slot. Keep this invariant when adding a provider: never
 * assume the element handed to wireElement survives the call.
 *
 * @param {string}   selector    CSS selector identifying the provider embed.
 * @param {Function} wireElement Called once with each matching element to wire
 *                               it (the same wiring the tracker runs at init).
 *                               Receives a second argument: a function
 *                               resolving the element that currently occupies
 *                               the wired element's slot, for a tracker whose
 *                               SDK replaces it (Spotify).
 * @param {Function} [isReady]   Optional predicate; when it returns a falsy value
 *                               the element is left unwired AND unmarked (e.g.
 *                               the player SDK has not loaded), so a later
 *                               insertion can still wire it once the SDK exists.
 * @return {MutationObserver|null} The shared observer, or null when runtime
 *                                 tracking is not enabled.
 */
export function gtm4wpObserveMedia( selector, wireElement, isReady ) {
	const wireOnce = function ( element ) {
		// Skip when this element — or an ancestor the tracker already marked
		// (e.g. the Twitch container whose SDK-injected iframe also matches the
		// selector) — has been wired.
		if ( element.closest( '[data-gtm4wp-media-wired]' ) ) {
			return;
		}
		// SDK not ready: leave the element unmarked so a later insertion (once
		// the SDK has loaded) can still wire it.
		if ( typeof isReady === 'function' && ! isReady() ) {
			return;
		}

		// Remember the slot BEFORE wiring: some provider SDKs replace the element
		// they are handed (see the re-mark below), which takes the marker with it.
		const parent = element.parentNode;
		const slot = parent
			? Array.prototype.indexOf.call( parent.childNodes, element )
			: -1;

		// Resolves whichever element currently occupies this slot: the wired
		// element itself, or — once an SDK has replaced it — its replacement.
		// Trackers hand this to gtm4wpNativeVideoParams so `gtm.videoVisible` is
		// measured on the node that is actually on screen; a detached original
		// would report a 0×0 box forever.
		const liveElement = function () {
			if ( element.isConnected ) {
				return element;
			}

			if ( parent && slot > -1 ) {
				const node = parent.childNodes[ slot ];

				// Same test as the re-mark below: only a node this scanner would
				// itself recognise as the provider's embed counts as the
				// replacement. Without it, a node that merely shifted into the
				// slot after an unrelated removal would be measured as if it
				// were the player.
				if (
					node &&
					1 === node.nodeType &&
					( node.matches( selector ) ||
						node.querySelector( selector ) )
				) {
					return node;
				}
			}

			return null;
		};

		element.setAttribute( 'data-gtm4wp-media-wired', '1' );
		wireElement( element, liveElement );

		// Some SDKs REPLACE the element they are given with their own iframe
		// rather than reusing it in place — Spotify's createController() does
		// `parentElement.replaceChild( iframe, target )` plus a synchronous `src`
		// assignment. The replacement matches the same selector, carries no
		// marker and has no marked ancestor, so the observer would wire it, which
		// replaces it again — an unbounded loop that hangs the tab. replaceChild
		// keeps the slot, so re-mark whatever now occupies it. Only a node that
		// would actually be re-wired by THIS scanner is marked (it matches the
		// selector, or wraps something that does), so an unrelated node shifting
		// into the slot after a plain removal is never marked — which would have
		// hidden a real embed from tracking.
		if ( parent && slot > -1 && element.parentNode !== parent ) {
			const replacement = parent.childNodes[ slot ];

			if (
				replacement &&
				1 === replacement.nodeType &&
				( replacement.matches( selector ) ||
					replacement.querySelector( selector ) )
			) {
				replacement.setAttribute( 'data-gtm4wp-media-wired', '1' );
			}
		}
	};

	// Wire everything already present (this happens regardless of the opt-in).
	document.querySelectorAll( selector ).forEach( wireOnce );

	// Runtime tracking of later-inserted players is opt-in.
	if ( ! window.gtm4wp_media_observe_dynamic ) {
		return null;
	}

	// Register this provider's scanner on the single shared observer. A
	// re-executed bundle (tag manager re-injection) replaces its own scanner
	// rather than stacking a duplicate — the double-init guard, mirroring the
	// VideoPress/Wistia/Spotify trackers.
	window.gtm4wp_media_scanners = (
		window.gtm4wp_media_scanners || []
	).filter( function ( scanner ) {
		return scanner.selector !== selector;
	} );
	window.gtm4wp_media_scanners.push( { selector, wireOnce } );

	if ( ! window.gtm4wp_media_observer ) {
		window.gtm4wp_media_observer = new MutationObserver( function (
			mutations
		) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					// Only element nodes can match or contain a selector.
					if ( node.nodeType !== 1 ) {
						return;
					}
					window.gtm4wp_media_scanners.forEach( function ( scanner ) {
						if ( node.matches( scanner.selector ) ) {
							scanner.wireOnce( node );
						}
						// The added node may be a wrapper (a lightbox/popup
						// container) holding the embed; querySelectorAll scans
						// only that added subtree, never the whole document.
						node.querySelectorAll( scanner.selector ).forEach(
							scanner.wireOnce
						);
					} );
				} );
			} );
		} );

		window.gtm4wp_media_observer.observe(
			document.body || document.documentElement,
			{ childList: true, subtree: true }
		);
	}

	return window.gtm4wp_media_observer;
}
