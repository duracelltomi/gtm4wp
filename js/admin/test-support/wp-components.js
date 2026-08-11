/**
 * Stand-ins for the `@wordpress/components` controls the admin app uses
 * (test only — see jest.config.js for why these are mapped rather than installed).
 *
 * Each renders plain, accessible DOM and honours exactly the prop contract the
 * app relies on: `onChange` is called with the VALUE (not the event),
 * `disabled`/`readOnly` reach the underlying input, and `label` provides the
 * accessible name. The `__next*` opt-in props are accepted and ignored — they are
 * forwarded to the real library on WordPress 6.3–7.0 and must not be removed from
 * the app, but they have no bearing on behaviour here.
 *
 * Deliberately less capable than the real library: no styling, no popovers, no
 * focus management. Anything a test needs beyond this contract is a signal the
 * test is asserting the library rather than the plugin.
 */

/*
 * Each stand-in mirrors the upstream component's prop names, so per-prop JSDoc
 * would only restate @wordpress/components' own documentation for a file that
 * ships no public API. Scoped off here in the spirit of BE-4 rather than
 * repeating a dozen prop tables.
 */
/* eslint-disable jsdoc/require-param-type, jsdoc/check-param-names */

import { createElement as h, useEffect, useState } from 'react';

/**
 * Renders the `help` slot the way every BaseControl-backed control does: inside a
 * `<p class="components-base-control__help">`, as a SIBLING of the label.
 *
 * Not cosmetic, and not a case of the stand-in modelling the library for its own
 * sake (UC-3): the `<p>` is what makes the slot phrasing-content-only, so help
 * markup that is not valid inside a paragraph fails here — with
 * `@wordpress/jest-console` turning React's validateDOMNesting error into a test
 * failure — instead of only in a browser console. A stand-in that accepted any
 * `help` node was green precisely because the constraint was untested.
 *
 * @param {any} help Help node, if any.
 * @return {Object|null} React element.
 */
function helpSlot( help ) {
	if ( ! help ) {
		return null;
	}

	return h( 'p', { className: 'components-base-control__help' }, help );
}

/**
 * @param {Object} props                     Component props.
 * @param          props.label
 * @param          props.help
 * @param          props.value
 * @param          props.onChange
 * @param          props.disabled
 * @param          props.readOnly
 * @param          props.placeholder
 * @param          props.className
 * @param          props.type
 * @param          props.hideLabelFromVision
 * @return {Object} React element.
 */
