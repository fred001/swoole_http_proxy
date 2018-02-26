<?php
   function now()
   {
      return date("Y-m-d H:i:s");
   }

   function save_log($msg)
   {
      $msg=sprintf("[%s] %s\n",now(),$msg);

      #openlog("swoole_proxy_http", LOG_PID | LOG_PERROR, LOG_LOCAL0);
      echo $msg;
      syslog(LOG_INFO,$msg);
   }

   class Cache
   {
      public static function init()
      {
         HttpProxyServer::$redis->hMset("wd_cache",array("test"=>"test value"));
      }

      public static function isAllowCache($key)
      {
         $allowed=array(
            'api/test/cache',
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

      public static function set($api,$data)
      {
         $key=self::getCacheKey($api);
         if(self::isAllowedCache($key))
         {
            HttpProxyServer::$redis->hset("wd_cache",$key,$data);
         }
      }

      public static function get($api)
      {
         $key=self::getCacheKey($api);
         if(self::isAllowedCache($key))
         {
            $data=HttpProxyServer::$redis->hget("wd_cache",$key);
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
      static $redis=null;

      /**
      * @param $fd
      * @return swoole_http_client
      */
      static function getClient($fd)
      {
         if (!isset(HttpProxyServer::$frontends[$fd]))
         {
            $client = new swoole_http_client('wd-api.anchumall.com', 80);
            //$client = new swoole_http_client('api.b2c.local', 80);

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
               save_log(self::$backendCloseCount . "\tbackend[{$cli->sock}]#[{$fd}] close");
            });
         }
         return HttpProxyServer::$frontends[$fd];
      }
   }

   #$serv = new swoole_http_server('127.0.0.1', 9527, SWOOLE_BASE);
   $serv = new swoole_http_server('10.80.154.24', 9527, SWOOLE_BASE);
   //$serv->set(array('worker_num' => 8));

   $serv->on('Close', function ($serv, $fd, $reactorId)
   {
      HttpProxyServer::$frontendCloseCount++;
      save_log(HttpProxyServer::$frontendCloseCount . "\tfrontend[{$fd}] close");
      //清理掉后端连接
      if (isset(HttpProxyServer::$frontends[$fd]))
      {
         $backend_socket = HttpProxyServer::$frontends[$fd];
         $backend_socket->close();
         unset(HttpProxyServer::$backends[$backend_socket->sock]);
         unset(HttpProxyServer::$frontends[$fd]);
      }
   });

   $serv->on('Request', function (swoole_http_request $req, swoole_http_response $resp)
   {
      if(empty($req->server['query_string']))
      {
         $url=$req->server['request_uri'];
      }
      else
      {
         $url=$req->server['request_uri']."?".$req->server['query_string'];
      }
      $client = HttpProxyServer::getClient($req->fd);
      $client->set(['timeout' => 10]);

      //$client->setHeaders($req->header);
      if($req->cookie)
      {
         $client->setCookies($req->cookie);
      }

      if ($req->server['request_method'] == 'GET')
      {
         $api=$req->get["s"];
         $cache_data=Cache::get($api);
         if($cache_data)
         {
            $resp->end($cache_data);
         }
         else
         {

            //var_dump($req->cookie);
            $client->get($url, function ($cli) use ($req, $resp) {
               $api=$req->get["s"];
               Cache::set($api,$cli->body);

               if($cli->statusCode == -1)
               {
               }
               else if($cli->statusCode == -2)
               {
                  //客户端提前关闭
                  $resp->status(499); //nginx 自定义的响应码
                  $resp->end('CLIENT CLOSED BY TIMEOUT');

                  $msg="client closed by timeout ";
                  $msg.="Error Detail \n";
                  $msg.="URL:".$url."\n";
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
                  $msg.="URL:".$url."\n";
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

               $resp->end($cli->body);
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

         //var_dump($req->rawContent());
         //echo file_get_contents("php://input");
         //var_dump($req->get);
         ////var_dump($req->post);
         var_dump($postData);

         $client->post($url, $postData, function ($cli) use ($req, $resp) {

            var_dump($cli->statusCode);
            var_dump($cli->body);
            if($cli->statusCode == -1)
            {
            }
            else if($cli->statusCode == -2)
            {
               //客户端提前关闭
               $resp->status(499); //nginx 自定义的响应码
               $resp->end('CLIENT CLOSED BY TIMEOUT');

               $msg="client closed by timeout ";
               $msg.="Error Detail \n";
               $msg.="URL:".$url."\n";
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
               $msg.="URL:".$url."\n";
               $msg.="postData: ".print_r($postData,true)."\n";
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

            $resp->end($cli->body);
         });
      }
      else
      {
         $resp->status(405);
         $resp->end("method not allow.");
      }
   });

   HttpProxyServer::$serv = $serv;
   $redis = new \Redis();
   $redis->connect("127.0.0.1",6379);
   HttpProxyServer::$redis= $redis;


   $serv->start();
