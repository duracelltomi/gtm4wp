<?php
/**
 * Unit tests for the two-shape refund event assembly.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Modules\GoogleDataManager\AttributionCookies;
use GTM4WP\Modules\GoogleDataManager\RefundData;
use GTM4WP\Modules\GoogleDataManager\RefundEvent;
use GTM4WP\Modules\GoogleDataManager\RefundSource;
use GTM4WP\Tests\unit\TestCase;

/**
 * The two shapes are the design decision this whole feature turns on, and both
 * of the ways to get them wrong are silent in Google Analytics:
 *
 * - Sending a full refund's value and items reverses the order twice over, once
 *   through the transaction id and once through the amounts.
 * - Treating the last partial of a sequence as full does the same thing, on top
 *   of the slices that were already reversed. Both platforms invite that
 *   mistake - Easy Digital Downloads passes an argument literally named
 *   `$all_refunded` on exactly that case - so it is asserted here from the
 *   amounts, and again in each platform adapter's own suite.
 *
 * Nothing about either is observable from our side once it has been sent, so
 * the assertions are on absence as much as presence: a full refund's body must
 * not merely have the right value, it must not carry the keys at all.
 */
final class GoogleDataManagerRefundEventTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
	}

	/**
	 * A refund description with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 * @return RefundData
	 */
	private static function refund( array $overrides = array() ): RefundData {
		$values = array_merge(
			array(
				'platform'        => RefundSource::PLATFORM_WOOCOMMERCE,
				'order_id'        => 12,
				'refund_id'       => 34,
				'transaction_id'  => 'WC-1001',
				'currency'        => 'EUR',
				'amount'          => 40.0,
				'order_total'     => 100.0,
				'timestamp'       => 1_800_000_000,
				'items'           => array(),
				'client_id'       => '313930999.1788522497',
				'consent_state'   => null,
				'billing_country' => 'DE',
				'shipping'        => 0.0,
				'tax'             => 0.0,
			),
			$overrides
		);

		return new RefundData(
			$values['platform'],
			$values['order_id'],
			$values['refund_id'],
			$values['transaction_id'],
			$values['currency'],
			$values['amount'],
			$values['order_total'],
			$values['timestamp'],
			$values['items'],
			$values['client_id'],
			$values['consent_state'],
			$values['billing_country'],
			$values['shipping'],
			$values['tax']
		);
	}

	/**
	 * One refunded line.
	 *
	 * @return array{itemId: string, unitPrice: float, quantity: int}
	 */
	private static function item(): array {
		return array(
			'itemId'    => 'SKU-1',
			'unitPrice' => 20.0,
			'quantity'  => 2,
		);
	}

	// ---- The full-refund shape ---------------------------------------------

	public function test_a_refund_of_the_whole_order_is_identified_by_its_transaction_id_alone(): void {
		$event = RefundEvent::build( self::refund( array( 'amount' => 100.0 ) ) );

		$this->assertSame(
			array(
				'eventName'      => 'refund',
				'eventTimestamp' => '2027-01-15T08:00:00Z',
				'transactionId'  => 'WC-1001',
				'eventSource'    => 'WEB',
				'clientId'       => '313930999.1788522497',
			),
			$event,
			'A full refund carries no money and no items at all - Analytics reverses the whole transaction from the id.'
		);
	}

	public function test_a_full_refund_carries_no_value_currency_or_cart_keys(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount' => 100.0,
					'items'  => array( self::item() ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'conversionValue', $event );
		$this->assertArrayNotHasKey( 'currency', $event );
		$this->assertArrayNotHasKey( 'cartData', $event, 'Items are dropped even when the refund object carries them: sending both reverses the order twice.' );
	}

	public function test_a_rounding_difference_still_counts_as_a_full_refund(): void {
		$this->assertTrue(
			RefundEvent::is_full(
				self::refund(
					array(
						'amount'      => 99.999,
						'order_total' => 100.0,
					)
				)
			)
		);
		$this->assertTrue(
			RefundEvent::is_full(
				self::refund(
					array(
						'amount'      => 100.001,
						'order_total' => 100.0,
					)
				)
			)
		);
	}

	public function test_a_cent_short_is_a_partial_refund(): void {
		$this->assertFalse(
			RefundEvent::is_full(
				self::refund(
					array(
						'amount'      => 99.99,
						'order_total' => 100.0,
					)
				)
			)
		);
	}

	public function test_an_order_with_no_total_is_never_called_a_full_refund(): void {
		$this->assertFalse(
			RefundEvent::is_full(
				self::refund(
					array(
						'amount'      => 0.0,
						'order_total' => 0.0,
					)
				)
			),
			'Zero equals zero, but an order with nothing to refund gives the comparison no meaning.'
		);
	}

	// ---- The partial shape -------------------------------------------------

	public function test_a_partial_refund_carries_its_own_amount_the_currency_and_its_lines(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount' => 40.0,
					'items'  => array( self::item() ),
				)
			)
		);

		$this->assertSame( 40.0, $event['conversionValue'] );
		$this->assertSame( 'EUR', $event['currency'] );
		$this->assertSame( array( 'items' => array( self::item() ) ), $event['cartData'] );
	}

	public function test_a_partial_refund_recorded_as_an_amount_only_sends_no_cart_data(): void {
		$event = RefundEvent::build( self::refund( array( 'items' => array() ) ) );

		$this->assertSame( 40.0, $event['conversionValue'] );
		$this->assertArrayNotHasKey( 'cartData', $event, 'An empty items list is omitted, not sent as an empty array: the two say different things.' );
	}

	public function test_the_conversion_value_is_rounded_to_two_decimals(): void {
		$event = RefundEvent::build( self::refund( array( 'amount' => 13.333333 ) ) );

		$this->assertSame( 13.33, $event['conversionValue'] );
	}

	/**
	 * The rule the whole design turns on, stated as a sequence: three partial
	 * refunds that add up to the order total. Each one reports its own slice,
	 * including the one that happens to complete the order.
	 */
	public function test_a_partial_that_completes_the_order_stays_partial(): void {
		$slices = array( 30.0, 30.0, 40.0 );

		foreach ( $slices as $index => $amount ) {
			$event = RefundEvent::build( self::refund( array( 'amount' => $amount ) ) );

			$this->assertSame(
				$amount,
				$event['conversionValue'],
				"Slice {$index} reports its own amount."
			);
			$this->assertSame( 'EUR', $event['currency'] );
		}

		$this->assertSame(
			100.0,
			array_sum( $slices ),
			'The slices add up to the order total, which is exactly the case that must not become one full-refund event.'
		);
	}

	// ---- Shared fields -----------------------------------------------------

	public function test_the_timestamp_is_rfc_3339_in_utc(): void {
		$event = RefundEvent::build( self::refund( array( 'timestamp' => 1_767_225_600 ) ) );

		$this->assertSame( '2026-01-01T00:00:00Z', $event['eventTimestamp'] );
	}

	public function test_an_absent_client_id_is_omitted_rather_than_sent_empty(): void {
		$event = RefundEvent::build( self::refund( array( 'client_id' => '' ) ) );

		$this->assertArrayNotHasKey( 'clientId', $event );
	}

	public function test_the_event_name_is_the_recommended_one(): void {
		$this->assertSame( 'refund', RefundEvent::EVENT_NAME );
		$this->assertSame( 'refund', RefundEvent::build( self::refund() )['eventName'] );
	}

	// ---- Consent signals ---------------------------------------------------

	public function test_an_order_with_no_captured_consent_reports_unknown_not_denied(): void {
		$this->assertNull(
			self::refund( array( 'consent_state' => null ) )->consent_signals(),
			'Null is what keeps "nobody asked" apart from "the visitor said no".'
		);
	}

	public function test_a_captured_consent_state_yields_its_signal_map(): void {
		$refund = self::refund(
			array(
				'consent_state' => array(
					AttributionCookies::KEY_SIGNALS     => array(
						'analytics_storage' => 'granted',
						'ad_storage'        => 'denied',
					),
					AttributionCookies::KEY_CAPTURED_AT => 1_800_000_000,
				),
			)
		);

		$this->assertSame(
			array(
				'analytics_storage' => 'granted',
				'ad_storage'        => 'denied',
			),
			$refund->consent_signals()
		);
	}

	public function test_a_damaged_consent_state_reads_as_an_empty_map_not_as_a_denial(): void {
		$refund = self::refund( array( 'consent_state' => array( AttributionCookies::KEY_SIGNALS => 'not-an-array' ) ) );

		$this->assertSame( array(), $refund->consent_signals() );
	}

	// ---- The last-chance filter --------------------------------------------

	public function test_the_filter_receives_the_event_the_refund_and_both_platform_objects(): void {
		$refund        = self::refund();
		$refund_object = new \stdClass();
		$order_object  = new \stdClass();
		$captured      = array();

		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )
			->once()
			->andReturnUsing(
				static function ( $event, $data, $refund_obj, $order_obj ) use ( &$captured ) {
					$captured = array( $event, $data, $refund_obj, $order_obj );
					return $event;
				}
			);

		RefundEvent::filter( RefundEvent::build( $refund ), $refund, $refund_object, $order_object );

		$this->assertSame( 'WC-1001', $captured[0]['transactionId'] );
		$this->assertSame( $refund, $captured[1] );
		$this->assertSame( $refund_object, $captured[2] );
		$this->assertSame( $order_object, $captured[3] );
	}

	public function test_returning_an_empty_array_cancels_the_send(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )->once()->andReturn( array() );

		$this->assertNull( RefundEvent::filter( RefundEvent::build( self::refund() ), self::refund(), null, null ) );
	}

	public function test_returning_a_non_array_cancels_the_send(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )->once()->andReturn( false );

		$this->assertNull( RefundEvent::filter( RefundEvent::build( self::refund() ), self::refund(), null, null ) );
	}

	public function test_an_event_the_filter_stripped_the_transaction_id_from_is_not_sent(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )
			->once()
			->andReturnUsing(
				static function ( $event ) {
					unset( $event['transactionId'] );
					return $event;
				}
			);

		$this->assertNull(
			RefundEvent::filter( RefundEvent::build( self::refund() ), self::refund(), null, null ),
			'Without the join key the event could never be matched to its purchase, so nothing is sent rather than something unusable.'
		);
	}

	public function test_a_filter_that_adds_a_field_is_honoured(): void {
		Filters\expectApplied( GTM4WP_WPFILTER_GDM_REFUND_EVENT )
			->once()
			->andReturnUsing(
				static function ( $event ) {
					$event['userId'] = 'crm-77';
					return $event;
				}
			);

		$event = RefundEvent::filter( RefundEvent::build( self::refund() ), self::refund(), null, null );

		$this->assertSame( 'crm-77', $event['userId'] );
		$this->assertSame( 'WC-1001', $event['transactionId'] );
	}

	// ---- Refunded shipping and tax -----------------------------------------

	public function test_a_partial_refund_reports_the_returned_shipping_and_tax_as_event_parameters(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount'   => 19.0,
					'items'    => array( self::item() ),
					'shipping' => 1.0,
					'tax'      => 3.5,
				)
			)
		);

		$this->assertSame(
			array(
				array(
					'parameterName' => 'shipping',
					'value'         => '1.00',
				),
				array(
					'parameterName' => 'tax',
					'value'         => '3.50',
				),
			),
			$event['additionalEventParameters'],
			'Neither amount has a field of its own; both travel as event parameters under their Google Analytics names, as strings.'
		);
	}

	public function test_the_refunded_shipping_is_reported_even_though_the_conversion_value_already_covers_it(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount'   => 19.0,
					'items'    => array( self::item() ),
					'shipping' => 1.0,
				)
			)
		);

		// The whole reason this exists: conversionValue carries the money, but
		// Analytics' shipping metric is incremented by the purchase event and
		// would never be decremented again.
		$this->assertSame( 19.0, $event['conversionValue'] );
		$this->assertSame(
			array(
				array(
					'parameterName' => 'shipping',
					'value'         => '1.00',
				),
			),
			$event['additionalEventParameters']
		);
	}

	public function test_an_amount_that_was_not_returned_is_left_out_rather_than_sent_as_zero(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount'   => 40.0,
					'items'    => array( self::item() ),
					'shipping' => 0.0,
					'tax'      => 8.0,
				)
			)
		);

		$this->assertSame(
			array(
				array(
					'parameterName' => 'tax',
					'value'         => '8.00',
				),
			),
			$event['additionalEventParameters'],
			'A refund that returned no shipping makes no claim about shipping.'
		);
	}

	public function test_a_partial_that_returned_neither_carries_no_parameter_list_at_all(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount' => 40.0,
					'items'  => array( self::item() ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'additionalEventParameters', $event );
	}

	public function test_a_full_refund_reports_no_shipping_or_tax_because_it_reverses_the_whole_transaction(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount'   => 100.0,
					'shipping' => 1.0,
					'tax'      => 20.0,
				)
			)
		);

		$this->assertArrayNotHasKey(
			'additionalEventParameters',
			$event,
			'The full shape carries the transaction id alone; adding amounts to it would describe a partial.'
		);
	}
}
