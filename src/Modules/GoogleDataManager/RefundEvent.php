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
 * **One shape.** Every refund carries its own amount, its currency and, where
 * the platform recorded them, the lines it covers. A refund that returns the
 * whole order is simply one that lists every line at its refunded quantity.
 *
 * It was two shapes until 2026-09-17: a whole-order refund went out as the
 * transaction id alone, on the old gtag contract that Analytics would reverse
 * the entire purchase from the id. The phase 4 acceptance run measured that
 * contract in a live property and it does not hold for the Data Manager API:
 * nine such events were accepted, applied (SUCCESS), counted as refunds - and
 * attributed a refund amount of zero, every one of them. Google's current
 * reference marks the value as required and says to include each refunded
 * item "regardless of whether you issue a full or partial refund" (U134).
 * Partials with a value were attributed correctly in the same run.
 *
 * The single shape also removes the decision the two-shape design had to get
 * right: which refund is "full". A sequence of partials that adds up to the
 * order total was the trap - both platforms make the completing slice easy to
 * mistake for a whole-order refund (Easy Digital Downloads hands it a flag
 * literally named "all refunded"), and treating it as one would have reversed
 * the purchase twice. With every refund reporting its own slice there is
 * nothing to decide, so nothing to get wrong.
 *
 * The amount mirrors the purchase event's value - the platform's own refund
 * total, shipping and tax included unless the store's exclude options say
 * otherwise on the purchase side - so a full refund nets to zero against the
 * purchase it reverses. The refunded shipping and tax also travel as event
 * parameters, which is where Analytics' own shipping and tax metrics are
 * decremented from.
 */
final class RefundEvent {

	/**
	 * The Google Analytics recommended event name. Recommended events need no
	 * allowlist on the destination (U135).
	 */
	public const EVENT_NAME = 'refund';

	/**
	 * The item parameters a refunded line carries besides its id, price and
	 * quantity, by their Google Analytics names - the names the Data Manager
	 * Item object takes in additionalItemParameters (U134), and the same keys
	 * the purchase event's items use.
	 *
	 * Everything the purchase item carries that a refund can carry too. Not
	 * here on purpose: item_list_name / item_list_id, which the purchase reads
	 * from the buyer's list-attribution cookie on the order-received page - at
	 * refund time there is no visitor browser to read; and the Google Ads
	 * fields (id, google_business_vertical, item_group_id), which are not
	 * Analytics item parameters.
	 *
	 * @var string[]
	 */
	public const ITEM_PARAMETERS = array(
		'item_name',
		'item_brand',
		'item_variant',
		'item_category',
		'item_category2',
		'item_category3',
		'item_category4',
		'item_category5',
		'affiliation',
		'discount',
	);

	/**
	 * One refunded line in the API's item shape, from the array the store's
	 * item builder produced for the purchase event.
	 *
	 * The one place the shape is defined, for both platforms. Until 2026-09-19
	 * the adapters sent the id, price and quantity and dropped everything else
	 * the builder had made, and the acceptance run showed what that costs:
	 * Analytics does not enrich a refund's items from the purchase it reverses,
	 * it reports the item parameters the refund event itself carries, so every
	 * refunded line showed up as "(not set)" with its amount attributed to no
	 * product. The name, brand, variant and categories now travel with the id,
	 * in additionalItemParameters, taken from the very same builder output -
	 * so a refunded line and the line it refunds are described identically by
	 * construction, not by a second implementation kept in step by hand.
	 *
	 * @param array<string, mixed> $built      The builder's item array (item_id, item_name, ...).
	 * @param float                $unit_price Refunded unit price, positive.
	 * @param int                  $quantity   Refunded quantity, positive.
	 * @return array<string, mixed>
	 */
	public static function item( array $built, float $unit_price, int $quantity ): array {
		$item = array(
			'itemId'    => (string) $built['item_id'],
			'unitPrice' => $unit_price,
			'quantity'  => $quantity,
		);

		$parameters = array();

		foreach ( self::ITEM_PARAMETERS as $name ) {
			$value = $built[ $name ] ?? null;

			// Absent stays absent: an empty name or category is not a claim
			// worth making, and the purchase event omits them the same way.
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$parameters[] = array(
				'parameterName' => $name,
				'value'         => (string) $value,
			);
		}

		if ( array() !== $parameters ) {
			$item['additionalItemParameters'] = $parameters;
		}

		return $item;
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

		$event['currency']        = $refund->currency;
		$event['conversionValue'] = round( $refund->amount, 2 );

		// Lines only when the platform recorded them. A by-amount refund
		// names none, and an empty list would claim "no items were refunded"
		// where the truth is "the store did not say which".
		if ( array() !== $refund->items ) {
			$event['cartData'] = array( 'items' => array_values( $refund->items ) );
		}

		$parameters = self::amount_parameters( $refund );

		if ( array() !== $parameters ) {
			$event['additionalEventParameters'] = $parameters;
		}

		return $event;
	}

	/**
	 * The refunded shipping and tax, in the API's event-parameter shape.
	 *
	 * Neither has a field of its own on the event: the recommended-events
	 * reference puts both in additionalEventParameters, under the Google
	 * Analytics parameter names, with string values. Without them Analytics
	 * increments its shipping and tax metrics at the purchase and never
	 * decrements them, because the purchase event reports both (see the
	 * WooCommerce purchase builder) while the refund would not.
	 *
	 * A zero amount is omitted rather than sent as "0", for the same reason
	 * the items are - a present field is a claim, and nothing was returned.
	 *
	 * @param RefundData $refund The refund.
	 * @return array<int, array{parameterName: string, value: string}>
	 */
	private static function amount_parameters( RefundData $refund ): array {
		$parameters = array();

		foreach ( array(
			'shipping' => $refund->shipping,
			'tax'      => $refund->tax,
		) as $name => $amount ) {
			if ( $amount <= 0 ) {
				continue;
			}

			$parameters[] = array(
				'parameterName' => $name,
				// A string, which is what EventParameter.value is, and fixed to
				// two decimals so 1.0 does not travel as "1".
				'value'         => number_format( round( $amount, 2 ), 2, '.', '' ),
			);
		}

		return $parameters;
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
