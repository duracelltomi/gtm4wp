<?php
/**
 * Regenerates the country phone table and its regression corpus from Google's
 * libphonenumber metadata.
 *
 * WHY THIS IS GENERATED
 * ---------------------
 * Turning a locally-typed phone number into E.164 needs two facts per country:
 * its calling code, and its national (trunk) prefix - the digits a caller dials
 * before the number domestically and which must NOT survive into E.164.
 *
 * Both are national numbering-plan facts that nothing in this plugin can verify,
 * so they are a mirror (upstream UD-1). Hand-maintaining that mirror was tried
 * and does not work: a five-entry table of "trunk prefixes that are not 0" plus
 * a "0" default was wrong for 15 territories, because the domain has THREE
 * categories and a default can only express two - a country may use "0", or use
 * something else, or have no trunk prefix at all and carry a leading zero that
 * is part of the number (Italy is the well-known case, and there are six more).
 *
 * So the table is mechanically generated from the same source a phone library
 * would use, and regenerating is a script rather than a research session.
 *
 * WHAT IT DELIBERATELY DOES NOT CARRY
 * -----------------------------------
 * Two columns only. libphonenumber additionally models nationalPrefixForParsing,
 * nationalPrefixTransformRule and per-country international prefixes, which is
 * what it needs to handle Argentina's mobile "9", Brazil's carrier-selection
 * codes, and a caller who dials a foreign number using their own international
 * access code. Those stay unhandled on purpose: adding a third and fourth column
 * is reimplementing that library one field at a time, and the point at which
 * that trade flips is the point to adopt it instead. See the local review report
 * for the measurement behind that call.
 *
 * USAGE
 * -----
 *     composer generate:phone-table
 *     php tools/generate-phone-table.php [path-or-url-to-PhoneNumberMetadata.xml]
 *
 * Then run the test suite: tests/unit/Modules/phone-corpus.php is regenerated
 * alongside the table from the same parse, so the two cannot disagree.
 *
 * @package GTM4WP
 */

declare( strict_types = 1 );

const SOURCE_URL = 'https://raw.githubusercontent.com/google/libphonenumber/master/resources/PhoneNumberMetadata.xml';

const TABLE_FILE  = __DIR__ . '/../src/Modules/WooCommerce/CountryPhoneData.php';
const CORPUS_FILE = __DIR__ . '/../tests/unit/Modules/phone-corpus.php';

/**
 * Reads the metadata, from a local path or over the network.
 *
 * @param string $source Path or URL.
 * @return string XML.
 */
