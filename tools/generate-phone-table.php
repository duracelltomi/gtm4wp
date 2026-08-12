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
 * THE THIRD COLUMN, AND WHY IT IS NOT THE ONE WE SAID WE WOULD NOT ADD
 * --------------------------------------------------------------------
 * Those two facts are enough to take a number apart. They are NOT enough to
 * decide which way to take it apart, because two readings of the same digits are
 * often both well-formed: "34 612 345 678" from a Spanish address is the
 * international form with the "+" left off, while "391 234 5678" from an Italian
 * one is a national mobile that merely begins with Italy's own calling code.
 *
 * The note that used to sit here said telling those apart needs per-country
 * possible lengths. Measured, that is wrong twice over: lengths do not settle
 * Italy (both readings are possible Italian lengths), and what does settle it -
 * the general national-number pattern - is one compact regex per territory,
 * 32 bytes on average, in the file we already download. So the third column is
 * shape, not length, and it costs ~11 KB against the ~1.9 MB of adopting the
 * library.
 *
 * It is used ONLY as a tie-breaker, never as a validator. A stale pattern can
 * therefore fail to improve a number but can never REJECT one - measured across
 * 558,103 inputs, there is no value the pattern column causes to be refused that
 * the previous code accepted - which is what keeps this inside upstream UC-5
 * ("do not encode the future as a validator").
 *
 * What it is NOT is a no-op when the pattern misses. An unrecognised number falls
 * through to the positional rules, and those are now applied UNIFORMLY: before
 * this column existed, a territory with no trunk prefix returned early ("anchor
 * and stop") and never reached the calling-code test, so for the 101 such
 * territories the fall-through differs from the older behaviour. The trade was
 * measured rather than assumed - under a fully stale pattern it is right in 6,060
 * sampled cases and wrong in 7, because a national number beginning with its own
 * calling code is far rarer than someone typing that calling code without a "+".
 * Do not "restore" the old asymmetry on the strength of the 7.
 *
 * WHAT IT STILL DELIBERATELY DOES NOT CARRY
 * -----------------------------------------
 * libphonenumber additionally models nationalPrefixForParsing,
 * nationalPrefixTransformRule and per-country international prefixes, which is
 * what it needs to handle Argentina's mobile "9", Brazil's carrier-selection
 * codes, and a caller who dials a foreign number using their own international
 * access code. Those stay unhandled on purpose, and THEY are the trigger: the
 * next time this table needs a column, it needs transform RULES rather than
 * facts, which is reimplementing the library one field at a time. That is the
 * point to adopt it instead. See the local review report for the measurement.
 *
 * USAGE
 * -----
 *     composer generate:phone-table
 *     php tools/generate-phone-table.php [path-or-url-to-PhoneNumberMetadata.xml]
 *
 * The composer script takes no argument, so it always fetches over the network and
 * there is no offline path through it. To work from a local copy - on a plane, or
 * to re-run a generation against the exact bytes a previous one used - pass the
 * path directly:
 *
 *     php tools/generate-phone-table.php /path/to/PhoneNumberMetadata.xml
 *
 * Note what that argument is, though: it chooses the source this script turns into
 * shipped PHP, so a file somebody sent you is a supply-chain decision, not a
 * convenience (PA-18). The validation below is written on that basis - it checks
 * the SHAPE of what it read rather than trusting where it came from.
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

/**
 * Refuses to continue, naming the territory and the field.
 *
 * Every check below is fatal rather than a skip. A generator that quietly drops
 * a territory writes a smaller table that still looks generated, and the release
 * gate reads its fresh date stamp as "checked recently" (PA-18).
 *
 * @param string $message What is wrong.
 * @return never
 */
function refuse( string $message ) {
	fwrite( STDERR, "Refusing to write: {$message}\n" );
	exit( 1 );
}

/**
 * Writes a generated file, or refuses.
 *
 * file_put_contents() returns false on a read-only target and a short count on a
 * full disk, and the script used to print "Wrote ..." either way - so a run that
 * wrote nothing looked exactly like a run that worked, and the previous copy
 * kept its old date stamp while the operator believed it had been refreshed.
 *
 * @param string $path     Destination.
 * @param string $contents Generated source.
 * @return void
 */
function write_or_refuse( string $path, string $contents ): void {
	$written = file_put_contents( $path, $contents );

	if ( false === $written || strlen( $contents ) !== $written ) {
		refuse( "could not write {$path}" );
	}
}

/**
 * The characters a national-number pattern is allowed to contain.
 *
 * Measured across all 245 territories: digits, the regex metacharacters of a
 * digit grammar, and backslash. Anything outside it - a quote, a slash, a
 * character class we do not expect - would either break the preg delimiter at
 * runtime or the PHP literal at generation time, so it stops the run here rather
 * than shipping. This is a floor on the SHAPE of upstream data, not a validator
 * on phone numbers.
 */
const PATTERN_GRAMMAR = '#^[0-9A-Za-z\[\]\(\)\?\:\|\{\}\,\-\\\\]+$#';

/** Below this many territories, assume a truncated fetch rather than a numbering-plan event. */
const MINIMUM_TERRITORIES = 200;

$countries = array();
$corpus    = array();
$ambiguous = array();

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

	// Both are dialled digits. Anything else is upstream changing shape, which is
	// a thing to look at rather than to interpolate into a source file.
	if ( 1 !== preg_match( '/^\d+$/', $calling_code ) ) {
		refuse( "{$id} has a non-numeric calling code" );
	}
	if ( '' !== $national_prefix && 1 !== preg_match( '/^\d+$/', $national_prefix ) ) {
		refuse( "{$id} has a non-numeric national prefix" );
	}

	$pattern = isset( $territory->generalDesc->nationalNumberPattern )
		? (string) preg_replace( '/\s+/', '', (string) $territory->generalDesc->nationalNumberPattern )
		: '';

	if ( '' === $pattern ) {
		refuse( "{$id} has no general national-number pattern" );
	}
	if ( 1 !== preg_match( PATTERN_GRAMMAR, $pattern ) ) {
		refuse( "{$id}'s pattern contains a character outside the expected digit grammar" );
	}
	if ( false === @preg_match( '#^(?:' . $pattern . ')$#', '' ) ) {
		refuse( "{$id}'s pattern does not compile: " . preg_last_error_msg() );
	}

	$countries[ $id ] = array( $calling_code, $national_prefix, $pattern );

	foreach ( array( 'fixedLine', 'mobile' ) as $type ) {
		if ( ! isset( $territory->{$type}->exampleNumber ) ) {
			continue;
		}

		$nsn = trim( (string) $territory->{$type}->exampleNumber );
		if ( '' === $nsn ) {
			continue;
		}

		$e164 = '+' . $calling_code . $nsn;

		// (a) The country's own example number spelled the way somebody there
		// would type it - national prefix where one exists.
		$corpus[] = array(
			'country'  => $id,
			'type'     => $type,
			'spelling' => 'national',
			'typed'    => $national_prefix . $nsn,
			'expected' => $e164,
		);

		// (b) The same number with the calling code and no "+". This is the
		// spelling the third column exists for, and it is the one the previous
		// two-column table got wrong for every territory without a trunk prefix.
		//
		// Excluded where the calling code followed by the national number is
		// ITSELF a valid national number here: both readings are then genuinely
		// correct, nothing in the metadata prefers one, and a fixture that picked
		// one would be asserting a guess (upstream UC-3). Counted, not silently
		// dropped.
		if ( 1 === preg_match( '#^(?:' . $pattern . ')$#', $calling_code . $nsn ) ) {
			$ambiguous[] = $id . ' ' . $type;
			continue;
		}

		$corpus[] = array(
			'country'  => $id,
			'type'     => $type,
			'spelling' => 'intl-no-plus',
			'typed'    => $calling_code . $nsn,
			'expected' => $e164,
		);
	}
}