export function TextControl( {
	label,
	help,
	value,
	onChange,
	disabled,
	readOnly,
	placeholder,
	className,
	type = 'text',
	hideLabelFromVision,
} ) {
	const input = h( 'input', {
		type,
		className,
		value: value ?? '',
		disabled: Boolean( disabled ),
		readOnly: Boolean( readOnly ),
		placeholder,
		'aria-label': hideLabelFromVision ? String( label ?? '' ) : undefined,
		onChange: ( event ) => onChange( event.target.value ),
	} );

	if ( hideLabelFromVision ) {
		return h( 'div', null, input, helpSlot( help ) );
	}

	return h(
		'div',
		null,
		h( 'label', null, h( 'span', null, label ), input ),
		helpSlot( help )
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.label
 * @param          props.help
 * @param          props.value
 * @param          props.onChange
 * @param          props.disabled
 * @param          props.rows
 * @return {Object} React element.
 */
export function TextareaControl( {
	label,
	help,
	value,
	onChange,
	disabled,
	rows,
} ) {
	return h(
		'div',
		null,
		h(
			'label',
			null,
			h( 'span', null, label ),
			h( 'textarea', {
				rows,
				value: value ?? '',
				disabled: Boolean( disabled ),
				onChange: ( event ) => onChange( event.target.value ),
			} )
		),
		helpSlot( help )
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.label
 * @param          props.help
 * @param          props.value
 * @param          props.options
 * @param          props.onChange
 * @param          props.disabled
 * @return {Object} React element.
 */
export function SelectControl( {
	label,
	help,
	value,
	options = [],
	onChange,
	disabled,
} ) {
	return h(
		'div',
		null,
		h(
			'label',
			null,
			h( 'span', null, label ),
			h(
				'select',
				{
					value: value ?? '',
					disabled: Boolean( disabled ),
					onChange: ( event ) => onChange( event.target.value ),
				},
				options.map( ( option ) =>
					h(
						'option',
						{ key: option.value, value: option.value },
						option.label
					)
				)
			)
		),
		helpSlot( help )
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.label
 * @param          props.help
 * @param          props.checked
 * @param          props.onChange
 * @param          props.disabled
 * @return {Object} React element.
 */
export function CheckboxControl( {
	label,
	help,
	checked,
	onChange,
	disabled,
	...rest
} ) {
	const input = h( 'input', {
		type: 'checkbox',
		checked: Boolean( checked ),
		disabled: Boolean( disabled ),
		'aria-label': rest[ 'aria-label' ],
		onChange: ( event ) => onChange( event.target.checked ),
	} );

	if ( rest[ 'aria-label' ] ) {
		return h( 'div', null, input );
	}

	return h(
		'div',
		null,
		h( 'label', null, input, h( 'span', null, label ) ),
		helpSlot( help )
	);
}

/**
 * The app uses ToggleControl for boolean options; behaviourally a checkbox.
 *
 * @param {Object} props          Component props.
 * @param          props.label
 * @param          props.help
 * @param          props.checked
 * @param          props.onChange
 * @param          props.disabled
 * @return {Object} React element.
 */
export function ToggleControl( { label, help, checked, onChange, disabled } ) {
	return h(
		'div',
		null,
		h(
			'label',
			null,
			h( 'input', {
				type: 'checkbox',
				role: 'switch',
				checked: Boolean( checked ),
				disabled: Boolean( disabled ),
				onChange: ( event ) => onChange( event.target.checked ),
			} ),
			h( 'span', null, label )
		),
		helpSlot( help )
	);
}

/**
 * `href` switches the rendered element from <button> to <a>, exactly as the real
 * component does. Honoured rather than accepted-and-ignored (UC-3): the help
 * links are anchors on purpose - an interactive <button> next to a control would
 * be picked up by ModulePanel's "focus the first control in this row" query, and
 * `rel` only means anything on an anchor. A stand-in that rendered every Button
 * as a <button> would make those tests green for the wrong reason.
 *
 * A button gets no `href`/`target`/`rel`, so passing them to one is not silently
 * swallowed here either.
 *
 * @param {Object} props               Component props.
 * @param          props.children
 * @param          props.onClick
 * @param          props.disabled
 * @param          props.label
 * @param          props.variant
 * @param          props.icon
 * @param          props.isDestructive
 * @param          props.isBusy
 * @param          props.className
 * @param          props.href
 * @param          props.target
 * @param          props.rel
 * @return {Object} React element.
 */
export function Button( {
	children,
	onClick,
	disabled,
	label,
	variant,
	icon,
	isDestructive,
	isBusy,
	className,
	href,
	target,
	rel,
} ) {
	const shared = {
		className,
		'aria-label': label,
		'data-variant': variant,
		'data-icon': icon,
		'data-destructive': isDestructive ? 'true' : undefined,
		'data-busy': isBusy ? 'true' : undefined,
		onClick,
	};

	if ( undefined !== href ) {
		return h( 'a', { ...shared, href, target, rel }, children );
	}

	return h(
		'button',
		{
			...shared,
			type: 'button',
			disabled: Boolean( disabled ),
		},
		children
	);
}

/**
 * @param {Object} props           Component props.
 * @param          props.children
 * @param          props.onDismiss
 * @return {Object} React element.
 */
export function Snackbar( { children, onDismiss } ) {
	return h(
		'div',
		{ role: 'status' },
		children,
		h( 'button', { type: 'button', onClick: onDismiss }, 'Dismiss' )
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.label
 * @param          props.value
 * @param          props.onChange
 * @return {Object} React element.
 */
export function SearchControl( { label, value, onChange } ) {
	return h(
		'label',
		null,
		h( 'span', null, label ),
		h( 'input', {
			type: 'search',
			value: value ?? '',
			onChange: ( event ) => onChange( event.target.value ),
		} )
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.children
 * @param          props.status
 * @return {Object} React element.
 */
export function Notice( { children, status } ) {
	return h( 'div', { role: 'alert', 'data-status': status }, children );
}

/**
 * Tab bar stand-in. The real control renders a button per tab and calls its
 * child render-prop with the selected tab; that is the whole contract
 * ModulePanel depends on, so the fake keeps the selection in the DOM rather than
 * in state the test cannot see.
 *
 * `initialTabName` and `onSelect` are honoured rather than accepted-and-ignored:
 * the deep link rides on the first and the bookmarkable URL on the second, and a
 * stand-in that swallowed either would make the suite green precisely because
 * the coupling is untested (UC-3). As in the real control `initialTabName`
 * applies at mount only. What either does with a name that matches no tab is
 * left untested on purpose - ModulePanel never passes one.
 *
 * `onSelect` also fires once on mount here. Whether the real control does that
 * is not something this file can answer, so the stand-in takes the harsher of
 * the two possibilities: the app has to stay correct when a mount reports the
 * tab it just opened on - and it is correct either way.
 *
 * @param {Object} props                Component props.
 * @param          props.tabs
 * @param          props.children
 * @param          props.className
 * @param          props.initialTabName
 * @param          props.onSelect
 * @return {Object} React element.
 */
export function TabPanel( {
	tabs = [],
	children,
	className,
	initialTabName,
	onSelect,
} ) {
	const [ selected, setSelected ] = useState(
		undefined === initialTabName ? tabs[ 0 ]?.name : initialTabName
	);
	const active = tabs.find( ( tab ) => tab.name === selected ) || tabs[ 0 ];
	const activeName = active?.name;

	useEffect( () => {
		if ( onSelect && activeName ) {
			onSelect( activeName );
		}
		// Mount only, mirroring the real control's initial selection; every
		// later change is reported from the click handler instead.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const select = ( name ) => {
		setSelected( name );

		if ( onSelect ) {
			onSelect( name );
		}
	};

	return h(
		'div',
		{ className },
		h(
			'div',
			{ role: 'tablist' },
			tabs.map( ( tab ) =>
				h(
					'button',
					{
						type: 'button',
						key: tab.name,
						role: 'tab',
						className: tab.className,
						'aria-selected': tab.name === activeName,
						onClick: () => select( tab.name ),
					},
					tab.title
				)
			)
		),
		active ? children( active ) : null
	);
}

/**
 * @param {Object} props          Component props.
 * @param          props.children
 * @return {Object} React element.
 */
export function Panel( { children } ) {
	return h( 'div', null, children );
}

/**
 * @param {Object} props             Component props.
 * @param          props.children
 * @param          props.title
 * @param          props.initialOpen
 * @return {Object} React element.
 */
export function PanelBody( { children, title, initialOpen = true } ) {
	return h(
		'section',
		null,
		h( 'h2', null, title ),
		false === initialOpen ? null : children
	);
}

// Renders the documented prop contract; see the file header.
export function Spinner() {
	return h( 'span', { role: 'progressbar' } );
}
