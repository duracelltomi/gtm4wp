#!/usr/bin/env node
/**
 * Reports whether the checked-out branch changes anything this project's own tooling
 * later EXECUTES.
 *
 * Why this exists
 * ---------------
 * Running `composer test`, `npm run build` or `vendor/bin/phpcs` on a branch does not
 * merely read that branch - it executes code the branch supplies. PHPUnit executes
 * `tests/bootstrap.php` and every test file; webpack executes `webpack.config.js`;
 * ESLint executes `.eslintrc.js`; Composer and npm execute lifecycle scripts and
 * plugins; PHP_CodeSniffer loads whatever `phpcs.xml` names. So reviewing a
 * contributor's branch locally can run that contributor's code as you, before you have
 * read a line of it. That is finding #101 in `.security/code-review-checklist.md`, and
 * the same class as #77 and #81.
 *
 * This script prints the wiring the branch changed, so you can read those files first.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is NOT a security control, and it must not be recorded as one.
 *
 *   1. It cannot defend against a hostile branch, because the branch supplies THIS
 *      FILE too - a hostile version simply prints "clean". Against a branch you
 *      actually distrust, run the git command from a checkout you control, or do not
 *      check the branch out at all.
 *   2. A clean report does NOT mean nothing branch-supplied will execute. PHPUnit runs
 *      every file under `tests/`, and the JS runner every file under `js/**\/test/`,
 *      whether or not the wiring changed. Those are executed by design and are far too
 *      noisy to diff usefully, so the report names them explicitly instead.
 *
 * What it IS: a cheap prompt for the ordinary case - an honest contributor's branch
 * that happens to touch the build or test wiring, which is exactly the change you want
 * to read before running anything. Per the threat model's own rule, that makes it a
 * *described* boundary rather than an *enforced* one. Only isolation (running the
 * tooling in a container) or not checking the branch out is enforced.
 *
 * Usage:
 *   npm run check-branch            # compare against origin/master
 *   composer check-branch
 *   node tools/check-branch.js origin/1.x
 *
 * Exit codes: 0 = no wiring changed · 1 = wiring changed (read it) · 2 = could not run.
 *
 * Exit 1 is a finding, not a failure - but Composer reports any non-zero script as
 * "returned with error code 1" after the output. That line is expected here and means
 * the report above it listed something; the exit code is kept meaningful so this can be
 * used in a script or a CI step.
 *
 * @package GTM4WP
 */

'use strict';

const { spawnSync } = require( 'child_process' );

/**
 * Every path whose contents are executed - directly or as configuration that names
 * code to load - by a command this project's docs tell a developer to run.
 *
 * Keep this list in step with the tool entry points. Adding a linter, formatter, task
 * runner or git hook means adding its config here: the recurring lesson behind #101 is
 * that a tool's config file reads as inert data right up until you notice its format
 * can reference code.
 */
const EXECUTED_PATHS = [
	// PHP toolchain.
	'phpcs.xml', // PHP_CodeSniffer: <autoload>/<rule ref> can load PHP.
	'phpunit.xml', // Names the bootstrap and the test suites.
	'tests/bootstrap.php', // Executed before any test.
	'composer.json', // scripts + allow-plugins; Composer plugins execute.
	'composer.lock', // Pins the dev dependencies that provide those executables.

	// JS toolchain.
	'package.json', // scripts + install lifecycle hooks.
	'package-lock.json', // Pins what npm ci installs.
	'webpack.config.js', // Executed as JavaScript by wp-scripts build.
	'.eslintrc.js', // Executed as JavaScript by ESLint.

	// Repo tooling that runs on your machine.
	'.githooks/', // core.hooksPath - runs on commit.
	'.claude/', // Agent hooks and pre-approved tool permissions.
	'.github/workflows/', // Runs in CI; read it before it runs with any token.
	'tools/', // Including this file.
];

/**
 * Paths whose contents a test runner executes on EVERY run, changed or not. Diffing
 * these would flag almost every legitimate pull request, so they are reported as a
 * standing fact rather than as a finding.
 */
const ALWAYS_EXECUTED = [
	'tests/**/*.php  (PHPUnit runs every test file)',
	'js/**/test/**   (the JS test runner does the same)',
];

/**
 * Runs git and returns trimmed stdout, or null when the command fails.
 *
 * @param {string[]} args Arguments passed to git.
 * @return {string|null} stdout, or null on failure.
 */
function git( args ) {
	const res = spawnSync( 'git', args, { encoding: 'utf8' } );

	if ( res.error || 0 !== res.status ) {
		return null;
	}

	return res.stdout.trim();
}

function main() {
	const base = process.argv[ 2 ] || 'origin/master';

	if ( null === git( [ 'rev-parse', '--git-dir' ] ) ) {
		console.error( 'check-branch: not a git repository.' );
		process.exit( 2 );
	}

	if ( null === git( [ 'rev-parse', '--verify', '--quiet', base ] ) ) {
		console.error(
			`check-branch: cannot resolve "${ base }". Fetch it first (git fetch origin), ` +
				'or pass a different base: node tools/check-branch.js <ref>'
		);
		process.exit( 2 );
	}

	// Three dots: compare against the merge base, so commits that landed on the base
	// branch since this one forked are not reported as the branch's doing.
	const out = git( [ 'diff', '--name-only', `${ base }...HEAD`, '--', ...EXECUTED_PATHS ] );

	if ( null === out ) {
		console.error( 'check-branch: git diff failed.' );
		process.exit( 2 );
	}

	const changed = out.split( '\n' ).filter( ( line ) => '' !== line );

	// The diff above compares COMMITS, which is what a pull request is - but it says
	// nothing about uncommitted edits, and "no executable wiring changed" would
	// otherwise read as reassurance while a watched file sat modified in the working
	// tree. Report that separately rather than letting it hide behind a clean diff.
	const dirty = ( git( [ 'status', '--porcelain', '--', ...EXECUTED_PATHS ] ) || '' )
		.split( '\n' )
		.filter( ( line ) => '' !== line );

	console.log( `Comparing HEAD against ${ base } (merge base).\n` );

	if ( 0 === changed.length ) {
		console.log( 'No executable wiring changed on this branch.' );
	} else {
		console.log( `This branch changes ${ changed.length } file(s) that the tooling EXECUTES:\n` );
		changed.forEach( ( file ) => console.log( `  ${ file }` ) );
		console.log( '\nRead these before running composer/npm/phpcs/phpunit on this branch.' );
	}

	if ( 0 !== dirty.length ) {
		console.log( '\nUncommitted changes to executable wiring in your working tree:\n' );
		dirty.forEach( ( line ) => console.log( `  ${ line }` ) );
		console.log( '\n(Not part of the branch comparison above - these are yours, or a stash left behind.)' );
	}

	console.log( '\nExecuted on every run regardless of the above:' );
	ALWAYS_EXECUTED.forEach( ( note ) => console.log( `  ${ note }` ) );
	console.log(
		'\nThis is a prompt, not a control: a hostile branch supplies this script too.\n' +
			'See the header of tools/check-branch.js.'
	);

	process.exit( 0 === changed.length && 0 === dirty.length ? 0 : 1 );
}

main();
