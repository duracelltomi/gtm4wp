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
use GTM4WP\Google\KeyVault;
use GTM4WP\Google\TokenService;
use GTM4WP\Google\WpTransport;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The module's abilities, handed to the plugin-wide Registrar through
 * AdminSchema::abilities() (Module\AbilitiesInterface) the way the module's
 * Site Health rows travel through SiteHealthInfoInterface.
 *
 * The read, gtm4wp/get-google-data-manager-log, is the recent server-side
 * sends and what became of them, the same rows the settings screen lists
 * under the destinations table. What a row may carry is decided where it is
 * written (SendLog): statuses, counts, reason codes and the store's own
 * order and refund ids - never a token, key material or a response body
 * from Google.
 *
 * The two writes are the panel's buttons. gtm4wp/test-google-data-manager-destination
 * is the Test button for one STORED destination, named by its measurement
 * ID; the assistant supplies no account or property id of its own
 * (DestinationProbe, shared with the REST route).
 * gtm4wp/replay-google-data-manager-refunds is the "send again" button,
 * behind confirm: true (RefundReplay, shared with the REST route). Both are
 * registered only while the site allows writes: they change little on the
 * site, but they cause requests to Google with the site's credentials,
 * which is the class of action the write switch withholds.
 */
final class Abilities implements ProviderInterface {

	public const GET_LOG          = Registrar::NAMESPACE_PREFIX . 'get-google-data-manager-log';
	public const TEST_DESTINATION = Registrar::NAMESPACE_PREFIX . 'test-google-data-manager-destination';
	public const REPLAY_REFUNDS   = Registrar::NAMESPACE_PREFIX . 'replay-google-data-manager-refunds';

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
	 * Plugin options, when injected; otherwise read fresh per call.
	 *
	 * @var Options|null
	 */
	private ?Options $options;

	/**
	 * The probe behind the destination test.
	 *
	 * @var DestinationProbe
	 */
	private DestinationProbe $probe;

	/**
	 * Constructor.
	 *
	 * @param SendLog|null          $log     The diagnostics ring; the stored one when null.
	 * @param Options|null          $options Plugin options; null reads the row afresh on every call, so a
	 *                                       destination saved earlier in the same request is seen.
	 * @param DestinationProbe|null $probe   The validateOnly probe; one over the stored vault and the live
	 *                                       transport when null.
	 */
	public function __construct( ?SendLog $log = null, ?Options $options = null, ?DestinationProbe $probe = null ) {
		$this->log     = $log ?? new SendLog();
		$this->options = $options;

		if ( null === $probe ) {
			$vault     = new KeyVault();
			$transport = new WpTransport();
			$probe     = new DestinationProbe( $vault, new EventsIngest( new TokenService( $vault, $transport ), $transport ) );
		}

		$this->probe = $probe;
	}

