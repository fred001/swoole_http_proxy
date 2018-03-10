<?php

   /*
   * cache rule
   * 1. from request_uri,params get cache_key
   * 2. check if the cache_key allow cache
   * 3. check chache data should be cache
   * 4. if above all allowed, then cache it
   */


   //from request_uri and params to create cache key
   function cache_key($request_uri,$params=array())
   {
      return $request_uri;
   }

   //use cache_key and data to check if the data should be cached
   //for example:  error response should not be cached
   function cache_data_is_valid($key,$data)
   {
      return true;
   }

   //which cache_key should  cache
   define("ALLOW_CACHE_KEYS",array(
   ));