ksort( $countries );

if ( count( $countries ) < MINIMUM_TERRITORIES ) {
	refuse( sprintf( 'only %d territories parsed, expected at least %d', count( $countries ), MINIMUM_TERRITORIES ) );
}

$with_prefix    = count( array_filter( $countries, static fn ( array $row ): bool => '' !== $row[1] ) );
$without_prefix = count( $countries ) - $with_prefix;

// ---------------------------------------------------------------- table file.

// var_export(), never "'" . $value . "'": this writes PHP source from a file
// somebody else controls, so an unescaped quote or a trailing backslash in an
// upstream value would break the literal - or worse, close it (PA-18). The
// grammar checks above make that unreachable today; this makes it unreachable
// by construction, which is the difference between a check and a guarantee.
$rows = '';
foreach ( $countries as $code => list( $calling_code, $national_prefix, $pattern ) ) {
	$rows .= sprintf(
		"\t\t%s => array( %s, %s, %s ),\n",
		var_export( $code, true ),
		var_export( $calling_code, true ),
		'' === $national_prefix ? 'null' : var_export( $national_prefix, true ),
		var_export( $pattern, true )
	);
}

$generated_on    = gmdate( 'Y-m-d' );
$total_countries = count( $countries );

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
 * Per-country dialling facts, used to turn a locally typed phone number into
 * E.164 before hashing it for Enhanced Conversions.
 *
 * Three columns, and the third is the one that decides between two readings of
 * the same digits:
 *
 * 1. **Calling code.** The country's international prefix, without the "+".
 * 2. **National (trunk) prefix, or null.** Null is NOT "unknown" - it means the
 *    country has no trunk prefix at all, so a leading zero is part of the number
 *    and must be kept. {$without_prefix} of the {$total_countries} entries are null; of those, Italy
 *    is the commercially important one, because conflating "no trunk prefix"
 *    with "uses 0" made every Italian landline hash to a value Google could
 *    never match.
 * 3. **General national-number pattern.** What a number of this country looks
 *    like. Used ONLY to choose between "this is a national number" and "this is
 *    the international form with the + left off" - never to reject a number, so a
 *    stale pattern can fail to improve a number but cannot refuse one (upstream
 *    UC-5). A number it does not recognise falls through to the positional rules,
 *    which are applied uniformly across all territories - not to the behaviour
 *    that predated this column, which returned early for the ones with no trunk
 *    prefix. See tools/generate-phone-table.php for the measurement behind that.
 *
 * See tools/generate-phone-table.php for why this is generated rather than
 * written, and what it deliberately does not model.
 */
