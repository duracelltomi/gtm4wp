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
 * Schedules the work that must not happen inside the request that triggered
 * it (an admin screen, or a gateway callback that retries if made slow). Two
 * backends: Action Scheduler when loaded (WooCommerce always, EDD since 3.6.5,
 * U139), WP-Cron otherwise. Never assume which one is in front of a store -
 * that assumption was wrong for every current EDD store. Both are reached
 * only through documented functions behind function_exists (UC-2).
 */
final class SendQueue {

	/**
	 * Hook of a queued refund send. The payload is one associative array, which
	 * keeps the callback signature identical on both backends.
	 */
	public const HOOK_SEND = 'gtm4wp_gdm_send_refund';

	/**
	 * Hook of a queued send-status poll.
	 */
	public const HOOK_STATUS = 'gtm4wp_gdm_poll_status';

	/**
	 * Every hook this class schedules; uninstall.php writes the names out
	 * (no autoloader there) and SendQueueTest pins its list against this one.
	 *
	 * @var string[]
	 */
	public const HOOKS = array( self::HOOK_SEND, self::HOOK_STATUS );

	/**
	 * Action Scheduler group, so the actions stand out on the Scheduled Actions screen.
	 */
	public const GROUP = 'gtm4wp';

	/**
	 * Delay before each retry, in seconds; the count is the number of retries.
	 * The long tail exists so a send survives a night-long incident.
	 *
	 * @var int[]
	 */
	public const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Delay before the first attempt. Not zero: the platform hooks fire while
	 * the refund is still being written.
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
	 * Queues one job with Action Scheduler. Public so each backend can be
	 * tested directly: a stub defines function_exists() process-wide, so the
	 * discriminator is asserted on its own.
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
	 * Queues one job with WP-Cron, which refuses a duplicate hook+arguments
	 * within ten minutes; every payload carries its attempt number, so a retry
	 * is never a duplicate.
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
	 * The delay before the retry that follows a given (one-based) attempt, or
	 * null when the retries are used up.
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
	 * How many jobs of one hook are waiting; best effort for a Site Health row,
	 * zero when the backend cannot answer.
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
				// The literal, not ActionScheduler_Store::STATUS_PENDING: the class is internal.
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
}
