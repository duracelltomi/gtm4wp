<?php
/**
 * Probes one destination with a validateOnly request.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\KeyVault;

defined( 'ABSPATH' ) || exit;

/**
 * The one definition of "test this destination" (UC-6): the panel's test
 * route hands over a row still being edited, the ability a stored row looked
 * up by measurement ID, and from here both refuse a row whose service
 * account is gone before anything leaves the site, send the validateOnly
 * probe, and end the destination's failure streak when Google accepts. The
 * answer carries the outcome and a sentence, never a token and never
 * Google's raw response body.
 */
final class DestinationProbe {

	/**
	 * Constructor.
	 *
	 * @param KeyVault               $vault  The key store, to name an unknown account before any request.
	 * @param EventsIngest           $ingest The validateOnly probe.
	 * @param DestinationHealth|null $health Per-destination health, cleared by a passing probe; null builds one on demand.
	 */
	public function __construct(
		private KeyVault $vault,
		private EventsIngest $ingest,
		private ?DestinationHealth $health = null
	) {
	}

	/**
	 * Probes one valid destination row.
	 *
	 * @param array<string, string> $row A normalized row that passed DestinationRows::is_valid_row().
	 * @return array{ok: bool, message: string}|\WP_Error 404 when the row's service account no longer exists.
	 */
	public function probe( array $row ) {
		if ( ! $this->vault->has( (string) ( $row[ DestinationRows::COLUMN_ACCOUNT ] ?? '' ) ) ) {
			return new \WP_Error(
				'gtm4wp_google_account_unknown',
				__( 'This service account no longer exists.', 'duracelltomi-google-tag-manager' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->ingest->validate_destination( $row );
		$ok     = ! ( $result instanceof \WP_Error );

		// A passing probe ends the failure streak and the notice it raises;
		// otherwise the notice stayed until the next refund.
		if ( $ok ) {
			( $this->health ?? new DestinationHealth() )->clear_failures( (string) $row[ DestinationRows::COLUMN_MEASUREMENT ] );
		}

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Google accepted a test request for this destination.', 'duracelltomi-google-tag-manager' )
				: $result->get_error_message(),
		);
	}
}
