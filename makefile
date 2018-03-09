SHELL=/bin/sh

prefix=/usr
bindir=/usr/bin
sysconfdir=/etc
src=$(CURDIR)/src
confdir=$(sysconfdir)/swoole-http-proxy/

#command

COPY=cp
RM=rm

default: test
test:
		@echo $(CURDIR)
install:
		@#copy swoole_http_proxy
		$(COPY) $(src)/swoole-http-proxy $(bindir)/swoole-http-proxy

		@#2. copy config   /etc/swoole_http_proxy/
		[  -d $(confdir) ] || mkdir $(confdir)

		$(COPY) -r $(src)/config/* $(sysconfdir)/swoole-http-proxy/

		@#3. copy service file  /etc/systemd/...
		$(COPY) $(src)/swoole-http-proxy.service /usr/lib/systemd/system/

		systemctl daemon-reload
		systemctl restart swoole-http-proxy

		@#mkdir /var/log/swoole_http_proxy.log
		@#mkdir /var/run/swoole_http_proxy.pid

uninstall:
		$(RM) $(bindir)/swoole-http-proxy
		$(RM) -rf $(sysconfdir)/swoole-http-proxy/
		$(RM) /usr/lib/systemd/system/swoole-http-proxy.service
		systemctl daemon-reload


