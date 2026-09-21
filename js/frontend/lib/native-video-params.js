/**
 * Google Tag Manager built-in "Video *" variable support: the flat `gtm.video*`
 * data layer keys GTM's native YouTube trigger emits, spread by every media
 * tracker next to its own `gtm4wp.media*` parameters so the built-in variables
 * resolve on our events too.
 *
 * EVERY push with a player to describe carries them (not only the two events
 * with a native counterpart): the data layer is merged state, so an omitted
 * key keeps the PREVIOUS push's value and reads as data rather than a gap. The
 * one exception is `gtm4wp.mediaApiReady`, which has no player. An event with
 * no native status passes `status: ''`, empty rather than absent, for the same
 * merge reason.
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
 * Reduces a media URL to its bare form: absolute, query string AND fragment
 * removed. The fragment half is not an edge case: WordPress core's
 * `wp_filter_oembed_result()` appends `#?secret=…` to the iframe src of every
 * untrusted oEmbed provider, so cutting only at `?` left every id parsed from
 * the path carrying a `#` (U106).
 *
 * @param {string} url The media URL, e.g. an iframe `src` or a media element's
 *                     `currentSrc`.
 * @return {string} The URL without query or fragment, or '' when there is
 *                  nothing to read.
 */
export function gtm4wpMediaBareUrl( url ) {
	const src = url || '';

	// new URL( '', href ) resolves to the PAGE, so '' must short-circuit.
	if ( '' === src ) {
		return '';
	}

	try {
		const parsed = new URL( src, window.location.href );

		return parsed.origin + parsed.pathname;
	} catch ( e ) {
		// Must not throw inside a tracker's wiring: cut by hand, '#' first so a
		// '?' inside the fragment is not mistaken for a query.
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
 * Whether a media player sits inside the viewport, for GTM's built-in "Video
 * Visible" variable. GTM publishes no threshold (U102), so this measures at
 * the moment of the push: the page must be on screen at all (a video keeps
 * playing in a background tab, so its milestones keep firing), and the
 * player's box must overlap the viewport without being hidden by CSS; a
 * player scrolled halfway out counts as visible. A covered window and a
 * Picture-in-Picture pop-out are not detectable. Synchronous
 * getBoundingClientRect, not a stored IntersectionObserver ratio, so a scroll
 * cannot invalidate it; media pushes are infrequent enough.
 *
 * @param {HTMLElement|Function} [target] The player element, or a function
 *                                        returning it (an SDK that swaps the
 *                                        element, or VideoPress).
 * @return {boolean|undefined} Whether the player is in the viewport, or
 *                             undefined when there is nothing to measure (the
 *                             caller then omits the key).
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

	// Background tab / minimised window: nothing on it is visible, whatever the
	// geometry says.
	if ( 'hidden' === view.document.visibilityState ) {
		return false;
	}

	// `visibility` is inherited (catches a hidden ancestor); a `display: none`
	// ancestor collapses the box to 0×0, caught by the rect below.
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
 * Builds the flat `gtm.video*` keys of GTM's built-in Video variables, ready
 * to spread into a data layer push. Times are in seconds (SoundCloud reports
 * milliseconds: convert first); `percent` is derived when not supplied.
 *
 * @param {Object}               args
 * @param {string}               args.provider    Video provider, e.g. 'youtube'.
 * @param {string}               args.status      Already-mapped `gtm.videoStatus` (may be '').
 * @param {string}               args.url         Video URL.
 * @param {string}               args.title       Video title.
 * @param {number}               args.currentTime Playback position, seconds.
 * @param {number}               args.duration    Total duration, seconds.
 * @param {number}               [args.percent]   Integer 0-100; computed when omitted.
 * @param {HTMLElement|Function} [args.element]   The player element (or a function returning
 *                                                it) for `gtm.videoVisible`; omitting it
 *                                                omits only that key.
 * @return {Object} The `gtm.video*` keys.
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

	// Omitted when there is nothing to measure rather than guessed false.
	const visible = gtm4wpMediaVisible( element );
	if ( typeof visible === 'boolean' ) {
		params[ 'gtm.videoVisible' ] = visible;
	}

	return params;
}

/**
 * Fires each `step`-sized mark (0, 10, 20, …) the current `percentage` has
 * newly crossed, once per media item: the shared milestone bookkeeping of
 * every media tracker.
 *
 * Callers must guard a zero/absent duration before computing `percentage`:
 * `time / 0` is `Infinity` and would fire every mark.
 *
 * `key` is a provider-reported media id, so it may be `__proto__`: the fired
 * list is resolved locally and Array-checked, never trusted from the store
 * (callers should still declare the store with `Object.create( null )`).
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
 * Runs a tracker's init once the DOM is ready: immediately when parsing is
 * already done (defer, or late injection by a tag manager), otherwise on
 * DOMContentLoaded.
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
 * Wires every element matching `selector`; players already in the DOM are
 * always wired. Watching for later insertions (popup/lightbox, AJAX) is opt-in
 * via `window.gtm4wp_media_observe_dynamic` (the "track dynamically inserted
 * players" setting) because a body-wide MutationObserver has a per-mutation
 * cost. All providers share ONE observer: each tracker registers a (selector,
 * wire) scanner, and the callback inspects only the nodes each mutation adds.
 *
 * A wired element carries a data-attribute marker so a re-report or a moved
 * node is never bound twice; a marked ancestor is skipped too (the Twitch
 * container whose SDK-injected iframe also matches). An SDK that REPLACES the
 * element it is handed (Spotify) takes the marker with it, and the observer
 * would wire the replacement, replacing it again forever, so wireOnce re-marks
 * whatever takes the element's slot. Invariant when adding a provider: never
 * assume the element handed to wireElement survives the call.
 *
 * @param {string}        selector    CSS selector identifying the provider embed.
 * @param {Function}      wireElement Called once per matching element with a second
 *                                    argument resolving the element currently in
 *                                    the wired element's slot (for a replacing SDK).
 * @param {Function}      [isReady]   When falsy the element is left unwired AND
 *                                    unmarked (SDK not loaded yet), so a later
 *                                    rescan can still wire it.
 * @param {string|Object} [sdk]       Provider SDK to load ONLY once the page is known
 *                                    to contain a matching embed: a script URL (ready
 *                                    on its load event), or `{ src, subscribe }` for
 *                                    an SDK signalling readiness through a global
 *                                    callback (onYouTubeIframeAPIReady,
 *                                    onSpotifyIframeApiReady): `subscribe` receives a
 *                                    rescan function to call from there. Omit when
 *                                    there is nothing to fetch (HTML5, Wistia, JW
 *                                    Player, VideoPress).
 * @return {MutationObserver|null} The shared observer, or null when runtime
 *                                 tracking is not enabled.
 */
export function gtm4wpObserveMedia( selector, wireElement, isReady, sdk ) {
	const wireOnce = function ( element ) {
		// Already wired, or inside a marked container (Twitch).
		if ( element.closest( '[data-gtm4wp-media-wired]' ) ) {
			return;
		}
		// SDK not ready: leave unmarked so a later rescan can wire it.
		if ( typeof isReady === 'function' && ! isReady() ) {
			return;
		}

		// Remember the slot BEFORE wiring: a replacing SDK takes the marker with
		// the node (see the re-mark below).
		const parent = element.parentNode;
		const slot = parent
			? Array.prototype.indexOf.call( parent.childNodes, element )
			: -1;

		// The element currently in this slot (the wired one or its SDK
		// replacement), so `gtm.videoVisible` is measured on the node actually
		// on screen; a detached original would report 0×0 forever.
		const liveElement = function () {
			if ( element.isConnected ) {
				return element;
			}

			if ( parent && slot > -1 ) {
				const node = parent.childNodes[ slot ];

				// Same test as the re-mark: only a node this scanner would
				// recognise counts, not one that merely shifted into the slot.
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

		// One bad embed must not abandon the remaining embeds and providers of
		// this pass (forEach over every match, and the shared observer callback
		// across every scanner). Cleanup is the tracker's job (Dailymotion
		// restores the embed it replaced).
		try {
			wireElement( element, liveElement );
		} catch ( e ) {}

		// A replacing SDK (Spotify's createController() does replaceChild plus a
		// synchronous src assignment) leaves an unmarked node matching the same
		// selector; the observer would wire it again, an unbounded loop that
		// hangs the tab. replaceChild keeps the slot, so re-mark what occupies
		// it, but only a node THIS scanner would re-wire: marking an unrelated
		// node that shifted into the slot would hide a real embed.
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

	// Idempotent (marked elements are skipped), so safe from every readiness
	// signal an SDK offers.
	const rescan = function () {
		document.querySelectorAll( selector ).forEach( wireOnce );
	};

	// Fetch the provider SDK at most once, and only once a matching embed was
	// found: a page without this provider's player must send the vendor no
	// request at all (no visitor IP, User-Agent or Referer).
	const ensureSdk = function () {
		if ( '' === sdkSrc || sdkRequested ) {
			return;
		}

		// Two ways the site can refuse the request, checked HERE so a refusal
		// costs only the vendor request (present players stay wired, a site
		// loading the SDK itself keeps working):
		// 1. the gtm4wp_media_sdk_blocked filter, decided server-side;
		// 2. the gate script (gtm4wp-media-gate.js), a real enqueued <script
		//    src> a consent manager can block by rewriting/removing its tag;
		//    a gate that never ran leaves gtm4wp_media_sdk_allowed unset. (Not
		//    wp_dequeue_script(): every tracker depends on that handle, so WP
		//    prints it regardless.)
		// The check needing NO configuration is the caller: every SDK-fetching
		// tracker selects on the embed's own vendor domain, so a consent-blocked
		// embed (src -> data-src, placeholder) already withholds the request.
		// Never widen a selector to match a consent-blocked embed.
		//
		// The gate is consulted only when PHP said it enqueued one: a bare
		// `! allowed` could not tell "gate blocked" from "no gate in play"
		// (tracker loaded on its own, unit test) and would silently end media
		// tracking.
		const gateExpected = !! window.gtm4wp_media_gate_expected;
		const gateOpen =
			! gateExpected || true === window.gtm4wp_media_sdk_allowed;

		if ( window.gtm4wp_media_sdk_blocked || ! gateOpen ) {
			sdkRequested = true;

			// Only the gate case is reported (the filter is a deliberate
			// server-side decision), and only with console output enabled: a
			// blocked gate fails closed and would otherwise look like a player
			// that never fires (RI-20). typeof: the flag is a top-level `const`
			// the head block may never have printed.
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

		// Already usable (site loads the SDK itself, or a re-executed bundle).
		if ( typeof isReady === 'function' && isReady() ) {
			return;
		}

		// Attach to a tag for this src already in flight (the site's own, or
		// ours before a bundle re-execution). Compared on the literal attribute,
		// not the resolved .src: YouTube's URL is protocol-relative.
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

		// `load` is too early for an SDK that hands its API to a global
		// callback (YouTube sets YT and only THEN calls onYouTubeIframeAPIReady;
		// that is what `subscribe` is for). Both are registered; a rescan while
		// isReady() is still false wires and marks nothing.
		tag.addEventListener( 'load', rescan );
	};

	// Registered before anything is fetched, so the SDK callback cannot fire
	// before the tracker listens.
	if ( sdk && 'function' === typeof sdk.subscribe ) {
		sdk.subscribe( rescan );
	}

	// Wire everything already present (regardless of the opt-in), then decide
	// whether this page owes the vendor a request.
	const present = document.querySelectorAll( selector );

	present.forEach( wireOnce );

	if ( present.length ) {
		ensureSdk();
	}

	// Runtime tracking of later-inserted players is opt-in.
	if ( ! window.gtm4wp_media_observe_dynamic ) {
		return null;
	}

	// Register this provider's scanner on the shared observer; a re-executed
	// bundle replaces its own scanner rather than stacking a duplicate.
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
						// The added node may be a wrapper holding the embed; only
						// that subtree is scanned.
						const inner = node.querySelectorAll( scanner.selector );

						inner.forEach( scanner.wireOnce );

						// First sighting of this provider (a lightbox player):
						// fetch the SDK now; its ready signal rescans and picks
						// up the elements wireOnce left unmarked.
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
