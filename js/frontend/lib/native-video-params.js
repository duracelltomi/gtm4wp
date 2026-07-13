/**
 * Google Tag Manager built-in "Video *" variable support.
 *
 * GTM ships built-in Video variables (Video Status, Video URL, Video Title,
 * Video Provider, Video Duration, Video Current Time, Video Percent) that only
 * read the flat `gtm.video*` data layer keys emitted by GTM's own native
 * YouTube trigger. GTM4WP's media trackers push their own bespoke
 * `gtm4wp.media*` shape, so those variables never resolve.
 *
 * These helpers build the `gtm.video*` keys so each tracker can spread them
 * next to its existing parameters in the same data layer push, letting a Custom
 * Event trigger on the existing `gtm4wp.media*` event names resolve the built-in
 * variables. `gtm.videoVisible` is intentionally omitted: GTM4WP does not
 * measure viewport visibility, and emitting a guessed value would be worse than
 * leaving the variable undefined.
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
 * Builds the flat `gtm.video*` keys that populate GTM's built-in Video
 * variables, ready to be spread into a data layer push.
 *
 * `currentTime` and `duration` are expected in seconds; callers whose player
 * API reports milliseconds (SoundCloud) must convert before calling. `percent`
 * is derived from time / duration when not supplied.
 *
 * @param {Object} args
 * @param {string} args.provider    Video provider, e.g. 'youtube', 'vimeo'.
 * @param {string} args.status      Already-mapped `gtm.videoStatus` (may be '').
 * @param {string} args.url         Video URL.
 * @param {string} args.title       Video title.
 * @param {number} args.currentTime Current playback position, in seconds.
 * @param {number} args.duration    Total duration, in seconds.
 * @param {number} [args.percent]   Integer 0-100; computed from time/duration
 *                                  when omitted.
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
} ) {
	const dur = Number( duration ) || 0;
	const cur = Number( currentTime ) || 0;

	let pct = 0;
	if ( typeof percent === 'number' ) {
		pct = percent;
	} else if ( dur > 0 ) {
		pct = Math.floor( ( cur / dur ) * 100 );
	}

	return {
		'gtm.videoProvider': provider,
		'gtm.videoUrl': url,
		'gtm.videoTitle': title,
		'gtm.videoStatus': status,
		'gtm.videoCurrentTime': Math.floor( cur ),
		'gtm.videoDuration': Math.floor( dur ),
		'gtm.videoPercent': pct,
	};
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
