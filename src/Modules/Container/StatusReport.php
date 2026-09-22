<?php
/**
 * The Container module's slice of the plugin status.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Container;

use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * What the Container module reports about itself to the gtm4wp/get-status
 * ability: the containers as they load, the placement, the data layer name
 * and the wp-config.php overrides in effect. The module owns this shaping
 * the way GoogleDataManager\SiteHealth owns its Site Health rows; the
 * ability only assembles. Reads the Options service it is given, so the
 * caller decides whether the answer is request-scoped or fresh.
 *
 * ⛔ The answer ends up in an AI assistant's transcript. Nothing leaves here
 * that is not already in the site's public HTML: container ids, domains and
 * paths travel in the loader URL. The environment auth and preview tokens
 * travel there too, but an assistant only needs to know they are set, so
 * they are reported as one boolean and never by value.
 */
final class StatusReport {

	public const PLACEMENT_FOOTER           = 'footer';
	public const PLACEMENT_BODY_OPEN_MANUAL = 'body_open_manual';
	public const PLACEMENT_BODY_OPEN_AUTO   = 'body_open_auto';
	public const PLACEMENT_OFF              = 'off';

	/**
	 * Every placement word, for the ability's output schema.
	 */
	public const PLACEMENTS = array(
		self::PLACEMENT_FOOTER,
		self::PLACEMENT_BODY_OPEN_MANUAL,
		self::PLACEMENT_BODY_OPEN_AUTO,
		self::PLACEMENT_OFF,
	);

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * The module's part of the status answer, in the ability's key order.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$rows       = ContainerRows::normalize( $this->options->get( GTM4WP_OPTION_GTM_CONTAINERS ) );
		$containers = array();

		foreach ( $rows as $row ) {
			$containers[] = array(
				'id'          => $row[ ContainerRows::COLUMN_ID ],
				'environment' => ( '' !== $row[ ContainerRows::COLUMN_AUTH ] ) && ( '' !== $row[ ContainerRows::COLUMN_PREVIEW ] ),
				'domain'      => $row[ ContainerRows::COLUMN_DOMAIN ],
				'path'        => $row[ ContainerRows::COLUMN_PATH ],
				'omit_id'     => ( '' !== $row[ ContainerRows::COLUMN_NO_ID ] ) && ( '0' !== $row[ ContainerRows::COLUMN_NO_ID ] ),
			);
		}

		$placement  = self::placement_name( $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );
		$configured = trim( (string) $this->options->get( GTM4WP_OPTION_DATALAYER_NAME ) );
		$effective  = ContainerRows::datalayer_name( $configured );
		$locks      = HardcodedContainers::locks();

		return array(
			'containers'            => $containers,
			'placement'             => $placement,
			'container_code_output' => self::PLACEMENT_OFF !== $placement,
			'datalayer_name'        => array(
				'configured' => $configured,
				'effective'  => $effective,
				'valid'      => ( '' === $configured ) || ( $configured === $effective ),
			),
			'hardcoded'             => array(
				'active'         => HardcodedContainers::locks_any( $locks ),
				'locked_columns' => array_keys( $locks['columns'] ),
				'locked_rows'    => array() !== $locks['rows'],
				'errors'         => $this->options->hardcoded_errors(),
			),
		);
	}

	/**
	 * The placement option as a word.
	 *
	 * @param mixed $stored The stored placement value.
	 * @return string One of PLACEMENTS.
	 */
	public static function placement_name( $stored ): string {
		switch ( (int) $stored ) {
			case GTM4WP_PLACEMENT_OFF:
				return self::PLACEMENT_OFF;
			case GTM4WP_PLACEMENT_BODYOPEN:
				return self::PLACEMENT_BODY_OPEN_MANUAL;
			case GTM4WP_PLACEMENT_BODYOPEN_AUTO:
				return self::PLACEMENT_BODY_OPEN_AUTO;
			default:
				return self::PLACEMENT_FOOTER;
		}
	}
}
