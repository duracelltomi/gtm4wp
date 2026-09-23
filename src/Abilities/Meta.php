<?php
/**
 * Registration metadata shared by every ability.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `meta` argument of wp_register_ability() in one place, so no
 * ability can be exposed differently or lose its annotations.
 *
 * Three flags, because three consumers read three keys (U156):
 * `show_in_rest` (WordPress 6.9/7.0), `public` (7.1) and `mcp.public` (the
 * MCP Adapter). Leaving one out hides the ability from one client silently.
 *
 * The annotations are a promise MCP clients act on (U157): a read-only
 * ability may be called without asking, a destructive one is confirmed.
 * ContractTest pins each ability's annotations to what it does.
 */
final class Meta {

	/**
	 * Meta of an ability that changes nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		return self::build( true, false, true, false );
	}

	/**
	 * Meta of an ability that changes state or talks to another system.
	 *
	 * @param bool $destructive Whether the change can lose data or turn tracking off site-wide.
	 * @param bool $idempotent  Whether repeating the same call has no further effect.
	 * @param bool $open_world  Whether the ability contacts a system outside this site (Google).
	 * @return array<string, mixed>
	 */
	public static function write( bool $destructive, bool $idempotent, bool $open_world = false ): array {
		return self::build( false, $destructive, $idempotent, $open_world );
	}

	/**
	 * The meta array.
	 *
	 * @param bool $read_only   readonly annotation.
	 * @param bool $destructive destructive annotation.
	 * @param bool $idempotent  idempotent annotation.
	 * @param bool $open_world  openWorldHint annotation, only written when true.
	 * @return array<string, mixed>
	 */
	private static function build( bool $read_only, bool $destructive, bool $idempotent, bool $open_world ): array {
		$annotations = array(
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		);

		// The MCP format is passed through by the adapter as it is; there is no
		// WordPress-side spelling of this one.
		if ( $open_world ) {
			$annotations['openWorldHint'] = true;
		}

		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => $annotations,
		);
	}
}