	/**
	 * Registers the abilities.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			self::GET_LOG,
			array(
				'label'               => __( 'Get the Google Data Manager send log', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Returns the recent server-side sends of the Google Data Manager integration (refund events sent to Google Analytics), newest first, with what became of each one: accepted by Google, applied, being retried, failed, or deliberately skipped with the reason. Set problems_only to true to see only the entries that need attention. An entry with replayable true can be sent again with replay-google-data-manager-refunds. Also reports how many sends and status checks are queued. Read-only; the log is empty on a site that does not use the Google Data Manager section.', 'duracelltomi-google-tag-manager' ),
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

		if ( Registrar::writes_allowed() ) {
			$this->register_test_destination();
			$this->register_replay_refunds();
		}
	}

	/**
	 * Registers gtm4wp/test-google-data-manager-destination.
	 *
	 * @return void
	 */
	private function register_test_destination(): void {
		wp_register_ability(
			self::TEST_DESTINATION,
			array(
				'label'               => __( 'Test a Google Data Manager destination', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Checks one stored Google Data Manager destination the way the Test button of the settings screen does: the site sends Google a validate-only request (nothing is recorded in Google Analytics) with the destination\'s service account, so a missing property permission, a wrong property or measurement ID or a refused key surfaces now rather than at the next refund. The destination is named by its measurement ID (G-XXXXXXX) and must be one of the destinations stored on the settings screen - call get-settings for the Google Data Manager module to see them; an unknown measurement ID is refused with 404 and nothing is sent. The answer says whether Google accepted the request (ok) and, when it did not, what to check; a passing test also clears the destination\'s failure count and the notice it raised. Repeating the call is harmless. It does contact Google with the site\'s credentials, so tell the user before calling it unless they asked for the test themselves.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'required'             => array( 'measurement_id' ),
					'properties'           => array(
						'measurement_id' => array(
							'type'        => 'string',
							'description' => 'The measurement ID of a stored destination, as get-settings lists it.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ok'      => array(
							'type'        => 'boolean',
							'description' => 'True when Google accepted the validate-only request.',
						),
						'message' => array(
							'type'        => 'string',
							'description' => 'What happened, in one sentence; what to check when the request was refused.',
						),
					),
				),
				'execute_callback'    => array( $this, 'test_destination' ),
				'permission_callback' => array( Registrar::class, 'can_write' ),
				'meta'                => Meta::write( false, true, true ),
			)
		);
	}

	/**
	 * Registers gtm4wp/replay-google-data-manager-refunds.
	 *
	 * @return void
	 */
	private function register_replay_refunds(): void {
		wp_register_ability(
			self::REPLAY_REFUNDS,
			array(
				'label'               => __( 'Send failed Google Data Manager refunds again', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Queues again the refund events that failed, or were skipped for a reason a configuration change can fix, so the sender tries them afresh against the destinations still missing them - the "send again" action of the settings screen. Nothing is sent by this call itself: the jobs run in the background and every gate (consent, destinations, health) applies again. Protocol, in this order: 1) call get-google-data-manager-log with problems_only true and show the user which refunds would be sent again - every entry with replayable true, or only the ones named in references (the reference values of the log, platform:order:refund); 2) ask "Send these refunds to Google again?" and wait for an explicit yes in the same turn - a general instruction such as "fix it" is not a confirmation; 3) call this ability with confirm true. Without confirm true the call is refused with 400 and nothing is queued; while the "Send refunds" option is off it is refused with 409. Not idempotent: each call queues the refunds again, so call it once per confirmation. The answer lists the references queued.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'required'             => array( 'confirm' ),
					'properties'           => array(
						'references' => array(
							'type'        => 'array',
							'description' => 'Send only these refunds again, by their reference from the log. Omit for every refund the log marks replayable.',
							'items'       => array( 'type' => 'string' ),
						),
						'confirm'    => array(
							'type'        => 'boolean',
							'description' => 'Must be true: the user confirmed, in this turn, that these refunds are to be sent to Google again.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'queued'     => array(
							'type'        => 'integer',
							'description' => 'How many refunds were queued.',
						),
						'references' => array(
							'type'        => 'array',
							'description' => 'The references queued.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'replay_refunds' ),
				'permission_callback' => array( Registrar::class, 'can_write' ),
				'meta'                => Meta::write( false, false, true ),
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

	/**
	 * The gtm4wp/test-google-data-manager-destination ability. The write
	 * switch first (the permission callback's first check, repeated so a
	 * caller that reaches the method directly gets the named 403), then the
	 * measurement ID is resolved against the destinations the plugin sends
	 * to; only a row found there reaches the probe, so the account and
	 * property ids in the request to Google are always the site's own.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function test_destination( $input = null ) {
		if ( ! Registrar::writes_allowed() ) {
			return Registrar::write_disabled_error();
		}

		$measurement = is_array( $input ) && isset( $input['measurement_id'] ) && is_scalar( $input['measurement_id'] )
			? strtoupper( trim( (string) $input['measurement_id'] ) )
			: '';

		foreach ( DestinationRows::rows( $this->options() ) as $row ) {
			if ( '' !== $measurement && $row[ DestinationRows::COLUMN_MEASUREMENT ] === $measurement ) {
				return $this->probe->probe( $row );
			}
		}

		return new \WP_Error(
			'gtm4wp_gdm_destination_unknown',
			__( 'No stored Google Data Manager destination has this measurement ID. Call get-settings for the Google Data Manager module to see the destinations, or add one on the settings screen first.', 'duracelltomi-google-tag-manager' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The gtm4wp/replay-google-data-manager-refunds ability. Refusals in the
	 * order of the cheapest one: the write switch, the missing confirmation,
	 * then the lane's master switch inside the shared replay.
	 *
	 * @param mixed $input The validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function replay_refunds( $input = null ) {
		if ( ! Registrar::writes_allowed() ) {
			return Registrar::write_disabled_error();
		}

		$input = is_array( $input ) ? $input : array();

		if ( true !== ( $input['confirm'] ?? null ) ) {
			return Registrar::confirmation_required_error();
		}

		return ( new RefundReplay( $this->log, $this->options() ) )->replay( RefundReplay::references( $input['references'] ?? array() ) );
	}

	/**
	 * The options the two actions read: the injected service, or a fresh
	 * read of the row (the Options service loads it once, at construction,
	 * so a destination stored earlier in the same request is only seen by a
	 * new one).
	 *
	 * @return Options
	 */
	private function options(): Options {
		return $this->options ?? new Options( ( new GoogleDataManagerModule() )->defaults() );
	}
}
