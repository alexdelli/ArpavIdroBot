SET NAMES UTF8;
DELIMITER $$

CREATE PROCEDURE `p_insertRegistration` (IN `pTelegramUserId` INT, IN `pChatId` INT, IN `pStationId` SMALLINT UNSIGNED, OUT `esito` INT)
BEGIN
	DECLARE `num` INT;
	DECLARE `userIdToInsert` INT UNSIGNED;
	DECLARE `duplicate` INT;
	DECLARE `stationExist` INT;
	SELECT COUNT(*) INTO stationExist
	FROM `Stations` WHERE `stationId`=`pStationId`;
	IF stationExist=0 THEN
	INSERT INTO `Stations` (`stationId`) VALUES (`pStationId`);
	END IF;
	SELECT COUNT(*) INTO num
	FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
	IF num=0 THEN
		INSERT INTO `Users` (`telegramUserId`, `chatId`) VALUES (`pTelegramUserId`, `pChatId`);
		SET userIdToInsert=LAST_INSERT_ID();
	ELSE
		SELECT `userId` INTO userIdToInsert
		FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
	END IF;
	SELECT COUNT(*) INTO duplicate
	FROM `Registrations` 
	WHERE `userId`=`userIdToInsert`
	AND `stationId` = `pStationId`;
	if duplicate=0 THEN
		SET esito=1;
		INSERT INTO `Registrations` (`userId`, `stationId`) VALUES (`userIdToInsert`, `pStationId`);
	ELSE
		SET esito=0;
	END IF;
END$$


CREATE PROCEDURE `p_removeRegistration` (IN `pTelegramUserId` INT, IN `pStationId` SMALLINT UNSIGNED, OUT `esito` INT)
BEGIN
	DECLARE `num` INT;
	DECLARE `userIdToInsert` INT UNSIGNED;
	DECLARE `numRegistrations` INT;
	DECLARE `rightRegistration` INT;
	DECLARE `stationFollowers` INT;
	SELECT COUNT(*) INTO num
	FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
	IF num=0 THEN #utente non è registrato.
		SET esito=0; #utente non è registrato.
	ELSE
		SELECT `userId` INTO userIdToInsert
		FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
		SELECT COUNT(*) INTO numRegistrations
		FROM `Registrations` 
		WHERE `userId`=`userIdToInsert`;
		IF numRegistrations=0 THEN #non ha registrazioni
			SET esito=1;
		ELSE #ha delle registrazioni
			SELECT COUNT(*) INTO rightRegistration
			FROM `Registrations` 
			WHERE `userId`=`userIdToInsert`
			AND `stationId`=`pStationId`;
			IF rightRegistration!=0 THEN #è registrato alla stazione da qui disiscriversi
				DELETE FROM `Registrations`
				WHERE `userId` = `userIdToInsert`
				AND `stationId`=`pStationId`;
				SET esito=3; #registrazione rimossa con successo
				SELECT COUNT(*) INTO stationFollowers
				FROM `Registrations`
				WHERE `stationId` = `pStationId`;
				IF stationFollowers=0 THEN #'deleted last follower of the station'
					DELETE FROM `Stations` #'delete station'
					WHERE `stationId` = `pStationId`;
				END IF;
			ELSE #non è registrato alla stazione scelta
				SET esito=2; #non è registrato alla stazione scelta
			END IF;

		END IF;
    END IF;
END$$


