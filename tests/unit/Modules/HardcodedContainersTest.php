<?php
/**
 * Unit tests for the wp-config.php container overrides.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the lock report the settings screen renders from
 * (locks()/is_active()) next to the override it describes (apply()), because
 * the two disagreeing is the whole bug class this file exists for: a constant
 * that overrides the container at output time while the settings screen stays
 * editable lets an admin save a container ID that never loads.
 *
 * Every constant-defining test runs in its own process - a constant cannot be
 * undefined once set, so they would otherwise leak into each other.
 */
final class HardcodedContainersTest extends TestCase {

	/**
	 * Two stored container rows used as the starting point of the overrides.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function stored_rows(): array {
		return ContainerRows::normalize(
			array(
				array(
					'id'          => 'GTM-AAA111',
					'gtm_auth'    => 'stored-auth',
					'gtm_preview' => 'env-7',
					'domain'      => 'first.example.com',
				),
				array(
					'id'     => 'GTM-BBB222',
					'domain' => 'second.example.com',
				),
			)
		);
	}

	public function test_without_constants_nothing_is_locked(): void {
		$this->assertFalse( HardcodedContainers::is_active() );
		$this->assertSame(
			array(
				'columns' => array(),
				'rows'    => array(),
			),
			HardcodedContainers::locks()
		);

		list( $rows, $errors ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertSame( $this->stored_rows(), $rows, 'The stored rows are returned untouched.' );
		$this->assertSame( array(), $errors );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_hardcoded_gtm_id_locks_the_id_column_and_the_row_set(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'GTM-BBB222' );

		$locks = HardcodedContainers::locks();

		$this->assertTrue( HardcodedContainers::is_active() );
		$this->assertSame(
			array( ContainerRows::COLUMN_ID => 'GTM4WP_HARDCODED_GTM_ID' ),
			$locks['columns'],
			'The container ID column is fixed by the constant.'
		);
		$this->assertSame(
			array( 'GTM4WP_HARDCODED_GTM_ID' ),
			$locks['rows'],
			'The constant decides which containers are loaded, so the row set is fixed too.'
		);

		list( $rows ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertCount( 1, $rows, 'Only the hard coded container is loaded.' );
		$this->assertSame( 'GTM-BBB222', $rows[0][ ContainerRows::COLUMN_ID ] );
	}

	/**
	 * The negative case that keeps the lock honest: a malformed constant
	 * overrides nothing at output time, so it must not make the field read-only
	 * either - the admin has to be able to fix their container setup while a
	 * separate admin notice names the broken constant.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_rejected_hardcoded_gtm_id_locks_nothing(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', 'not-a-gtm-id' );

		$this->assertFalse( HardcodedContainers::is_active(), 'A rejected constant must leave the settings screen editable.' );
		$this->assertSame( array(), HardcodedContainers::locks()['columns'] );
		$this->assertSame( array(), HardcodedContainers::locks()['rows'] );

		list( $rows, $errors ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertSame( $this->stored_rows(), $rows, 'The stored container setup keeps loading.' );
		$this->assertSame( array( 'GTM4WP_HARDCODED_GTM_ID' ), $errors, 'The broken constant is still reported to the admin.' );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_single_hardcoded_env_parameter_locks_only_its_column(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', 'hard-auth' );

		$locks = HardcodedContainers::locks();

		$this->assertSame(
			array( ContainerRows::COLUMN_AUTH => 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ),
			$locks['columns']
		);
		$this->assertSame(
			array(),
			$locks['rows'],
			'One environment parameter alone does not decide which containers are loaded, so the rows stay editable.'
		);

		list( $rows ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertCount( 2, $rows, 'Every stored container keeps loading.' );
		$this->assertSame( 'hard-auth', $rows[0][ ContainerRows::COLUMN_AUTH ] );
		$this->assertSame( 'hard-auth', $rows[1][ ContainerRows::COLUMN_AUTH ] );
		$this->assertSame( 'env-7', $rows[0][ ContainerRows::COLUMN_PREVIEW ], 'The column that is not hard coded keeps its stored value.' );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_both_hardcoded_env_parameters_lock_the_row_set(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', 'hard-auth' );
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', 'env-42' );

		$locks = HardcodedContainers::locks();

		$this->assertSame(
			array(
				ContainerRows::COLUMN_AUTH    => 'GTM4WP_HARDCODED_GTM_ENV_AUTH',
				ContainerRows::COLUMN_PREVIEW => 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW',
			),
			$locks['columns']
		);
		$this->assertSame(
			array( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ),
			$locks['rows'],
			'A complete environment override belongs to one container, so the row set is decided by wp-config.php.'
		);

		list( $rows ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertCount( 1, $rows, 'Only the first container is loaded.' );
		$this->assertSame( 'env-42', $rows[0][ ContainerRows::COLUMN_PREVIEW ] );
	}

	/**
	 * An empty environment constant is a deliberate "clear the environment": it
	 * IS applied, therefore it locks its column - the admin must not be able to
	 * type a value into a cell that is forced empty on every request.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_empty_hardcoded_env_parameter_locks_its_column_without_an_error(): void {
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', '' );

		$this->assertSame(
			array( ContainerRows::COLUMN_AUTH => 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ),
			HardcodedContainers::locks()['columns']
		);
		$this->assertSame( array(), HardcodedContainers::locks()['rows'], 'An empty value cannot complete an environment, so the row set stays free.' );

		list( $rows, $errors ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertSame( '', $rows[0][ ContainerRows::COLUMN_AUTH ] );
		$this->assertSame( array(), $errors );
	}

	/**
	 * PHP allows an array constant, so wp-config.php can hand a non-scalar to
	 * every one of these constants. It must be rejected like any other malformed
	 * value instead of reaching explode()/preg_match() and fataling on a
	 * frontend request.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_non_scalar_constants_are_rejected_without_a_fatal(): void {
		define( 'GTM4WP_HARDCODED_GTM_ID', array( 'GTM-AAA111' ) );
		define( 'GTM4WP_HARDCODED_GTM_ENV_AUTH', array( 'hard-auth' ) );
		define( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW', array( 'env-42' ) );

		list( $rows, $errors ) = HardcodedContainers::apply( $this->stored_rows() );

		$this->assertSame( $this->stored_rows(), $rows, 'The stored container setup keeps loading.' );
		$this->assertSame(
			array(
				'GTM4WP_HARDCODED_GTM_ID',
				'GTM4WP_HARDCODED_GTM_ENV_AUTH',
				'GTM4WP_HARDCODED_GTM_ENV_PREVIEW',
			),
			$errors
		);
		$this->assertFalse( HardcodedContainers::is_active(), 'Nothing is overridden, so nothing is locked.' );
	}
}
