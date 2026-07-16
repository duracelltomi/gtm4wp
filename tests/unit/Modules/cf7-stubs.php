<?php
/**
 * Minimal Contact Form 7 class stub for unit testing.
 *
 * Only the two static/instance methods the GTM4WP Contact Form 7 module calls
 * are implemented. The "current" form is injected through the static property.
 *
 * @package GTM4WP
 */

// phpcs:disable

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	class WPCF7_ContactForm {
		/**
		 * The form returned by get_current(); set by the test.
		 *
		 * @var WPCF7_ContactForm|null
		 */
		public static $current = null;

		public function __construct( private string $stub_title = '', private int $stub_id = 0 ) {}

		public static function get_current() {
			return self::$current;
		}

		public function id() {
			return $this->stub_id;
		}

		public function title() {
			return $this->stub_title;
		}
	}
}
