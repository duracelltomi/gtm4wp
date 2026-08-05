/**
 * Stand-in for `@wordpress/element` (test only — see jest.config.js).
 *
 * The real package is a thin re-export of React plus a few WordPress helpers, so
 * the hooks are React's own — nothing is faked here.
 */

import { createElement } from 'react';

export {
	useState,
	useEffect,
	useMemo,
	useRef,
	useCallback,
	useReducer,
	useContext,
	createElement,
	Fragment,
	forwardRef,
	createRef,
	Component,
	cloneElement,
	isValidElement,
} from 'react';

/**
 * Renders a trusted HTML string, as `RawHTML` does in the real package.
 *
 * @param {Object} props           Component props.
 * @param {string} props.children  HTML string to render.
 * @param {string} props.className Optional wrapper class name.
 * @return {Object} React element.
 */
export function RawHTML( { children, className } ) {
	return createElement( 'div', {
		className,
		dangerouslySetInnerHTML: { __html: children ?? '' },
	} );
}
