<?php
   define("SERVER_HOST","127.0.0.1");
   define("SERVER_PORT",9527);
   define("CACHE_REDIS_HOST","127.0.0.1");
   define("CACHE_REDIS_PORT",6379);

   define("CLIENT_HOST",'127.0.0.1');
   define("CLIENT_PORT",'80');

   define("DEBUG",false);

   define("ALLOW_CACHE_KEYS",array(
      'api/test/cache',
      'user/site/get_site_list',
   ));
