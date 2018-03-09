SHELL=/bin/sh

prefix=/usr
bindir=/usr/bin
sysconfdir=/etc
src=$(CURDIR)/src

#5. info user  enable /start  daemon

# prefix 等安装目录变量要自己定义的

default: test
test:
		@echo $(CURDIR)
install:
		#copy swoole_http_proxy
		cp $(src)/swoole-http-proxy $(bindir)/swoole-http-proxy

		#2. copy config   /etc/swoole_http_proxy/
		cp -r $(src)/config $(sysconfdir)/swoole-http-proxy/

		#3. copy service file  /etc/systemd/...
		cp $(src)/swoole-http-proxy.service /usr/lib/systemd/system/

		systemctl daemon-reload

		@#mkdir /var/log/swoole_http_proxy.log
		@#mkdir /var/run/swoole_http_proxy.pid

uninstall:
		rm $(bindir)/swoole-http-proxy
		rm -rf $(sysconfdir)/swoole-http-proxy/
		rm /usr/lib/systemd/system/swoole-http-proxy.service
		systemctl daemon-reload


