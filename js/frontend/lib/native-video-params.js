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
 *
 * EVERY `gtm4wp.media*` push that has a player to describe carries these keys —
 * not only the two events with a native GTM counterpart. The data layer is
 * merged state, so a key left out of a push keeps the value the PREVIOUS push
 * gave it: a `gtm4wp.mediaPlayerEvent` that omitted them would resolve Video
 * Title/Status/Percent to whatever the last state change happened to leave
 * behind, which reads as data rather than as a gap. The one exception is
 * `gtm4wp.mediaApiReady`, which fires when the provider's SDK loads and has no
 * player to report at all.
 *
 * An event with no native equivalent (player ready, and the player events that
 * are not state changes) passes `status: ''`, which is what
 * gtm4wpNativeVideoStatus() already returns for a state GTM does not model.
 * Empty rather than absent, for the same merge reason: it clears the previous
 * status instead of inheriting it.
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
 * Reduces a media URL to its bare form: absolute, with the query string AND the
 * fragment removed.
 *
 * Every tracker that reads an identifier out of a URL needs this first, and the
 * fragment half is not an edge case. WordPress core's
 * `wp_filter_oembed_result()` appends `#?secret=<10 chars>` to the iframe src of
 * every oEmbed result whose provider is not in core's own trusted list (it also
 * adds `sandbox="allow-scripts"` and drops `allow`/`allowfullscreen`) — so for
 * those providers a fragment is what WordPress *always* renders. Cutting only at
 * the `?` leaves the `#` behind and every id parsed from the path carries it,
 * which is what turned a Cloudflare Stream `…/{uid}/iframe#?secret=…` embed into
 * the id `iframe#`. Registered as upstream claim U106.
 *
 * `origin + pathname` drops both in one step and resolves a relative or
 * protocol-relative URL on the way.
 *
 * @param {string} url The media URL, e.g. an iframe `src` or a media element's
 *                     `currentSrc`.
 * @return {string} The URL without query or fragment, or '' when there is
 *                  nothing to read.
 */
export function gtm4wpMediaBareUrl( url ) {
	const src = url || '';

	// An empty URL has to short-circuit: new URL( '', href ) resolves to the
	// PAGE, so an embed with nothing to play would otherwise report the article
	// it sits on as the media URL.
	if ( '' === src ) {
		return '';
	}

	try {
		const parsed = new URL( src, window.location.href );

		return parsed.origin + parsed.pathname;
	} catch ( e ) {
		// A URL no parser accepts still must not throw inside a tracker's
		// wiring: fall back to cutting the two delimiters off by hand, '#' first
		// so a '?' living inside the fragment cannot be mistaken for a query.
		return src.split( '#' ).shift().split( '?' ).shift();
	}
}

/**
 * The same bare URL, read straight off an embed's `src` attribute — what every
 * iframe tracker wants before it pulls an id out of the path.
 *
 * @see gtm4wpMediaBareUrl
 *
 * @param {HTMLElement} element The embed element carrying the `src`.
 * @return {string} The media URL without query or fragment, or '' when the
 *                  element has no `src`.
 */
export function gtm4wpMediaSrcUrl( element ) {
	return gtm4wpMediaBareUrl( element && element.getAttribute( 'src' ) );
}

