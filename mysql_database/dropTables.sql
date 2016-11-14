ALTER TABLE `Registrations` DROP FOREIGN KEY `fk_Registrations_Stations`;
ALTER TABLE `Registrations` DROP FOREIGN KEY `fk_Registrations_Users`;

DROP TABLE IF EXISTS `Registrations`;
DROP TABLE IF EXISTS `Stations`;
DROP TABLE IF EXISTS `Users`;