function read_source( string $source ): string {
	if ( is_file( $source ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- build-time script, no WordPress loaded.
		$xml = file_get_contents( $source );
	} else {
		fwrite( STDOUT, "Fetching {$source}\n" );
		$context = stream_context_create(
			array(
				'http' => array(
					'method'  => 'GET',
					'timeout' => 60,
					'header'  => "User-Agent: GTM4WP phone table generator\r\n",
				),
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- build-time script, no WordPress loaded.
		$xml = file_get_contents( $source, false, $context );
	}

	if ( false === $xml || '' === $xml ) {
		fwrite( STDERR, "Could not read {$source}\n" );
		exit( 1 );
	}

	return $xml;
}

// Stated rather than assumed. SimpleXML is enabled on most builds and this script
// is also run by a CI job on a runner whose default extension set is not
// something this repository controls or can check - so the requirement is
// asserted here, where the message can name the fix, instead of arriving as an
// undefined-function fatal several lines later.
if ( ! function_exists( 'simplexml_load_string' ) ) {
	fwrite( STDERR, "This script needs the SimpleXML extension (php -m | grep -i simplexml).\n" );
	exit( 1 );
}

$source = $argv[1] ?? SOURCE_URL;
$xml    = read_source( $source );

$previous = libxml_use_internal_errors( true );
$doc      = simplexml_load_string( $xml );
libxml_use_internal_errors( $previous );

if ( false === $doc ) {
	fwrite( STDERR, "Metadata is not parseable XML.\n" );
	exit( 1 );
}

$countries = array();
$corpus    = array();

foreach ( $doc->xpath( '//territory' ) as $territory ) {
	$id = (string) $territory['id'];

	// Two-letter ids only: the metadata also carries non-geographic entries
	// keyed by calling code (800, 808, 870, ...), which no billing address has.
	if ( 2 !== strlen( $id ) || 1 !== preg_match( '/^[A-Z]{2}$/', $id ) ) {
		continue;
	}

	$calling_code    = (string) $territory['countryCode'];
	$national_prefix = isset( $territory['nationalPrefix'] ) ? (string) $territory['nationalPrefix'] : '';

	if ( '' === $calling_code ) {
		continue;
	}

	$countries[ $id ] = array( $calling_code, $national_prefix );

	// One corpus case per number type: the country's own example number, spelled
	// the way somebody there would type it (national prefix, where one exists),
	// against the E.164 the metadata says it is.
	foreach ( array( 'fixedLine', 'mobile' ) as $type ) {
		if ( ! isset( $territory->{$type}->exampleNumber ) ) {
			continue;
		}

		$nsn = trim( (string) $territory->{$type}->exampleNumber );
		if ( '' === $nsn ) {
			continue;
		}

		$corpus[] = array(
			'country'  => $id,
			'type'     => $type,
			'typed'    => $national_prefix . $nsn,
			'expected' => '+' . $calling_code . $nsn,
		);
	}
}

ksort( $countries );

$with_prefix = count( array_filter( $countries, static fn ( array $row ): bool => '' !== $row[1] ) );

// ---------------------------------------------------------------- table file.

$rows = '';
foreach ( $countries as $code => list( $calling_code, $national_prefix ) ) {
	$rows .= sprintf(
		"\t\t'%s' => array( '%s', %s ),\n",
		$code,
		$calling_code,
		'' === $national_prefix ? 'null' : "'" . $national_prefix . "'"
	);
}

$generated_on = gmdate( 'Y-m-d' );

$table = <<<PHP
<?php
/**
 * GENERATED FILE - DO NOT EDIT BY HAND.
 *
 * Regenerate with: composer generate:phone-table
 * Source: Google libphonenumber, resources/PhoneNumberMetadata.xml
 * Generated: {$generated_on}
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Per-country calling code and national (trunk) prefix, used to turn a locally
 * typed phone number into E.164 before hashing it for Enhanced Conversions.
 *
 * A null prefix is NOT "unknown" - it means the country has no trunk prefix at
 * all, so a leading zero is part of the number and must be kept. Italy is the
 * best-known case; six more behave the same way. Conflating that with "uses 0"
 * is what made every Italian landline hash to a value Google could never match.
 *
 * See tools/generate-phone-table.php for why this is generated rather than
 * written, and what it deliberately does not model.
 */
final class CountryPhoneData {

	/**
	 * ISO 3166-1 alpha-2 code => array( calling code, national prefix or null ).
	 *
	 * {$with_prefix} of the entries have a trunk prefix; the rest have none.
	 *
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const COUNTRIES = array(
{$rows}	);

	/**
	 * Looks up a country's dialling facts.
	 *
	 * @param string \$country_code ISO 3166-1 alpha-2 code, any case, may be padded.
	 * @return array{0: string, 1: string|null}|null Calling code and national prefix,
	 *                                               or null when the country is unknown.
	 */
	public static function lookup( string \$country_code ): ?array {
		\$country_code = strtoupper( trim( \$country_code ) );

		return self::COUNTRIES[ \$country_code ] ?? null;
	}
}

PHP;

file_put_contents( TABLE_FILE, $table );

// --------------------------------------------------------------- corpus file.

// Keys padded to a common width: WPCS aligns the double arrows of an array
// block, so an unpadded generated file fails the project's own linter and the
// next person has to decide whether that is their fault.
$key_width = 0;
foreach ( $corpus as $case ) {
	$key_width = max( $key_width, strlen( $case['country'] . ' ' . $case['type'] ) + 2 );
}

$cases = '';
foreach ( $corpus as $case ) {
	$cases .= sprintf(
		"\t%s => array( '%s', '%s', '%s' ),\n",
		str_pad( "'" . $case['country'] . ' ' . $case['type'] . "'", $key_width ),
		$case['country'],
		$case['typed'],
		$case['expected']
	);
}

$corpus_count = count( $corpus );

$corpus_file = <<<PHP
<?php
/**
 * GENERATED FILE - DO NOT EDIT BY HAND.
 *
 * Regenerate with: composer generate:phone-table
 * Source: Google libphonenumber, resources/PhoneNumberMetadata.xml
 * Generated: {$generated_on}
 *
 * Every territory's own example number, spelled the way somebody there would
 * type it, against the E.164 the numbering plan says it is. {$corpus_count} cases.
 *
 * This is an ORACLE, not a mirror of our own output: both halves are read out of
 * the metadata (national prefix + example number in, calling code + example
 * number out), so it fails when Helpers::normalize_phone_number() disagrees with
 * the numbering plan rather than when it changes.
 *
 * A case here is one canonical spelling per country. It therefore proves the
 * per-country table is right and proves nothing about how people actually type -
 * courtesy zeros, extensions and international access codes are covered by the
 * hand-written cases in HelpersTest instead.
 *
 * @package GTM4WP
 */

return array(
{$cases});

PHP;

file_put_contents( CORPUS_FILE, $corpus_file );

printf(
	"Wrote %s (%d countries, %d with a trunk prefix)\nWrote %s (%d cases)\n",
	realpath( TABLE_FILE ),
	count( $countries ),
	$with_prefix,
	realpath( CORPUS_FILE ),
	$corpus_count
);
