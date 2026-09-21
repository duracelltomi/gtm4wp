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
 * ONE shape: every refund carries its own amount, currency and lines; a
 * whole-order refund lists every line at its refunded quantity. Do NOT
 * reintroduce the id-only whole-order shape: measured in a live property, the
 * Data Manager API attributes a refund amount of zero to it (U134 marks the
 * value required). One shape also removes the "which refund is full" decision,
 * where the slice completing a sequence of partials (EDD even flags it "all
 * refunded") would have reversed the purchase twice.
 *
 * The amount mirrors the purchase event's value (the platform's refund total,
 * shipping and tax included unless the exclude options say otherwise) so a
 * full refund nets to zero; refunded shipping and tax also travel as event
 * parameters, where Analytics decrements its own metrics from.
 */
final class RefundEvent {

	/**
	 * The Google Analytics recommended event name; needs no allowlist (U135).
	 */
	public const EVENT_NAME = 'refund';

	/**
	 * Item parameters a refunded line carries besides id, price and quantity,
	 * by their Analytics names (additionalItemParameters, U134): everything
	 * the purchase item carries that a refund can. Absent on purpose:
	 * item_list_name / item_list_id (read from the buyer's cookie at purchase;
	 * no browser at refund time) and the Google Ads fields.
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
	 * One refunded line in the API's item shape, from the store's own purchase
	 * item builder output (the one definition for both platforms). Analytics
	 * does not enrich a refund's items from the purchase it reverses: without
	 * the name, brand, variant and categories every refunded line reported as
	 * "(not set)" with its amount attributed to no product.
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
	 * The refunded shipping and tax as additionalEventParameters (neither has
	 * a field of its own); without them Analytics never decrements the metrics
	 * the purchase incremented. A zero amount is omitted, not sent as "0".
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
	 * Runs an assembled event through the site's last-chance filter. Anything
	 * but a non-empty array cancels the send (the documented veto), and a
	 * filtered event without a transaction id is not sent either.
	 *
	 * @param array<string, mixed> $event         The assembled event.
	 * @param RefundData           $refund        The refund it describes.
	 * @param mixed                $refund_object The platform's own refund object.
	 * @param mixed                $order_object  The platform's own parent order object.
	 * @return array<string, mixed>|null The event to send, or null when it was vetoed.
	 */
	public static function filter( array $event, RefundData $refund, $refund_object, $order_object ): ?array {
		/**
		 * Filters one assembled Google Data Manager refund event, exactly as it
		 * would be sent. Return an empty array to cancel the send.
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
