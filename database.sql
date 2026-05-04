-- Database: web_fromkonsul
-- Created for Hostinger phpMyAdmin
-- Date: 2026-04-26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+07:00";
SET NAMES utf8mb4;

-- --------------------------------------------------------
-- Table structure for table `accounts`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `target_leads` int DEFAULT '100',
  `logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin') DEFAULT 'admin',
  `account_id` bigint unsigned DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT NULL,
  `last_check_notif_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_account_id_foreign` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `needs_categories`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `needs_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `status_categories`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `status_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `color` varchar(255) DEFAULT '#737c7f',
  `sort_order` int DEFAULT '0',
  `css_class` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `consultations`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `consultations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `consultation_id` varchar(255) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `district` varchar(255) DEFAULT NULL,
  `account_id` bigint unsigned NOT NULL,
  `needs_category_id` bigint unsigned DEFAULT NULL,
  `status_category_id` bigint unsigned DEFAULT NULL,
  `notes` text,
  `product_details` text,
  `created_by` bigint unsigned DEFAULT NULL,
  `consultation_date` date DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `consultations_consultation_id_unique` (`consultation_id`),
  KEY `consultations_account_id_foreign` (`account_id`),
  KEY `consultations_needs_category_id_foreign` (`needs_category_id`),
  KEY `consultations_status_category_id_foreign` (`status_category_id`),
  KEY `consultations_created_by_foreign` (`created_by`),
  KEY `consultations_client_name_index` (`client_name`),
  KEY `consultations_phone_index` (`phone`),
  KEY `consultations_consultation_date_index` (`consultation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `consultation_notes`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `consultation_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `consultation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `consultation_notes_consultation_id_foreign` (`consultation_id`),
  KEY `consultation_notes_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `reminders`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `consultation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `remind_at` datetime NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reminders_consultation_id_foreign` (`consultation_id`),
  KEY `reminders_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `consultation_imports`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `consultation_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `status` varchar(255) DEFAULT 'queued',
  `total_rows` int unsigned DEFAULT '0',
  `success_count` int unsigned DEFAULT '0',
  `duplicate_count` int unsigned DEFAULT '0',
  `error_count` int unsigned DEFAULT '0',
  `error_preview` text,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `consultation_imports_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `audit_logs`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loggable_type` varchar(255) DEFAULT NULL,
  `loggable_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_loggable_type_loggable_id_index` (`loggable_type`,`loggable_id`),
  KEY `audit_logs_user_id_index` (`user_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `jobs`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `job_batches`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `failed_jobs`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `password_reset_tokens`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `sessions`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `consultation_needs_category`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `consultation_needs_category` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `consultation_id` bigint unsigned NOT NULL,
  `needs_category_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `consultation_needs_category_consultation_id_needs_category_id_unique` (`consultation_id`,`needs_category_id`),
  KEY `consultation_needs_category_consultation_id_foreign` (`consultation_id`),
  KEY `consultation_needs_category_needs_category_id_foreign` (`needs_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `account_user`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `account_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_user_account_id_user_id_unique` (`account_id`,`user_id`),
  KEY `account_user_account_id_foreign` (`account_id`),
  KEY `account_user_user_id_foreign` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `consultation_sequences`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `consultation_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `year` int NOT NULL,
  `sequence` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `consultation_sequences_account_id_year_unique` (`account_id`,`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Foreign key constraints
-- --------------------------------------------------------

ALTER TABLE `users`
  ADD CONSTRAINT `users_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL;

ALTER TABLE `consultations`
  ADD CONSTRAINT `consultations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consultations_needs_category_id_foreign` FOREIGN KEY (`needs_category_id`) REFERENCES `needs_categories`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `consultations_status_category_id_foreign` FOREIGN KEY (`status_category_id`) REFERENCES `status_categories`(`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `consultations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `consultation_notes`
  ADD CONSTRAINT `consultation_notes_consultation_id_foreign` FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consultation_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `reminders`
  ADD CONSTRAINT `reminders_consultation_id_foreign` FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `consultation_imports`
  ADD CONSTRAINT `consultation_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

ALTER TABLE `consultation_needs_category`
  ADD CONSTRAINT `consultation_needs_category_consultation_id_foreign` FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consultation_needs_category_needs_category_id_foreign` FOREIGN KEY (`needs_category_id`) REFERENCES `needs_categories`(`id`) ON DELETE CASCADE;

ALTER TABLE `account_user`
  ADD CONSTRAINT `account_user_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `account_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

ALTER TABLE `consultation_sequences`
  ADD CONSTRAINT `consultation_sequences_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- Insert sample data for needs_categories
-- --------------------------------------------------------

INSERT INTO `needs_categories` (`name`, `created_at`, `updated_at`) VALUES
('Kitchenset', NOW(), NOW()),
('TV Wall', NOW(), NOW()),
(' Living Room', NOW(), NOW()),
('Kamar Tidur', NOW(), NOW()),
('Rak', NOW(), NOW()),
('Kitchen', NOW(), NOW()),
('Full Home', NOW(), NOW()),
('Ruang Makan', NOW(), NOW()),
('Apartement', NOW(), NOW()),
('Renovasi Rumah', NOW(), NOW()),
('Almunium', NOW(), NOW()),
('Kanopi', NOW(), NOW()),
('Pagar', NOW(), NOW()),
('Tangga', NOW(), NOW()),
('Meja', NOW(), NOW()),
('Lemari', NOW(), NOW()),
('Partisi', NOW(), NOW()),
('Lainnya', NOW(), NOW());

-- --------------------------------------------------------
-- Insert sample data for status_categories
-- --------------------------------------------------------

INSERT INTO `status_categories` (`name`, `color`, `sort_order`, `css_class`, `created_at`, `updated_at`) VALUES
('Hanya Tanya Tanya', '#eab308', 1, 'chip-hanya-tanya', NOW(), NOW()),
('Request Survey', '#8582ff', 2, 'chip-request-survey', NOW(), NOW()),
('Kendala Anggaran', '#9f403d', 3, 'chip-kendala-anggaran', NOW(), NOW()),
('Tidak Ada Respon', '#737c7f', 4, 'chip-tidak-ada-respon', NOW(), NOW()),
('Selesai/Deal', '#006d4a', 5, 'chip-selesai-deal', NOW(), NOW());

-- --------------------------------------------------------
-- Insert sample data for accounts
-- --------------------------------------------------------

INSERT INTO `accounts` (`name`, `description`, `created_at`, `updated_at`) VALUES
('HOME INTERIOR BANDUNG', 'PUTRA CORPORATION', NOW(), NOW()),
('INTERHOUSE ID', 'PUTRA CORPORATION', NOW(), NOW()),
('ZODIAK INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('AKBAR INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('PARTNER INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('ELVAN INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('MEWAH INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('MEDIAN INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('ARGO INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('SAVOY INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('FURNITURE CIMAHI', 'PUTRA CORPORATION', NOW(), NOW()),
('DEKOR INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('NISCALA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('INTERIOR CUSTOM', 'PUTRA CORPORATION', NOW(), NOW()),
('INTERIOR BANDUNG', 'PUTRA CORPORATION', NOW(), NOW()),
('INTERIOR MODERN', 'PUTRA CORPORATION', NOW(), NOW()),
('BROTO INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('KITCHENSET SOLUTION BANDUNG', 'PUTRA CORPORATION', NOW(), NOW()),
('GIBRAN INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('HOME SAVOY INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('LAVENTIA', 'PUTRA CORPORATION', NOW(), NOW()),
('PUTRO INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('PUSAT INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('KAMARSET', 'PUTRA CORPORATION', NOW(), NOW()),
('HEYA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('KURNIA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('KEJORA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('PORTO INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('ANEKA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('RADEA INTERIOR', 'PUTRA CORPORATION', NOW(), NOW()),
('ELVAN FURNITURE', 'PUTRA CORPORATION', NOW(), NOW()),
('PUTRA MOULDING', 'PUTRA CORPORATION', NOW(), NOW());