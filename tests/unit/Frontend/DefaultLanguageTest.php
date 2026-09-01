<?php
/**
 * Unit tests for the shared default (master) language resolver.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Frontend;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GTM4WP\Frontend\DefaultLanguage;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the WPML/Polylang resolution shared by the master-language data layer
 * features (issue #145). Pure static helper, so it extends the base TestCase
 * (TC-3).
 *
 * A Brain Monkey stub of pll_* (here or in ANY other test file) defines the
 * function process-wide, so every case whose expectation is "Polylang is not
 * installed" runs in its own process (TS-16) - in-file ordering cannot protect
 * them under --order-by=random or from another file's Polylang cases.
 */
final class DefaultLanguageTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_is_active_false_without_multilingual_plugin(): void {
		$this->assertFalse( DefaultLanguage::is_active() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_ids_unchanged_without_multilingual_plugin(): void {
		$this->assertSame( 42, DefaultLanguage::post_id( 42, 'post' ) );
		$this->assertSame( 30, DefaultLanguage::term_id( 30, 'category' ) );
	}

	public function test_non_positive_ids_are_returned_unchanged(): void {
		$this->assertSame( 0, DefaultLanguage::post_id( 0, 'post' ) );
		$this->assertSame( -5, DefaultLanguage::term_id( -5, 'category' ) );
	}

	public function test_wpml_resolves_post_and_term_ids_to_the_default_language(): void {
		$this->activate_wpml(
			array(
				42 => 8,
				30 => 3,
			)
		);

		$this->assertTrue( DefaultLanguage::is_active() );
		// Posts use the post type as the WPML element type, terms use the taxonomy.
		$this->assertSame( 8, DefaultLanguage::post_id( 42, 'post' ) );
		$this->assertSame( 3, DefaultLanguage::term_id( 30, 'category' ) );
	}

	public function test_wpml_untranslated_object_keeps_the_original_id(): void {
		// wpml_object_id's 4th `true` argument returns the original id when no
		// translation exists, so an untranslated object resolves to itself.
		$this->activate_wpml( array() );

		$this->assertSame( 42, DefaultLanguage::post_id( 42, 'post' ) );
		$this->assertSame( 30, DefaultLanguage::term_id( 30, 'category' ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_post_id_is_filterable(): void {
		// With no plugin active a third party can still drive resolution through
		// the filter alone (e.g. to support another multilingual plugin).
		Filters\expectApplied( 'gtm4wp_master_language_post_id' )->zeroOrMoreTimes()->andReturnUsing(
			static fn ( $resolved, $id ) => 42 === (int) $id ? 99 : $resolved
		);

		$this->assertSame( 99, DefaultLanguage::post_id( 42, 'post' ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_term_id_is_filterable(): void {
		Filters\expectApplied( 'gtm4wp_master_language_term_id' )->zeroOrMoreTimes()->andReturnUsing(
			static fn ( $resolved, $id ) => 30 === (int) $id ? 7 : $resolved
		);

		$this->assertSame( 7, DefaultLanguage::term_id( 30, 'category' ) );
	}

	public function test_polylang_resolves_post_and_term_ids_to_the_default_language(): void {
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_get_post' )->alias( static fn ( $id ): int => 42 === (int) $id ? 8 : 0 );
		Functions\when( 'pll_get_term' )->alias( static fn ( $id ): int => 30 === (int) $id ? 3 : 0 );

		$this->assertTrue( DefaultLanguage::is_active() );
		$this->assertSame( 8, DefaultLanguage::post_id( 42, 'post' ) );
		$this->assertSame( 3, DefaultLanguage::term_id( 30, 'category' ) );
	}

	public function test_polylang_untranslated_object_keeps_the_original_id(): void {
		// pll_get_post/pll_get_term return 0 when there is no translation.
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_get_post' )->justReturn( 0 );
		Functions\when( 'pll_get_term' )->justReturn( 0 );

		$this->assertSame( 42, DefaultLanguage::post_id( 42, 'post' ) );
		$this->assertSame( 30, DefaultLanguage::term_id( 30, 'category' ) );
	}

	/**
	 * Activates WPML for a test: registers the wpml_current_language filter (so
	 * is_active() and the resolver detect WPML) and maps wpml_object_id /
	 * wpml_default_language.
	 *
	 * @param array<int, int> $map Current-language id => default-language id.
	 * @return void
	 */
	private function activate_wpml( array $map ): void {
		add_filter( 'wpml_current_language', static fn () => 'de' );
		Filters\expectApplied( 'wpml_default_language' )->zeroOrMoreTimes()->andReturn( 'en' );
		Filters\expectApplied( 'wpml_object_id' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id ) use ( $map ) {
				return $map[ (int) $id ] ?? $id;
			}
		);
	}
}
