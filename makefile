SHELL=/bin/sh

#1. copy swoole_http_proxy
#2. copy config
#3. copy service file
#4. system reload domain
#6. log dir, pid dir

#5. info user  enable /start  daemon

default: test
test:
		@echo $(CC)
		@#ls $(prefix)
