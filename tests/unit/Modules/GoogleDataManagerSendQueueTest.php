<?php
/**
 * Unit tests for the background send queue and its retry ladder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\SendQueue;
use GTM4WP\Tests\unit\TestCase;

/**
 * The queue has two backends and the plugin has to work on both: WooCommerce
 * bundles Action Scheduler, Easy Digital Downloads does not, so on an EDD-only
 * store the WP-Cron path is the only one there is. Every test here therefore
 * runs both ways rather than assuming the better backend is present.
 *
 * The Action Scheduler functions are stubbed rather than the class: the
 * `as_*` functions are its documented API, the classes behind them are not,
 * which is also why the production code reaches them through function_exists.
 */
final class GoogleDataManagerSendQueueTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * Every as_* / wp_schedule_single_event call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();

		$this->scheduled = array();
	}

	/**
	 * Installs the WP-Cron backend: no Action Scheduler in sight.
	 *
	 * @return void
	 */
	private function stub_wp_cron(): void {
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->scheduled[] = array(
					'backend'   => 'wp-cron',
					'timestamp' => $timestamp,
					'hook'      => $hook,
					'args'      => $args,
				);

				return true;
			}
		);
	}

	/**
	 * Installs the Action Scheduler backend.
	 *
	 * @param int $action_id The id as_schedule_single_action() returns; 0 means refused.
	 * @return void
	 */
	private function stub_action_scheduler( int $action_id = 4242 ): void {
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = array(), $group = '' ) use ( $action_id ) {
				$this->scheduled[] = array(
					'backend'   => 'action-scheduler',
					'timestamp' => $timestamp,
					'hook'      => $hook,
					'args'      => $args,
					'group'     => $group,
				);

				return $action_id;
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
	}

	// ---- Backend selection -------------------------------------------------

	/**
	 * The dispatcher's own rule, asserted where it can be: the Action Scheduler
	 * functions really are what decides. Its two branches are exercised
	 * separately below, because a stub that defines an as_* function defines it
	 * for the whole PHP process - so no test can make the "absent" side true
	 * again once any test has made it false (TS-16).
	 */
	public function test_the_action_scheduler_functions_are_the_discriminator(): void {
		$this->assertSame(
			function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ),
			SendQueue::has_action_scheduler()
		);
	}

	public function test_action_scheduler_is_preferred_when_it_is_loaded(): void {
		$this->stub_action_scheduler();
		$this->stub_wp_cron();

		$this->assertTrue( SendQueue::has_action_scheduler(), 'Both functions are stubbed, so the dispatcher sees the better backend.' );
		$this->assertTrue( SendQueue::schedule( SendQueue::HOOK_SEND, array( 'refund_id' => 7 ), 60 ) );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'action-scheduler', $this->scheduled[0]['backend'], 'WP-Cron is stubbed too, so this fails if the dispatcher picks the wrong one.' );
		$this->assertSame( SendQueue::GROUP, $this->scheduled[0]['group'], 'The plugin\'s actions are grouped so a store owner can tell them apart from WooCommerce\'s own.' );
	}

	public function test_the_wp_cron_backend_queues_the_job_where_action_scheduler_is_absent(): void {
		$this->stub_wp_cron();

		$this->assertTrue( SendQueue::schedule_cron( SendQueue::HOOK_SEND, array( 'refund_id' => 7 ), self::NOW ) );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'wp-cron', $this->scheduled[0]['backend'] );
		$this->assertSame( self::NOW, $this->scheduled[0]['timestamp'] );
		$this->assertSame( SendQueue::HOOK_SEND, $this->scheduled[0]['hook'] );
	}

	public function test_both_backends_receive_the_payload_as_a_single_array_argument(): void {
		$payload = array(
			'platform'  => 'woocommerce',
			'order_id'  => 12,
			'refund_id' => 34,
			'attempt'   => 1,
		);

		$this->stub_wp_cron();
		$this->stub_action_scheduler();

		SendQueue::schedule_cron( SendQueue::HOOK_SEND, $payload, self::NOW );
		SendQueue::schedule_action( SendQueue::HOOK_SEND, $payload, self::NOW );

		$this->assertSame( array( $payload ), $this->scheduled[0]['args'], 'WP-Cron gets the payload as the single argument the callback takes.' );
		$this->assertSame( array( $payload ), $this->scheduled[1]['args'], 'Action Scheduler gets exactly the same, so one callback signature serves both.' );
	}

	public function test_the_delay_is_added_to_the_current_time(): void {
		$this->stub_wp_cron();
		$this->stub_action_scheduler();

		// time() is a PHP internal and cannot be stubbed here, so the window it
		// could have returned in is bracketed instead of pinned.
		$before = time();
		SendQueue::schedule( SendQueue::HOOK_SEND, array(), 300 );
		$after = time();

		$this->assertGreaterThanOrEqual( $before + 300, $this->scheduled[0]['timestamp'] );
		$this->assertLessThanOrEqual( $after + 300, $this->scheduled[0]['timestamp'] );
	}

	public function test_a_negative_delay_never_schedules_in_the_past(): void {
		$this->stub_wp_cron();
		$this->stub_action_scheduler();

		$before = time();
		SendQueue::schedule( SendQueue::HOOK_SEND, array(), -5000 );

		$this->assertGreaterThanOrEqual( $before, $this->scheduled[0]['timestamp'] );
	}

	public function test_a_refused_action_scheduler_job_is_reported_as_not_queued(): void {
		$this->stub_action_scheduler( 0 );

		$this->assertFalse(
			SendQueue::schedule_action( SendQueue::HOOK_SEND, array(), self::NOW ),
			'Action Scheduler returns 0 when it refuses; a caller must be able to tell that from a queued job.'
		);
	}

	public function test_a_refused_wp_cron_job_is_reported_as_not_queued(): void {
		Functions\when( 'wp_schedule_single_event' )->justReturn( false );

		$this->assertFalse( SendQueue::schedule_cron( SendQueue::HOOK_SEND, array(), self::NOW ) );
	}

	// ---- The retry ladder --------------------------------------------------

	public function test_the_backoff_ladder_is_one_minute_to_twelve_hours(): void {
		$this->assertSame( array( 60, 300, 1800, 7200, 43200 ), SendQueue::BACKOFF );
	}

	public function test_each_attempt_is_followed_by_the_next_step_of_the_ladder(): void {
		$this->assertSame( 60, SendQueue::retry_delay( 1 ) );
		$this->assertSame( 300, SendQueue::retry_delay( 2 ) );
		$this->assertSame( 1800, SendQueue::retry_delay( 3 ) );
		$this->assertSame( 7200, SendQueue::retry_delay( 4 ) );
		$this->assertSame( 43200, SendQueue::retry_delay( 5 ) );
	}

	public function test_the_retries_run_out_after_the_last_step(): void {
		$this->assertNull(
			SendQueue::retry_delay( 6 ),
			'Six attempts is the end of it: a failure that survives twenty hours of retries is a configuration problem, not a blip.'
		);
		$this->assertNull( SendQueue::retry_delay( 99 ) );
	}

	public function test_an_impossible_attempt_number_does_not_restart_the_ladder(): void {
		$this->assertNull( SendQueue::retry_delay( 0 ) );
		$this->assertNull( SendQueue::retry_delay( -1 ) );
	}

	public function test_the_first_send_waits_so_the_refund_is_saved_before_it_is_read(): void {
		$this->assertSame( 60, SendQueue::INITIAL_DELAY );
	}

	// ---- Queue depth -------------------------------------------------------

	public function test_queue_depth_comes_from_action_scheduler_when_it_is_there(): void {
		$captured = array();
		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( $args, $return_format ) use ( &$captured ) {
				$captured = array( $args, $return_format );

				return array( 11, 12, 13 );
			}
		);

		$this->assertSame( 3, SendQueue::pending_actions( SendQueue::HOOK_SEND ) );
		$this->assertSame( SendQueue::HOOK_SEND, $captured[0]['hook'] );
		$this->assertSame( 'pending', $captured[0]['status'], 'Only jobs still waiting are counted - a completed one is not queue depth.' );
		$this->assertSame( -1, $captured[0]['per_page'] );
		$this->assertSame( 'ids', $captured[1] );
	}

	public function test_queue_depth_counts_the_cron_array_otherwise(): void {
		Functions\when( '_get_cron_array' )->justReturn(
			array(
				self::NOW      => array(
					SendQueue::HOOK_SEND   => array(
						'a' => array(),
						'b' => array(),
					),
					SendQueue::HOOK_STATUS => array( 'c' => array() ),
				),
				self::NOW + 60 => array(
					SendQueue::HOOK_SEND => array( 'd' => array() ),
				),
			)
		);

		$this->assertSame( 3, SendQueue::pending_cron( SendQueue::HOOK_SEND ), 'Events of one hook are counted across every scheduled minute.' );
		$this->assertSame( 1, SendQueue::pending_cron( SendQueue::HOOK_STATUS ) );
	}

	public function test_an_unanswerable_queue_depth_reports_zero_rather_than_raising(): void {
		Functions\when( '_get_cron_array' )->justReturn( false );

		$this->assertSame( 0, SendQueue::pending_cron( SendQueue::HOOK_SEND ), 'The depth feeds a Site Health row; it must never be the reason that page fails.' );

		Functions\when( 'as_get_scheduled_actions' )->justReturn( null );

		$this->assertSame( 0, SendQueue::pending_actions( SendQueue::HOOK_SEND ) );
	}

	// ---- Cleanup -----------------------------------------------------------

	public function test_uninstall_unschedules_every_hook_on_both_backends(): void {
		$unscheduled = array();

		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( $hook, $args, $group ) use ( &$unscheduled ) {
				$unscheduled[] = 'as:' . $hook . ':' . $group;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $hook ) use ( &$unscheduled ) {
				$unscheduled[] = 'cron:' . $hook;

				return 0;
			}
		);

		SendQueue::unschedule_all();

		$this->assertSame(
			array(
				'as:gtm4wp_gdm_send_refund:gtm4wp',
				'cron:gtm4wp_gdm_send_refund',
				'as:gtm4wp_gdm_poll_status:gtm4wp',
				'cron:gtm4wp_gdm_poll_status',
			),
			$unscheduled
		);
	}

	/**
	 * The uninstaller cannot read these constants - it runs without the
	 * autoloader - so it writes the names out. This is the assertion that keeps
	 * its copy and this one identical; UninstallTest asserts the same pair from
	 * the other side.
	 */
	public function test_the_hook_names_are_the_ones_the_uninstaller_writes_out(): void {
		$this->assertSame(
			array( 'gtm4wp_gdm_send_refund', 'gtm4wp_gdm_poll_status' ),
			SendQueue::HOOKS
		);
		$this->assertSame( 'gtm4wp_gdm_send_refund', SendQueue::HOOK_SEND );
		$this->assertSame( 'gtm4wp_gdm_poll_status', SendQueue::HOOK_STATUS );
		$this->assertSame( 'gtm4wp', SendQueue::GROUP );
	}
}
