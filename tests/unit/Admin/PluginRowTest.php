<?php
/**
 * Unit tests for the Plugins-page integration.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Admin;

use Brain\Monkey\Functions;
use GTM4WP\Admin\PluginRow;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the settings action link (only for this plugin's row) and the upgrade
 * notice output. The security-relevant assertion is that the upgrade_notice,
 * which comes from the remote wordpress.org update payload, is esc_html()'d
 * before it is echoed below the plugin description (finding #15 / RI-2): a
 * both-directions escaping test (TS-2).
 */
final class PluginRowTest extends TestCase {

	private const PLUGIN_BASENAME = 'duracelltomi-google-tag-manager-for-wordpress/duracelltomi-google-tag-manager-for-wordpress.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// Real htmlspecialchars for esc_html(), so the escaping is actually exercised.
		Functions\stubEscapeFunctions();
		Functions\when( 'plugin_basename' )->justReturn( self::PLUGIN_BASENAME );
		Functions\when( 'menu_page_url' )->justReturn( 'options-general.php?page=' . GTM4WP_ADMINSLUG );
	}

	public function test_add_action_links_prepends_a_settings_link_for_this_plugin(): void {
		$row = new PluginRow();

		$links = $row->add_action_links( array( '<a href="#deactivate">Deactivate</a>' ), self::PLUGIN_BASENAME );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'page=' . GTM4WP_ADMINSLUG, $links[0], 'The settings link points at the options page.' );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertSame( '<a href="#deactivate">Deactivate</a>', $links[1], 'Existing links are preserved after the settings link.' );
	}

	public function test_add_action_links_leaves_other_plugins_untouched(): void {
		$row = new PluginRow();

		$original = array( '<a href="#deactivate">Deactivate</a>' );
		$links    = $row->add_action_links( $original, 'another-plugin/another-plugin.php' );

		$this->assertSame( $original, $links, 'Links for other plugins are returned unchanged.' );
	}

	public function test_show_upgrade_notification_escapes_the_remote_notice(): void {
		$row = new PluginRow();

		ob_start();
		$row->show_upgrade_notification(
			array(),
			(object) array( 'upgrade_notice' => '<script>alert(1)</script>' )
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Important Upgrade Notice:', $output );
		// Present: the esc_html()'d form of the hostile notice.
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
		// Absent: the raw markup that would execute if the notice were not escaped.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
	}

	public function test_show_upgrade_notification_outputs_nothing_without_a_notice(): void {
		$row = new PluginRow();

		ob_start();
		$row->show_upgrade_notification( array(), (object) array() );
		$this->assertSame( '', ob_get_clean(), 'No notice property means no output.' );
	}

	public function test_show_upgrade_notification_outputs_nothing_for_a_blank_notice(): void {
		$row = new PluginRow();

		ob_start();
		$row->show_upgrade_notification( array(), (object) array( 'upgrade_notice' => '   ' ) );
		$this->assertSame( '', ob_get_clean(), 'A whitespace-only notice is treated as empty.' );
	}
}