CREATE PROCEDURE `p_removeAllRegistrations` (IN `pTelegramUserId` INT, OUT `esito` INT)
BEGIN
	DECLARE `userRegistered` INT;
	DECLARE `userIdToClear` INT;
	DECLARE `numRegistrations` INT;
	SELECT COUNT(*) INTO userRegistered
	FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
	IF userRegistered=0 THEN #utente non è registrato.
		SET esito=0;
	ELSE #utente è registrato.
		SELECT `userId` INTO userIdToClear
		FROM `Users` WHERE `telegramUserId`=`pTelegramUserId`;
		SELECT COUNT(*) INTO numRegistrations
		FROM `Registrations` 
		WHERE `userId`=`userIdToClear`;
		IF numRegistrations=0 THEN #non ha registrazioni
			SET esito = 0;
		ELSE #ha delle registrazioni
			DELETE FROM `Registrations`
			WHERE `userId` = `userIdToClear`;
			SET esito = numRegistrations;
			#Clear stations without subscribers
			DELETE FROM `Stations`
			WHERE `stationId` NOT IN(
				SELECT `stationId`
				FROM `Registrations`
			);
		END IF;
	END IF;
END$$


CREATE PROCEDURE `p_getRegistrations` (IN `pTelegramUserId` INT)
BEGIN
	SELECT `stationId` FROM `Registrations`
	WHERE `userId` = (
		SELECT `userId` FROM `Users`
		WHERE `telegramUserId` = `pTelegramUserId`
	);
END$$


CREATE PROCEDURE `p_setLastCriticalityState` (IN `pStationId` INT, IN `pCriticalityState` TINYINT)
BEGIN
	DECLARE `stationExist` INT;
	SELECT COUNT(*) INTO stationExist
	FROM `Stations`
	WHERE `stationId` = `pStationId`;
	IF stationExist=1 THEN
		UPDATE `Stations`
		SET `lastCriticalityState`=`pCriticalityState`
		WHERE `stationId`=`pStationId`;
	END IF;
END$$


CREATE PROCEDURE `p_getLastCriticalityState` ()
BEGIN
	SELECT `stationId`, `lastCriticalityState`
	FROM `Stations`;
END$$


CREATE PROCEDURE `p_getStationSubscribersChats` (IN `pStationId` INT)
BEGIN
	SELECT `chatId`
	FROM `Users`
	WHERE `userId` IN (
		SELECT `userId`
		FROM `Registrations`
		WHERE `stationId` = `pStationId`
	);
END$$


CREATE PROCEDURE `p_insertLocation`(IN `pTelegramUserId` INT, IN `pChatid` INT, IN `p_Latitude` FLOAT, IN `p_Longitude` FLOAT, OUT `esito` INT)
BEGIN
	DECLARE `userExist` INT;
	SELECT COUNT(*) INTO userExist
	FROM `Users` 
	WHERE `telegramUserId`=`pTelegramUserId`;
	IF userExist=0 THEN
		INSERT INTO `Users` (`telegramUserId`, `chatId`, `latitude`, `longitude`)
		VALUES (`pTelegramUserId`, `pChatId`, `p_Latitude`, `p_Longitude`);
		SET esito=1; #user created and location saved.
	ELSE
		UPDATE `Users`
		SET `latitude`=`p_Latitude`, `longitude`=`p_Longitude`
		WHERE `telegramUserId`=`pTelegramUserId`;
		SET esito=2; #location updated.
	END IF;
END$$


CREATE PROCEDURE `p_getLocation`(IN `pTelegramUserId` INT)
BEGIN
	SELECT `latitude`, `longitude`
	FROM `Users` 
	WHERE `telegramUserId`=`pTelegramUserId`
	AND `latitude` IS NOT NULL
	AND `longitude` IS NOT NULL;
END$$


CREATE PROCEDURE `p_deleteUser`(IN `pTelegramUserId` INT, OUT `esito` INT)
BEGIN
	DECLARE `userExist` INT;
	CALL `p_removeAllRegistrations` (`pTelegramUserId`, @esitoRegistrations);
	SELECT COUNT(*) INTO userExist
	FROM `Users` 
	WHERE `telegramUserId`=`pTelegramUserId`;
	IF userExist = 0 THEN
		SET esito = 2;
	ELSE
		DELETE
		FROM `Users` 
		WHERE `telegramUserId`=`pTelegramUserId`;
		SET esito = 1;
	END IF;
END$$

DELIMITER ;