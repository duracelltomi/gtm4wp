<?php
/**
 * Site Health surfaces of the Google Data Manager integration.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Admin\SettingsPage;
use GTM4WP\Google\KeyVault;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the state of the send lanes where support workflows already look.
 *
 * The admin notice next to this interrupts whoever happens to open wp-admin;
 * Site Health is the page hosts and support threads send people to, and it
 * keeps the state instead of being dismissed. They complement each other
 * rather than duplicating: the notice is about a live gap, the status test is
 * a standing answer to "is this working".
 *
 * Two surfaces, wired differently. The status test is registered here, as
 * this module's own. The Info rows are not: the plugin has ONE section on the
 * Info tab, assembled by Admin\SiteHealthInfo from every module that opts in
 * through SiteHealthInfoInterface, and this module's AdminSchema is the one
 * that opts in - it asks this class for the rows (debug_fields()), which is
 * where the knowledge of what is safe to show stays.
 *
 * Both surfaces read the STORED records only. Neither ever makes an HTTP call:
 * a Site Health page load must not depend on Google being reachable, and the
 * destination panel's Test button remains the one place a live probe happens.
 *
 * ⛔ **What may not appear in the debug section.** Its text is copied wholesale
 * into public support threads by people who have no way to review it first, so
 * it carries statuses, timestamps, counts and bare code names, and nothing
 * else: no service-account address, no key material in any form, no raw error
 * text from Google (which can quote fragments of what we sent), and no GA4
 * property IDs. Destinations are named by the label the site owner chose, and
 * by their measurement ID - which is already in the site's public HTML.
 */
final class SiteHealth {

	/**
	 * Id of the status test.
	 */
	public const TEST_ID = 'gtm4wp_google_data_manager';

	/**
	 * Constructor.
	 *
	 * @param Options           $options The plugin options service.
	 * @param DestinationHealth $health  Per-destination health records.
	 * @param CaptureStats      $stats   Attribution capture-rate counters.
	 * @param KeyVault          $vault   The service-account store.
	 */
	public function __construct(
		private Options $options,
		private DestinationHealth $health,
		private CaptureStats $stats,
		private KeyVault $vault
	) {
	}

