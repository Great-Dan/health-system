-- Migration: Add tables for System Restore Points and System Backups
-- Cloudflare destination: https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home

CREATE TABLE IF NOT EXISTS `system_restore_points` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `configuration` text NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_restore_points_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_backups` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` varchar(190) NOT NULL,
  `filepath` varchar(255) NOT NULL,
  `filesize` bigint(20) UNSIGNED NOT NULL,
  `records_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `destination` varchar(255) NOT NULL DEFAULT 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system_backups_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

