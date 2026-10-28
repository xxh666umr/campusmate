-- 智能客服系统 MySQL 建表脚本（可选）
-- 默认情况下应用会自动建库建表；此文件用于手动部署或答辩展示。
-- 使用：mysql -uroot -p smart_service < schema/mysql.sql

CREATE DATABASE IF NOT EXISTS `smart_service` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smart_service`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(64) NOT NULL,
  `email` VARCHAR(128) DEFAULT NULL,
  `role` VARCHAR(16) NOT NULL DEFAULT 'user',
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `last_login_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `mode` VARCHAR(16) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  INDEX `idx_conversations_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `role` VARCHAR(16) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `meta` TEXT,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_messages_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `feedback` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `message_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT NOT NULL,
  `comment` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `idx_feedback_message` (`message_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `detail` TEXT NOT NULL,
  `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `assignee_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  INDEX `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(64) NOT NULL,
  `level` VARCHAR(16) NOT NULL DEFAULT 'info',
  `detail` TEXT,
  `ip` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kb_articles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(64) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `keywords` VARCHAR(500) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
