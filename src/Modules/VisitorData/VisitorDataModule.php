<?php
/**
 * Cache-safe data layer module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\VisitorData;

use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\AbstractModule;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Master switch for the cache-safe data layer (issue #398). On a full-page
 * cached site the HTML built for one visitor is served to every visitor, so a
 * visitor/session value baked into the data layer leaks. With the option on,
 * modules stop rendering those values; the browser pushes what it can compute
 * itself as gtm4wp.visitorData, and server-only fields arrive via the
 * once-per-session / cookie-gated endpoint (docs/dev/cache-safe-data-layer.md),
 * under the same variable names, so existing GTM setups keep working.
 */
final class VisitorDataModule extends AbstractModule {

	/**
	 * JS-readable companion cookie mirroring the HttpOnly WordPress logged-in
	 * state: the Tier 3 gate, so an anonymous visitor never fetches user data.
	 * Maintained by maintain_login_gate_cookie().
	 */
	public const LOGIN_GATE_COOKIE = 'gtm4wp_login';

	/**
	 * The sessionStorage key the client caches the Tier 2 values and the Tier 3
	 * gate bookkeeping under; here only so PHP and the client agree.
	 */
	public const SESSION_STORAGE_KEY = 'gtm4wp_visitor_session';

	/**
	 * Data layer event names, one per data family, because the families arrive
	 * on different channels at different moments and a GTM setup must tell from
	 * the event name alone which keys arrived. Public contract: never change.
	 * These are the authority; gtm4wp-visitor-data.js carries them only as the
	 * config-less fallback.
	 */
	public const EVENT_VISITOR_DATA  = 'gtm4wp.visitorData';
	public const EVENT_CUSTOMER_DATA = 'gtm4wp.customerData';
	public const EVENT_CART_DATA     = 'gtm4wp.cartData';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'visitor-data';
	}

	/**
	 * Whether the cache-safe data layer is enabled for the given options.
	 *
	 * Shared read used by the modules that must omit their server-rendered
	 * visitor/session fields when the mode is on (PageVariables, WooCommerce).
	 *
	 * @param Options $options The plugin options service.
	 * @return bool
	 */
	public static function is_enabled( Options $options ): bool {
		return (bool) $options->get( GTM4WP_OPTION_CACHE_SAFE_DATALAYER );
	}

	/**
	 * Option defaults. Off by default: the mode is experimental and changes
	 * (omits) visitor data, so it must never turn on without the admin opting in.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_CACHE_SAFE_DATALAYER => false,
		);
	}

	/**
	 * Registers the frontend hooks. Nothing loads unless the mode is on.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! self::is_enabled( $this->options ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// REST requests run this frontend code path (is_admin() is false there).
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );

		// init runs before output (cookies settable); a logged-in page is not cached.
		add_action( 'init', array( $this, 'maintain_login_gate_cookie' ) );
	}

	/**
	 * Registers the first-party session endpoint. Hooked to rest_api_init.
	 *
	 * @return void
	 */
	public function register_endpoint(): void {
		( new VisitorDataEndpoint() )->register_routes();
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Loads the client runtime with its per-request field config when at least
	 * one field is active. The config holds only cache-safe metadata (event
	 * names, Tier 1 sources, endpoint URL, nonce, gate metadata), never a
	 * visitor value, so it is safe in cached HTML.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$config = $this->build_config();

		if ( null === $config ) {
			return;
		}

		$this->enqueue_script( 'gtm4wp-visitor-data', 'gtm4wp-visitor-data.js' );

		wp_add_inline_script(
			'gtm4wp-visitor-data',
			'var gtm4wp_visitordata_config = ' . ScriptTag::json_literal( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
		);
	}

	/**
	 * Builds the cache-safe client config from the visitor-scoped fields every
	 * module declares (through GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS), or null when
	 * there is nothing to deliver on this request (so the runtime is not loaded).
	 *
	 * @return array<string, mixed>|null
	 */
	public function build_config(): ?array {
		/**
		 * Collects the visitor-scoped fields to deliver outside the cacheable
		 * page HTML. Callbacks append VisitorField objects.
		 *
		 * @since 2.0
		 *
		 * @param VisitorField[] $fields Visitor-scoped fields declared so far.
		 *
		 * @return VisitorField[] Visitor-scoped fields for this request.
		 */
		$fields = apply_filters( GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS, array() );

		$client_fields  = array();
		$session_keys   = array();
		$gates          = array();
		$actions        = array();
		$action_confirm = array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( ! $field instanceof VisitorField ) {
					continue;
				}

				if ( VisitorField::TIER_CLIENT === $field->tier && '' !== $field->client_source ) {
					$client_fields[ $field->key ] = $field->client_source;
				} elseif ( VisitorField::TIER_SESSION === $field->tier ) {
					$session_keys[] = $field->key;
				} elseif ( VisitorField::TIER_ACTION === $field->tier && '' !== $field->cookie_gate ) {
					// One-shots go to the `actions` list: fetched only while the event
					// cookie is present, pushed once, never cached or replayed.
					if ( $field->one_shot ) {
						$actions[ $field->cookie_gate ][] = $field->key;

						// The POST beacon fired after delivery, per key, keeps the GET read-only.
						if ( '' !== $field->confirm_url ) {
							$action_confirm[ $field->cookie_gate ][ $field->key ] = $field->confirm_url;
						}
					} else {
						$gates[ $field->cookie_gate ][] = $field->key;
					}
				}
			}
		}

		$has_endpoint_fields = array() !== $session_keys || array() !== $gates || array() !== $actions;

		if ( array() === $client_fields && ! $has_endpoint_fields ) {
			return null;
		}

		// Built after the early return so the event map alone can never produce a
		// non-null config.
		$config = array(
			'events' => array(
				'visitor'  => self::EVENT_VISITOR_DATA,
				'customer' => self::EVENT_CUSTOMER_DATA,
				'cart'     => self::EVENT_CART_DATA,
			),
			'fields' => $client_fields,
		);

		if ( $has_endpoint_fields ) {
			$config['endpoint']   = rest_url( VisitorDataEndpoint::REST_NAMESPACE . VisitorDataEndpoint::REST_ROUTE );
			$config['nonce']      = wp_create_nonce( 'wp_rest' );
			$config['sessionKey'] = self::SESSION_STORAGE_KEY;

			if ( array() !== $session_keys ) {
				$config['session'] = array_values( array_unique( $session_keys ) );
			}

			if ( array() !== $gates ) {
				$config['gates'] = array();
				foreach ( $gates as $cookie => $keys ) {
					$config['gates'][] = array(
						'cookie' => $cookie,
						'keys'   => array_values( array_unique( $keys ) ),
					);
				}
			}

			if ( array() !== $actions ) {
				$config['actions'] = array();
				foreach ( $actions as $cookie => $keys ) {
					$entry = array(
						'cookie' => $cookie,
						'keys'   => array_values( array_unique( $keys ) ),
					);

					// key => beacon URL, absent when no one-shot declared one.
					if ( ! empty( $action_confirm[ $cookie ] ) ) {
						$entry['confirm'] = $action_confirm[ $cookie ];
					}

					$config['actions'][] = $entry;
				}
			}
		}

		return $config;
	}

	/**
	 * Keeps LOGIN_GATE_COOKIE in sync with the WordPress login state: set to an
	 * opaque per-session token for a logged-in user, cleared for a logged-out
	 * one. Hooked to init.
	 *
	 * @return void
	 */
	public function maintain_login_gate_cookie(): void {
		// Headers already sent by another plugin: the next logged-in request corrects it.
		if ( headers_sent() ) {
			return;
		}

		$current = isset( $_COOKIE[ self::LOGIN_GATE_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::LOGIN_GATE_COOKIE ] ) )
			: '';

		if ( is_user_logged_in() ) {
			$desired = $this->login_gate_value();

			if ( $current !== $desired ) {
				$this->set_login_gate_cookie( $desired, time() + ( 14 * DAY_IN_SECONDS ) );
				$_COOKIE[ self::LOGIN_GATE_COOKIE ] = $desired;
			}
		} elseif ( '' !== $current ) {
			// An ANONYMOUS request clearing a stale gate cookie: unlike the logged-in
			// branch its response could be cached, and a Set-Cookie must never be
			// replayed to other visitors, so mark it no-store (#44).
			nocache_headers();
			$this->set_login_gate_cookie( '', time() - DAY_IN_SECONDS );
			unset( $_COOKIE[ self::LOGIN_GATE_COOKIE ] );
		}
	}

	/**
	 * The opaque, stable-per-session value of the login gate cookie: wp_hash() of
	 * user id + session token, so a re-login triggers a re-fetch and the value
	 * cannot authenticate.
	 *
	 * @return string
	 */
	private function login_gate_value(): string {
		$token = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';

		return substr( wp_hash( get_current_user_id() . '|' . $token ), 0, 20 );
	}

	/**
	 * Sets (or, with an empty value and past expiry, clears) the login gate cookie.
	 * The cookie is deliberately NOT HttpOnly (the client must read it) and scoped
	 * like the WordPress auth cookies.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	private function set_login_gate_cookie( string $value, int $expires ): void {
		setcookie(
			self::LOGIN_GATE_COOKIE,
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
