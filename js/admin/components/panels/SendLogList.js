/**
 * Recent sends of the Google Data Manager module.
 *
 * The send lane runs in the background, minutes to a day after the refund that
 * started it, so without this list the only thing anyone could say about it is
 * "the number in Analytics looks wrong". Every attempt is shown with what
 * became of it, including the ones that deliberately sent nothing and why.
 *
 * The entries are read from the server as stored; what may be in them is
 * decided where they are written. No token, no key material, no request or
 * response body ever reaches this component.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Plain-words label of a stored outcome. An outcome this bundle does not know -
 * a newer server than the built JavaScript - falls back to the stored word
 * rather than to a blank cell.
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

export default function SendLogList( { logPath, hideWhenEmpty = false } ) {
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( async () => {
		// The effect below is registered before the early return further down,
		// so a panel rendered without a log path would otherwise still reach
		// the network on mount.
		if ( ! logPath ) {
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const response = await apiFetch( { path: logPath } );

			// Built defensively: a proxy or an exhausted worker can answer with
			// something that is not the envelope, and that must read as "no
			// entries", not as a TypeError inside React's render.
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

	if ( ! logPath ) {
		return null;
	}

	// Nothing has ever been sent AND no send lane is on: there is no reading
	// of "Nothing has been sent yet" that tells the admin anything, because
	// nothing is waiting to happen. The heading, the explanation and the
	// Refresh button would be a block about a feature that is off. Once an
	// entry exists the list is shown whatever the lanes say - turning a lane
	// off must not hide what it did while it was on. This also covers the
	// still-loading state, so an empty log never flashes the block on mount,
	// and a log that failed to load: with every lane off, silence is the
	// honest answer.
	if ( hideWhenEmpty && ( null === entries || 0 === entries.length ) ) {
		return null;
	}

	return (
		<div className="gtm4wp-send-log">
			<h3>{ __( 'Recent sends', 'duracelltomi-google-tag-manager' ) }</h3>
			<p>
				{ __(
					'What the plugin has sent to Google from the server, newest first, and what became of it. Sending happens in the background, and Google reports the outcome of an accepted request between half an hour and a day later.',
					'duracelltomi-google-tag-manager'
				) }
			</p>

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

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ null !== entries && 0 === entries.length && ! error && (
				<p className="gtm4wp-send-log__empty">
					{ __(
						'Nothing has been sent yet.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
			) }

			{ null !== entries && entries.length > 0 && (
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
						{ entries.map( ( entry, index ) => (
							<tr key={ index }>
								<td>{ formatTime( entry.time ) }</td>
								<td>
									{ entry.feature } { entry.reference }
								</td>
								<td>{ entry.destination || '—' }</td>
								<td>{ outcomeLabel( entry.outcome ) }</td>
								<td>
									{ reasonLabel( entry.reason ) }
									{ resultLabel( entry ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
