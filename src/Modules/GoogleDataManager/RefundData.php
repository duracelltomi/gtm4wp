<?php
/**
 * Platform-neutral description of one refund.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the refund lane needs to know about one refund, read from the
 * platform once and then handled without asking the platform anything again.
 *
 * This is what makes WooCommerce and Easy Digital Downloads share the whole
 * lane rather than half of it: the two adapters differ only in how they fill
 * this object. Event assembly, the consent gate, the send, the retries, the
 * diagnostics and the tests below them all work on this shape.
 *
 * The amounts are positive here. Both platforms model a refund with negative
 * numbers - WooCommerce stores the refund's total as the negated amount, EDD
 * negates subtotal, tax and total on the refund order - and the API wants a
 * positive value, so the sign is dealt with once, in the adapter, instead of
 * at every later reader.
 */
final class RefundData {

	/**
	 * Constructor.
	 *
	 * @param string                                                             $platform        Platform id, one of RefundSource::PLATFORM_*.
	 * @param int                                                                $order_id        The parent order's id.
	 * @param int                                                                $refund_id       The refund's own id.
	 * @param string                                                             $transaction_id  The GA transaction id of the parent order, prefix included - the join key.
	 * @param string                                                             $currency        ISO 4217 currency of the order.
	 * @param float                                                              $amount          The refunded amount, positive.
	 * @param float                                                              $order_total     The parent order's own total, as it was before any refund.
	 * @param int                                                                $timestamp       Unix time the refund was created.
	 * @param array<int, array{itemId: string, unitPrice: float, quantity: int}> $items           Refunded lines; empty for a refund the platform recorded as an amount only.
	 * @param string                                                             $client_id       Stored Google Analytics client id, empty when none was captured.
	 * @param array<string, mixed>|null                                          $consent_state   Stored consent-mode state, or null when none was captured.
	 * @param string                                                             $billing_country Two-letter billing country, empty when the order has none.
	 * @param float                                                              $shipping        Refunded shipping, positive; 0.0 when none was returned or the platform has no shipping.
	 * @param float                                                              $tax             Refunded tax, positive; 0.0 when none was returned.
	 */
	public function __construct(
		public string $platform,
		public int $order_id,
		public int $refund_id,
		public string $transaction_id,
		public string $currency,
		public float $amount,
		public float $order_total,
		public int $timestamp,
		public array $items,
		public string $client_id,
		public ?array $consent_state,
		public string $billing_country,
		// Defaulted so an adapter that has no such amount to report - EDD
		// carries no shipping on an order at all - simply leaves it out.
		public float $shipping = 0.0,
		public float $tax = 0.0
	) {
	}

	/**
	 * The reference the diagnostics ring records this refund under.
	 *
	 * Ids rather than the order number: this identifies a row in the store's
	 * own database, which is what someone reading the log needs to open.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->platform . ':' . $this->order_id . ':' . $this->refund_id;
	}

	/**
	 * The consent-mode signal map inside the stored consent state, or null when
	 * no state was captured for this order at all.
	 *
	 * The nullable return is the whole point: an absent state is unknown, and
	 * unknown is not denied. A captured state whose map came back empty is
	 * still unknown for any particular signal, which is what the gate below
	 * decides on - so both cases reach ConsentPolicy::decide() distinguishable
	 * from a map that genuinely says "denied".
	 *
	 * @return array<string, string>|null
	 */
	public function consent_signals(): ?array {
		if ( null === $this->consent_state ) {
			return null;
		}

		$signals = $this->consent_state[ AttributionCookies::KEY_SIGNALS ] ?? null;

		if ( ! is_array( $signals ) ) {
			return array();
		}

		$clean = array();

		foreach ( $signals as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$clean[ $name ] = $value;
			}
		}

		return $clean;
	}
}
