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

use GTM4WP\Module\AbstractModule;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Master switch for the cache-safe data layer (issue #398).
 *
 * On full-page-cached sites the HTML built for one visitor is served to every
 * visitor, so any visitor/session specific value baked into the data layer HTML
 * leaks (a logged-in editor's page cached with their email, then served to
 * anonymous visitors). When this module's option is on, other modules stop
 * rendering those values into the cacheable HTML and instead the browser pushes
 * what it can compute itself as a gtm4wp.visitorData data layer event — no
 * network request, no leak (Phase 1). Server-only visitor fields (IP, country,
 * user, cart) are omitted in Phase 1 and delivered client-side in Phase 2 via a
 * once-per-session / cookie-gated endpoint (see docs/dev/cache-safe-data-layer.md).
 *
 * Mirrors the ClientDeviceData module pattern: a small client script pushes the
 * values shortly after page load with the same data layer variable names 1.x /
 * the server path used, so existing Google Tag Manager setups keep working.
 */
final class VisitorDataModule extends AbstractModule {

	/**
	 * Name of the JS-readable companion cookie that mirrors the (HttpOnly, so
	 * JS-invisible) WordPress logged-in state. The client runtime uses it as the
	 * Tier 3 gate for the logged-in-user fields: it re-fetches only when this
	 * cookie changed, so an anonymous visitor — who never has it — never fetches
	 * user data. Set on login, cleared on logout by maintain_login_gate_cookie().
	 */
	public const LOGIN_GATE_COOKIE = 'gtm4wp_login';

	/**
	 * Storage key shared with the client runtime: the sessionStorage entry under
	 * which the client caches the Tier 2 (once-per-session) values and the Tier 3
	 * cookie-gate bookkeeping. Kept here only so PHP and the client agree on the name.
	 */
	public const SESSION_STORAGE_KEY = 'gtm4wp_visitor_session';

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

		// The session endpoint that delivers the Tier 2/3 fields (registered on REST
		// requests, which run this frontend code path — is_admin() is false there).
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );

		// Maintain the JS-readable login gate cookie so the client can tell logged-in
		// from anonymous without reading the HttpOnly WordPress auth cookie. init runs
		// before output (cookies settable) and for logged-in users the page is not
		// cached, so the Set-Cookie never lands on a cacheable response.
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
	 * Loads the client-side visitor-data runtime with its per-request field
	 * config, but only when at least one field is active on this request. The
	 * config carries only cache-safe (content/URL-derived, not visitor) information
	 * — which data layer keys the browser computes and from which source (Tier 1),
	 * plus the session-endpoint URL, its nonce and the field/cookie-gate metadata
	 * for Tier 2/3 — so it is safe to bake into cached HTML. No visitor value is in
	 * the config; those come from the endpoint at runtime.
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
			'var gtm4wp_visitordata_config = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
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
					// One-shot events (Phase 3) are delivered differently from a
					// persistent gate: fetched only while the event cookie is present,
					// pushed once with a de-dupe guard, then the cookie is cleared and
					// the value is never cached. Route them to the `actions` list so the
					// client runtime handles them separately from the replayed gates.
					if ( $field->one_shot ) {
						$actions[ $field->cookie_gate ][] = $field->key;

						// A one-shot may carry an authenticated POST-beacon URL the client
						// fires after delivery (e.g. the reliable-purchase fallback flagging
						// its order tracked, issue #398). Surface it per key so the client
						// keeps the GET read-only.
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

		$config = array(
			'event'  => 'gtm4wp.visitorData',
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

					// The client fires each mapped key's beacon (key => URL) after
					// delivering that one-shot event; absent when no one-shot on this
					// cookie declared a confirm URL.
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
	 * Keeps the JS-readable login gate cookie (self::LOGIN_GATE_COOKIE) in sync with
	 * the WordPress login state so the client runtime can gate the Tier 3 user-data
	 * fetch on it: it is set (to an opaque per-session token) for a logged-in user
	 * and cleared for a logged-out one. The client re-fetches only when the value
	 * changed, so an anonymous visitor — who never has the cookie — never fetches
	 * user data. Hooked to init; only runs when the cache-safe mode is on.
	 *
	 * @return void
	 */
	public function maintain_login_gate_cookie(): void {
		// Cookies can only be set before output. If a plugin already sent headers by
		// init, skip silently — the next (logged-in, non-cached) request corrects it.
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
			// Logged out: clear the cookie so the client stops treating the visitor as
			// logged in and does not push stale user data.
			$this->set_login_gate_cookie( '', time() - DAY_IN_SECONDS );
			unset( $_COOKIE[ self::LOGIN_GATE_COOKIE ] );
		}
	}

	/**
	 * The opaque, stable-per-session value of the login gate cookie. Derived with
	 * wp_hash() from the current user id and session token so it (a) differs between
	 * users and login sessions — a re-login triggers a re-fetch — and (b) is not the
	 * session token itself, so it cannot be used to authenticate.
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
