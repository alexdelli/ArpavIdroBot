#!/bin/bash
BOT_NAME='ArpavIdroBot'
VAR=`ps -ef | grep "${BOT_NAME}$" | grep -v grep | awk '{print $2}'`
DIR=`dirname "$(readlink -f "$0")"`
START_FILE_NAME='start.sh';
LOG_FILE_NAME="${BOT_NAME}.log"
COMMAND1="${DIR}/${LOG_FILE_NAME}"
COMMAND2="@reboot bash "${DIR}"/"${START_FILE_NAME}" "
if [ -n "$VAR" ]
	then
		kill -9 "$VAR"
		echo "${BOT_NAME}: Successfuly stopped."
	else
		echo "${BOT_NAME}: Nothing to stop."
fi
crontab -l | grep -v "$COMMAND1" | grep -v "$COMMAND2" | crontab -