<?php
/**
 * Google Data Manager module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\KeyVault;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\PanelSchemaInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Modules\GoogleAuth\GoogleAuthModule;
use GTM4WP\Options\Field;
use GTM4WP\Options\Options;
use GTM4WP\RestCors;

defined( 'ABSPATH' ) || exit;

/**
 * The destinations table plus the custom test panel below it - the first
 * schema mixing Field controls with a PanelSchemaInterface panel.
 *
 * Cost discipline: fields() is walked by the settings REST controller's
 * value schema on EVERY REST request site-wide, so nothing in fields() may
 * read the database. The service-account choices of the select column come
 * through panel_data() instead (columnChoices), which only runs when the
 * settings page itself is rendered - the same cost class as the key notice.
 */
final class AdminSchema implements AdminSchemaInterface, DocumentedSchemaInterface, PanelSchemaInterface, SiteHealthInfoInterface {

	/**
	 * Documentation page of this module on gtm4wp.com.
	 */
	private const DOC_PAGE = 'setup-gtm4wp-features/google-data-manager';

	/**
	 * Id of the React component rendering the test panel, defined in
	 * js/admin/components/panels/index.js.
	 */
	public const PANEL = 'gdm-destinations';

	/**
	 * Accordion group of the destinations table and its test panel.
	 */
	public const GROUP_DESTINATIONS = 'destinations';

	/**
	 * Accordion group of the attribution-capture settings.
	 */
	public const GROUP_ATTRIBUTION = 'attribution';

	/**
	 * Accordion group of the server-side send lanes.
	 */
	public const GROUP_SENDING = 'sending';

