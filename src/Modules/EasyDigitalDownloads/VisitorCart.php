<?php
/**
 * Easy Digital Downloads customer + cart delivery for the cache-safe data layer.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\EasyDigitalDownloads;

use GTM4WP\Frontend\DataLayer;
use GTM4WP\Modules\VisitorData\VisitorDataModule;
use GTM4WP\Modules\VisitorData\VisitorField;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers the EDD customer and cart blocks under the cache-safe data layer
 * (issue #398) as gtm4wp.customerData / gtm4wp.cartData, the events the
 * WooCommerce cart fragment produces. EDD has no cart fragments and all its
 * cookies are HttpOnly (U170), so the block rides the visitor-data endpoint as
 * one Tier 3 field, gated on STATE_COOKIE: a JS-readable fingerprint of the
 * block, rewritten whenever the cart or the customer changes, cleared when
 * there is nothing to deliver. The fingerprint is a hash, never a value.
 */
final class VisitorCart {

	/**
	 * JS-readable gate cookie; the runtime learns the name from the config.
	 */
	public const STATE_COOKIE = 'gtm4wp_edd_state';

	/**
	 * Endpoint payload key of the { customer, cart } block.
	 */
	public const FIELD_KEY = 'eddVisitorCart';

	/**
	 * EDD's cart and discount mutation actions (U170). They run on admin-ajax
	 * and REST requests too, hence register_state_hooks() outside the frontend.
	 */
	private const CART_HOOKS = array(
		'edd_post_add_to_cart',
		'edd_post_remove_from_cart',
		'edd_after_set_cart_item_quantity',
		'edd_empty_cart',
		'edd_cart_discounts_updated',
		'edd_cart_discounts_removed',
	);

	/**
	 * Substring of EDD's session cookie name ([prefix]edd_session_<COOKIEHASH>, U170).
	 */
	private const EDD_SESSION_COOKIE_MARKER = 'edd_session_';

	/**
	 * Constructor.
	 *
	 * @param Options       $options        The plugin options service.
	 * @param PageDataLayer $page_datalayer The EDD page data layer builder.
	 */
	public function __construct(
		private Options $options,
		private PageDataLayer $page_datalayer
	) {
	}

	/**
	 * Whether the block is delivered client-side: cache-safe mode, EDD tracking
	 * and at least one of customer data / cart content on.
	 *
	 * @param Options $options The plugin options service.
	 * @return bool
	 */
	public static function is_enabled( Options $options ): bool {
		return VisitorDataModule::is_enabled( $options )
			&& true === $options->get( GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE )
			&& (
				(bool) $options->get( GTM4WP_OPTION_INTEGRATE_EDDCUSTOMERDATA )
				|| (bool) $options->get( GTM4WP_OPTION_INTEGRATE_EDDINCLUDECARTINDL )
			);
	}

	/**
	 * Registers the state cookie upkeep on every request type. Called from
	 * Plugin::boot() because EDD changes the cart on admin-ajax, where only the
	 * admin path boots.
	 *
	 * @param Options $options The plugin options service.
	 * @return void
	 */
	public static function register_state_hooks( Options $options ): void {
		// The platform check sits here: the module path is gated by Registry::frontend().
		if ( ! self::is_enabled( $options ) || ! ( new EasyDigitalDownloadsModule() )->is_available() ) {
			return;
		}

		$visitor_cart = new self(
			$options,
			new PageDataLayer( $options, new DownloadData( $options ), new DataLayer( $options ) )
		);

		// Priority 20: after EDD's own callbacks have settled the session.
		foreach ( self::CART_HOOKS as $hook ) {
			add_action( $hook, array( $visitor_cart, 'maintain_state_cookie' ), 20, 0 );
		}

		// EDD forgets the session on wp_logout at 10; the guest state follows.
		add_action( 'wp_logout', array( $visitor_cart, 'maintain_state_cookie' ), 20, 0 );
		add_action( 'template_redirect', array( $visitor_cart, 'maintain_state_cookie_on_page' ) );
	}