/**
 * Reports whether a media player sits inside the browser viewport, for GTM's
 * built-in "Video Visible" variable (`gtm.videoVisible`).
 *
 * GTM describes the variable only as "true if the video is visible in the
 * viewport" and publishes no threshold for its own YouTube trigger, so this
 * measures at the moment of the push, in two parts: the page must be on screen
 * at all (a background tab is not), and the player's box must overlap the
 * viewport without being hidden by CSS. A player scrolled halfway out therefore
 * still counts as visible, which follows the published wording rather than
 * inventing a percentage. Registered as upstream claim U102.
 *
 * The tab half matters more than it looks: a video keeps playing in a
 * background tab, so its progress milestones keep firing at a player nobody can
 * see, and geometry alone would call every one of them visible.
 *
 * Two states are not detectable and are reported as the page's own visibility:
 * a window fully covered by another window (no browser API exposes it), and a
 * player popped out into Picture-in-Picture, which stays on screen while its
 * tab is hidden (for a provider iframe the pop-out happens inside the embed,
 * where nothing on this page can observe it).
 *
 * The measurement is synchronous (`getBoundingClientRect`) rather than a stored
 * IntersectionObserver ratio, so it can never report a value that a scroll
 * since the last observer callback has already invalidated. Media pushes are
 * infrequent — player ready, state changes, percentage milestones and the
 * user-initiated player events (rate/volume/quality/fullscreen/error), never
 * every time update — so the layout read costs nothing measurable.
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

	// A page in a background tab or a minimised window is not on screen at all,
	// whatever its geometry says — and media keeps playing there, so progress
	// milestones do keep firing. This is checked first because no box on a page
	// nobody is looking at can be visible.
	if ( 'hidden' === view.document.visibilityState ) {
		return false;
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
 * `key` is a media id the provider reports, so it is never assumed to be an
 * ordinary property name. The already-fired list is resolved into a local
 * variable and type-checked rather than read back out of the store on each
 * pass: a key of `__proto__` reads `Object.prototype` off a plain object — an
 * existing value, so a `typeof … === 'undefined'` test skips the initialisation
 * and the loop then calls `.indexOf()` on it — and writing that key back does
 * not create an own property either. Callers should still declare the store
 * with `Object.create( null )`, which is what makes the write a plain property;
 * this end holds regardless, because the store belongs to the caller and a
 * third-party tracker may hand over any object at all.
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
	let fired = marks[ key ];

	if ( ! Array.isArray( fired ) ) {
		fired = [];
		marks[ key ] = fired;
	}

	for ( let i = 0; i < 100; i += step ) {
		if ( percentage > i && fired.indexOf( i ) === -1 ) {
			fired.push( i );
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
 * @param {string}        selector    CSS selector identifying the provider embed.
 * @param {Function}      wireElement Called once with each matching element to wire
 *                                    it (the same wiring the tracker runs at init).
 *                                    Receives a second argument: a function
 *                                    resolving the element that currently occupies
 *                                    the wired element's slot, for a tracker whose
 *                                    SDK replaces it (Spotify).
 * @param {Function}      [isReady]   Optional predicate; when it returns a falsy value
 *                                    the element is left unwired AND unmarked (e.g.
 *                                    the player SDK has not loaded), so a later
 *                                    insertion can still wire it once the SDK exists.
 * @param {string|Object} [sdk]       The provider SDK to load, and ONLY once this page
 *                                    is known to contain a matching embed. A string is
 *                                    the script URL, ready when the script fires load.
 *                                    Use the object form `{ src, subscribe }` for an
 *                                    SDK that signals readiness through a global
 *                                    callback instead of its own load event (YouTube's
 *                                    onYouTubeIframeAPIReady, Spotify's
 *                                    onSpotifyIframeApiReady): `subscribe` is handed a
 *                                    rescan function to call from that callback. Omit
 *                                    for a tracker with no SDK to fetch (HTML5 media)
 *                                    or one that binds to a runtime the page already
 *                                    loads (Wistia, JW Player, VideoPress).
 * @return {MutationObserver|null} The shared observer, or null when runtime
 *                                 tracking is not enabled.
 */
