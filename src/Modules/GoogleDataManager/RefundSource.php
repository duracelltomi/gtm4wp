<?php
/**
 * Per-platform refund adapter contract.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * The thin per-platform half of the refund lane.
 *
 * Everything a refund event needs is expressed as RefundData, so an adapter
 * only answers four questions: which hook says a refund happened, what does
 * that refund look like, has it been sent already, and how do I remember that
 * it has. Nothing about the API, the queue, the consent gate or the two event
 * shapes lives on this side.
 *
 * Adding a third commerce platform is therefore one class, and the standing
 * parity rule (WooCommerce and Easy Digital Downloads land together, never one
 * as a fast-follow) costs a hook and a reader rather than a second lane.
 */
interface RefundSource {

	/**
	 * Platform id of the WooCommerce adapter.
	 */
	public const PLATFORM_WOOCOMMERCE = 'woocommerce';

	/**
	 * Platform id of the Easy Digital Downloads adapter.
	 */
	public const PLATFORM_EDD = 'edd';

	/**
	 * Meta key remembering that a refund has been sent. Written on the REFUND,
	 * not the order: each partial refund of an order is its own event and must
	 * be sent exactly once, which a per-order flag could not express.
	 */
	public const META_SENT = '_gtm4wp_gdm_refund_sent';

	/**
	 * The platform id this adapter serves.
	 *
	 * @return string
	 */
	public function platform(): string;

	/**
	 * Whether this platform is active in the current request.
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Registers the platform's refund hook.
	 *
	 * @param callable $enqueue Receives ( int $order_id, int $refund_id ) for every refund issued.
	 * @return void
	 */
	public function register_hooks( callable $enqueue ): void;

	/**
	 * Reads one refund into the platform-neutral shape.
	 *
	 * @param int $order_id  The parent order.
	 * @param int $refund_id The refund.
	 * @return RefundData|null Null when either object is gone or is not what it claims to be.
	 */
	public function load( int $order_id, int $refund_id ): ?RefundData;

	/**
	 * The platform's own refund and order objects, for the last-chance filter.
	 *
	 * @param int $order_id  The parent order.
	 * @param int $refund_id The refund.
	 * @return array{0: mixed, 1: mixed} Refund object, order object; either may be null.
	 */
	public function objects( int $order_id, int $refund_id ): array;

	/**
	 * Whether this refund has already been sent successfully.
	 *
	 * @param int $refund_id The refund.
	 * @return bool
	 */
	public function is_sent( int $refund_id ): bool;

	/**
	 * Remembers that this refund was sent.
	 *
	 * @param int    $refund_id  The refund.
	 * @param string $request_id The API request id of the send that succeeded.
	 * @return void
	 */
	public function mark_sent( int $refund_id, string $request_id ): void;
}
