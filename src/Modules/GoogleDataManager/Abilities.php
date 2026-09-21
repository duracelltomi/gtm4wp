<?php
/**
 * Abilities of the Google Data Manager module.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Abilities\Meta;
use GTM4WP\Abilities\ProviderInterface;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * The module's abilities, handed to the plugin-wide Registrar through
 * AdminSchema::abilities() (Module\AbilitiesInterface) the way the module's
 * Site Health rows travel through SiteHealthInfoInterface. Phase 1 registers
 * gtm4wp/get-google-data-manager-log: the recent server-side sends and what
 * became of them, the same rows the settings screen lists under the
 * destinations table. What a row may carry is decided where it is written
 * (SendLog): statuses, counts, reason codes and the store's own order and
 * refund ids - never a token, key material or a response body from Google.
 */
final class Abilities implements ProviderInterface {

	public const GET_LOG = Registrar::NAMESPACE_PREFIX . 'get-google-data-manager-log';

	/**
	 * Longest list a call returns; also the ring's size.
	 */
	public const MAX_LIMIT = SendLog::MAX_ENTRIES;

	/**
	 * The diagnostics ring.
	 *
	 * @var SendLog
	 */
	private SendLog $log;

	/**
	 * Constructor.
	 *
	 * @param SendLog|null $log The diagnostics ring; the stored one when null.
	 */
	public function __construct( ?SendLog $log = null ) {
		$this->log = $log ?? new SendLog();
	}

	/**
	 * Registers the ability.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			self::GET_LOG,
			array(
				'label'               => __( 'Get the Google Data Manager send log', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Returns the recent server-side sends of the Google Data Manager integration (refund events sent to Google Analytics), newest first, with what became of each one: accepted by Google, applied, being retried, failed, or deliberately skipped with the reason. Set problems_only to true to see only the entries that need attention. Also reports how many sends and status checks are queued. Read-only; the log is empty on a site that does not use the Google Data Manager section.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'problems_only' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Only the entries that failed, were skipped, are being retried or came back with warnings.',
						),
						'limit'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => self::MAX_LIMIT,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'entries' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'time'        => array( 'type' => 'integer' ),
									'feature'     => array( 'type' => 'string' ),
									'reference'   => array( 'type' => 'string' ),
									'destination' => array( 'type' => 'string' ),
									'outcome'     => array( 'type' => 'string' ),
									'attempt'     => array( 'type' => 'integer' ),
									'status'      => array( 'type' => 'integer' ),
									'request_id'  => array( 'type' => 'string' ),
									'reason'      => array( 'type' => 'string' ),
									'result'      => array( 'type' => 'string' ),
									'errors'      => array( 'type' => 'integer' ),
									'warnings'    => array( 'type' => 'integer' ),
									'tone'        => array(
										'type' => 'string',
										'enum' => array( SendLog::TONE_OK, SendLog::TONE_PENDING, SendLog::TONE_WARN, SendLog::TONE_ERROR ),
									),
									'replayable'  => array( 'type' => 'boolean' ),
								),
							),
						),
						'queue'   => array(
							'type'       => 'object',
							'properties' => array(
								'to_send'       => array( 'type' => 'integer' ),
								'status_checks' => array( 'type' => 'integer' ),
								'backend'       => array(
									'type' => 'string',
									'enum' => array( 'action-scheduler', 'wp-cron' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_log' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);
	}

	/**
	 * The gtm4wp/get-google-data-manager-log ability.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>
	 */
	public function get_log( $input = null ): array {
		$input         = is_array( $input ) ? $input : array();
		$problems_only = ! empty( $input['problems_only'] );
		$limit         = isset( $input['limit'] ) ? max( 1, min( self::MAX_LIMIT, (int) $input['limit'] ) ) : self::MAX_LIMIT;

		$entries = $this->log->entries_for_display();

		if ( $problems_only ) {
			$entries = array_values(
				array_filter(
					$entries,
					static fn ( array $entry ) => in_array( $entry['tone'], array( SendLog::TONE_WARN, SendLog::TONE_ERROR ), true )
				)
			);
		}

		return array(
			'entries' => array_slice( $entries, 0, $limit ),
			'queue'   => array(
				'to_send'       => SendQueue::pending( SendQueue::HOOK_SEND ),
				'status_checks' => SendQueue::pending( SendQueue::HOOK_STATUS ),
				'backend'       => SendQueue::has_action_scheduler() ? 'action-scheduler' : 'wp-cron',
			),
		);
	}
}
