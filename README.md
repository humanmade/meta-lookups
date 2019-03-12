# meta-lookups
Object cached Key/Value meta lookup framework for WordPress

### Usage

*Register a lookup*

```
add_action( 'init', function() {
   HM\Meta_Lookups\register_lookup( 'my_lookup', 'post', 'my_meta_key' );
}, 1 );
```

*Use the lookup*

```
$post_id = HM\Meta_Lookups\do_lookup( 'my meta value', 'my_lookup' );
```