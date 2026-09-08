<?php
/**
 * Background queue of the Google Data Manager send lanes.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the work that must not happen inside the request that triggered it.
 *
 * A refund is issued by a human waiting on an admin screen, or by a payment
 * gateway callback that will retry if we make it slow. Neither may be made to
 * wait on a round trip to Google, so every send is queued and every retry is
 * queued again.
 *
 * Two backends, because the plugin serves two commerce platforms:
 *
 * - **Action Scheduler**, which both commerce platforms bundle - WooCommerce
 *   always, Easy Digital Downloads since 3.6.5 (U139). It is the better one -
 *   durable, visible in an admin screen, with its own retry and concurrency
 *   handling - so it is used whenever it is loaded.
 * - **WP-Cron**, everywhere else: a store on an older Easy Digital Downloads,
 *   or any site where the library is not loaded for a reason of its own.
 *
 * Which of the two is in front of a given store is therefore not something
 * this class may assume - it was assumed once, and the assumption was wrong
 * for every current EDD store (measured on an EDD-only site, 2026-09-08).
 * Both are reached only through the functions Action Scheduler documents as
 * its public API, each behind function_exists (UC-2), so a store that
 * deactivates its commerce plugin mid-flight falls back to WP-Cron rather
 * than fataling, and a store that gains the library starts using it.
 */
final class SendQueue {

	/**
	 * Hook of a queued refund send. The payload is a single associative array,
	 * because both backends pass the scheduled arguments through as a list and
	 * one array argument keeps the callback signature identical on both.
	 */
	public const HOOK_SEND = 'gtm4wp_gdm_send_refund';

	/**
	 * Hook of a queued send-status poll.
	 */
	public const HOOK_STATUS = 'gtm4wp_gdm_poll_status';

	/**
	 * Every hook this class schedules. Uninstall unschedules each one.
	 *
	 * @var string[]
	 */
	public const HOOKS = array( self::HOOK_SEND, self::HOOK_STATUS );

	/**
	 * Action Scheduler group of every action this plugin queues, so a site
	 * owner can tell them apart from WooCommerce's own in the Scheduled
	 * Actions screen.
	 */
	public const GROUP = 'gtm4wp';

	/**
	 * Delay before each retry, in seconds: one minute, five minutes, half an
	 * hour, two hours, twelve hours. A rate limit or a Google-side outage is
	 * usually over within the first two; the long tail exists so a send is not
	 * given up on during a night-long incident.
	 *
	 * The number of entries is also the number of retries: an attempt with no
	 * delay left is the last one.
	 *
	 * @var int[]
	 */
	public const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Delay before the first send attempt. Not zero: the platform hooks fire
	 * while the refund is being written, and a job that starts a second later
	 * reads a refund that is fully saved.
	 */
	public const INITIAL_DELAY = 60;

	/**
	 * Whether Action Scheduler is available in this request.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Queues one job.
	 *
	 * @param string               $hook    One of the HOOKS.
	 * @param array<string, mixed> $payload Job payload; scalars only, it is serialized into the store.
	 * @param int                  $delay   Seconds from now.
	 * @return bool Whether the job was queued.
	 */
	public static function schedule( string $hook, array $payload, int $delay = 0 ): bool {
		$when = time() + max( 0, $delay );

		return self::has_action_scheduler()
			? self::schedule_action( $hook, $payload, $when )
			: self::schedule_cron( $hook, $payload, $when );
	}

	/**
	 * Queues one job with Action Scheduler.
	 *
	 * Public, and named for what it does, because the choice between the two
	 * backends is made from function_exists() - which no test can make false
	 * again once a stub has defined the function process-wide. Each backend is
	 * therefore exercised directly, and has_action_scheduler() is asserted on
	 * its own as the discriminator between them.
	 *
	 * @param string               $hook    One of the HOOKS.
	 * @param array<string, mixed> $payload Job payload.
	 * @param int                  $when    Unix time to run at.
	 * @return bool Whether the job was queued.
	 */
	public static function schedule_action( string $hook, array $payload, int $when ): bool {
		// Returns the action id, or 0 when Action Scheduler refused it.
		return 0 !== (int) as_schedule_single_action( $when, $hook, array( $payload ), self::GROUP );
	}

	/**
	 * Queues one job with WP-Cron.
	 *
	 * WP-Cron refuses a duplicate of the same hook and arguments scheduled
	 * within ten minutes. Every payload carries its attempt number, so a retry
	 * is never a duplicate of the attempt that produced it.
	 *
	 * @param string               $hook    One of the HOOKS.
	 * @param array<string, mixed> $payload Job payload.
	 * @param int                  $when    Unix time to run at.
	 * @return bool Whether the job was queued.
	 */
	public static function schedule_cron( string $hook, array $payload, int $when ): bool {
		return true === wp_schedule_single_event( $when, $hook, array( $payload ) );
	}

	/**
	 * The delay before the retry that follows a given attempt, or null when
	 * the retries are used up.
	 *
	 * Attempts are one-based: attempt 1 is the first send, so it is followed
	 * by the first backoff step.
	 *
	 * @param int $attempt The attempt that just failed.
	 * @return int|null Seconds to wait, or null to give up.
	 */
	public static function retry_delay( int $attempt ): ?int {
		$index = $attempt - 1;

		if ( $index < 0 || $index >= count( self::BACKOFF ) ) {
			return null;
		}

		return self::BACKOFF[ $index ];
	}

	/**
	 * How many jobs of one hook are waiting.
	 *
	 * Read-only and best effort: it feeds a Site Health row, so a backend that
	 * cannot answer reports zero rather than raising anything.
	 *
	 * @param string $hook One of the HOOKS.
	 * @return int
	 */
	public static function pending( string $hook ): int {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			return self::pending_actions( $hook );
		}

		return self::pending_cron( $hook );
	}

	/**
	 * How many Action Scheduler actions of one hook are waiting.
	 *
	 * @param string $hook One of the HOOKS.
	 * @return int
	 */
	public static function pending_actions( string $hook ): int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				// The literal Action Scheduler stores, rather than its
				// ActionScheduler_Store::STATUS_PENDING constant: the function
				// is documented, the class is an internal.
				'status'   => 'pending',
				'per_page' => -1,
			),
			'ids'
		);

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	/**
	 * How many WP-Cron events of one hook are waiting.
	 *
	 * @param string $hook One of the HOOKS.
	 * @return int
	 */
	public static function pending_cron( string $hook ): int {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return 0;
		}

		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$pending = 0;

		foreach ( $cron as $events ) {
			if ( is_array( $events ) && isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
				$pending += count( $events[ $hook ] );
			}
		}

		return $pending;
	}

	/**
	 * Removes every queued job of this plugin. Called from uninstall, so a
	 * removed plugin does not leave a cron entry firing at a hook nothing
	 * listens on any more.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		foreach ( self::HOOKS as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}

			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}
}
