<?php
/**
 * Queues failed or fixable refunds again.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The one definition of "send these refunds again": the replay route of the
 * destinations panel and the gtm4wp/replay-google-data-manager-refunds
 * ability are thin adapters over this class (UC-6), so what the button does
 * and what an assistant does cannot drift apart.
 *
 * Nothing is sent from the calling request. Every refund the log's replay
 * plan names is queued as a fresh first attempt aimed at the destinations
 * still missing it, and the sender applies every gate again when the job
 * runs - consent, the destination list, the health threshold. The only
 * check made here is the lane's master switch: a job queued while sending
 * is off would be dropped by the sender with no row to say so.
 */
final class RefundReplay {

	/**
	 * Constructor.
	 *
	 * @param SendLog      $log     The diagnostics ring the plan is read from.
	 * @param Options|null $options Plugin options; null skips the sending-on check (tests).
	 */
	public function __construct( private SendLog $log, private ?Options $options = null ) {
	}

	/**
	 * The references of a raw list that name a refund: strings in the
	 * platform:order:refund form the log writes. Anything else is dropped
	 * rather than refused, so a client naming one bad reference next to a
	 * good one still gets the good one queued.
	 *
	 * @param mixed $raw The list as submitted.
	 * @return string[]
	 */
	public static function references( $raw ): array {
		$references = array();

		foreach ( (array) $raw as $reference ) {
			if ( is_string( $reference ) && null !== SendLog::parse_reference( $reference ) ) {
				$references[] = $reference;
			}
		}

		return array_values( array_unique( $references ) );
	}

	/**
	 * Queues every replayable refund again, or only the named ones.
	 *
	 * @param string[] $references Limit to these references; empty means every replayable refund.
	 * @return array{queued: int, references: string[]}|\WP_Error The count and the references queued; 409 while sending is off.
	 */
	public function replay( array $references = array() ) {
		if ( null !== $this->options && ! $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ) {
			return new \WP_Error(
				'gtm4wp_gdm_sending_off',
				__( 'Turn on "Send refunds to Google Analytics" and save before sending anything again.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 409 )
			);
		}

		$queued = array();

		foreach ( $this->log->replay_plan( $references ) as $reference => $job ) {
			$payload = array(
				'platform'  => $job['platform'],
				'order_id'  => $job['order_id'],
				'refund_id' => $job['refund_id'],
				'attempt'   => 1,
			);

			if ( array() !== $job['only'] ) {
				$payload['only'] = $job['only'];
			}

			if ( SendQueue::schedule( SendQueue::HOOK_SEND, $payload ) ) {
				$queued[] = $reference;
			}
		}

		return array(
			'queued'     => count( $queued ),
			'references' => $queued,
		);
	}
}
