<?php
namespace Gaia\Cache;
use Gaia\Container;

class Callback extends Wrap
{
    public function get($request, $options = NULL){
        
        $options = new Container( $options );
        
        // we want to work with a list of keys
        $keys =  ( $single = is_scalar( $request ) ) ? array( $request ) : $request;
        
        // if we couldn't convert the value to an array, skip out
        if( ! is_array($keys ) ) return FALSE;
        
        // initialize the array for keeping track of all the results.
        $matches = array();
        
        // write all the keynames with the namespace prefix as null values into our result set
        foreach( $keys as $k ){
            $matches[ $k ] = NULL;
        }
        
        // ask for the keys from mecache object ... should we pass along the options down internally?
        // think not, but just asking.
        $result = $this->core->get( $keys );
        
        // did we find it?
        // if memcache didn't return an array it blew up with an internal error.
        // this should never happen, but anyway, here it is.
        if( ! is_array( $result ) ) return $result;
        
        // convert the result from the cache back into key/value pairs without a prefix.
        // overwrite the empty values we populated earlier.
        foreach( $result as $k=>$v) $matches[$k] = $v;
        
        // find the missing ones.
        $missing = array_keys( $matches, NULL, TRUE);
        
        // get rid of any of the missing keys now
        foreach( $missing as $k ) unset( $matches[ $k] );
        
        // here is where we call a callback function to get any additional rows missing.
        
        if( count($missing) > 0 && isset( $options->callback) && is_callable($options->callback) ){
            $result = call_user_func( $options->callback,$missing);
            if( ! is_array( $result ) ) return $matches;
            if( ! isset( $options->timeout ) ) $options->timeout = 0;
            if( ! isset( $options->method) ) $options->method = 'set';
            if( $options->cache_missing ){
                foreach( $missing as $k ){
                    if( ! isset( $result[ $k ] ) ) $result[$k] = self::UNDEF;
                }
            }
                        
            foreach( $result as $k=>$v ) {
                $matches[ $k ] = $v;
                $this->core->{$options->method}($k, $v, $options->timeout);
            }
        }
        
        foreach( $matches as $k => $v ){
            if( $v === self::UNDEF ) unset( $matches[ $k ] );
        }
        if( isset( $options->default ) ) {
            foreach( $missing as $k ){
                if( ! isset( $matches[ $k ] ) ) $matches[$k] = $options->default;
            }
        }
        if( $single ) return isset( $matches[ $request ] ) ? $matches[ $request ] : FALSE;
        
        return $matches;
    }
}
