<?php
   require_once(dirname(__FILE__)."/config.php");

   function now()
   {
      return date("Y-m-d H:i:s");
   }

   function save_log($msg)
   {
      $msg=sprintf("[%s] %s\n",now(),$msg);

      openlog("swoole_proxy_http", LOG_PID | LOG_PERROR ,LOG_LOCAL0);
      syslog(LOG_INFO,$msg);
   }

   function debug($msg)
   {
      if(DEBUG )
      {
         $msg=sprintf("debug %s",$msg);
         save_log($msg);
      }
   }

   function client_response($cli,$resp,$debug_data)
   {
      if($cli->statusCode == -1)
      {
         $msg="statusCode = -1";
         $msg.="errCode: ".$cli->errCode;
         save_log($msg);
      }
      else if($cli->statusCode == -2)
      {
         //客户端提前关闭
         $resp->status(499); //nginx 自定义的响应码
         $resp->end('CLIENT CLOSED BY TIMEOUT');

         $msg="client closed by timeout ";
         $msg.="Error Detail \n";
         //$msg.="URL:".$url."\n";
         save_log($msg);
      }
      else if($cli->statusCode > 0)
      {
         $resp->status($cli->statusCode);
      }
      else 
      {
         $msg="unknown statusCode".$cli->statusCode;
         $msg.="Error Detail \n";
         //$msg.="URL:".$url."\n";
         //$msg.="postData: ".print_r($postData,true)."\n";
         $msg.=$cli->body;

         save_log($msg);
      }

      if($cli->cookies)
      {
         foreach($cli->cookies as $k=>$v)
         {
            $resp->cookie($k,$v);
         }
      }

      if($cli->headers)
      {
         //不压缩
         foreach($cli->headers as $k=>$v)
         {
            if(in_array($k,array(
               'content-encoding',
               'vary',
               'transfer-encoding',
            )))
            continue;

            $resp->header($k,$v);
         }
      }

      debug(json_encode($debug_data));

      $resp->end($cli->body);
   }

   class Cache
   {
      public static function init($redis)
      {
         $redis->hMset("wd_cache",array("test"=>"test value"));
      }

      public static function isAllowCache($key)
      {
         $allowed=array(
            'api/test/cache',
            'user/site/get_site_list',
         );

         if(in_array($key,$allowed))
         {
            return true;
         }
         else
         {
            return false;
         }
      }

      public static function set($redis,$api,$data)
      {
         $key=self::getCacheKey($api);
         if(self::isAllowCache($key))
         {
            //check if data is valid
            $origin_data=json_decode($data,true);
            if(isset($origin_data['error']) && $origin_data['error'] == 0)
            {
               $redis->hset("wd_cache",$key,$data);
            }
         }
      }

      public static function get($redis,$api)
      {
         $key=self::getCacheKey($api);
         if(self::isAllowCache($key))
         {
            $data=$redis->hget("wd_cache",$key);
            if($data != false)
            {
               return $data;
            }
         }

         return false;
      }

      public static function getCacheKey($api)
      {
         return $api;
      }
   }

   class HttpProxyServer
   {
      static $frontendCloseCount = 0;
      static $backendCloseCount = 0;
      static $frontends = array();
      static $backends = array();
      static $serv;
      static $status=array();

      static function addStatus($key,$plus_value)
      {
         $plus_value=intval($plus_value);

         if(!isset(self::$status[$key]))
         {
            self::$status[$key]=$plus_value;
         }
         else
         {
            self::$status[$key]+=$plus_value;
         }
      }

      static function showStatus()
      {
         $msg="Status:\n";
         foreach(self::$status as $k=>$v)
         {
            $msg.=sprintf("\t %s : %s\n", $k,$v);
         }

         echo $msg;
      }

      static function run($serv)
      {
         self::$serv = $serv;

         $redis = new \Redis();
         $redis->connect(CACHE_REDIS_HOST,CACHE_REDIS_PORT);

         Cache::init($redis);

         self::$serv->set(array(
            'pid_file' => __DIR__.'/server.pid',
         ));
         self::$serv->start();

      }

      /**
      * @param $fd
      * @return swoole_http_client
      */
      static function getClient($fd)
      {
         if (!isset(HttpProxyServer::$frontends[$fd]))
         {
            $client = new swoole_http_client(CLIENT_HOST,CLIENT_PORT);

            $client->set(array('keep_alive' => 0));
            HttpProxyServer::$frontends[$fd] = $client;
            $client->on('connect', function ($cli) use ($fd)
            {
               HttpProxyServer::$backends[$cli->sock] = $fd;
            });
            $client->on('close', function ($cli) use ($fd)
            {
               self::$backendCloseCount++;
               unset(HttpProxyServer::$backends[$cli->sock]);
               unset(HttpProxyServer::$frontends[$fd]);
            });
         }
         return HttpProxyServer::$frontends[$fd];
      }
   }

   $serv = new swoole_http_server(SERVER_HOST, SERVER_PORT, SWOOLE_BASE);
   $serv->set(array('worker_num' => 8));

   $serv->on('workerstart', function($serv, $id) {
      $redis = new \Redis();
      $redis->connect(CACHE_REDIS_HOST,CACHE_REDIS_PORT);

      $serv->redis = $redis;
   });


   $serv->on('Close', function ($serv, $fd, $reactorId)
   {
      HttpProxyServer::$frontendCloseCount++;
      if (isset(HttpProxyServer::$frontends[$fd]))
      {
         $backend_socket = HttpProxyServer::$frontends[$fd];
         $backend_socket->close();
         unset(HttpProxyServer::$backends[$backend_socket->sock]);
         unset(HttpProxyServer::$frontends[$fd]);
      }
   });

   $serv->on("Start",function(){
      save_log("server start");
   });

   $serv->on("Shutdown",function(){
      save_log("server shutdown");
   });


   $serv->on('Request', function (swoole_http_request $req, swoole_http_response $resp) use ($serv){

      $debug_data=array();

      if(empty($req->server['query_string']))
      {
         $url=$req->server['request_uri'];
      }
      else
      {
         $url=$req->server['request_uri']."?".$req->server['query_string'];
      }

      $debug_data['request_url']=$url;


      HttpProxyServer::addStatus("visit",1);

      if($url == "/status")
      {
         HttpProxyServer::showStatus();
      }


      $client = HttpProxyServer::getClient($req->fd);
      $client->set(['timeout' => 10]);

      //$client->setHeaders($req->header);
      if($req->cookie)
      {
         $client->setCookies($req->cookie);
      }

      $debug_data['request_method']=$req->server['request_method'];

      if ($req->server['request_method'] == 'GET')
      {
         $api=$req->get["s"];
         $cache_data=Cache::get($serv->redis,$api);
         if($cache_data)
         {
            $resp->end($cache_data);
         }
         else
         {
            $client->get($url, function ($cli) use ($req, $resp,$debug_data) {

               $api=$req->get["s"];
               Cache::set($serv->redis,$api,$cli->body);

               $debug_data['response_code']=$cli->statusCode;
               $debug_data['response']=$cli->body;
               client_response($cli,$resp,$debug_data);
            });
         }
      }
      elseif ($req->server['request_method'] == 'POST')
      {
         $postData = $req->post;
         if($postData == false) 
         {
            $postData=$req->rawContent();
         }

         $debug_data['post_data']=$postData;

         $client->post($url, $postData, function ($cli) use ($req, $resp,$debug_data) {

            client_response($cli,$resp,$debug_data);
         });
      }
      else
      {
         debug("method not allow (not get or post)");

         $resp->status(405);
         $resp->end("method not allow.");
      }
   });

   HttpProxyServer::run($serv);
