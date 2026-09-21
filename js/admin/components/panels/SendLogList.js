/**
 * Recent sends of the Google Data Manager module: every background attempt
 * with what became of it, including the ones that deliberately sent nothing.
 * Entries are shown as stored; what may be in them is decided where they are
 * written (no token, key material or request/response body).
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, ToggleControl } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Plain-words label of a stored outcome; an unknown one (newer server) falls
 * back to the stored word.
 *
 * @param {string} outcome Stored outcome.
 * @return {string} Label to show.
 */
function outcomeLabel( outcome ) {
	switch ( outcome ) {
		case 'accepted':
			return __( 'Accepted', 'duracelltomi-google-tag-manager' );
		case 'retrying':
			return __(
				'Failed, trying again',
				'duracelltomi-google-tag-manager'
			);
		case 'failed':
			return __( 'Failed', 'duracelltomi-google-tag-manager' );
		case 'skipped':
			return __( 'Not sent', 'duracelltomi-google-tag-manager' );
		default:
			return outcome || '';
	}
}

/**
 * Plain-words explanation of a reason code, or the code itself when it is one
 * this bundle does not know.
 *
 * @param {string} reason Stored reason.
 * @return {string} Explanation to show.
 */
function reasonLabel( reason ) {
	switch ( reason ) {
		case 'no_client_id':
			return __(
				'No Google Analytics client ID was stored with the order, so the event could not be matched to its purchase.',
				'duracelltomi-google-tag-manager'
			);
		case 'consent_no_client_id':
			return __(
				'The buyer did not allow analytics storage, so no Google Analytics client ID was stored with the order and the refund cannot be matched to its purchase. The consent rule below does not change this: with analytics storage denied, Google issues a new client ID on every page view, and one captured then would match nothing.',
				'duracelltomi-google-tag-manager'
			);
		case 'consent_denied':
			return __(
				'The buyer did not allow analytics storage.',
				'duracelltomi-google-tag-manager'
			);
		case 'consent_unknown':
			return __(
				'No consent answer was stored with the order, and your consent rule requires one.',
				'duracelltomi-google-tag-manager'
			);
		case 'no_destination':
			return __(
				'No destination is configured to send to.',
				'duracelltomi-google-tag-manager'
			);
		case 'vetoed':
			return __(
				'Code on this site cancelled the event.',
				'duracelltomi-google-tag-manager'
			);
		case 'empty_refund':
			return __(
				'The refund returned no money.',
				'duracelltomi-google-tag-manager'
			);
		case 'refund_unreadable':
			return __(
				'The refund could not be read back from the store.',
				'duracelltomi-google-tag-manager'
			);
		case 'platform_inactive':
			return __(
				'The shop plugin that issued the refund was not active when the send ran.',
				'duracelltomi-google-tag-manager'
			);
		default:
			return reason || '';
	}
}

/**
 * What Google reported once it had finished processing an accepted request.
 *
 * @param {Object} entry Stored entry.
 * @return {string} Text to show, empty while nothing is known yet.
 */
function resultLabel( entry ) {
	if ( ! entry.result ) {
		return '';
	}

	if ( 'PROCESSING' === entry.result ) {
		return __(
			'Google is still processing it',
			'duracelltomi-google-tag-manager'
		);
	}

	if ( entry.errors > 0 || entry.warnings > 0 ) {
		return sprintf(
			/* translators: 1: status reported by Google. 2: number of records with errors. 3: number of records with warnings. */
			__(
				'%1$s (%2$d with errors, %3$d with warnings)',
				'duracelltomi-google-tag-manager'
			),
			entry.result,
			entry.errors,
			entry.warnings
		);
	}

	return entry.result;
}

/**
 * The CSS suffix for an entry's tone. The judgement is the server's
 * (SendLog::tone); an unknown word draws as neutral, never as a success.
 *
 * @param {Object} entry Stored entry.
 * @return {string} One of ok, pending, warn, error.
 */
function toneClass( entry ) {
	switch ( entry.tone ) {
		case 'ok':
		case 'warn':
		case 'error':
			return entry.tone;
		default:
			return 'pending';
	}
}

