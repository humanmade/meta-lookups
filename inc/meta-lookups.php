<?php
/**
 * Meta_Lookups namespaced functions
 *
 * @package HM\Meta_Lookups
 */
namespace HM\Meta_Lookups;

/**
 * Do a meta lookup for the given meta key and registered lookup name.
 *
 * @param mixed $meta_value the value of the meta entry
 * @param string $lookup_name the name used to register the lookup
 *
 * @return mixed|bool
 */
function do_lookup( $meta_value, $lookup_name ) {
	$lookup = Lookup::get_instance( $lookup_name );
	return $lookup->get( $meta_value );
}

/**
 * Register a new lookup
 *
 * @param string $lookup_name A unique name for the lookup
 * @param string $object_type The type of object this lookup applies to post|user|term|comment
 * @param $meta_key
 * @return Lookup
 */
function register_lookup( $lookup_name, $object_type, $meta_key ) {
	$registered = Lookup::get_instance( $lookup_name );

	if ( $registered ) {
		return $registered;
	}

	return new Lookup( $lookup_name, $object_type, $meta_key );
}
