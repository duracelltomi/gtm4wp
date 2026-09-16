<?php
/**
 * Per-destination health records of the Data Manager integration.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers how each configured destination has been doing: last successful
 * send, last error, and how many sends in a row have failed. The records are
 * written by the send paths and read by the admin notice (HealthNotice) and,
 * in a later phase, the Site Health surfaces - never by a live HTTP call on
 * page load.
 *
 * Records are keyed by the destination's measurement id: it is the one cell
 * the save-time sanitizer keeps unique across rows, and it is a value already
 * public in the site's own HTML, so keying on it stores nothing new. A record
 * whose measurement id no longer matches a configured destination is simply
 * never shown; the readers join against the configured rows.
 *
 * Only error summaries are stored - a short, sanitized reason. No tokens, no
 * key material, no raw API response bodies (an error body can echo request
 * fragments).
 *
 * Each failure also records a **reason class** next to the summary: a bare
 * code name such as PERMISSION_DENIED. The two are not redundant. The summary
 * is what an admin reads on the settings screen, and it can quote Google's
 * own sentence about our request; the class is what may be shown where that
 * sentence must not go, which is Site Health's debug section.
 */
final class DestinationHealth {

	/**
	 * Option row holding every record. Non-autoloaded: it is read on admin
	 * pageviews and at send time, never on a frontend pageview. Deleted by
	 * uninstall.php.
	 */
	public const OPTION_NAME = 'gtm4wp_gdm_destination_health';

	/**
	 * Consecutive failures after which a destination counts as failing and the
	 * admin notice names it. One failure is a blip Google's own guidance says
	 * to retry through; three in a row is a configuration problem (revoked
	 * grant, deleted property, broken key) that will not fix itself.
	 */
	public const FAILURE_THRESHOLD = 3;

	/**
	 * Longest stored error summary. The text is shown on the settings screen
	 * and in the admin notice; the cap keeps a verbose upstream error from
	 * bloating the option row.
	 */
	private const ERROR_MAX_LENGTH = 200;

	/**
	 * Longest stored reason class. A google.rpc.Code name is well inside this.
	 */
	private const ERROR_CLASS_MAX_LENGTH = 40;

	/**
	 * Returns the current Unix time. Injectable so tests can pin the stored
	 * timestamps exactly; mirrors the KeyVault seam.
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current Unix time; null uses time().
	 *                             Injected by tests.
	 */
	public function __construct( $clock = null ) {
		$this->clock = $clock;
	}

	/**
	 * Records a successful send to a destination, clearing any failure streak.
	 *
	 * @param string $measurement_id The destination's measurement id.
	 * @return void
	 */
	public function record_success( string $measurement_id ): void {
		if ( '' === $measurement_id ) {
			return;
		}

		$records = $this->read();

		$records[ $measurement_id ] = array(
			'last_success'         => $this->now(),
			'last_failure'         => (int) ( $records[ $measurement_id ]['last_failure'] ?? 0 ),
			'consecutive_failures' => 0,
			'last_error'           => '',
			'last_error_class'     => '',
		);

		$this->write( $records );
	}

	/**
	 * Ends a destination's failure streak without claiming a send succeeded.
	 *
	 * For the Test button: a validation probe that passes after the admin has
	 * corrected the destination exercises the same chain a send does - the
	 * key, the API being enabled, the account's access to the property - and
	 * is the moment the site-wide notice should stop. It is not a send, so
	 * last_success stays what it was; if the next real send fails, the streak
	 * starts counting again from there.
	 *
	 * @param string $measurement_id The destination's measurement id.
	 * @return void
	 */
	public function clear_failures( string $measurement_id ): void {
		if ( '' === $measurement_id ) {
			return;
		}

		$records = $this->read();

		if ( ! isset( $records[ $measurement_id ] ) ) {
			return;
		}

		$records[ $measurement_id ]['consecutive_failures'] = 0;
		$records[ $measurement_id ]['last_error']           = '';
		$records[ $measurement_id ]['last_error_class']     = '';

		$this->write( $records );
	}

