/**
 * Data Manager destinations test panel, rendered below the destinations
 * table of the google-data-manager module.
 *
 * Lists the rows currently in the editor - saved or not - and probes each one
 * with the validateOnly test route, so a missing property grant or a typo'd
 * ID surfaces before anything is saved or sent. Also shows the stored health
 * record of a destination once real sends have run against it.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import SendLogList from './SendLogList';

// Column keys of a destination row. They double as the parameter names of the
// test route, which validates them server side against the same rules.
const COLUMN_ACCOUNT = 'service_account';
const COLUMN_TYPE = 'type';
const COLUMN_PROPERTY = 'property_id';
const COLUMN_MEASUREMENT = 'measurement_id';
const COLUMN_LABEL = 'label';

/**
 * A row's cell as a trimmed string.
 *
 * @param {Object} row Destination row.
 * @param {string} key Column key.
 * @return {string} Cell value.
 */
function cell( row, key ) {
	const value = row && row[ key ];

	return 'string' === typeof value ? value.trim() : String( value ?? '' );
}

/**
 * Whether a row is filled in enough to probe. The server re-validates; this
 * only decides whether a Test button makes sense.
 *
 * @param {Object} row Destination row.
 * @return {boolean} Whether the row is testable.
 */
function isTestable( row ) {
	return (
		'' !== cell( row, COLUMN_ACCOUNT ) &&
		'' !== cell( row, COLUMN_PROPERTY ) &&
		'' !== cell( row, COLUMN_MEASUREMENT )
	);
}

/**
 * Display name of a row: its label, or its measurement ID.
 *
 * @param {Object} row Destination row.
 * @return {string} Name to show.
 */
function rowName( row ) {
	return cell( row, COLUMN_LABEL ) || cell( row, COLUMN_MEASUREMENT );
}

/**
 * The request body of a probe for one row. Its JSON form doubles as the
 * result's identity: a stored result is shown only while the row at that
 * index still probes the same values, so an edited row - or a different
 * row shifted into the index by a removal above - never wears another
 * probe's verdict, and never has its own failing-health warning hidden
 * behind one. The label is display-only and deliberately not part of it.
 *
 * @param {Object} row Destination row.
 * @return {Object} Probe payload.
 */
function probePayload( row ) {
	return {
		[ COLUMN_ACCOUNT ]: cell( row, COLUMN_ACCOUNT ),
		[ COLUMN_TYPE ]: cell( row, COLUMN_TYPE ) || 'ga4',
		[ COLUMN_PROPERTY ]: cell( row, COLUMN_PROPERTY ),
		[ COLUMN_MEASUREMENT ]: cell( row, COLUMN_MEASUREMENT ),
	};
}

