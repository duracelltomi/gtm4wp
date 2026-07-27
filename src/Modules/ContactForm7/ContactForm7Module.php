<?php
/**
 * Contact Form 7 module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ContactForm7;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Contact Form 7 submission tracker script.
 * Port of the CF7 part of gtm4wp_enqueue_scripts() from 1.x.
 */
final class ContactForm7Module extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'contact-form-7';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INTEGRATE_WPCF7           => false,
			GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS    => 'full',
			GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS => false,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! $this->opt( GTM4WP_OPTION_INTEGRATE_WPCF7 ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'wpcf7_form_additional_atts', array( $this, 'add_form_name_attribute' ) );
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
	 * Loads the Contact Form 7 tracker script.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_INTEGRATE_WPCF7, true );
		$this->enqueue_script( 'gtm4wp-contact-form-7-tracker', 'gtm4wp-contact-form-7-tracker.js', array(), $in_footer );

		$config = array(
			'inputs'    => (string) $this->opt( GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS ),
			'ga4events' => (bool) $this->opt( GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS ),
		);

		wp_add_inline_script(
			'gtm4wp-contact-form-7-tracker',
			'var gtm4wp_cf7_config = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
		);
	}

	/**
	 * Injects the human-readable Contact Form 7 form title onto the rendered
	 * <form> element as a data attribute, so the tracker can expose it in the
	 * data layer (the CF7 DOM events only carry the numeric form id).
	 *
	 * Hooked on wpcf7_form_additional_atts; Contact Form 7 escapes the returned
	 * attribute values with esc_attr via wpcf7_format_atts().
	 *
	 * @param array<string, string> $atts Additional <form> attributes.
	 * @return array<string, string>
	 */
	public function add_form_name_attribute( $atts ): array {
		$atts = (array) $atts;

		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return $atts;
		}

		$form = \WPCF7_ContactForm::get_current();
		if ( $form ) {
			$atts['data-gtm4wp-form-name'] = $form->title();
		}

		return $atts;
	}
}
