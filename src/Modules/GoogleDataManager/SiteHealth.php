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
use GTM4WP\Admin\SiteHealthRows;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The state of the send lanes in Site Health, the page support threads send
 * people to (the admin notice is about a live gap; this is the standing
 * answer to "is this working"). The rows and the test reach WordPress
 * through this module's AdminSchema and the Admin collectors. Both read
 * STORED records only, never an HTTP call.
 *
 * ⛔ The Info section is pasted into public support threads: statuses,
 * timestamps, counts and bare code names only. No raw Google error text, no
 * GA4 property IDs; a destination is named by its label and measurement ID
 * (already in the public HTML). The service-account rows are GoogleAuth's.
 */
final class SiteHealth {

	/**
	 * Id of the sending test, as Admin\SiteHealthTests derives it.
	 */
	public const TEST_ID = 'gtm4wp_google_data_manager_sending';

	/**
	 * Module-local key of the sending test.
	 */
	public const TEST_KEY = 'sending';

	/**
	 * Constructor.
	 *
	 * @param Options           $options The plugin options service.
	 * @param DestinationHealth $health  Per-destination health records.
	 * @param CaptureStats      $stats   Attribution capture-rate counters.
	 */
	public function __construct(
		private Options $options,
		private DestinationHealth $health,
		private CaptureStats $stats
	) {
	}

	/**
	 * The sending test: **critical** when a destination has failed repeatedly
	 * (events are being lost now); **recommended** when capture is on and
	 * orders arrive with nothing captured (the otherwise silent failure, an
	 * inference rather than a refusal); **good** otherwise, including when
	 * nothing is turned on.
	 *
	 * @return array<string, mixed>
	 */
	public function run_test(): array {
		$result = array(
			'label'       => __( 'Google Data Manager is working', 'duracelltomi-google-tag-manager' ),
			'status'      => 'good',
			'description' => '<p>' . esc_html__( 'The data this site sends to Google from the server is going through, or nothing is set up to be sent.', 'duracelltomi-google-tag-manager' ) . '</p>',
			'actions'     => '',
		);

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
	 * This module's rows of the plugin's Site Health Info section, under
	 * module-local keys the collector prefixes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function debug_fields(): array {
		$record = $this->stats->get();
		$queue  = array(
			SendQueue::pending( SendQueue::HOOK_SEND ),
			SendQueue::pending( SendQueue::HOOK_STATUS ),
			SendQueue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron',
		);

		$fields = array(
			'capture_attribution' => SiteHealthRows::on_off( __( 'Attribution capture', 'duracelltomi-google-tag-manager' ), (bool) $this->options->get( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) ),
			'send_refunds'        => SiteHealthRows::on_off( __( 'Refund sending', 'duracelltomi-google-tag-manager' ), (bool) $this->options->get( GTM4WP_OPTION_GDM_SEND_REFUNDS ) ),
			'consent_policy'      => SiteHealthRows::text( __( 'Consent requirement', 'duracelltomi-google-tag-manager' ), (string) $this->options->get( GTM4WP_OPTION_GDM_CONSENT_POLICY ) ),
			'capture_rate'        => SiteHealthRows::text(
				__( 'Attribution captured', 'duracelltomi-google-tag-manager' ),
				( 0 === $record['seen'] )
					? __( 'no orders seen yet', 'duracelltomi-google-tag-manager' )
					: sprintf(
						/* translators: 1: number of orders with attribution. 2: number of orders seen. 3: date of the last capture, or a dash. */
						__( '%1$d of the last %2$d orders, last on %3$s', 'duracelltomi-google-tag-manager' ),
						(int) $record['captured'],
						(int) $record['seen'],
						SiteHealthRows::stamp( $record['last_captured_at'] )
					),
				( 0 === $record['seen'] )
					? 'no orders seen yet'
					: sprintf( '%1$d of the last %2$d orders, last on %3$s', (int) $record['captured'], (int) $record['seen'], SiteHealthRows::stamp( $record['last_captured_at'] ) )
			),
			'queue'               => SiteHealthRows::text(
				__( 'Queued sends', 'duracelltomi-google-tag-manager' ),
				/* translators: 1: number of queued sends. 2: number of queued status checks. 3: name of the queue backend. */
				sprintf( __( '%1$d to send, %2$d status checks pending (%3$s)', 'duracelltomi-google-tag-manager' ), ...$queue ),
				sprintf( '%1$d to send, %2$d status checks pending (%3$s)', ...$queue )
			),
		);

		foreach ( $this->destination_rows() as $index => $row ) {
			$fields[ 'destination_' . $index ] = SiteHealthRows::text( __( 'Data Manager destination', 'duracelltomi-google-tag-manager' ), $row[0], $row[1] );
		}

		return $fields;
	}

	/**
	 * One line per configured destination - what it is called, which data
	 * stream it is, how it has been doing - translated and in English.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function destination_rows(): array {
		$records = $this->health->all();
		$rows    = array();

		foreach ( $this->stored_rows() as $row ) {
			$measurement = $row[ DestinationRows::COLUMN_MEASUREMENT ];
			$label       = ( '' !== $row[ DestinationRows::COLUMN_LABEL ] ) ? $row[ DestinationRows::COLUMN_LABEL ] : $measurement;
			$record      = $records[ $measurement ] ?? null;

			if ( null === $record ) {
				$rows[] = array(
					/* translators: 1: destination label. 2: destination type. 3: measurement ID. */
					sprintf( __( '%1$s (%2$s, %3$s): nothing sent yet', 'duracelltomi-google-tag-manager' ), $label, $row[ DestinationRows::COLUMN_TYPE ], $measurement ),
					sprintf( '%1$s (%2$s, %3$s): nothing sent yet', $label, $row[ DestinationRows::COLUMN_TYPE ], $measurement ),
				);

				continue;
			}

			$facts = array(
				$label,
				$row[ DestinationRows::COLUMN_TYPE ],
				$measurement,
				SiteHealthRows::stamp( $record['last_success'] ),
				(int) $record['consecutive_failures'],
				( '' !== $record['last_error_class'] ) ? $record['last_error_class'] : '-',
			);

			$rows[] = array(
				/* translators: 1: destination label. 2: destination type. 3: measurement ID. 4: date of the last success, or a dash. 5: number of failures in a row. 6: reason code of the last failure, or a dash. */
				sprintf( __( '%1$s (%2$s, %3$s): last success %4$s, %5$d failures in a row, last reason %6$s', 'duracelltomi-google-tag-manager' ), ...$facts ),
				sprintf( '%1$s (%2$s, %3$s): last success %4$s, %5$d failures in a row, last reason %6$s', ...$facts ),
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
	 * The stored destination rows, normalized: the stored list, not the
	 * filtered runtime one, because this points the admin at the settings table.
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
