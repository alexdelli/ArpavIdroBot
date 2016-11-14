#!/bin/bash
BOT_NAME='ArpavIdroBot'
VAR=`ps -ef | grep "${BOT_NAME}$" | grep -v grep | awk '{print $2}'`
DIR=`dirname "$(readlink -f "$0")"`
START_FILE_NAME='start.sh';
LOG_FILE_NAME="${BOT_NAME}.log"
TEMP_FILE_NAME="temp.tmp"
COMMAND1="0 0 * * * ( tail -n 100000 ${DIR}/${LOG_FILE_NAME} > ${DIR}/${TEMP_FILE_NAME} && (cat ${DIR}/${TEMP_FILE_NAME} > ${DIR}/${LOG_FILE_NAME} ; rm ${DIR}/${TEMP_FILE_NAME} ) ) "
COMMAND2="@reboot bash "${DIR}"/"${START_FILE_NAME}" "
REMOVE_COMMAND1="${DIR}/${LOG_FILE_NAME}"
REMOVE_COMMAND2="@reboot bash "${DIR}"/"${START_FILE_NAME}" "
CRONVAR=`crontab -l | grep "$REMOVE_COMMAND1"`
CRONVAR+=`crontab -l | grep "$REMOVE_COMMAND2"`
if [ -z "$VAR" ]
	then
		nohup php "${DIR}"/"${BOT_NAME}Launcher.php" >> "${DIR}"/"${BOT_NAME}.log" 2>&1 &
		if [ -z "$CRONVAR" ]
			then
				( crontab -l ; echo "$COMMAND1" ) | crontab -
				( crontab -l ; echo "$COMMAND2" ) | crontab -
				echo "${BOT_NAME}: crontab set."
			fi
		echo "${BOT_NAME}: Launched."
	else
		echo "${BOT_NAME}: Another instance of the bot is already running."
fi