export default function DestinationsPanel( { data, values } ) {
	const { testPath, optionKey, health, threshold, logPath } = data;

	// Whether any server-side send lane is on, read from the CURRENT editor
	// state so switching the refund option hides or shows the log without a
	// save. The lane option keys come from the server (panel_data) rather
	// than being spelled out here, so the two ends cannot drift.
	const sendingEnabled = ( data.sendKeys || [] ).some(
		( key ) => !! ( values && values[ key ] )
	);

	const rows = Array.isArray( values && values[ optionKey ] )
		? values[ optionKey ]
		: [];
	const accounts =
		( data.columnChoices &&
			data.columnChoices[ optionKey ] &&
			data.columnChoices[ optionKey ][ COLUMN_ACCOUNT ] ) ||
		{};
	const healthRecords = health || {};

	// Result per row index; only one probe runs at a time.
	const [ results, setResults ] = useState( {} );
	const [ busyIndex, setBusyIndex ] = useState( null );

	const onTest = async ( row, index ) => {
		// Handler-level twin of disabled={busy} - the ServiceAccountsPanel
		// pattern: the disabled prop cannot be trusted across the supported
		// WP range.
		if ( null !== busyIndex ) {
			return;
		}

		// Captured at request time: the table stays editable while a probe is
		// in flight, and the fingerprint decides which row may show the result.
		const payload = probePayload( row );
		const fingerprint = JSON.stringify( payload );

		setBusyIndex( index );
		setResults( ( current ) => ( { ...current, [ index ]: null } ) );

		const fallbackText = __(
			'The destination could not be tested.',
			'duracelltomi-google-tag-manager'
		);

		try {
			const response = await apiFetch( {
				path: testPath,
				method: 'POST',
				data: payload,
			} );

			// Built before setResults so a malformed response (no envelope
			// from a proxy or an exhausted worker) is a failure verdict here,
			// not a TypeError inside React's render.
			const verdict = {
				ok: Boolean( response && response.ok ),
				text: ( response && response.message ) || fallbackText,
				fingerprint,
			};

			setResults( ( current ) => ( {
				...current,
				[ index ]: verdict,
			} ) );
		} catch ( error ) {
			const verdict = {
				ok: false,
				text: ( error && error.message ) || fallbackText,
				fingerprint,
			};

			setResults( ( current ) => ( {
				...current,
				[ index ]: verdict,
			} ) );
		} finally {
			setBusyIndex( null );
		}
	};

	if ( 0 === Object.keys( accounts ).length ) {
		return (
			<div className="gtm4wp-destinations">
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Upload a Google service account first (see the Google service accounts section) - every destination sends with one.',
						'duracelltomi-google-tag-manager'
					) }
				</Notice>
			</div>
		);
	}

	const testable = rows
		.map( ( row, index ) => ( { row, index } ) )
		.filter( ( { row } ) => isTestable( row ) );

	// The send log is shown even when no row is testable yet: its most useful
	// entries are the ones that explain why nothing was sent, and "no
	// destination configured" is one of them.
	if ( 0 === testable.length ) {
		return (
			<SendLogList
				logPath={ logPath }
				hideWhenEmpty={ ! sendingEnabled }
			/>
		);
	}

	return (
		<div className="gtm4wp-destinations">
			<h3>
				{ __( 'Test destinations', 'duracelltomi-google-tag-manager' ) }
			</h3>
			<p>
				{ __(
					'Each test sends one validation-only request: Google checks the destination and the access of its service account, but stores nothing. Unsaved changes to the table are tested as shown above.',
					'duracelltomi-google-tag-manager'
				) }
			</p>
			<ul className="gtm4wp-destinations__list">
				{ testable.map( ( { row, index } ) => {
					const record =
						healthRecords[ cell( row, COLUMN_MEASUREMENT ) ];
					const failing =
						record && record.consecutive_failures >= threshold;
					const stored = results[ index ];
					const result =
						stored &&
						stored.fingerprint ===
							JSON.stringify( probePayload( row ) )
							? stored
							: null;

					return (
						<li key={ index } className="gtm4wp-destinations__row">
							<span className="gtm4wp-destinations__name">
								{ rowName( row ) }
							</span>
							<Button
								variant="secondary"
								disabled={ null !== busyIndex }
								isBusy={ busyIndex === index }
								label={ sprintf(
									/* translators: %s: label or measurement ID of the destination. */
									__(
										'Test %s',
										'duracelltomi-google-tag-manager'
									),
									rowName( row )
								) }
								onClick={ () => onTest( row, index ) }
							>
								{ __(
									'Test',
									'duracelltomi-google-tag-manager'
								) }
							</Button>
							{ result && (
								<span
									className={ `gtm4wp-destinations__result gtm4wp-destinations__result--${
										result.ok ? 'ok' : 'error'
									}` }
								>
									{ result.text }
								</span>
							) }
							{ ! result && failing && (
								<span className="gtm4wp-destinations__result gtm4wp-destinations__result--error">
									{ sprintf(
										/* translators: 1: number of consecutive failed sends. 2: last error reported by Google. */
										__(
											'The last %1$d sends to this destination failed. Last error: %2$s',
											'duracelltomi-google-tag-manager'
										),
										record.consecutive_failures,
										record.last_error ||
											__(
												'unknown',
												'duracelltomi-google-tag-manager'
											)
									) }
								</span>
							) }
						</li>
					);
				} ) }
			</ul>
			<SendLogList
				logPath={ logPath }
				hideWhenEmpty={ ! sendingEnabled }
			/>
		</div>
	);
}