final class CountryPhoneData {

	/**
	 * ISO 3166-1 alpha-2 code => array( calling code, national prefix or null, national-number pattern ).
	 *
	 * {$with_prefix} of the {$total_countries} entries have a trunk prefix; {$without_prefix} have none.
	 *
	 * @var array<string, array{0: string, 1: string|null, 2: string}>
	 */
	private const COUNTRIES = array(
{$rows}	);

	/**
	 * Looks up a country's dialling facts.
	 *
	 * @param string \$country_code ISO 3166-1 alpha-2 code, any case, may be padded.
	 * @return array{0: string, 1: string|null, 2: string}|null Calling code, national prefix
	 *                                                          and national-number pattern,
	 *                                                          or null when the country is unknown.
	 */
	public static function lookup( string \$country_code ): ?array {
		\$country_code = strtoupper( trim( \$country_code ) );

		return self::COUNTRIES[ \$country_code ] ?? null;
	}
}

PHP;

write_or_refuse( TABLE_FILE, $table );

// --------------------------------------------------------------- corpus file.

// Keys padded to a common width: WPCS aligns the double arrows of an array
// block, so an unpadded generated file fails the project's own linter and the
// next person has to decide whether that is their fault.
$key_width = 0;
foreach ( $corpus as $case ) {
	$key_width = max( $key_width, strlen( $case['country'] . ' ' . $case['type'] . ' ' . $case['spelling'] ) + 2 );
}

$cases = '';
foreach ( $corpus as $case ) {
	$cases .= sprintf(
		"\t%s => array( %s, %s, %s ),\n",
		str_pad( var_export( $case['country'] . ' ' . $case['type'] . ' ' . $case['spelling'], true ), $key_width ),
		var_export( $case['country'], true ),
		var_export( $case['typed'], true ),
		var_export( $case['expected'], true )
	);
}

$corpus_count    = count( $corpus );
$ambiguous_count = count( $ambiguous );
$ambiguous_list  = '' === implode( '', $ambiguous ) ? '(none)' : implode( ', ', $ambiguous );

$corpus_file = <<<PHP
<?php
/**
 * GENERATED FILE - DO NOT EDIT BY HAND.
 *
 * Regenerate with: composer generate:phone-table
 * Source: Google libphonenumber, resources/PhoneNumberMetadata.xml
 * Generated: {$generated_on}
 *
 * Every territory's own example number in TWO spellings, against the E.164 the
 * numbering plan says it is. {$corpus_count} cases.
 *
 * This is an ORACLE, not a mirror of our own output: both halves are read out of
 * the metadata (example number in, calling code + example number out), so it
 * fails when Helpers::normalize_phone_number() disagrees with the numbering plan
 * rather than when it changes.
 *
 * The two spellings, because one of them proves nothing about the other:
 *
 * - `national`     - the way somebody in that country types it, trunk prefix
 *                    included where one exists. Proves the calling code and the
 *                    trunk-prefix column.
 * - `intl-no-plus` - the calling code with the "+" left off. Proves the pattern
 *                    column, which is the only thing that can tell this from a
 *                    national number that happens to start with the same digits.
 *
 * {$ambiguous_count} example numbers carry NO `intl-no-plus` case, because for them the
 * calling code followed by the national number is itself a valid national number
 * of that country: both readings are correct and the metadata prefers neither,
 * so a case here would assert a guess rather than the plan. Excluded: {$ambiguous_list}.
 *
 * Beyond these two spellings the fixture proves nothing about how people type -
 * courtesy zeros, extensions, international access codes and the fall-through
 * are covered by the hand-written cases in HelpersTest instead.
 *
 * @package GTM4WP
 */

return array(
{$cases});

PHP;

write_or_refuse( CORPUS_FILE, $corpus_file );

printf(
	"Wrote %s (%d countries, %d with a trunk prefix, %d without)\nWrote %s (%d cases, %d intl-no-plus spellings excluded as ambiguous)\n",
	realpath( TABLE_FILE ),
	$total_countries,
	$with_prefix,
	$without_prefix,
	realpath( CORPUS_FILE ),
	$corpus_count,
	$ambiguous_count
);
