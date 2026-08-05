/**
 * Stand-in for `@wordpress/api-fetch` (test only — see jest.config.js).
 *
 * A bare jest mock: every test that exercises a REST call configures the
 * resolution or rejection it wants. Left unconfigured it rejects, so a component
 * that reaches the network unexpectedly fails loudly instead of silently
 * resolving to undefined.
 */

/* eslint-env jest */

const apiFetch = jest.fn( () =>
	Promise.reject( new Error( 'apiFetch was not configured for this test' ) )
);

export default apiFetch;
