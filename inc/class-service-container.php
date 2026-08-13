<?php
/**
 * The service container.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

/**
 * Lazy singletons, keyed by class name, with no reflection and no autowiring.
 *
 * Registration stores a factory closure and instantiates nothing. A service
 * comes into existence the first time something asks for it, and exactly once
 * after that.
 *
 * Autowiring was deliberately not built. Wiring stays greppable: every
 * dependency a service takes is visible in the one file that registers it,
 * which is what you want at 2am and not what a reflection-based container
 * gives you.
 */
final class Service_Container {

	/**
	 * Factory closures, keyed by service id.
	 *
	 * @var array<string, callable(self): object>
	 */
	private array $factories = array();

	/**
	 * Resolved instances, keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Ids currently being resolved, used to detect dependency cycles.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Registers a factory for a service.
	 *
	 * @template T of object
	 * @param string   $id      Service id — always the class name it produces.
	 * @param callable $factory Builds the service. Must not add hooks or touch WordPress.
	 * @return void
	 *
	 * @phpstan-param class-string<T>   $id
	 * @phpstan-param callable(self): T $factory
	 *
	 * @throws Container_Exception When the id is already registered.
	 */
	public function register( string $id, callable $factory ): void {
		if ( isset( $this->factories[ $id ] ) ) {
			// Silently replacing would mean a second registration quietly wins,
			// and which one wins depends on file order. Better to fail at boot.
			throw new Container_Exception(
				sprintf( 'Service "%s" is already registered.', $id )
			);
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Reports whether a service is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolves a service, building it on first use.
	 *
	 * @template T of object
	 * @param string $id Service id.
	 * @return object
	 *
	 * @phpstan-param class-string<T> $id
	 * @phpstan-return T
	 *
	 * @throws Container_Exception When the id is unregistered, cyclic, or the factory returns the wrong type.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			/**
			 * Already proven to be an instance of $id when it was first stored.
			 *
			 * @var T $cached
			 */
			$cached = $this->instances[ $id ];

			return $cached;
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new Container_Exception(
				sprintf( 'Service "%s" is not registered.', $id )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			// Without this the cycle is a fatal "maximum function nesting"
			// somewhere unrelated, several frames from the actual mistake.
			throw new Container_Exception(
				sprintf(
					'Circular dependency resolving "%s" (chain: %s).',
					$id,
					implode( ' -> ', array_keys( $this->resolving ) )
				)
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$instance = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( ! $instance instanceof $id ) {
			// The id is the contract. A factory returning something else makes
			// every type annotation downstream a lie, so catch it at the source.
			throw new Container_Exception(
				sprintf(
					'Factory for "%s" returned %s.',
					$id,
					get_debug_type( $instance )
				)
			);
		}

		$this->instances[ $id ] = $instance;

		/**
		 * Narrowed by the instanceof check above.
		 *
		 * @var T $instance
		 */
		return $instance;
	}

	/**
	 * Every registered service id, in registration order.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_keys( $this->factories );
	}
}