export function gtm4wpObserveMedia( selector, wireElement, isReady, sdk ) {
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

		// One provider's wiring must never cost another's. This runs inside
		// forEach over every match on the page and, with runtime tracking on,
		// inside the shared MutationObserver callback across every registered
		// scanner - so an exception escaping here would abandon the remaining
		// embeds and the remaining providers for that pass, from one bad embed.
		// The tracker is responsible for its own cleanup (the Dailymotion one
		// restores the embed it replaced); this only stops the blast radius.
		try {
			wireElement( element, liveElement );
		} catch ( e ) {}

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

	const sdkSrc = 'string' === typeof sdk ? sdk : ( sdk && sdk.src ) || '';
	let sdkRequested = false;

	// Re-run the whole scan. Elements wired on an earlier pass carry the marker
	// and are skipped, so this is idempotent and safe to call from every
	// readiness signal an SDK offers.
	const rescan = function () {
		document.querySelectorAll( selector ).forEach( wireOnce );
	};

	// Fetch the provider SDK at most once, and only from a caller that has
	// already found a matching embed. That deferral is the point: a page with
	// no embed of this provider must cost zero bytes and zero requests to the
	// vendor — which also means no visitor IP, User-Agent or Referer reaches
	// them from a page that never had their player on it.
	const ensureSdk = function () {
		if ( '' === sdkSrc || sdkRequested ) {
			return;
		}

		// Two ways the site can refuse this request, checked HERE rather than at
		// the top of gtm4wpObserveMedia so a refusal costs the vendor request
		// and nothing else: players already on the page are still wired, and a
		// site that loads an SDK itself keeps working normally.
		//
		// 1. The gtm4wp_media_sdk_blocked filter, decided server-side.
		// 2. The gate script (js/frontend/gtm4wp-media-gate.js), which exists to
		//    be blockable: it is a real enqueued <script src>, so a consent
		//    manager can refuse it by rewriting or removing its tag, and a gate
		//    that never ran leaves gtm4wp_media_sdk_allowed unset. Blocking the
		//    tag, not wp_dequeue_script() - every tracker declares that handle as
		//    a dependency, so WordPress prints it whether or not it was dequeued.
		//
		// Both of these take deliberate configuration. Note that the check that
		// needs NONE is the one above this function: ensureSdk() is reached only
		// from `if ( present.length )` and the observer's `if ( matched || … )`,
		// and every SDK-fetching tracker selects on the embed's own vendor domain
		// - so a consent manager that blocks the EMBED (src -> data-src, or a
		// placeholder node) already withholds the vendor request here, with no
		// rule naming GTM4WP anywhere. Keep it that way: never widen a selector
		// to match a consent-blocked embed.
		//
		// The gate is only consulted when PHP said it enqueued one. That
		// distinction is what keeps "the gate was blocked" apart from "no gate
		// is in play at all" - a tracker loaded on its own, or a unit test -
		// which a bare `! allowed` check cannot tell apart, and getting it wrong
		// in that direction silently ends media tracking rather than a request.
		const gateExpected = !! window.gtm4wp_media_gate_expected;
		const gateOpen =
			! gateExpected || true === window.gtm4wp_media_sdk_allowed;

		if ( window.gtm4wp_media_sdk_blocked || ! gateOpen ) {
			sdkRequested = true;

			// Only the gate case is reported, and only where the site asked for
			// console output. The filter is a deliberate server-side decision
			// and needs no telling; a blocked gate is something that happened TO
			// the site, and it fails closed - so without a word here it looks
			// exactly like a player that simply never fires (RI-20). Guarded
			// with typeof because the flag is a top-level `const` the head block
			// may never have printed.
			if (
				! window.gtm4wp_media_sdk_blocked &&
				typeof gtm4wp_console_log !== 'undefined' &&
				gtm4wp_console_log &&
				window.console &&
				window.console.warn
			) {
				window.console.warn(
					'GTM4WP: a media player library was not requested because gtm4wp-media-gate.js did not run. ' +
						'That is expected if a consent manager or an optimization plugin blocked it. Players ' +
						'already on the page are still tracked; only the events that need this library are missing. ' +
						'Library: ' +
						sdkSrc
				);
			}

			return;
		}

		sdkRequested = true;

		// Already usable: the site loads this SDK itself, or a re-executed
		// bundle got here first. Nothing to fetch and nothing to wait for.
		if ( typeof isReady === 'function' && isReady() ) {
			return;
		}

		// A tag for this exact src may already be in flight — the site's own,
		// or one this function added before the bundle was re-executed. Attach
		// to that one instead of requesting the same script twice. Compared on
		// the literal attribute rather than the resolved .src property, because
		// these URLs are written as literals by the trackers (YouTube's is
		// protocol-relative, so the two forms never match as strings).
		let tag = null;
		const scripts = document.getElementsByTagName( 'script' );

		for ( let i = 0; i < scripts.length; i++ ) {
			if ( scripts[ i ].getAttribute( 'src' ) === sdkSrc ) {
				tag = scripts[ i ];
				break;
			}
		}

		if ( ! tag ) {
			tag = document.createElement( 'script' );
			tag.async = true;
			tag.src = sdkSrc;
			( document.head || document.documentElement ).appendChild( tag );
		}

		// `load` fires once the script has executed, which for an SDK that
		// hands its API to a global callback is still too early — YouTube sets
		// YT and only THEN calls onYouTubeIframeAPIReady. That is what the
		// `subscribe` form is for. Registering both means neither signal is
		// load-bearing on its own, and a rescan that arrives while isReady() is
		// still false costs nothing: it wires nothing and marks nothing.
		tag.addEventListener( 'load', rescan );
	};

	// Let a callback-style SDK drive the rescan. Registered before anything is
	// fetched, so the callback cannot fire before the tracker is listening.
	if ( sdk && 'function' === typeof sdk.subscribe ) {
		sdk.subscribe( rescan );
	}

	// Wire everything already present (this happens regardless of the opt-in),
	// and only then decide whether this page owes the vendor a request.
	const present = document.querySelectorAll( selector );

	present.forEach( wireOnce );

	if ( present.length ) {
		ensureSdk();
	}

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
	window.gtm4wp_media_scanners.push( { selector, wireOnce, ensureSdk } );

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
						let matched = false;

						if ( node.matches( scanner.selector ) ) {
							scanner.wireOnce( node );
							matched = true;
						}
						// The added node may be a wrapper (a lightbox/popup
						// container) holding the embed; querySelectorAll scans
						// only that added subtree, never the whole document.
						const inner = node.querySelectorAll( scanner.selector );

						inner.forEach( scanner.wireOnce );

						// First sighting of this provider on a page that loaded
						// without one (a player opened in a lightbox): the SDK
						// was deliberately not fetched at init, so fetch it now.
						// wireOnce above left these elements unmarked while
						// isReady() was false, and the SDK's load/ready signal
						// rescans the document and picks them up.
						if ( matched || inner.length ) {
							scanner.ensureSdk();
						}
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