	/**
	 * Records a failed send to a destination.
	 *
	 * @param string $measurement_id The destination's measurement id.
	 * @param string $message        Short error summary; sanitized and capped here.
	 * @param string $reason_class   Bare code name of the failure, safe to show in Site Health.
	 * @return void
	 */
	public function record_failure( string $measurement_id, string $message, string $reason_class = '' ): void {
		if ( '' === $measurement_id ) {
			return;
		}

		$records = $this->read();
		$record  = $records[ $measurement_id ] ?? array();

		$records[ $measurement_id ] = array(
			'last_success'         => (int) ( $record['last_success'] ?? 0 ),
			'last_failure'         => $this->now(),
			'consecutive_failures' => (int) ( $record['consecutive_failures'] ?? 0 ) + 1,
			'last_error'           => mb_substr( sanitize_text_field( $message ), 0, self::ERROR_MAX_LENGTH ),
			'last_error_class'     => mb_substr( sanitize_text_field( $reason_class ), 0, self::ERROR_CLASS_MAX_LENGTH ),
		);

		$this->write( $records );
	}

	/**
	 * One destination's record, or null when none exists yet.
	 *
	 * @param string $measurement_id The destination's measurement id.
	 * @return array{last_success: int, last_failure: int, consecutive_failures: int, last_error: string, last_error_class: string}|null
	 */
	public function get( string $measurement_id ): ?array {
		$records = $this->read();

		return isset( $records[ $measurement_id ] ) ? $this->clean_record( $records[ $measurement_id ] ) : null;
	}

	/**
	 * Every record, keyed by measurement id. Callers join against the
	 * configured destination rows; a stale record is theirs to skip.
	 *
	 * @return array<string, array{last_success: int, last_failure: int, consecutive_failures: int, last_error: string, last_error_class: string}>
	 */
	public function all(): array {
		$records = array();

		foreach ( $this->read() as $measurement_id => $record ) {
			$records[ (string) $measurement_id ] = $this->clean_record( $record );
		}

		return $records;
	}

	/**
	 * Whether a destination's failure streak has crossed the threshold.
	 *
	 * @param string $measurement_id The destination's measurement id.
	 * @return bool
	 */
	public function is_failing( string $measurement_id ): bool {
		$record = $this->get( $measurement_id );

		return null !== $record && ( $record['consecutive_failures'] >= self::FAILURE_THRESHOLD );
	}

	/**
	 * The current Unix time through the injectable clock.
	 *
	 * @return int
	 */
	private function now(): int {
		return is_callable( $this->clock ) ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * A stored record coerced to the documented shape, tolerating a row
	 * written before a field existed (or damaged at the DB level).
	 *
	 * @param mixed $record Stored record.
	 * @return array{last_success: int, last_failure: int, consecutive_failures: int, last_error: string, last_error_class: string}
	 */
	private function clean_record( $record ): array {
		$record = is_array( $record ) ? $record : array();

		return array(
			'last_success'         => (int) ( $record['last_success'] ?? 0 ),
			'last_failure'         => (int) ( $record['last_failure'] ?? 0 ),
			'consecutive_failures' => (int) ( $record['consecutive_failures'] ?? 0 ),
			'last_error'           => is_string( $record['last_error'] ?? null ) ? $record['last_error'] : '',
			'last_error_class'     => is_string( $record['last_error_class'] ?? null ) ? $record['last_error_class'] : '',
		);
	}

	/**
	 * Reads the option row.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Writes the option row, non-autoloaded (the KeyVault pattern: the
	 * autoload flag only applies when the row is created, so the first write
	 * goes through add_option()).
	 *
	 * @param array<string, array<string, mixed>> $records Every record.
	 * @return void
	 */
	private function write( array $records ): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $records, '', false );
			return;
		}

		update_option( self::OPTION_NAME, $records, false );
	}
}
