<?php
/**
 * Regenerates the country phone table and its regression corpus from Google's
 * libphonenumber metadata.
 *
 * WHY GENERATED: E.164 conversion needs each country's calling code and its
 * national (trunk) prefix, numbering-plan facts nothing here can verify (UD-1
 * mirror). A hand-kept table was wrong for 15 territories: the domain has THREE
 * cases ("0", something else, or no trunk prefix with a leading zero that is
 * part of the number, as in Italy), and a default can only express two.
 *
 * THE THIRD COLUMN: two readings of the same digits are often both well-formed
 * ("34 612 345 678" is an international form without "+", "391 234 5678" an
 * Italian mobile starting with Italy's own code). Lengths do not settle it; the
 * general national-number pattern does (~32 bytes per territory, ~11 KB total).
 * It is a tie-breaker ONLY, never a validator: a stale pattern can fail to
 * improve a number but never REJECT one (UC-5). An unrecognised number falls
 * through to positional rules applied UNIFORMLY, including the 101 territories
 * without a trunk prefix that used to return early; measured under a fully
 * stale pattern that is right in 6,060 sampled cases and wrong in 7. Do not
 * "restore" the old asymmetry on the strength of the 7.
 *
 * NOT CARRIED: nationalPrefixForParsing, nationalPrefixTransformRule and
 * per-country international prefixes (Argentina's mobile "9", Brazil's carrier
 * codes, foreign access codes). Needing transform RULES rather than facts is
 * the trigger to adopt the library instead of reimplementing it field by field.
 *
 * USAGE
 *     composer generate:phone-table
 *     php tools/generate-phone-table.php [path-or-url-to-PhoneNumberMetadata.xml]
 *
 * The composer script always fetches; pass a local path to work offline or to
 * re-run against the exact bytes of a previous generation. That argument picks
 * the source this script turns into shipped PHP, so a file somebody sent you is
 * a supply-chain decision (PA-18): the validation checks the SHAPE of what it
 * read, not where it came from. tests/unit/Modules/phone-corpus.php is
 * regenerated from the same parse, so the two cannot disagree.
 *
 * @package GTM4WP
 */

declare( strict_types = 1 );

const SOURCE_URL = 'https://raw.githubusercontent.com/google/libphonenumber/master/resources/PhoneNumberMetadata.xml';

const TABLE_FILE  = __DIR__ . '/../src/Ecommerce/CountryPhoneData.php';
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

// Asserted where the message can name the fix: the CI runner's extension
// set is not this repository's to check.
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
 * Refuses to continue, naming the territory and the field. Every check is
 * fatal, not a skip: a quietly smaller table still looks generated and the
 * release gate reads its fresh stamp as "checked recently" (PA-18).
 *
 * @param string $message What is wrong.
 * @return never
 */
function refuse( string $message ) {
	fwrite( STDERR, "Refusing to write: {$message}\n" );
	exit( 1 );
}

/**
 * Writes a generated file, or refuses: file_put_contents() returns false or a
 * short count, and a run that wrote nothing must not look like one that worked.
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
 * The characters a national-number pattern may contain (measured across all
 * 245 territories). Anything else could break the preg delimiter or the PHP
 * literal, so it stops the run. A floor on the SHAPE of upstream data, not a
 * validator on phone numbers.
 */
const PATTERN_GRAMMAR = '#^[0-9A-Za-z\[\]\(\)\?\:\|\{\}\,\-\\\\]+$#';

/** Below this many territories, assume a truncated fetch rather than a numbering-plan event. */
const MINIMUM_TERRITORIES = 200;

$countries = array();
$corpus    = array();
$ambiguous = array();

foreach ( $doc->xpath( '//territory' ) as $territory ) {
	$id = (string) $territory['id'];

	// Two-letter ids only: non-geographic entries (800, 808, 870, ...) have
	// no billing address.
	if ( 2 !== strlen( $id ) || 1 !== preg_match( '/^[A-Z]{2}$/', $id ) ) {
		continue;
	}

	$calling_code    = (string) $territory['countryCode'];
	$national_prefix = isset( $territory['nationalPrefix'] ) ? (string) $territory['nationalPrefix'] : '';

	if ( '' === $calling_code ) {
		continue;
	}

	// Both are dialled digits; anything else is upstream changing shape.
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

		// (a) The example number as somebody there would type it.
		$corpus[] = array(
			'country'  => $id,
			'type'     => $type,
			'spelling' => 'national',
			'typed'    => $national_prefix . $nsn,
			'expected' => $e164,
		);

		// (b) The same number with the calling code and no "+", the spelling
		// the third column exists for. Excluded (and counted) where that is
		// ITSELF a valid national number: a fixture picking one reading would
		// assert a guess (UC-3).
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

// var_export(), never "'" . $value . "'": this writes PHP source from a
// file somebody else controls, and a quote or trailing backslash would
// close the literal (PA-18).
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

namespace GTM4WP\Ecommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Per-country dialling facts, used to turn a locally typed phone number into
 * E.164 before hashing it for Enhanced Conversions. Three columns:
 *
 * 1. **Calling code**, without the "+".
 * 2. **National (trunk) prefix, or null.** Null is NOT "unknown": the country
 *    has no trunk prefix, so a leading zero is part of the number ({$without_prefix} of
 *    {$total_countries} entries; Italy is the commercially important one).
 * 3. **General national-number pattern.** Used ONLY to choose between "a
 *    national number" and "the international form with the + left off", never
 *    to reject a number (UC-5); an unrecognised number falls through to the
 *    positional rules.
 *
 * See tools/generate-phone-table.php for what it deliberately does not model.
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

// Keys padded: WPCS aligns the double arrows of an array block.
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
