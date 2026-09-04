<?php
/**
 * Capture-rate counters of the attribution capture.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Counts how many orders capture saw and on how many of them it actually
 * resolved a client id.
 *
 * This exists because the feature's characteristic failure is silent. Capture
 * needs a Google Analytics tag that really fires for the configured
 * measurement ID; when the ID is mistyped, or the tag was removed from the
 * container, or consent is denied site-wide, the lookups are simply never
 * answered - no error anywhere, and the first symptom would be a refund that
 * cannot be sent weeks later. Two counters turn that into something the
 * settings screen and Site Health can state plainly ("attribution captured on
 * 0 of the last N orders").
 *
 * Deliberately counters and timestamps only: no order references, nothing
 * per-visitor. The record answers "is this working", not "what happened to
 * order 1234".
 */
final class CaptureStats {

	/**
	 * Option holding the counters. Its own row, not autoloaded: it is written
	 * at order creation and read on the settings screen, never on a pageview.
	 */
	public const OPTION_NAME = 'gtm4wp_gdm_capture_stats';

	/**
	 * How many recent orders the rate is measured over. The counters halve
	 * when they reach it, so the record keeps reflecting the recent past
	 * instead of averaging over the store's whole history - a store that fixed
	 * its container should see the number recover.
	 */
	public const WINDOW = 100;

	/**
	 * Clock, injectable for tests.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Builds the counter store.
	 *
	 * @param callable|null $clock Returns the current unix timestamp.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock;
	}

	/**
	 * Current timestamp.
	 *
	 * @return int
	 */
	private function now(): int {
		return null !== $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Records one order.
	 *
	 * @param bool $captured Whether attribution usable for a send was stored.
	 * @return void
	 */
	public function record( bool $captured ): void {
		$record = $this->get();
		$now    = $this->now();

		++$record['seen'];
		$record['last_seen_at'] = $now;

		if ( $captured ) {
			++$record['captured'];
			$record['last_captured_at'] = $now;
		}

		if ( $record['seen'] >= self::WINDOW ) {
			$record['seen']     = (int) floor( $record['seen'] / 2 );
			$record['captured'] = (int) floor( $record['captured'] / 2 );
		}

		$this->save( $record );
	}

	/**
	 * The stored counters, with every member present.
	 *
	 * @return array{seen: int, captured: int, last_seen_at: int, last_captured_at: int}
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'seen'             => isset( $stored['seen'] ) ? max( 0, (int) $stored['seen'] ) : 0,
			'captured'         => isset( $stored['captured'] ) ? max( 0, (int) $stored['captured'] ) : 0,
			'last_seen_at'     => isset( $stored['last_seen_at'] ) ? max( 0, (int) $stored['last_seen_at'] ) : 0,
			'last_captured_at' => isset( $stored['last_captured_at'] ) ? max( 0, (int) $stored['last_captured_at'] ) : 0,
		);
	}

	/**
	 * Whether the record shows orders flowing with nothing being captured -
	 * the state worth telling the site owner about.
	 *
	 * @return bool
	 */
	public function is_failing(): bool {
		$record = $this->get();

		return $record['seen'] > 0 && 0 === $record['captured'];
	}

	/**
	 * Stores the counters, creating the row non-autoloaded on first write.
	 *
	 * @param array<string, int> $record The counters.
	 * @return void
	 */
	private function save( array $record ): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $record, '', false );

			return;
		}

		update_option( self::OPTION_NAME, $record, false );
	}
}
