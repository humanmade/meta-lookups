<?php
/**
 * Helper for high efficiency meta lookups.
 *
 * @package HM\Meta_Lookups
 */
namespace HM\Meta_Lookups;

/**
 * Class Lookup
 */
class Lookup {

	/**
	 * WP cache prefix for meta lookups.
	 */
	const PREFIX = 'cached-lookups_';

	/**
	 * @var string The class instance name.
	 */
	public $name;

	/**
	 * @var string The object type, post|user|term|comment.
	 */
	public $object_type;

	/**
	 * @var string The meta key to query against.
	 */
	public $meta_key;

	/**
	 * @var string The object meta SQL table suffix.
	 */
	public $meta_table;

	/**
	 * @var string The column name for the object ID reference on the meta table.
	 */
	public $meta_table_id_column;

	/**
	 * @var int Cache group version reference for invalidating specific lookup caches.
	 */
	public $incrementor;

	/**
	 * Lookup constructor.
	 *
	 * @param $name
	 * @param $object_type
	 * @param $meta_key
	 *
	 * @throws \Exception
	 */
	public function __construct( $name, $object_type, $meta_key ) {
		$this->name        = $name;
		$this->object_type = $object_type;
		$this->meta_key    = $meta_key;

		if ( ! in_array( $object_type, [ 'post', 'term', 'user', 'comment' ], true ) ) {
			throw new \Exception( 'invalid-object-type', 'This class only support the following object types: post, term, user, comment. ' );
		}

		$this->meta_table           = "{$object_type}meta";
		$this->meta_table_id_column = "{$object_type}_id";
		$this->incrementor          = get_transient( $this->get_cache_group( false ) . '_inc' ) ?: 0;

		$this->register();
	}

	/**
	 * Get lookup instance based on name.
	 *
	 * @param $name
	 *
	 * @return Lookup|bool
	 */
	public static function get_instance( $name ) {
		$lookups = apply_filters( 'cached_lookups', [] );
		return $lookups[ $name ] ?? false;
	}

	/**
	 * Register required actions for this lookup.
	 */
	public function register() {
		$instance = $this;
		add_filter( 'cached_lookups', function ( $lookups ) use ( $instance ) {
			$lookups[ $instance->name ] = $instance;
			return $lookups;
		} );
		add_action( "update_{$this->object_type}_meta", [ $this, 'bust_lookup_on_update' ], 5, 4 );
		add_action( "delete_{$this->object_type}_meta", [ $this, 'bust_lookup_on_delete' ], 10, 3 );
		add_action( "added_{$this->object_type}_meta", [ $this, 'bust_lookup_on_add' ], 10, 4 );

		add_action( "flush_{$this->name}_lookups", [ $this, 'increment_cache_version' ] );
	}

	/**
	 * Returns associated object with the lookup value.
	 *
	 * @param $value
	 *
	 * @return bool|mixed
	 */
	public function get( $value, $single = true ) {
		global $wpdb;

		$objects = wp_cache_get( $value, $this->get_cache_group() );

		if ( ! is_array( $objects ) ) {

			// phpcs:disable
			$objects = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT `{$this->meta_table_id_column}` FROM `{$wpdb->{$this->meta_table}}` WHERE meta_key = %s AND meta_value = %s",
					$this->meta_key,
					$value
				)
			); // WPCS db call ok, db cache ok

			// phpcs:enable

			wp_cache_set( $value, $objects, $this->get_cache_group() );
		}

		if ( empty( $objects ) ) {
			return false;
		}

		return $single ? reset( $objects ) : $objects;
	}

	/**
	 * Clear lookup cache on meta update.
	 *
	 * @param $meta_id
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 */
	public function bust_lookup_on_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( $this->meta_key !== $meta_key ) {
			return;
		}

		/**
		 * Delete old and new value AFTER the update has been completed.
		 */
		$object_type = $this->object_type;
		$old_value   = get_metadata( $object_type, $object_id, $meta_key, true );
		$func        = function () use ( &$func, $old_value, $meta_value, $object_type ) {
			remove_action( "updated_{$object_type}_meta", $func );
			wp_cache_delete( $old_value, $this->get_cache_group() );
			wp_cache_delete( $meta_value, $this->get_cache_group() );
		};
		// Hook the anonymous function into the `updated_term_meta` call so that we clear cache AFTER DB update
		add_action( "updated_{$this->object_type}_meta", $func );
	}

	/**
	 * Clear lookup cache on meta delete.
	 *
	 * @param $meta_ids
	 * @param $object_id
	 * @param $meta_key
	 */
	public function bust_lookup_on_delete( $meta_ids, $object_id, $meta_key ) {
		if ( $this->meta_key !== $meta_key ) {
			return;
		}

		/**
		 * Delete old and new value AFTER the delete has been completed.
		 */
		$object_type = $this->object_type;
		$old_value   = get_metadata( $object_type, $object_id, $meta_key, true );
		$func        = function () use ( &$func, $old_value, $object_type ) {
			remove_action( "deleted_{$object_type}_meta", $func );
			wp_cache_delete( $old_value, $this->get_cache_group() );
		};
		// Hook the anonymous function into the `updated_term_meta` call so that we clear cache AFTER DB update
		add_action( "deleted_{$this->object_type}_meta", $func );
	}

	/**
	 * Clear lookup cache on addition of meta.
	 *
	 * @param $mid
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 */
	public function bust_lookup_on_add( $mid, $object_id, $meta_key, $meta_value ) {
		if ( $this->meta_key !== $meta_key ) {
			return;
		}
		wp_cache_delete( $meta_value, $this->get_cache_group() );
	}

	/**
	 * Return cache group name.
	 *
	 * @return string
	 */
	public function get_cache_group( $with_incrementor = true ) {
		return self::PREFIX . $this->name . ( $with_incrementor && $this->incrementor ? '_' . $this->incrementor : '' );
	}

	/**
	 * Increment cache version to flush cache of this lookup only.
	 */
	public function increment_cache_version() {
		return set_transient( $this->get_cache_group( false ), $this->incrementor++ );
	}
}
