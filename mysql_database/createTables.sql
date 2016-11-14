CREATE TABLE `Users`(
`userId` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
`telegramUserId` INT NOT NULL UNIQUE,
`chatId` INT NOT NULL UNIQUE,
`latitude` FLOAT(10,6),
`longitude` FLOAT(10,6)
)ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;

CREATE TABLE `Stations`(
`stationId` SMALLINT UNSIGNED PRIMARY KEY,
`lastCriticalityState` TINYINT
)ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;

CREATE TABLE `Registrations`(
`userId` INT UNSIGNED,
`stationId` SMALLINT UNSIGNED,
PRIMARY KEY (`userId`,`stationId`),
CONSTRAINT `fk_Registrations_Users` FOREIGN KEY (`userId`) REFERENCES `Users`(`userId`) ON DELETE CASCADE ON UPDATE CASCADE,
CONSTRAINT `fk_Registrations_Stations` FOREIGN KEY (`stationId`) REFERENCES `Stations`(`stationId`) ON DELETE CASCADE ON UPDATE CASCADE
)ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;