	/**
	 * Registers the status test. The Info rows reach WordPress through
	 * Admin\SiteHealthInfo, not from here.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the direct status test.
	 *
	 * @param array<string, mixed> $tests The registered tests.
	 * @return array<string, mixed>
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Google Data Manager sending', 'duracelltomi-google-tag-manager' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Runs the status test.
	 *
	 * Three outcomes, in order of severity:
	 *
	 * - **critical** when a stored service-account key can no longer be
	 *   decrypted - that kills every destination using it at once, and it
	 *   happens without anyone touching the plugin; rotating the security keys
	 *   in wp-config.php is enough - and when a destination has failed
	 *   repeatedly. Both are the same outcome for the store: events it was set
	 *   up to send are being lost, right now, with every refund. The key case
	 *   is listed first only because it outranks the other when both hold. It
	 *   was "recommended" once, while the admin notice for the very same
	 *   condition was red, site-wide and not dismissible; Site Health's
	 *   "recommended" bucket is for improvements, and a configured lane that
	 *   is dropping data is not an improvement waiting to be made.
	 * - **recommended** when attribution capture is on and orders are arriving
	 *   with nothing being captured. This is the failure the feature is
	 *   otherwise silent about - a measurement ID that matches no tag in the
	 *   container produces no error anywhere, and would first be noticed weeks
	 *   later as refunds that could not be sent - but it is an inference from
	 *   a run of orders, not a refusal Google answered with, so it asks for a
	 *   look rather than declaring a breakage.
	 * - **good** otherwise, including when nothing is turned on.
	 *
	 * @return array<string, mixed>
	 */
	public function run_test(): array {
		$result = array(
			'label'       => __( 'Google Data Manager is working', 'duracelltomi-google-tag-manager' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The data this site sends to Google from the server is going through, or nothing is set up to be sent.', 'duracelltomi-google-tag-manager' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST_ID,
		);

		$unreadable = $this->vault->unreadable();

		if ( array() !== $unreadable ) {
			return $this->problem(
				$result,
				'critical',
				__( 'A Google service account key can no longer be read', 'duracelltomi-google-tag-manager' ),
				esc_html__( 'The stored key of at least one Google service account can no longer be decrypted, which happens when the security keys in wp-config.php are changed. Every destination using that account has stopped sending. Upload the key file again to fix it.', 'duracelltomi-google-tag-manager' )
			);
		}

		$failing = $this->failing_destinations();

		if ( array() !== $failing ) {
			return $this->problem(
				$result,
				'critical',
				__( 'Sending to a Google Data Manager destination keeps failing', 'duracelltomi-google-tag-manager' ),
				sprintf(
					/* translators: %s: comma separated list of destination names. */
					esc_html__( 'Events are not reaching these destinations: %s. Check that the service account still has access to the Google Analytics property, then use the Test button of the destination to see the reason.', 'duracelltomi-google-tag-manager' ),
					esc_html( implode( ', ', $failing ) )
				)
			);
		}

		if ( $this->options->get( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) && $this->stats->is_failing() ) {
			$record = $this->stats->get();

			return $this->problem(
				$result,
				'recommended',
				__( 'No attribution data is being captured', 'duracelltomi-google-tag-manager' ),
				sprintf(
					esc_html(
						/* translators: %d: number of recent orders. */
						_n(
							'Attribution data was stored on none of the last %d order. Without it a refund cannot be matched to its purchase in Google Analytics, so nothing about those orders can be sent. Check that a Google Analytics tag really fires in your container for the measurement ID of your destination.',
							'Attribution data was stored on none of the last %d orders. Without it a refund cannot be matched to its purchase in Google Analytics, so nothing about those orders can be sent. Check that a Google Analytics tag really fires in your container for the measurement ID of your destination.',
							$record['seen'],
							'duracelltomi-google-tag-manager'
						)
					),
					(int) $record['seen']
				)
			);
		}

		return $result;
	}

	/**
	 * This module's rows of the plugin's Site Health Info section.
	 *
	 * The keys are module-local; the collector prefixes them with the module
	 * id. Everything the class doc block forbids is forbidden here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function debug_fields(): array {
		$fields = array(
			'gdm_capture_attribution' => array(
				'label' => __( 'Attribution capture', 'duracelltomi-google-tag-manager' ),
				'value' => $this->on_off( (bool) $this->options->get( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) ),
			),
			'gdm_send_refunds'        => array(
				'label' => __( 'Refund sending', 'duracelltomi-google-tag-manager' ),
				'value' => $this->on_off( (bool) $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ),
			),
			'gdm_consent_policy'      => array(
				'label' => __( 'Consent requirement', 'duracelltomi-google-tag-manager' ),
				'value' => (string) $this->options->get( GTM4WP_OPTION_GDM_CONSENT_POLICY ),
			),
			'gdm_capture_rate'        => array(
				'label' => __( 'Attribution captured', 'duracelltomi-google-tag-manager' ),
				'value' => $this->capture_rate(),
			),
			'gdm_queue'               => array(
				'label' => __( 'Queued sends', 'duracelltomi-google-tag-manager' ),
				'value' => sprintf(
					/* translators: 1: number of queued sends. 2: number of queued status checks. 3: name of the queue backend. */
					__( '%1$d to send, %2$d status checks pending (%3$s)', 'duracelltomi-google-tag-manager' ),
					SendQueue::pending( SendQueue::HOOK_SEND ),
					SendQueue::pending( SendQueue::HOOK_STATUS ),
					SendQueue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron'
				),
			),
		);

		foreach ( $this->vault->all() as $index => $account ) {
			// The account is named by the label its owner typed and by nothing
			// else. Its client_email is an identifier of their Google Cloud
			// project and has no business in a section built to be pasted in
			// public.
			$fields[ 'gdm_account_' . $index ] = array(
				'label' => __( 'Google service account', 'duracelltomi-google-tag-manager' ),
				'value' => sprintf(
					/* translators: 1: account label. 2: status word. */
					__( '%1$s: %2$s', 'duracelltomi-google-tag-manager' ),
					(string) $account['label'],
					(string) $account['status']
				),
			);
		}

		foreach ( $this->destination_rows() as $index => $row ) {
			$fields[ 'gdm_destination_' . $index ] = array(
				'label' => __( 'Data Manager destination', 'duracelltomi-google-tag-manager' ),
				'value' => $row,
			);
		}

		return $fields;
	}

	/**
	 * One text line per configured destination: what it is called, which data
	 * stream it is, and how it has been doing.
	 *
	 * @return string[]
	 */
	private function destination_rows(): array {
		$records = $this->health->all();
		$rows    = array();

		foreach ( $this->stored_rows() as $row ) {
			$measurement = $row[ DestinationRows::COLUMN_MEASUREMENT ];
			$label       = ( '' !== $row[ DestinationRows::COLUMN_LABEL ] ) ? $row[ DestinationRows::COLUMN_LABEL ] : $measurement;
			$record      = $records[ $measurement ] ?? null;

			if ( null === $record ) {
				$rows[] = sprintf(
					/* translators: 1: destination label. 2: destination type. 3: measurement ID. */
					__( '%1$s (%2$s, %3$s): nothing sent yet', 'duracelltomi-google-tag-manager' ),
					$label,
					$row[ DestinationRows::COLUMN_TYPE ],
					$measurement
				);

				continue;
			}

			$rows[] = sprintf(
				/* translators: 1: destination label. 2: destination type. 3: measurement ID. 4: date of the last success, or a dash. 5: number of failures in a row. 6: reason code of the last failure, or a dash. */
				__( '%1$s (%2$s, %3$s): last success %4$s, %5$d failures in a row, last reason %6$s', 'duracelltomi-google-tag-manager' ),
				$label,
				$row[ DestinationRows::COLUMN_TYPE ],
				$measurement,
				$this->stamp( $record['last_success'] ),
				(int) $record['consecutive_failures'],
				( '' !== $record['last_error_class'] ) ? $record['last_error_class'] : '-'
			);
		}

		return $rows;
	}

	/**
	 * The configured destinations that are failing, by the name the settings
	 * screen shows them under.
	 *
	 * @return string[]
	 */
	private function failing_destinations(): array {
		$failing = array();

		foreach ( $this->stored_rows() as $row ) {
			$measurement = $row[ DestinationRows::COLUMN_MEASUREMENT ];

			if ( ( '' === $measurement ) || ! $this->health->is_failing( $measurement ) ) {
				continue;
			}

			$failing[] = ( '' !== $row[ DestinationRows::COLUMN_LABEL ] ) ? $row[ DestinationRows::COLUMN_LABEL ] : $measurement;
		}

		return $failing;
	}

	/**
	 * The stored destination rows, normalized.
	 *
	 * The stored list rather than the filtered runtime one, for the reason the
	 * health notice gives: both surfaces point the admin at the settings table,
	 * and naming a row a third-party filter injected would send them looking
	 * for something that is not there.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function stored_rows(): array {
		$stored = $this->options->get( GTM4WP_OPTION_GDM_DESTINATIONS );
		$rows   = array();

		if ( ! is_array( $stored ) ) {
			return $rows;
		}

		foreach ( $stored as $raw_row ) {
			if ( is_array( $raw_row ) ) {
				$rows[] = DestinationRows::normalize_row( $raw_row );
			}
		}

		return $rows;
	}

	/**
	 * The capture-rate line: how many of the recent orders carried attribution.
	 *
	 * @return string
	 */
	private function capture_rate(): string {
		$record = $this->stats->get();

		if ( 0 === $record['seen'] ) {
			return __( 'no orders seen yet', 'duracelltomi-google-tag-manager' );
		}

		return sprintf(
			/* translators: 1: number of orders with attribution. 2: number of orders seen. 3: date of the last capture, or a dash. */
			__( '%1$d of the last %2$d orders, last on %3$s', 'duracelltomi-google-tag-manager' ),
			(int) $record['captured'],
			(int) $record['seen'],
			$this->stamp( $record['last_captured_at'] )
		);
	}

	/**
	 * A stored Unix timestamp as a date, or a dash when there is none.
	 *
	 * @param int $timestamp Unix time, 0 for never.
	 * @return string
	 */
	private function stamp( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '-';
		}

		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}

	/**
	 * A boolean option as a word.
	 *
	 * @param bool $value The option value.
	 * @return string
	 */
	private function on_off( bool $value ): string {
		return $value
			? __( 'on', 'duracelltomi-google-tag-manager' )
			: __( 'off', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Turns the good result into a problem result, with a link to the settings.
	 *
	 * @param array<string, mixed> $result      The good result.
	 * @param string               $status      Site Health status.
	 * @param string               $label       Result headline.
	 * @param string               $description Escaped description text.
	 * @return array<string, mixed>
	 */
	private function problem( array $result, string $status, string $label, string $description ): array {
		$result['status']      = $status;
		$result['label']       = $label;
		$result['description'] = '<p>' . $description . '</p>';
		$result['actions']     = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( SettingsPage::url( GTM4WP_OPTION_GDM_DESTINATIONS ) ),
			esc_html__( 'Open the Google Data Manager settings', 'duracelltomi-google-tag-manager' )
		);

		return $result;
	}
}
