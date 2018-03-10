<?php
   function cache_key($request_uri,$params=array())
   {
      return $request_uri;
   }

   function cache_data_is_valid($key,$data)
   {
      return true;
   }


   define("ALLOW_CACHE_KEYS",array(
   ));
