SHELL=/bin/sh

#1. copy swoole_http_proxy   /usr/bin/
#2. copy config   /etc/swoole_http_proxy/
#3. copy service file  /etc/systemd/...
#4. system reload domain
#6. log dir, pid dir

#5. info user  enable /start  daemon

# prefix 等安装目录变量要自己定义的

default: test
test:
		@echo $(SHELL)
		@echo $(bindir)
		@echo $(sbindir)
		@echo $(libdir)
		@#ls $(prefix)
