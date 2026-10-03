CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_vk` INT NOT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `avatar` VARCHAR(512) DEFAULT NULL,
  `access_tocken` TEXT,
  `private_tocken` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_vk` (`id_vk`)
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_group` INT NOT NULL,
  `id_admin` INT NOT NULL,
  PRIMARY KEY (`id`)
) CHARACTER SET utf8mb4;
