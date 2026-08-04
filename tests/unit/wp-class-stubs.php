<?php
/**
 * Minimal WordPress class stubs for unit testing.
 *
 * @package GTM4WP
 */

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $slug;
		public $name;

		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private array $headers = array();

		public function __construct( private $data = null, private int $status = 200 ) {}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private array $params = array(), private array $headers = array() ) {}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ) {
			// WordPress normalizes header names (case-insensitive, '-'/'_' equivalent).
			$normalized = strtolower( str_replace( '-', '_', $key ) );
			foreach ( $this->headers as $name => $value ) {
				if ( strtolower( str_replace( '-', '_', (string) $name ) ) === $normalized ) {
					return $value;
				}
			}
			return null;
		}

		/**
		 * The route this request was matched to, e.g. /gtm4wp/v2/visitor-data. Real
		 * requests carry it; code that scopes a rest_pre_serve_request filter to its
		 * own namespace reads it.
		 *
		 * @var string
		 */
		private string $route = '';

		public function set_route( string $route ): void {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

// phpcs:enable