	/**
	 * Declares the block as a Tier 3 field gated on STATE_COOKIE. Hooked to
	 * GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS.
	 *
	 * @param array<int, VisitorField> $fields Visitor-scoped fields declared so far.
	 * @return array<int, VisitorField>
	 */
	public function declare_visitor_scoped_fields( array $fields ): array {
		if ( ! self::is_enabled( $this->options ) ) {
			return $fields;
		}

		$fields[] = new VisitorField(
			self::FIELD_KEY,
			VisitorField::TIER_ACTION,
			'',
			array( $this, 'resolve_visitor_cart' ),
			self::STATE_COOKIE,
			false,
			'',
			true
		);

		return $fields;
	}

	/**
	 * Endpoint resolver: the caller's own block, or null. No request parameter
	 * is read (PA-10): the customer is the authenticated user, the cart is the
	 * caller's EDD session. A caller with neither gets null before the session
	 * is touched, so the public GET never starts one (PA-11).
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	public function resolve_visitor_cart(): ?array {
		if ( ! $this->visitor_has_edd_state() ) {
			return null;
		}

		$block = $this->page_datalayer->visitor_cart_datalayer();

		return array() === $block ? null : $block;
	}

	/**
	 * Keeps STATE_COOKIE on the current fingerprint on a page request: always
	 * for a logged-in visitor (the customer totals can change without a cart
	 * change), and for a guest only while the cookie exists, to retire it.
	 * Hooked to template_redirect.
	 *
	 * @return void
	 */
	public function maintain_state_cookie_on_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		if ( is_user_logged_in() || isset( $_COOKIE[ self::STATE_COOKIE ] ) ) {
			$this->maintain_state_cookie();
		}
	}

	/**
	 * Rewrites STATE_COOKIE when the fingerprint changed, or clears it when
	 * there is nothing to deliver. Hooked to the CART_HOOKS and wp_logout.
	 *
	 * @return void
	 */
	public function maintain_state_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		$current = isset( $_COOKIE[ self::STATE_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) )
			: '';
		$desired = $this->state_fingerprint();

		if ( $current === $desired ) {
			return;
		}

		// A guest response here may be cacheable; a Set-Cookie must never be
		// replayed to other visitors (#44).
		nocache_headers();

		if ( '' === $desired ) {
			$this->set_state_cookie( '', time() - DAY_IN_SECONDS );
			unset( $_COOKIE[ self::STATE_COOKIE ] );

			return;
		}

		$this->set_state_cookie( $desired, time() + ( 30 * DAY_IN_SECONDS ) );
		$_COOKIE[ self::STATE_COOKIE ] = $desired;
	}

	/**
	 * The opaque fingerprint of the current block, bound to the user and login
	 * session so a re-login re-fetches; empty when there is no customer part
	 * and no cart line, so an idle guest never fetches.
	 *
	 * @return string
	 */
	private function state_fingerprint(): string {
		$block = $this->page_datalayer->visitor_cart_datalayer();

		if ( ! isset( $block['customer'] ) && empty( $block['cart']['cartContent']['items'] ) ) {
			return '';
		}

		$token = ( is_user_logged_in() && function_exists( 'wp_get_session_token' ) ) ? (string) wp_get_session_token() : '';

		return substr( wp_hash( get_current_user_id() . '|' . $token . '|' . wp_json_encode( $block ) ), 0, 20 );
	}

	/**
	 * Whether the caller is logged in or carries an EDD session cookie. Only
	 * the cookie's presence is read.
	 *
	 * @return bool
	 */
	private function visitor_has_edd_state(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; no value is read.
		foreach ( array_keys( $_COOKIE ) as $one_name ) {
			if ( false !== strpos( (string) $one_name, self::EDD_SESSION_COOKIE_MARKER ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sets (or, with an empty value and past expiry, clears) STATE_COOKIE: NOT
	 * HttpOnly (the runtime reads it), scoped like the login gate cookie.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	private function set_state_cookie( string $value, int $expires ): void {
		setcookie(
			self::STATE_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
