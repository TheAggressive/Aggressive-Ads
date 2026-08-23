<?php
/**
 * Service container tests.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit;

use ArrayObject;
use Aggressive\Ads\Container_Exception;
use Aggressive\Ads\Service_Container;
use stdClass;
use PHPUnit\Framework\TestCase;

/**
 * The container's contract: lazy, singleton, explicit, and loud about mistakes.
 */
final class ServiceContainerTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var Service_Container
	 */
	private Service_Container $container;

	/**
	 * Sets up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = new Service_Container();
	}

	/**
	 * Registration stores a factory and runs nothing. This is the property the
	 * whole register/init split depends on.
	 *
	 * @return void
	 */
	public function test_registration_does_not_invoke_the_factory(): void {
		$called = false;

		$this->container->register(
			stdClass::class,
			static function () use ( &$called ): stdClass {
				$called = true;

				return new stdClass();
			}
		);

		$this->assertTrue( $this->container->has( stdClass::class ) );
		$this->assertFalse( $called, 'Registering a service must not construct it.' );
	}

	/**
	 * The factory runs on first get(), and only once.
	 *
	 * @return void
	 */
	public function test_resolves_lazily_and_only_once(): void {
		$calls = 0;

		$this->container->register(
			stdClass::class,
			static function () use ( &$calls ): stdClass {
				++$calls;

				return new stdClass();
			}
		);

		$first  = $this->container->get( stdClass::class );
		$second = $this->container->get( stdClass::class );

		$this->assertSame( 1, $calls );
		$this->assertSame( $first, $second );
	}

	/**
	 * The factory receives the container, which is how a service declares its
	 * dependencies without reflection.
	 *
	 * @return void
	 */
	public function test_factory_receives_the_container(): void {
		$this->container->register( stdClass::class, static fn (): stdClass => new stdClass() );

		$this->container->register(
			ArrayObject::class,
			static fn ( Service_Container $c ): ArrayObject => new ArrayObject( array( $c->get( stdClass::class ) ) )
		);

		$resolved = $this->container->get( ArrayObject::class );

		$this->assertCount( 1, $resolved );
		$this->assertSame( $this->container->get( stdClass::class ), $resolved[0] );
	}

	/**
	 * A second registration under the same id is a boot-time mistake, not a
	 * silent override whose winner depends on file order.
	 *
	 * @return void
	 */
	public function test_duplicate_registration_throws(): void {
		$this->container->register( stdClass::class, static fn (): stdClass => new stdClass() );

		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'already registered' );

		$this->container->register( stdClass::class, static fn (): stdClass => new stdClass() );
	}

	/**
	 * Asking for something unregistered names what was asked for.
	 *
	 * @return void
	 */
	public function test_unregistered_service_throws(): void {
		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'stdClass' );

		$this->container->get( stdClass::class );
	}

	/**
	 * The id is the contract. A factory returning something else would make
	 * every downstream type annotation a lie.
	 *
	 * @return void
	 */
	public function test_factory_returning_the_wrong_type_throws(): void {
		/*
		 * Deliberately mis-wired, which is exactly the mistake being guarded
		 * against; the annotation is suppressed rather than the guard weakened.
		 *
		 * @phpstan-ignore argument.type
		 */
		$this->container->register( ArrayObject::class, static fn (): stdClass => new stdClass() );

		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'returned stdClass' );

		$this->container->get( ArrayObject::class );
	}

	/**
	 * A dependency cycle reports the chain, instead of exhausting the stack
	 * several frames away from the actual mistake.
	 *
	 * @return void
	 */
	public function test_circular_dependency_throws_naming_the_chain(): void {
		$this->container->register(
			stdClass::class,
			static fn ( Service_Container $c ): stdClass => (object) array( 'dep' => $c->get( ArrayObject::class ) )
		);

		$this->container->register(
			ArrayObject::class,
			static fn ( Service_Container $c ): ArrayObject => new ArrayObject( array( $c->get( stdClass::class ) ) )
		);

		$this->expectException( Container_Exception::class );
		$this->expectExceptionMessage( 'Circular dependency' );

		$this->container->get( stdClass::class );
	}

	/**
	 * A failed resolution must not leave the container wedged: the id has to
	 * come off the resolving stack even when the factory throws, or every
	 * later attempt reports a phantom cycle.
	 *
	 * @return void
	 */
	public function test_a_throwing_factory_does_not_wedge_later_resolution(): void {
		$attempts = 0;

		$this->container->register(
			stdClass::class,
			static function () use ( &$attempts ): stdClass {
				++$attempts;

				if ( 1 === $attempts ) {
					throw new Container_Exception( 'transient failure' );
				}

				return new stdClass();
			}
		);

		try {
			$this->container->get( stdClass::class );
		} catch ( Container_Exception $e ) {
			$this->assertSame( 'transient failure', $e->getMessage() );
		}

		$this->assertInstanceOf( stdClass::class, $this->container->get( stdClass::class ) );
	}

	/**
	 * Registration order is reported back, which is what makes the wiring
	 * inspectable.
	 *
	 * @return void
	 */
	public function test_ids_are_reported_in_registration_order(): void {
		$this->container->register( stdClass::class, static fn (): stdClass => new stdClass() );
		$this->container->register( ArrayObject::class, static fn (): ArrayObject => new ArrayObject() );

		$this->assertSame(
			array( stdClass::class, ArrayObject::class ),
			$this->container->ids()
		);
	}

	/**
	 * Registration is what has() reports, not resolution.
	 *
	 * @return void
	 */
	public function test_has_reports_unregistered_ids_as_absent(): void {
		$this->assertFalse( $this->container->has( stdClass::class ) );
	}
}
