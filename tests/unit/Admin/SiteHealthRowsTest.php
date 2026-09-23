<?php
/**
 * Tests for the shared Site Health row formatters.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\SiteHealthRows;
use GTM4WP\Tests\unit\TestCase;

/**
 * Two properties every module row inherits from here: the copied text never
 * prints "undefined" (no formatter returns ''), and the `debug` twin is
 * English whatever the site language. The second is pinned with a translator
 * stub that marks every string it sees, so a debug value that went through
 * __() cannot pass as English.
 */
final class SiteHealthRowsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->alias( static fn ( string $text ): string => '[' . $text . ']' );
	}

	public function test_a_text_row_carries_the_value_as_its_own_debug_twin(): void {
		$this->assertSame(
			array(
				'label' => 'Measurement',
				'value' => 'G-ABC123',
				'debug' => 'G-ABC123',
			),
			SiteHealthRows::text( 'Measurement', 'G-ABC123' )
		);
	}

	public function test_a_translated_value_travels_with_its_english_twin(): void {
		$row = SiteHealthRows::text( 'Captured', '[no orders seen yet]', 'no orders seen yet' );

		$this->assertSame( '[no orders seen yet]', $row['value'] );
		$this->assertSame( 'no orders seen yet', $row['debug'] );
	}

	public function test_an_empty_value_becomes_a_dash_never_an_empty_string(): void {
		$row = SiteHealthRows::text( 'Version', '' );

		$this->assertSame( '-', $row['value'], 'Core prints an empty value as "undefined".' );
		$this->assertSame( '-', $row['debug'] );
	}

	public function test_the_state_words_are_translated_on_the_page_and_english_in_the_copy(): void {
		$this->assertSame( '[on]', SiteHealthRows::on_off( 'L', true )['value'] );
		$this->assertSame( 'on', SiteHealthRows::on_off( 'L', true )['debug'] );
		$this->assertSame( 'off', SiteHealthRows::on_off( 'L', false )['debug'] );
		$this->assertSame( '[yes]', SiteHealthRows::yes_no( 'L', true )['value'] );
		$this->assertSame( 'no', SiteHealthRows::yes_no( 'L', false )['debug'] );
		$this->assertSame( 'set', SiteHealthRows::set_or_empty( 'L', 'GTM-ABC' )['debug'] );
		$this->assertSame( '[empty]', SiteHealthRows::set_or_empty( 'L', '   ' )['value'], 'Whitespace is empty.' );
		$this->assertSame( 'none', SiteHealthRows::none( 'L' )['debug'] );
	}

	public function test_set_or_empty_never_carries_the_value_itself(): void {
		$row = SiteHealthRows::set_or_empty( 'Header', 'HTTP_X_SECRET_HEADER' );

		$this->assertStringNotContainsString( 'SECRET', $row['value'] );
		$this->assertStringNotContainsString( 'SECRET', $row['debug'] );
	}

	public function test_a_count_of_zero_is_the_digit_not_a_dash(): void {
		$this->assertSame( '0', SiteHealthRows::count( 'L', 0 )['value'] );
	}

	public function test_a_group_lists_every_switch_with_its_state_the_off_ones_included(): void {
		$row = SiteHealthRows::group(
			'Options',
			array(
				'wc-use-sku'       => true,
				'wc-customer-data' => false,
			)
		);

		$this->assertSame(
			array(
				'wc-use-sku'       => '[on]',
				'wc-customer-data' => '[off]',
			),
			$row['value']
		);
		$this->assertSame(
			array(
				'wc-use-sku'       => 'on',
				'wc-customer-data' => 'off',
			),
			$row['debug'],
			'Core prints each entry as "key: value" - the key is the option name, the state is English.'
		);
	}

	public function test_an_empty_group_or_list_is_none(): void {
		$this->assertSame( 'none', SiteHealthRows::group( 'L', array() )['debug'] );
		$this->assertSame( 'none', SiteHealthRows::items( 'L', array() )['debug'] );
		$this->assertSame( '[none]', SiteHealthRows::items( 'L', array( '', '' ) )['value'], 'Empty entries do not count.' );
		$this->assertSame( 'none', SiteHealthRows::assoc( 'L', array() )['debug'] );
	}

	public function test_items_are_joined_on_one_line(): void {
		$row = SiteHealthRows::items( 'Statuses', array( 'processing', 'completed' ) );

		$this->assertSame( 'processing, completed', $row['value'], 'A plain list would print as "0: processing" - one line instead.' );
		$this->assertSame( 'processing, completed', $row['debug'] );
	}

	public function test_assoc_rows_default_their_debug_twin_to_themselves(): void {
		$rows = array( 'GTM-ABC123' => 'environment: yes' );

		$this->assertSame( $rows, SiteHealthRows::assoc( 'Containers', $rows )['debug'] );
		$this->assertSame( array( 'GTM-ABC123' => 'env' ), SiteHealthRows::assoc( 'Containers', $rows, array( 'GTM-ABC123' => 'env' ) )['debug'] );
	}

	public function test_a_stamp_is_a_utc_date_or_a_dash(): void {
		$this->assertSame( '-', SiteHealthRows::stamp( 0 ) );
		$this->assertSame( '2027-01-15 08:00 UTC', SiteHealthRows::stamp( 1_800_000_000 ) );
	}
}