/**
 * A stored Unix timestamp in the reader's own locale, or an em dash.
 *
 * @param {number} time Unix timestamp.
 * @return {string} Formatted time.
 */
function formatTime( time ) {
	if ( ! time ) {
		return '—';
	}

	return new Date( time * 1000 ).toLocaleString();
}

export default function SendLogList( {
	logPath,
	replayPath,
	hideWhenEmpty = false,
} ) {
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ problemsOnly, setProblemsOnly ] = useState( false );
	const [ replaying, setReplaying ] = useState( false );
	const [ replayNotice, setReplayNotice ] = useState( null );

	const load = useCallback( async () => {
		// The effect is registered before the early return below: no network
		// without a log path.
		if ( ! logPath ) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const response = await apiFetch( { path: logPath } );

			// A non-envelope answer (proxy, exhausted worker) reads as "no
			// entries", not as a TypeError in render.
			setEntries(
				Array.isArray( response && response.entries )
					? response.entries
					: []
			);
		} catch ( caught ) {
			setEntries( [] );
			setError(
				( caught && caught.message ) ||
					__(
						'The list of recent sends could not be loaded.',
						'duracelltomi-google-tag-manager'
					)
			);
		} finally {
			setBusy( false );
		}
	}, [ logPath ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Re-queues the failed and fixable refunds (all, or the ones named).
	// Nothing is sent from here; the sender applies every gate again a minute
	// later, so the reload shows the same rows until the next Refresh.
	const replay = async ( references ) => {
		if ( ! replayPath || replaying ) {
			return;
		}

		setReplaying( true );
		setReplayNotice( null );

		try {
			const response = await apiFetch( {
				path: replayPath,
				method: 'POST',
				data: references ? { references } : {},
			} );
			const queued = Number( response && response.queued ) || 0;

			setReplayNotice( {
				status: queued > 0 ? 'success' : 'info',
				text:
					queued > 0
						? sprintf(
								/* translators: %d: number of refunds queued to be sent again. */
								_n(
									'%d refund is queued to be sent again. It runs in the background within a minute; press Refresh afterwards to see what became of it.',
									'%d refunds are queued to be sent again. They run in the background within a minute; press Refresh afterwards to see what became of them.',
									queued,
									'duracelltomi-google-tag-manager'
								),
								queued
						  )
						: __(
								'Nothing was queued: every refund in this list has either been accepted since, or is one that a setting cannot fix.',
								'duracelltomi-google-tag-manager'
						  ),
			} );
		} catch ( caught ) {
			setReplayNotice( {
				status: 'error',
				text:
					( caught && caught.message ) ||
					__(
						'The refunds could not be queued again.',
						'duracelltomi-google-tag-manager'
					),
			} );
		} finally {
			setReplaying( false );
		}
	};

	if ( ! logPath ) {
		return null;
	}

	// Nothing ever sent AND no send lane on: a block about a feature that is
	// off says nothing. Once an entry exists the list shows whatever the
	// lanes say (turning a lane off must not hide what it did). Also covers
	// the still-loading and failed-to-load states.
	if ( hideWhenEmpty && ( null === entries || 0 === entries.length ) ) {
		return null;
	}

	const all = entries || [];
	const problems = all.filter( ( entry ) => {
		const tone = toneClass( entry );

		return 'warn' === tone || 'error' === tone;
	} );
	const shown = problemsOnly ? problems : all;

	// Distinct refunds the bulk action would touch (a refund is many rows and
	// one job); the server decides what is replayable.
	const replayable = new Set(
		all
			.filter( ( entry ) => entry.replayable )
			.map( ( entry ) => entry.reference )
	);

	return (
		<div className="gtm4wp-send-log">
			<h3>{ __( 'Recent sends', 'duracelltomi-google-tag-manager' ) }</h3>
			<p>
				{ __(
					'What the plugin has sent to Google from the server, newest first, and what became of it. Sending happens in the background, and Google reports the outcome of an accepted request between half an hour and a day later.',
					'duracelltomi-google-tag-manager'
				) }
			</p>

			<div className="gtm4wp-send-log__actions">
				<Button
					variant="secondary"
					disabled={ busy }
					isBusy={ busy }
					onClick={ () => {
						// Handler-level twin of the disabled prop, which cannot be
						// trusted across the supported WordPress range.
						if ( ! busy ) {
							load();
						}
					} }
				>
					{ __( 'Refresh', 'duracelltomi-google-tag-manager' ) }
				</Button>
				{ replayPath && replayable.size > 0 && (
					<Button
						variant="secondary"
						disabled={ replaying || busy }
						isBusy={ replaying }
						onClick={ () => replay( null ) }
					>
						{ sprintf(
							/* translators: %d: number of refunds that can be sent again. */
							_n(
								'Send again what can be sent (%d)',
								'Send again what can be sent (%d)',
								replayable.size,
								'duracelltomi-google-tag-manager'
							),
							replayable.size
						) }
					</Button>
				) }
			</div>

			{ replayNotice && (
				<Notice
					status={ replayNotice.status }
					isDismissible
					onRemove={ () => setReplayNotice( null ) }
				>
					{ replayNotice.text }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ /* Only worth offering once there is something to hide behind it. */ }
			{ all.length > 0 && (
				<ToggleControl
					__nextHasNoMarginBottom
					className="gtm4wp-send-log__filter"
					checked={ problemsOnly }
					label={ sprintf(
						/* translators: %d: number of entries that failed, were skipped or came back with warnings. */
						__(
							'Only what needs attention (%d)',
							'duracelltomi-google-tag-manager'
						),
						problems.length
					) }
					help={ __(
						'Hides the sends Google accepted and applied, so the failures and the deliberate skips are what is left. It filters this list, which keeps the most recent sends only - it does not search further back.',
						'duracelltomi-google-tag-manager'
					) }
					onChange={ ( next ) => setProblemsOnly( next ) }
				/>
			) }

			{ null !== entries && 0 === entries.length && ! error && (
				<p className="gtm4wp-send-log__empty">
					{ __(
						'Nothing has been sent yet.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
			) }

			{ problemsOnly && 0 === problems.length && all.length > 0 && (
				<p className="gtm4wp-send-log__empty">
					{ __(
						'Nothing needs attention: every send in this list was accepted.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
			) }

			{ shown.length > 0 && (
				<table className="gtm4wp-send-log__table widefat striped">
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'When',
									'duracelltomi-google-tag-manager'
								) }
							</th>
							<th scope="col">
								{ __(
									'What',
									'duracelltomi-google-tag-manager'
								) }
							</th>
							<th scope="col">
								{ __(
									'Destination',
									'duracelltomi-google-tag-manager'
								) }
							</th>
							<th scope="col">
								{ __(
									'Outcome',
									'duracelltomi-google-tag-manager'
								) }
							</th>
							<th scope="col">
								{ __(
									'Details',
									'duracelltomi-google-tag-manager'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ shown.map( ( entry, index ) => (
							<tr
								key={ index }
								className={ `gtm4wp-send-log__row gtm4wp-send-log__row--${ toneClass(
									entry
								) }` }
							>
								<td>{ formatTime( entry.time ) }</td>
								<td>
									{ entry.feature } { entry.reference }
								</td>
								<td>{ entry.destination || '—' }</td>
								<td className="gtm4wp-send-log__outcome">
									{ outcomeLabel( entry.outcome ) }
								</td>
								<td className="gtm4wp-send-log__details">
									{ reasonLabel( entry.reason ) }
									{ resultLabel( entry ) }
									{ replayPath && entry.replayable && (
										<Button
											variant="link"
											className="gtm4wp-send-log__replay"
											disabled={ replaying }
											label={ sprintf(
												/* translators: %s: the refund reference. */
												__(
													'Send %s again',
													'duracelltomi-google-tag-manager'
												),
												entry.reference
											) }
											onClick={ () =>
												replay( [ entry.reference ] )
											}
										>
											{ __(
												'Send again',
												'duracelltomi-google-tag-manager'
											) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
