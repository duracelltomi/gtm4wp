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

	// ---- One shape, whatever the refund returns ----------------------------

	/**
	 * The measurement this pins: nine whole-order refunds sent as the
	 * transaction id alone were accepted and applied by Google, counted as
	 * refunds, and attributed a refund amount of zero in the property, every
	 * one of them - while partials carrying a value were attributed in the
	 * same run. The id-only shape is a claim Google no longer honours.
	 */
	public function test_a_refund_of_the_whole_order_carries_its_amount_currency_and_every_line(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount' => 100.0,
					'items'  => array( self::item() ),
				)
			)
		);

		$this->assertSame(
			array(
				'eventName'       => 'refund',
				'eventTimestamp'  => '2027-01-15T08:00:00Z',
				'transactionId'   => 'WC-1001',
				'eventSource'     => 'WEB',
				'clientId'        => '313930999.1788522497',
				'currency'        => 'EUR',
				'conversionValue' => 100.0,
				'cartData'        => array( 'items' => array( self::item() ) ),
			),
			$event,
			'A whole-order refund is a refund that lists every line; there is no transaction-id-only shape any more.'
		);
	}

	public function test_a_refund_matching_the_order_total_is_not_treated_differently_from_any_other(): void {
		$whole = RefundEvent::build(
			self::refund(
				array(
					'amount' => 100.0,
					'items'  => array( self::item() ),
				)
			)
		);
		$part  = RefundEvent::build(
			self::refund(
				array(
					'amount' => 40.0,
					'items'  => array( self::item() ),
				)
			)
		);

		$this->assertSame( array_keys( $whole ), array_keys( $part ), 'Same keys whatever the amount: the amount is data, never a switch between shapes.' );
	}

	// ---- Amount, currency and lines ---------------------------------------

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
			'The slices add up to the order total - the case the two-shape design once had to special-case, and the one-shape design has nothing to decide about.'
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

	public function test_a_whole_order_refund_reports_its_shipping_and_tax_like_any_other(): void {
		$event = RefundEvent::build(
			self::refund(
				array(
					'amount'   => 100.0,
					'shipping' => 1.0,
					'tax'      => 20.0,
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
					'value'         => '20.00',
				),
			),
			$event['additionalEventParameters'],
			'The purchase incremented the shipping and tax metrics; a whole-order refund has to take them off again like a partial does.'
		);
	}

	// ---- The item shape ----------------------------------------------------

	public function test_an_item_carries_the_builders_parameters_under_their_analytics_names(): void {
		$item = RefundEvent::item(
			array(
				'item_id'                  => 'SKU-1',
				'item_name'                => 'Hoodie',
				'item_brand'               => 'Acme',
				'item_variant'             => 'Blue',
				'item_category'            => 'Clothing',
				'item_category2'           => 'Tops',
				'price'                    => 45.0,
				'quantity'                 => 1,
				'google_business_vertical' => 'retail',
			),
			45.0,
			1
		);

		$this->assertSame(
			array(
				'itemId'                   => 'SKU-1',
				'unitPrice'                => 45.0,
				'quantity'                 => 1,
				'additionalItemParameters' => array(
					array(
						'parameterName' => 'item_name',
						'value'         => 'Hoodie',
					),
					array(
						'parameterName' => 'item_brand',
						'value'         => 'Acme',
					),
					array(
						'parameterName' => 'item_variant',
						'value'         => 'Blue',
					),
					array(
						'parameterName' => 'item_category',
						'value'         => 'Clothing',
					),
					array(
						'parameterName' => 'item_category2',
						'value'         => 'Tops',
					),
				),
			),
			$item,
			'Only the item parameters Analytics knows travel; price, quantity and the Ads vertical have their own fields or none.'
		);
	}

	public function test_an_item_with_nothing_but_an_id_carries_no_parameter_list(): void {
		$item = RefundEvent::item( array( 'item_id' => '38' ), 10.0, 2 );

		$this->assertSame(
			array(
				'itemId'    => '38',
				'unitPrice' => 10.0,
				'quantity'  => 2,
			),
			$item,
			'An empty parameter list is omitted, not sent: a present field is a claim.'
		);
	}

	public function test_empty_and_non_scalar_parameter_values_are_left_out(): void {
		$item = RefundEvent::item(
			array(
				'item_id'       => '38',
				'item_name'     => 'Deal',
				'item_brand'    => '',
				'item_variant'  => null,
				'item_category' => array( 'not', 'a', 'string' ),
			),
			10.0,
			1
		);

		$this->assertSame(
			array(
				array(
					'parameterName' => 'item_name',
					'value'         => 'Deal',
				),
			),
			$item['additionalItemParameters']
		);
	}

	public function test_a_numeric_item_id_travels_as_a_string(): void {
		$this->assertSame( '38', RefundEvent::item( array( 'item_id' => 38 ), 1.0, 1 )['itemId'] );
	}
}
