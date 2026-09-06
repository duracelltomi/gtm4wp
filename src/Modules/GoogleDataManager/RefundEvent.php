<?php
/**
 * Assembly of the Data Manager refund event.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one platform-neutral RefundData into the event body the API takes.
 *
 * **The two shapes.** Google Analytics understands two different refunds and
 * this class sends whichever one actually happened:
 *
 * - A **full refund** - one refund action that returns the whole order - is
 *   identified by its transaction id alone. No value, no currency, no items:
 *   the transaction id says which purchase to reverse, and Analytics reverses
 *   all of it.
 * - A **partial refund** carries its own amount and, where the platform
 *   recorded them, the lines it covers. Item-level refund metrics need the
 *   lines; the value pair is what the API requires alongside them.
 *
 * **A completing partial stays partial.** "Full" means *this one action
 * returned the entire order*, never "the order is now fully refunded". A
 * sequence of partials that adds up to the order total has already sent each
 * slice; turning the last one into a transaction-id-only event would tell
 * Analytics to reverse the whole purchase a second time, on top of the slices
 * already reversed. Both platforms make this easy to get wrong - Easy Digital
 * Downloads even hands the completing case a flag literally named
 * "all refunded" - so the decision is made here, from amounts, once, and
 * pinned by tests.
 */
final class RefundEvent {

	/**
	 * The Google Analytics recommended event name. Recommended events need no
	 * allowlist on the destination (U135).
	 */
	public const EVENT_NAME = 'refund';

	/**
	 * How close a refund's amount must be to the order total to count as
	 * returning the whole order.
	 *
	 * Stored money is decimal, read back as a float and summed on both sides,
	 * so an exact comparison would classify a rounding artefact as a partial
	 * refund of nothing. Half a cent is below the smallest amount any currency
	 * this runs in can express.
	 */
	public const AMOUNT_EPSILON = 0.005;

	/**
	 * Whether this refund returns the entire order in one action.
	 *
	 * Decided by comparing the refund's own amount against the parent order's
	 * own total - not against what is left unrefunded, and not from any flag
	 * the platform offers. A partial that happens to complete the order is
	 * smaller than the order total by exactly the slices already sent, so it
	 * falls out on the partial side by construction.
	 *
	 * An order with a total of zero or less has nothing to compare against and
	 * is never called full.
	 *
	 * @param RefundData $refund The refund.
	 * @return bool
	 */
	public static function is_full( RefundData $refund ): bool {
		if ( $refund->order_total <= 0 ) {
			return false;
		}

		return abs( $refund->amount - $refund->order_total ) < self::AMOUNT_EPSILON;
	}

	/**
	 * Builds the event body.
	 *
	 * Absent values are absent keys, never empty strings or zeroes: the API
	 * reads a present field as a claim, and "no items were recorded" has to
	 * stay distinguishable from "the refund covered no items".
	 *
	 * @param RefundData $refund The refund.
	 * @return array<string, mixed>
	 */
	public static function build( RefundData $refund ): array {
		$event = array(
			'eventName'      => self::EVENT_NAME,
			'eventTimestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $refund->timestamp ),
			'transactionId'  => $refund->transaction_id,
			'eventSource'    => EventsIngest::EVENT_SOURCE_WEB,
		);

		if ( '' !== $refund->client_id ) {
			$event['clientId'] = $refund->client_id;
		}

		if ( self::is_full( $refund ) ) {
			return $event;
		}

		$event['currency']        = $refund->currency;
		$event['conversionValue'] = round( $refund->amount, 2 );

		if ( array() !== $refund->items ) {
			$event['cartData'] = array( 'items' => array_values( $refund->items ) );
		}

		return $event;
	}

	/**
	 * Runs an assembled event through the site's own last-chance filter.
	 *
	 * Returning anything that is not a non-empty array cancels the send, which
	 * is the documented veto. A filtered event is checked against the same
	 * minimum the built-in assembly always satisfies, so a site that removes
	 * the transaction id gets nothing sent rather than an event Analytics
	 * cannot join to anything.
	 *
	 * @param array<string, mixed> $event         The assembled event.
	 * @param RefundData           $refund        The refund it describes.
	 * @param mixed                $refund_object The platform's own refund object.
	 * @param mixed                $order_object  The platform's own parent order object.
	 * @return array<string, mixed>|null The event to send, or null when it was vetoed.
	 */
	public static function filter( array $event, RefundData $refund, $refund_object, $order_object ): ?array {
		/**
		 * Filters one assembled Google Data Manager refund event.
		 *
		 * Receives the event exactly as it would be sent, the RefundData it was
		 * built from, and the platform's own refund and order objects. Return
		 * an empty array to cancel the send for this refund.
		 *
		 * @since 2.1.0
		 *
		 * @param array      $event         The assembled event.
		 * @param RefundData $refund        The platform-neutral refund description.
		 * @param mixed      $refund_object The platform's refund object.
		 * @param mixed      $order_object  The platform's parent order object.
		 */
		$filtered = apply_filters( GTM4WP_WPFILTER_GDM_REFUND_EVENT, $event, $refund, $refund_object, $order_object );

		if ( ! is_array( $filtered ) || array() === $filtered ) {
			return null;
		}

		$transaction_id = $filtered['transactionId'] ?? '';

		if ( ! is_string( $transaction_id ) || ( '' === $transaction_id ) ) {
			return null;
		}

		return $filtered;
	}
}