	/**
	 * Module documentation page.
	 *
	 * @return string
	 */
	public function doc_url(): string {
		return self::DOC_PAGE;
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Google Data Manager', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return '<p>' . esc_html__(
			'The Google Data Manager API lets this site send e-commerce signals to Google from the server - most importantly signals the browser never sees, such as refunds issued in the store admin. Define where the data should go, verify that Google accepts it, then turn on the signals you want sent. Nothing leaves the site until you do: every option here is off by default.',
			'duracelltomi-google-tag-manager'
		) . '</p><p>' . sprintf(
			/* translators: 1: opening anchor tag linking to the Google service accounts section. 2: closing anchor tag. */
			esc_html__(
				'Every destination sends with a stored %1$sGoogle service account%2$s, so upload a key file there first, then add the service account to your Google Analytics 4 property with the Editor role. Use the Test button of a destination to check the whole chain: the key, the API being enabled in your Google Cloud project, and the account\'s access to the property.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="#' . esc_attr( GoogleAuthModule::ID ) . '">',
			'</a>'
		) . '</p>';
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			self::GROUP_DESTINATIONS => __( 'Destinations', 'duracelltomi-google-tag-manager' ),
			self::GROUP_ATTRIBUTION  => __( 'Attribution capture', 'duracelltomi-google-tag-manager' ),
			self::GROUP_SENDING      => __( 'Sending events', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return array(
			new Field(
				key: GTM4WP_OPTION_GDM_DESTINATIONS,
				type: Field::TYPE_TABLE,
				default_value: array(),
				label: __( 'Data Manager destinations', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Add one row for each Google Analytics 4 property the plugin should be able to send events to. The property ID is the numeric ID shown in the GA admin, the measurement ID (G-XXXXXXX) identifies the web data stream of that property. The service account of the row must be added to the property with the Editor role, and the Data Manager API must be enabled in the Google Cloud project the account belongs to - the Test button below the table checks all of that with a validation request that stores nothing on the Google side.',
					'duracelltomi-google-tag-manager'
				),
				group: self::GROUP_DESTINATIONS,
				phase: Field::PHASE_EXPERIMENTAL,
				columns: array(
					array(
						'key'         => DestinationRows::COLUMN_LABEL,
						'label'       => __( 'Label', 'duracelltomi-google-tag-manager' ),
						'placeholder' => __( 'Optional name', 'duracelltomi-google-tag-manager' ),
					),
					array(
						'key'     => DestinationRows::COLUMN_ACCOUNT,
						'label'   => __( 'Service account', 'duracelltomi-google-tag-manager' ),
						'type'    => 'select',
						// Choices arrive through panel_data()['columnChoices']
						// at settings-page load; an empty list here means "none
						// uploaded yet" and the panel says so in words.
						'choices' => array(),
					),
					array(
						'key'     => DestinationRows::COLUMN_TYPE,
						'label'   => __( 'Type', 'duracelltomi-google-tag-manager' ),
						'type'    => 'select',
						'default' => DestinationRows::TYPE_GA4,
						'choices' => self::type_choices(),
					),
					array(
						'key'             => DestinationRows::COLUMN_PROPERTY,
						'label'           => __( 'GA4 property ID', 'duracelltomi-google-tag-manager' ),
						'placeholder'     => '123456789',
						// The save-time rule (PROPERTY_PATTERN), handed to the
						// table so a wrong value is marked while typing.
						'pattern'         => '^' . DestinationRows::PROPERTY_PATTERN_BODY . '$',
						'invalid_message' => __( 'The property ID is the all-numeric ID shown in the Google Analytics admin - not the G-XXXXXXX measurement ID.', 'duracelltomi-google-tag-manager' ),
					),
					array(
						'key'             => DestinationRows::COLUMN_MEASUREMENT,
						'label'           => __( 'Measurement ID', 'duracelltomi-google-tag-manager' ),
						'placeholder'     => 'G-XXXXXXX',
						'pattern'         => '^' . DestinationRows::MEASUREMENT_PATTERN_BODY . '$',
						'invalid_message' => __( 'The measurement ID starts with G-, as shown for the web data stream in the Google Analytics admin.', 'duracelltomi-google-tag-manager' ),
					),
				),
				sanitizer: static function ( $value ) {
					return self::sanitize_destinations( $value );
				},
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Store attribution data with each order', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Stores the Google Analytics client and session IDs, the Google Ads click IDs (gclid, gbraid, wbraid) of the visit and the consent state with every new order, so that events sent later from the server - a refund, for example - can be matched to the original purchase in Google Analytics. The IDs are read through the official Google tag API, so this needs at least one destination on the Destinations tab whose measurement ID belongs to a Google Analytics 4 tag that actually fires in your container: without that the browser never answers and nothing is stored. Turning this on loads a small script on every page of the site and writes two first-party cookies (gtm4wp_gdm_ids and gtm4wp_gdm_consent, 90 days) in every visitor\'s browser, not only for those who reach the checkout - the second one records the visitor\'s consent answer and is written even when that answer is no, because it is what the consent rule below is then read against. Mention both in your cookie notice. The stored values are written into the order and are never shown on the site.',
					'duracelltomi-google-tag-manager'
				),
				group: self::GROUP_ATTRIBUTION,
				phase: Field::PHASE_EXPERIMENTAL,
				depends_on: GTM4WP_OPTION_GDM_DESTINATIONS,
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_GDM_CONSENT_POLICY,
				type: Field::TYPE_SELECT,
				default_value: ConsentPolicy::POLICY_EEA_ONLY,
				label: __( 'Require consent before sending', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Decides for which orders the stored consent state has to allow analytics storage before anything about them is sent to Google. The billing country of the order decides the region, which is more reliable than guessing from the visitor\'s IP address. Choosing "Never" means you assert your own lawful basis for the transfer, so the plugin sends whatever it holds regardless of the stored answer - pick it only if that is a decision you have made deliberately. It governs sending, not collecting, and there is one thing it cannot do: an order whose buyer refused analytics storage has no client ID stored at all, because the browser was never allowed to keep one, so nothing can be sent for it under any rule here. Where this setting does make the difference is an order whose consent answer was never recorded - a visitor who ordered before your banner loaded, or a site running no consent tool at all.',
					'duracelltomi-google-tag-manager'
				),
				group: self::GROUP_ATTRIBUTION,
				phase: Field::PHASE_EXPERIMENTAL,
				choices: self::consent_policy_choices(),
				// The chain the feature actually has: a destination gives
				// capture a measurement ID to ask about, and capture gives this
				// gate a consent state to read. With capture off nothing is
				// ever stored for it to decide on.
				depends_on: GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION,
				doc: self::DOC_PAGE
			),
			new Field(
				key: GTM4WP_OPTION_GDM_SEND_REFUNDS,
				type: Field::TYPE_CHECKBOX,
				default_value: false,
				label: __( 'Send refunds to Google Analytics', 'duracelltomi-google-tag-manager' ),
				description: esc_html__(
					'Sends a refund event to every destination on the Destinations tab whenever a refund is issued in your store, so that Google Analytics stops counting revenue you have given back. This is the signal browser-side tracking can never report: a refund happens in the store admin, where no page is loaded and no tag fires. Every refund reports its own amount, the items it covers and the shipping and tax it returned, whether it returns a single line or the whole order. Nothing is sent for an order that attribution capture stored no client ID for, or where the consent rule on the Attribution capture tab does not allow it - the Recent sends list below the destinations table shows the reason in each case. Sending happens in the background a minute after the refund, so issuing one is never slowed down by it.',
					'duracelltomi-google-tag-manager'
				),
				group: self::GROUP_SENDING,
				phase: Field::PHASE_EXPERIMENTAL,
				// The chain in full: a destination to send to, and capture to
				// have a client id to send with. Without the second one every
				// refund would be skipped for want of an identifier.
				depends_on: GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION,
				doc: self::DOC_PAGE
			),
		);
	}

	/**
	 * Choices of the consent policy select.
	 *
	 * @return array<string, string>
	 */
	public static function consent_policy_choices(): array {
		return array(
			ConsentPolicy::POLICY_EEA_ONLY => __( 'Only for buyers in the EEA, the UK and Switzerland (recommended)', 'duracelltomi-google-tag-manager' ),
			ConsentPolicy::POLICY_ALWAYS   => __( 'For every order', 'duracelltomi-google-tag-manager' ),
			ConsentPolicy::POLICY_NEVER    => __( 'Never - I assert my own lawful basis', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Destination type choices. One entry today; Google Ads joins as a new
	 * choice, not a new table shape.
	 *
	 * @return array<string, string>
	 */
	public static function type_choices(): array {
		return array(
			DestinationRows::TYPE_GA4 => __( 'Google Analytics 4', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Save-time sanitizer of the destinations table.
	 *
	 * Rejecting, not repairing (the container-table discipline): a row that
	 * cannot send is named with the reason instead of being stored broken or
	 * silently dropped. Rows where every cell the user fills is empty are
	 * dropped silently - that is the untouched "Add row" state, which arrives
	 * with the type select's seeded default and nothing else (RI-28), so the
	 * emptiness test must ignore the type column. Error messages number rows
	 * as the screen shows them, dropped rows included.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	private static function sanitize_destinations( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows      = array();
		$seen_ids  = array();
		$vault     = null;
		$row_index = 0;

		foreach ( $value as $raw_row ) {
			if ( ! is_array( $raw_row ) ) {
				continue;
			}

			$row = DestinationRows::normalize_row( $raw_row );

			++$row_index;

			$user_cells = $row;
			unset( $user_cells[ DestinationRows::COLUMN_TYPE ] );

			if ( '' === implode( '', $user_cells ) ) {
				continue;
			}

			$row[ DestinationRows::COLUMN_LABEL ] = mb_substr(
				sanitize_text_field( $row[ DestinationRows::COLUMN_LABEL ] ),
				0,
				DestinationRows::LABEL_MAX_LENGTH
			);

			$row_name = ( '' !== $row[ DestinationRows::COLUMN_LABEL ] )
				? $row[ DestinationRows::COLUMN_LABEL ]
				: (string) $row_index;

			if ( ! in_array( $row[ DestinationRows::COLUMN_TYPE ], DestinationRows::TYPES, true ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_invalid_type',
					sprintf(
						/* translators: %s: the label (or number) of the destination row with the invalid value. */
						__( 'Unknown destination type in destination row %s.', 'duracelltomi-google-tag-manager' ),
						$row_name
					)
				);
			}

			if ( 1 !== preg_match( DestinationRows::ACCOUNT_PATTERN, $row[ DestinationRows::COLUMN_ACCOUNT ] ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_missing_account',
					sprintf(
						/* translators: %s: the label (or number) of the destination row with the invalid value. */
						__( 'Please pick the Google service account of destination row %s. Upload one in the Google service accounts section first if the list is empty.', 'duracelltomi-google-tag-manager' ),
						$row_name
					)
				);
			}

			// One vault read per save, and only when a row actually names an
			// account - never on the empty-table path.
			if ( null === $vault ) {
				$vault = new KeyVault();
			}

			if ( ! $vault->has( $row[ DestinationRows::COLUMN_ACCOUNT ] ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_unknown_account',
					sprintf(
						/* translators: %s: the label (or number) of the destination row with the invalid value. */
						__( 'The service account selected in destination row %s no longer exists. Please pick another one.', 'duracelltomi-google-tag-manager' ),
						$row_name
					)
				);
			}

			if ( 1 !== preg_match( DestinationRows::PROPERTY_PATTERN, $row[ DestinationRows::COLUMN_PROPERTY ] ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_invalid_property',
					sprintf(
						/* translators: %s: the label (or number) of the destination row with the invalid value. */
						__( 'Invalid GA4 property ID in destination row %s. Enter the numeric property ID shown in the Google Analytics admin.', 'duracelltomi-google-tag-manager' ),
						$row_name
					)
				);
			}

			if ( 1 !== preg_match( DestinationRows::MEASUREMENT_PATTERN, $row[ DestinationRows::COLUMN_MEASUREMENT ] ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_invalid_measurement',
					sprintf(
						/* translators: %s: the label (or number) of the destination row with the invalid value. */
						__( 'Invalid measurement ID in destination row %s. It should have the format G-XXXXXXX, as shown for the web data stream in the Google Analytics admin.', 'duracelltomi-google-tag-manager' ),
						$row_name
					)
				);
			}

			$measurement = $row[ DestinationRows::COLUMN_MEASUREMENT ];
			if ( isset( $seen_ids[ $measurement ] ) ) {
				return new \WP_Error(
					'gtm4wp_gdm_duplicate_measurement',
					sprintf(
						/* translators: %s: the duplicated measurement ID. */
						__( 'The measurement ID %s is listed in more than one destination row. Every data stream can only be entered once.', 'duracelltomi-google-tag-manager' ),
						$measurement
					)
				);
			}
			$seen_ids[ $measurement ] = true;

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The module is always available - an empty destination list is the
	 * onboarding state, not a missing dependency.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}

	/**
	 * The React panel component rendered below the fields.
	 *
	 * @return string
	 */
	public function panel(): string {
		return self::PANEL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The rows themselves live in SiteHealth next to the status test, which
	 * reads the same records: what is safe to show is decided once, there.
	 * Runs only when the Site Health page is rendered, so the reads are in
	 * the same cost class as panel_data().
	 *
	 * @param Options $options The plugin options service.
	 * @return array<string, array<string, mixed>>
	 */
	public function site_health_info( Options $options ): array {
		return ( new SiteHealth( $options, new DestinationHealth(), new CaptureStats(), new KeyVault() ) )->debug_fields();
	}

	/**
	 * Boot data of the panel. Runs only when the settings page is rendered,
	 * so this is where the vault and health reads live (see the class doc
	 * block): the service-account choices of the table's select column and
	 * the stored per-destination health records.
	 *
	 * @return array<string, mixed>
	 */
	public function panel_data(): array {
		$accounts = array();
		foreach ( ( new KeyVault() )->all() as $account ) {
			$accounts[ (string) $account['id'] ] = (string) $account['label'];
		}

		return array(
			// The test panel belongs to the destinations table it tests, so it
			// renders inside that tab - not below the attribution-capture one,
			// where a "Test destination" button has nothing to do with what the
			// tab is showing.
			'panelGroup'    => self::GROUP_DESTINATIONS,
			'testPath'      => RestCors::REST_NAMESPACE . RestController::REST_ROUTE,
			'optionKey'     => GTM4WP_OPTION_GDM_DESTINATIONS,
			'health'        => ( new DestinationHealth() )->all(),
			'threshold'     => DestinationHealth::FAILURE_THRESHOLD,
			'logPath'       => RestCors::REST_NAMESPACE . RestController::LOG_ROUTE,
			'replayPath'    => RestCors::REST_NAMESPACE . RestController::REPLAY_ROUTE,
			// The send lanes, so the panel can tell an empty log that is
			// waiting for its first send from one that can never fill up
			// because nothing is turned on. A new lane joins this list.
			'sendKeys'      => array( GTM4WP_OPTION_GDM_SEND_REFUNDS ),
			'columnChoices' => array(
				GTM4WP_OPTION_GDM_DESTINATIONS => array(
					DestinationRows::COLUMN_ACCOUNT => $accounts,
				),
			),
		);
	}
}
