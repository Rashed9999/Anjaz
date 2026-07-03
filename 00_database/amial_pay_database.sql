/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: amial_pay
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `amial_pay`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `amial_pay` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `amial_pay`;

--
-- Table structure for table `account_recovery_requests`
--

DROP TABLE IF EXISTS `account_recovery_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_recovery_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_ulid` varchar(26) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `request_type` enum('phone_change_self','phone_change_lost_phone','pin_reset_admin') NOT NULL,
  `old_phone` varchar(20) NOT NULL,
  `new_phone` varchar(20) DEFAULT NULL,
  `status` enum('pending_otp','pending_review','approved','rejected','cancelled','expired') NOT NULL DEFAULT 'pending_otp',
  `identification_documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`identification_documents`)),
  `user_notes` varchar(500) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `otp_old_phone` varchar(6) DEFAULT NULL,
  `otp_new_phone` varchar(6) DEFAULT NULL,
  `otp_expires_at` timestamp NULL DEFAULT NULL,
  `otp_old_verified` tinyint(1) NOT NULL DEFAULT 0,
  `otp_new_verified` tinyint(1) NOT NULL DEFAULT 0,
  `risk_score` tinyint(3) unsigned DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `old_phone_encrypted` text DEFAULT NULL,
  `old_phone_blind_index` char(64) DEFAULT NULL,
  `new_phone_encrypted` text DEFAULT NULL,
  `new_phone_blind_index` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_recovery_requests_request_ulid_unique` (`request_ulid`),
  KEY `recovery_user_status_idx` (`user_id`,`status`),
  KEY `recovery_status_idx` (`status`),
  KEY `recovery_expires_idx` (`expires_at`),
  KEY `account_recovery_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `account_recovery_requests_request_type_index` (`request_type`),
  KEY `account_recovery_requests_status_index` (`status`),
  KEY `idx_recovery_old_phone_blind` (`old_phone_blind_index`),
  KEY `idx_recovery_new_phone_blind` (`new_phone_blind_index`),
  CONSTRAINT `account_recovery_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_recovery_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_recovery_requests`
--

LOCK TABLES `account_recovery_requests` WRITE;
/*!40000 ALTER TABLE `account_recovery_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_recovery_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_security_events`
--

DROP TABLE IF EXISTS `account_security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(48) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `device_id` varchar(128) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `severity` enum('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `security_events_user_idx` (`user_id`,`created_at`),
  KEY `security_events_user_type_idx` (`user_id`,`event_type`),
  KEY `security_events_type_idx` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_security_events`
--

LOCK TABLES `account_security_events` WRITE;
/*!40000 ALTER TABLE `account_security_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_security_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_float_logs`
--

DROP TABLE IF EXISTS `agent_float_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_float_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_user_id` bigint(20) unsigned NOT NULL,
  `log_date` date NOT NULL,
  `opening_float` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `cash_in_total` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `cash_out_total` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `topup_total` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `commission_earned` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `closing_float` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `transaction_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_agent_date` (`agent_user_id`,`log_date`),
  KEY `agent_float_logs_log_date_index` (`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_float_logs`
--

LOCK TABLES `agent_float_logs` WRITE;
/*!40000 ALTER TABLE `agent_float_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `agent_float_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_profiles`
--

DROP TABLE IF EXISTS `agent_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `parent_agent_id` bigint(20) unsigned DEFAULT NULL,
  `agent_level` enum('distributor','sub_agent','independent') NOT NULL DEFAULT 'independent',
  `business_name` varchar(200) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `location_city` varchar(100) DEFAULT NULL,
  `location_address` varchar(300) DEFAULT NULL,
  `location_lat` decimal(10,7) DEFAULT NULL,
  `location_lng` decimal(10,7) DEFAULT NULL,
  `daily_cash_in_limit` decimal(20,4) NOT NULL DEFAULT 500000.0000,
  `daily_cash_out_limit` decimal(20,4) NOT NULL DEFAULT 500000.0000,
  `single_transaction_limit` decimal(20,4) NOT NULL DEFAULT 100000.0000,
  `min_float_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.50,
  `status` enum('active','suspended','pending_approval') NOT NULL DEFAULT 'pending_approval',
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_profiles_user_id_unique` (`user_id`),
  KEY `agent_profiles_parent_agent_id_status_index` (`parent_agent_id`,`status`),
  KEY `agent_profiles_agent_level_status_index` (`agent_level`,`status`),
  KEY `agent_profiles_location_city_index` (`location_city`),
  CONSTRAINT `agent_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_profiles`
--

LOCK TABLES `agent_profiles` WRITE;
/*!40000 ALTER TABLE `agent_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `agent_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_settlements`
--

DROP TABLE IF EXISTS `agent_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_ulid` varchar(26) NOT NULL,
  `agent_user_id` bigint(20) unsigned NOT NULL,
  `settled_with_id` bigint(20) unsigned NOT NULL,
  `settlement_type` enum('topup','payout','reconciliation') NOT NULL DEFAULT 'topup',
  `amount` decimal(20,4) NOT NULL,
  `commission_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `status` enum('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `ledger_entry_ulid` varchar(26) DEFAULT NULL,
  `approved_by_id` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_settlements_settlement_ulid_unique` (`settlement_ulid`),
  KEY `agent_settlements_agent_user_id_status_created_at_index` (`agent_user_id`,`status`,`created_at`),
  KEY `agent_settlements_status_index` (`status`),
  CONSTRAINT `agent_settlements_agent_user_id_foreign` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_settlements`
--

LOCK TABLES `agent_settlements` WRITE;
/*!40000 ALTER TABLE `agent_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `agent_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `amial_notifications`
--

DROP TABLE IF EXISTS `amial_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `amial_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(48) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `icon` varchar(80) DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `amial_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `amial_notifications_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `amial_notifications_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `amial_notifications`
--

LOCK TABLES `amial_notifications` WRITE;
/*!40000 ALTER TABLE `amial_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `amial_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_alerts`
--

DROP TABLE IF EXISTS `aml_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `alert_ulid` varchar(26) NOT NULL,
  `alert_code` varchar(64) NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL,
  `subject_type` varchar(50) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `title_ar` varchar(200) NOT NULL,
  `message_ar` text NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `status` enum('open','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'open',
  `assigned_to_admin_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aml_alerts_alert_ulid_unique` (`alert_ulid`),
  KEY `aml_alerts_status_severity_created_at_index` (`status`,`severity`,`created_at`),
  KEY `aml_alerts_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `aml_alerts_assigned_to_admin_id_foreign` (`assigned_to_admin_id`),
  KEY `aml_alerts_resolved_by_admin_id_foreign` (`resolved_by_admin_id`),
  KEY `aml_alerts_alert_code_index` (`alert_code`),
  KEY `aml_alerts_severity_index` (`severity`),
  KEY `aml_alerts_status_index` (`status`),
  CONSTRAINT `aml_alerts_assigned_to_admin_id_foreign` FOREIGN KEY (`assigned_to_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aml_alerts_resolved_by_admin_id_foreign` FOREIGN KEY (`resolved_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_alerts`
--

LOCK TABLES `aml_alerts` WRITE;
/*!40000 ALTER TABLE `aml_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_flagged_transactions`
--

DROP TABLE IF EXISTS `aml_flagged_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_flagged_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `flag_ulid` varchar(26) NOT NULL,
  `transaction_ulid` varchar(32) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `counterparty_user_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(20,4) DEFAULT NULL,
  `total_risk_score` decimal(6,2) NOT NULL,
  `triggered_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`triggered_rules`)),
  `initial_decision` enum('flag','hold','block') NOT NULL,
  `current_status` enum('pending_review','approved_by_admin','rejected_by_admin','escalated','auto_resolved') NOT NULL DEFAULT 'pending_review',
  `assigned_to_admin_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_decision_note` text DEFAULT NULL,
  `transaction_executed` tinyint(1) NOT NULL DEFAULT 0,
  `executed_at` timestamp NULL DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aml_flagged_transactions_flag_ulid_unique` (`flag_ulid`),
  KEY `aml_flagged_transactions_current_status_created_at_index` (`current_status`,`created_at`),
  KEY `aml_flagged_transactions_actor_user_id_current_status_index` (`actor_user_id`,`current_status`),
  KEY `aml_flagged_transactions_transaction_type_current_status_index` (`transaction_type`,`current_status`),
  KEY `aml_flagged_transactions_counterparty_user_id_foreign` (`counterparty_user_id`),
  KEY `aml_flagged_transactions_assigned_to_admin_id_foreign` (`assigned_to_admin_id`),
  KEY `aml_flagged_transactions_reviewed_by_admin_id_foreign` (`reviewed_by_admin_id`),
  KEY `aml_flagged_transactions_current_status_index` (`current_status`),
  CONSTRAINT `aml_flagged_transactions_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `aml_flagged_transactions_assigned_to_admin_id_foreign` FOREIGN KEY (`assigned_to_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aml_flagged_transactions_counterparty_user_id_foreign` FOREIGN KEY (`counterparty_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aml_flagged_transactions_reviewed_by_admin_id_foreign` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_flagged_transactions`
--

LOCK TABLES `aml_flagged_transactions` WRITE;
/*!40000 ALTER TABLE `aml_flagged_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_flagged_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_rule_evaluations`
--

DROP TABLE IF EXISTS `aml_rule_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_rule_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_ulid` varchar(32) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `counterparty_user_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(20,4) DEFAULT NULL,
  `rule_id` bigint(20) unsigned NOT NULL,
  `rule_code` varchar(64) NOT NULL,
  `matched` tinyint(1) NOT NULL,
  `contributed_risk_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `evaluation_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evaluation_context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `aml_rule_evaluations_actor_user_id_created_at_index` (`actor_user_id`,`created_at`),
  KEY `aml_rule_evaluations_rule_code_matched_index` (`rule_code`,`matched`),
  KEY `aml_rule_evaluations_transaction_type_created_at_index` (`transaction_type`,`created_at`),
  KEY `aml_rule_evaluations_rule_id_foreign` (`rule_id`),
  KEY `aml_rule_evaluations_transaction_type_index` (`transaction_type`),
  KEY `aml_rule_evaluations_actor_user_id_index` (`actor_user_id`),
  KEY `idx_aml_eval_user_time` (`actor_user_id`,`created_at`),
  KEY `idx_aml_eval_user_amount_time` (`actor_user_id`,`amount`,`created_at`),
  CONSTRAINT `aml_rule_evaluations_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `aml_rule_evaluations_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `aml_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_rule_evaluations`
--

LOCK TABLES `aml_rule_evaluations` WRITE;
/*!40000 ALTER TABLE `aml_rule_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_rule_evaluations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_rules`
--

DROP TABLE IF EXISTS `aml_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name_ar` varchar(200) NOT NULL,
  `description_ar` text DEFAULT NULL,
  `rule_type` varchar(50) DEFAULT NULL,
  `applies_to` varchar(200) NOT NULL DEFAULT 'send_money,safe_payment,donation,bill_pay',
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`parameters`)),
  `action_on_match` enum('allow','flag','hold','block') NOT NULL DEFAULT 'flag',
  `risk_score_contribution` decimal(5,2) NOT NULL DEFAULT 10.00,
  `priority` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `shadow_mode` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aml_rules_code_unique` (`code`),
  KEY `aml_rules_is_active_priority_index` (`is_active`,`priority`),
  KEY `aml_rules_created_by_admin_id_foreign` (`created_by_admin_id`),
  KEY `aml_rules_updated_by_admin_id_foreign` (`updated_by_admin_id`),
  KEY `aml_rules_rule_type_index` (`rule_type`),
  CONSTRAINT `aml_rules_created_by_admin_id_foreign` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aml_rules_updated_by_admin_id_foreign` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_rules`
--

LOCK TABLES `aml_rules` WRITE;
/*!40000 ALTER TABLE `aml_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_shadow_decisions`
--

DROP TABLE IF EXISTS `aml_shadow_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_shadow_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_ulid` varchar(32) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL,
  `amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `would_be_action` enum('allow','flag','hold','block') NOT NULL,
  `total_risk_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `triggered_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`triggered_rules`)),
  `actual_action` enum('allow','flag','hold','block') NOT NULL DEFAULT 'allow',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `aml_shadow_decisions_would_be_action_created_at_index` (`would_be_action`,`created_at`),
  KEY `aml_shadow_decisions_user_id_created_at_index` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_shadow_decisions`
--

LOCK TABLES `aml_shadow_decisions` WRITE;
/*!40000 ALTER TABLE `aml_shadow_decisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_shadow_decisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `aml_user_risk_profiles`
--

DROP TABLE IF EXISTS `aml_user_risk_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aml_user_risk_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `current_risk_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `risk_level` enum('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `total_transactions` int(10) unsigned NOT NULL DEFAULT 0,
  `total_flagged` int(10) unsigned NOT NULL DEFAULT 0,
  `total_blocked` int(10) unsigned NOT NULL DEFAULT 0,
  `total_held` int(10) unsigned NOT NULL DEFAULT 0,
  `avg_transaction_amount` decimal(20,4) DEFAULT NULL,
  `max_transaction_amount` decimal(20,4) DEFAULT NULL,
  `lifetime_volume` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `last_evaluation_at` timestamp NULL DEFAULT NULL,
  `last_flagged_at` timestamp NULL DEFAULT NULL,
  `last_review_at` timestamp NULL DEFAULT NULL,
  `manual_override` enum('none','whitelist','blacklist') NOT NULL DEFAULT 'none',
  `override_reason` text DEFAULT NULL,
  `override_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `aml_user_risk_profiles_override_admin_id_foreign` (`override_admin_id`),
  KEY `aml_user_risk_profiles_risk_level_index` (`risk_level`),
  CONSTRAINT `aml_user_risk_profiles_override_admin_id_foreign` FOREIGN KEY (`override_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aml_user_risk_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `aml_user_risk_profiles`
--

LOCK TABLES `aml_user_risk_profiles` WRITE;
/*!40000 ALTER TABLE `aml_user_risk_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `aml_user_risk_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_decisions`
--

DROP TABLE IF EXISTS `audit_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `decision_id` varchar(32) NOT NULL,
  `actor_type` enum('user','admin','system','agent','merchant') NOT NULL DEFAULT 'system',
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `subject_type` enum('user','transaction','wallet','merchant','session','pin') NOT NULL DEFAULT 'user',
  `subject_id` varchar(64) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `decision_code` varchar(64) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `transaction_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `zone_code` varchar(16) DEFAULT NULL,
  `severity` enum('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `audit_decisions_decision_id_unique` (`decision_id`),
  KEY `audit_actor_created_idx` (`actor_user_id`,`created_at`),
  KEY `audit_subject_created_idx` (`subject_id`,`created_at`),
  KEY `audit_code_created_idx` (`decision_code`,`created_at`),
  KEY `audit_transaction_idx` (`transaction_id`),
  KEY `audit_idempotency_idx` (`idempotency_key`),
  KEY `audit_severity_idx` (`severity`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_decisions`
--

LOCK TABLES `audit_decisions` WRITE;
/*!40000 ALTER TABLE `audit_decisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_decisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banners`
--

DROP TABLE IF EXISTS `banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `banners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `receiver` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banners`
--

LOCK TABLES `banners` WRITE;
/*!40000 ALTER TABLE `banners` DISABLE KEYS */;
/*!40000 ALTER TABLE `banners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_payment_orders`
--

DROP TABLE IF EXISTS `bill_payment_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_payment_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_ulid` varchar(26) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) unsigned NOT NULL,
  `service_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `subscriber_account` varchar(100) NOT NULL,
  `subscriber_extra` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`subscriber_extra`)),
  `amount` decimal(20,4) NOT NULL,
  `fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_debited` decimal(20,4) NOT NULL,
  `status` enum('pending','processing','pending_provider_confirmation','success','failed','reversed') NOT NULL DEFAULT 'pending',
  `wallet_transaction_id` varchar(32) DEFAULT NULL,
  `provider_reference` varchar(100) DEFAULT NULL,
  `provider_message` varchar(500) DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `completed_at` timestamp NULL DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reverse_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bill_payment_orders_order_ulid_unique` (`order_ulid`),
  KEY `bill_payment_orders_user_id_status_created_at_index` (`user_id`,`status`,`created_at`),
  KEY `bill_payment_orders_provider_id_status_index` (`provider_id`,`status`),
  KEY `bill_payment_orders_provider_reference_index` (`provider_reference`),
  KEY `bill_payment_orders_service_id_foreign` (`service_id`),
  KEY `bill_payment_orders_product_id_foreign` (`product_id`),
  KEY `bill_payment_orders_status_index` (`status`),
  KEY `idx_bill_orders_user_status` (`user_id`,`status`,`created_at`),
  CONSTRAINT `bill_payment_orders_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `bill_service_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bill_payment_orders_provider_id_foreign` FOREIGN KEY (`provider_id`) REFERENCES `bill_providers` (`id`),
  CONSTRAINT `bill_payment_orders_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `bill_services` (`id`),
  CONSTRAINT `bill_payment_orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_payment_orders`
--

LOCK TABLES `bill_payment_orders` WRITE;
/*!40000 ALTER TABLE `bill_payment_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_payment_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_provider_requests`
--

DROP TABLE IF EXISTS `bill_provider_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_provider_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `provider_id` bigint(20) unsigned NOT NULL,
  `request_type` enum('inquire','pay','status_check','reverse') NOT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `http_status` int(11) DEFAULT NULL,
  `latency_ms` int(11) DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bill_provider_requests_order_id_created_at_index` (`order_id`,`created_at`),
  KEY `bill_provider_requests_provider_id_index` (`provider_id`),
  KEY `bill_provider_requests_request_type_index` (`request_type`),
  CONSTRAINT `bill_provider_requests_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `bill_payment_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bill_provider_requests_provider_id_foreign` FOREIGN KEY (`provider_id`) REFERENCES `bill_providers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_provider_requests`
--

LOCK TABLES `bill_provider_requests` WRITE;
/*!40000 ALTER TABLE `bill_provider_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_provider_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_providers`
--

DROP TABLE IF EXISTS `bill_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_providers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_name_ar` varchar(100) NOT NULL,
  `integration_type` varchar(32) NOT NULL DEFAULT 'stub',
  `endpoint_url` varchar(500) DEFAULT NULL,
  `api_key_encrypted` varchar(500) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bill_providers_code_unique` (`code`),
  KEY `bill_providers_zone_code_is_active_index` (`zone_code`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_providers`
--

LOCK TABLES `bill_providers` WRITE;
/*!40000 ALTER TABLE `bill_providers` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_providers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_service_products`
--

DROP TABLE IF EXISTS `bill_service_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_service_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `product_code` varchar(64) NOT NULL,
  `name` varchar(200) NOT NULL,
  `amount_type` enum('fixed','variable') NOT NULL DEFAULT 'fixed',
  `fixed_amount` decimal(20,4) DEFAULT NULL,
  `min_amount` decimal(20,4) DEFAULT NULL,
  `max_amount` decimal(20,4) DEFAULT NULL,
  `fee_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `fee_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bill_service_products_service_id_product_code_unique` (`service_id`,`product_code`),
  CONSTRAINT `bill_service_products_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `bill_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_service_products`
--

LOCK TABLES `bill_service_products` WRITE;
/*!40000 ALTER TABLE `bill_service_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_service_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_services`
--

DROP TABLE IF EXISTS `bill_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_name_ar` varchar(100) NOT NULL,
  `service_type` varchar(32) NOT NULL,
  `icon_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `requires_account_number` tinyint(1) NOT NULL DEFAULT 1,
  `account_validation_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`account_validation_rules`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bill_services_provider_id_code_unique` (`provider_id`,`code`),
  KEY `bill_services_provider_id_is_active_index` (`provider_id`,`is_active`),
  CONSTRAINT `bill_services_provider_id_foreign` FOREIGN KEY (`provider_id`) REFERENCES `bill_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_services`
--

LOCK TABLES `bill_services` WRITE;
/*!40000 ALTER TABLE `bill_services` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blog_categories`
--

DROP TABLE IF EXISTS `blog_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `blog_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` mediumtext NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `click_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blog_categories_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blog_categories`
--

LOCK TABLES `blog_categories` WRITE;
/*!40000 ALTER TABLE `blog_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `blog_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blogs`
--

DROP TABLE IF EXISTS `blogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `blogs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` mediumtext NOT NULL,
  `readable_id` varchar(255) NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `writer` varchar(255) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` longtext NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `publish_date` datetime NOT NULL DEFAULT '2026-06-06 19:58:21',
  `is_published` tinyint(4) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 0,
  `is_draft` tinyint(4) NOT NULL DEFAULT 0,
  `draft_data` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `click_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blogs`
--

LOCK TABLES `blogs` WRITE;
/*!40000 ALTER TABLE `blogs` DISABLE KEYS */;
/*!40000 ALTER TABLE `blogs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bonuses`
--

DROP TABLE IF EXISTS `bonuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bonuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `user_type` varchar(50) DEFAULT NULL,
  `min_add_money_amount` float NOT NULL DEFAULT 0,
  `limit_per_user` int(11) NOT NULL DEFAULT 0,
  `bonus_type` varchar(50) DEFAULT NULL,
  `bonus` float NOT NULL DEFAULT 0,
  `max_bonus_amount` float NOT NULL DEFAULT 0,
  `start_date_time` datetime DEFAULT NULL,
  `end_date_time` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bonuses`
--

LOCK TABLES `bonuses` WRITE;
/*!40000 ALTER TABLE `bonuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `bonuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `manager_pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `cached_pos_users_count` int(10) unsigned NOT NULL DEFAULT 0,
  `cached_terminals_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ulid` varchar(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `br_merchant_name_unique` (`merchant_user_id`,`name`),
  UNIQUE KEY `branches_ulid_unique` (`ulid`),
  KEY `br_merchant` (`merchant_user_id`),
  KEY `br_manager` (`manager_pos_user_id`),
  KEY `br_merchant_active` (`merchant_user_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_settings`
--

DROP TABLE IF EXISTS `business_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_settings`
--

LOCK TABLES `business_settings` WRITE;
/*!40000 ALTER TABLE `business_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `business_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charity_campaigns`
--

DROP TABLE IF EXISTS `charity_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charity_campaigns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_ulid` varchar(26) NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `title_ar` varchar(200) NOT NULL,
  `description_ar` text NOT NULL,
  `story_md` text DEFAULT NULL,
  `location_ar` varchar(200) DEFAULT NULL,
  `target_amount` decimal(20,4) NOT NULL,
  `current_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `platform_fee_collected` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `beneficiary_count` int(10) unsigned DEFAULT NULL,
  `beneficiary_description_ar` varchar(500) DEFAULT NULL,
  `cover_image_url` varchar(500) DEFAULT NULL,
  `gallery_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery_images`)),
  `start_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deadline_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','pending_approval','active','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  `approved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `donor_count` int(10) unsigned NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charity_campaigns_campaign_ulid_unique` (`campaign_ulid`),
  KEY `charity_campaigns_status_deadline_at_index` (`status`,`deadline_at`),
  KEY `charity_campaigns_org_id_status_index` (`org_id`,`status`),
  KEY `charity_campaigns_category_id_status_index` (`category_id`,`status`),
  KEY `charity_campaigns_is_featured_status_index` (`is_featured`,`status`),
  KEY `charity_campaigns_approved_by_admin_id_foreign` (`approved_by_admin_id`),
  KEY `charity_campaigns_status_index` (`status`),
  CONSTRAINT `charity_campaigns_approved_by_admin_id_foreign` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `charity_campaigns_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `charity_categories` (`id`),
  CONSTRAINT `charity_campaigns_org_id_foreign` FOREIGN KEY (`org_id`) REFERENCES `charity_organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charity_campaigns`
--

LOCK TABLES `charity_campaigns` WRITE;
/*!40000 ALTER TABLE `charity_campaigns` DISABLE KEYS */;
/*!40000 ALTER TABLE `charity_campaigns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charity_categories`
--

DROP TABLE IF EXISTS `charity_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charity_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name_ar` varchar(100) NOT NULL,
  `icon` varchar(64) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charity_categories_code_unique` (`code`),
  KEY `charity_categories_is_active_sort_order_index` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charity_categories`
--

LOCK TABLES `charity_categories` WRITE;
/*!40000 ALTER TABLE `charity_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `charity_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charity_organizations`
--

DROP TABLE IF EXISTS `charity_organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charity_organizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `org_ulid` varchar(26) NOT NULL,
  `name_ar` varchar(200) NOT NULL,
  `name_en` varchar(200) DEFAULT NULL,
  `license_number` varchar(100) NOT NULL,
  `license_document_path` varchar(500) DEFAULT NULL,
  `description_ar` text NOT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) NOT NULL,
  `website_url` varchar(500) DEFAULT NULL,
  `address_ar` varchar(500) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_account_holder` varchar(200) DEFAULT NULL,
  `bank_swift` varchar(50) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `cover_image_url` varchar(500) DEFAULT NULL,
  `verification_status` enum('pending_verification','verified','rejected','suspended') NOT NULL DEFAULT 'pending_verification',
  `verified_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `suspension_reason` text DEFAULT NULL,
  `total_collected` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_campaigns` int(10) unsigned NOT NULL DEFAULT 0,
  `total_donors` int(10) unsigned NOT NULL DEFAULT 0,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charity_organizations_org_ulid_unique` (`org_ulid`),
  KEY `charity_organizations_verification_status_is_active_index` (`verification_status`,`is_active`),
  KEY `charity_organizations_license_number_index` (`license_number`),
  KEY `charity_organizations_verified_by_admin_id_foreign` (`verified_by_admin_id`),
  KEY `charity_organizations_verification_status_index` (`verification_status`),
  CONSTRAINT `charity_organizations_verified_by_admin_id_foreign` FOREIGN KEY (`verified_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charity_organizations`
--

LOCK TABLES `charity_organizations` WRITE;
/*!40000 ALTER TABLE `charity_organizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `charity_organizations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charity_settlements`
--

DROP TABLE IF EXISTS `charity_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charity_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_ulid` varchar(26) NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `donation_count` int(10) unsigned NOT NULL DEFAULT 0,
  `campaign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_donations` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_platform_fees` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `payable_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `status` enum('pending','transferred','failed','cancelled') NOT NULL DEFAULT 'pending',
  `transferred_at` timestamp NULL DEFAULT NULL,
  `bank_transfer_reference` varchar(100) DEFAULT NULL,
  `transfer_notes` text DEFAULT NULL,
  `report_pdf_path` varchar(500) DEFAULT NULL,
  `generated_by_admin_id` bigint(20) unsigned NOT NULL,
  `transferred_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `org_period_unique` (`org_id`,`period_start`,`period_end`),
  UNIQUE KEY `charity_settlements_settlement_ulid_unique` (`settlement_ulid`),
  KEY `charity_settlements_status_created_at_index` (`status`,`created_at`),
  KEY `charity_settlements_generated_by_admin_id_foreign` (`generated_by_admin_id`),
  KEY `charity_settlements_transferred_by_admin_id_foreign` (`transferred_by_admin_id`),
  KEY `charity_settlements_status_index` (`status`),
  CONSTRAINT `charity_settlements_generated_by_admin_id_foreign` FOREIGN KEY (`generated_by_admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `charity_settlements_org_id_foreign` FOREIGN KEY (`org_id`) REFERENCES `charity_organizations` (`id`),
  CONSTRAINT `charity_settlements_transferred_by_admin_id_foreign` FOREIGN KEY (`transferred_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charity_settlements`
--

LOCK TABLES `charity_settlements` WRITE;
/*!40000 ALTER TABLE `charity_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `charity_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `mobile_number` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `reply` text DEFAULT NULL,
  `feedback` varchar(255) DEFAULT '0',
  `seen` tinyint(1) DEFAULT 0,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country` varchar(255) DEFAULT NULL,
  `currency_code` varchar(255) DEFAULT NULL,
  `currency_symbol` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_credit_accounts`
--

DROP TABLE IF EXISTS `customer_credit_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_credit_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `customer_phone` varchar(32) NOT NULL,
  `customer_user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(120) NOT NULL,
  `credit_limit` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `current_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `classification` varchar(16) NOT NULL DEFAULT 'bronze',
  `last_payment_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_credit_accounts_merchant_user_id_customer_phone_unique` (`merchant_user_id`,`customer_phone`),
  KEY `customer_credit_accounts_merchant_user_id_is_active_index` (`merchant_user_id`,`is_active`),
  KEY `customer_credit_accounts_merchant_user_id_index` (`merchant_user_id`),
  KEY `customer_credit_accounts_customer_user_id_index` (`customer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_credit_accounts`
--

LOCK TABLES `customer_credit_accounts` WRITE;
/*!40000 ALTER TABLE `customer_credit_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_credit_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_credit_movements`
--

DROP TABLE IF EXISTS `customer_credit_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_credit_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `movement_ulid` varchar(40) NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `type` varchar(16) NOT NULL,
  `amount` decimal(20,4) NOT NULL,
  `balance_after` decimal(20,4) NOT NULL,
  `due_date` date DEFAULT NULL,
  `reference_type` varchar(32) DEFAULT NULL,
  `reference_id` varchar(40) DEFAULT NULL,
  `reference_number` varchar(32) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_credit_movements_movement_ulid_unique` (`movement_ulid`),
  KEY `customer_credit_movements_account_id_created_at_index` (`account_id`,`created_at`),
  KEY `customer_credit_movements_account_id_type_index` (`account_id`,`type`),
  KEY `customer_credit_movements_due_date_index` (`due_date`),
  KEY `customer_credit_movements_account_id_index` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_credit_movements`
--

LOCK TABLES `customer_credit_movements` WRITE;
/*!40000 ALTER TABLE `customer_credit_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_credit_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispute_reasons`
--

DROP TABLE IF EXISTS `dispute_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispute_reasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reason` varchar(255) NOT NULL,
  `user_type` varchar(30) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispute_reasons`
--

LOCK TABLES `dispute_reasons` WRITE;
/*!40000 ALTER TABLE `dispute_reasons` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispute_reasons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `disputes`
--

DROP TABLE IF EXISTS `disputes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `disputes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` bigint(20) unsigned NOT NULL,
  `sender_type` varchar(255) DEFAULT NULL,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `disputed_user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `trx_id` varchar(255) NOT NULL,
  `sending_time` datetime NOT NULL DEFAULT '2026-06-06 19:58:21',
  `report_reason` longtext DEFAULT NULL,
  `comment` longtext DEFAULT NULL,
  `denied_note` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `disputes`
--

LOCK TABLES `disputes` WRITE;
/*!40000 ALTER TABLE `disputes` DISABLE KEYS */;
/*!40000 ALTER TABLE `disputes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `donations`
--

DROP TABLE IF EXISTS `donations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `donations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `donation_ulid` varchar(26) NOT NULL,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL,
  `donor_user_id` bigint(20) unsigned NOT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(20,4) NOT NULL,
  `platform_fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `net_to_charity` decimal(20,4) NOT NULL,
  `wallet_transaction_id` varchar(32) NOT NULL,
  `receipt_id` bigint(20) unsigned DEFAULT NULL,
  `donor_message` varchar(500) DEFAULT NULL,
  `status` enum('completed','refunded','settled') NOT NULL DEFAULT 'completed',
  `settlement_id` bigint(20) unsigned DEFAULT NULL,
  `donated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `refunded_at` timestamp NULL DEFAULT NULL,
  `refund_reason` text DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `donations_donation_ulid_unique` (`donation_ulid`),
  KEY `donations_campaign_id_status_index` (`campaign_id`,`status`),
  KEY `donations_donor_user_id_donated_at_index` (`donor_user_id`,`donated_at`),
  KEY `donations_org_id_status_index` (`org_id`,`status`),
  KEY `donations_settlement_id_index` (`settlement_id`),
  KEY `donations_receipt_id_foreign` (`receipt_id`),
  KEY `donations_status_index` (`status`),
  KEY `idx_donations_donor_time` (`donor_user_id`,`donated_at`),
  KEY `idx_donations_campaign_status` (`campaign_id`,`status`,`donated_at`),
  CONSTRAINT `donations_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `charity_campaigns` (`id`),
  CONSTRAINT `donations_donor_user_id_foreign` FOREIGN KEY (`donor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `donations_org_id_foreign` FOREIGN KEY (`org_id`) REFERENCES `charity_organizations` (`id`),
  CONSTRAINT `donations_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `donations_settlement_id_foreign` FOREIGN KEY (`settlement_id`) REFERENCES `charity_settlements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `donations`
--

LOCK TABLES `donations` WRITE;
/*!40000 ALTER TABLE `donations` DISABLE KEYS */;
/*!40000 ALTER TABLE `donations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `e_money`
--

DROP TABLE IF EXISTS `e_money`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `e_money` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `current_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `charge_earned` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `pending_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `held_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `e_money_user_id_unique` (`user_id`),
  KEY `e_money_zone_idx` (`zone_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `e_money`
--

LOCK TABLES `e_money` WRITE;
/*!40000 ALTER TABLE `e_money` DISABLE KEYS */;
/*!40000 ALTER TABLE `e_money` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family_fund_members`
--

DROP TABLE IF EXISTS `family_fund_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_fund_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fund_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` enum('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  `status` enum('invited','active','declined','removed') NOT NULL DEFAULT 'invited',
  `total_contributed` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_disbursed` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `invited_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `joined_at` timestamp NULL DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `invited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fund_member_unique` (`fund_id`,`user_id`),
  KEY `family_fund_members_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `family_fund_members_fund_id_foreign` FOREIGN KEY (`fund_id`) REFERENCES `family_funds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `family_fund_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family_fund_members`
--

LOCK TABLES `family_fund_members` WRITE;
/*!40000 ALTER TABLE `family_fund_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `family_fund_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family_fund_transactions`
--

DROP TABLE IF EXISTS `family_fund_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_fund_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tx_ulid` varchar(26) NOT NULL,
  `fund_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `tx_type` enum('contribute','disburse_to_member','disburse_to_external','adjustment') NOT NULL,
  `amount` decimal(20,4) NOT NULL,
  `balance_before` decimal(20,4) NOT NULL,
  `balance_after` decimal(20,4) NOT NULL,
  `wallet_transaction_id` varchar(32) DEFAULT NULL,
  `beneficiary_user_id` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `status` enum('completed','pending_approval','rejected','cancelled') NOT NULL DEFAULT 'completed',
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `family_fund_transactions_tx_ulid_unique` (`tx_ulid`),
  KEY `family_fund_transactions_fund_id_created_at_index` (`fund_id`,`created_at`),
  KEY `family_fund_transactions_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `family_fund_transactions_beneficiary_user_id_foreign` (`beneficiary_user_id`),
  KEY `family_fund_transactions_tx_type_index` (`tx_type`),
  KEY `family_fund_transactions_status_index` (`status`),
  CONSTRAINT `family_fund_transactions_beneficiary_user_id_foreign` FOREIGN KEY (`beneficiary_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `family_fund_transactions_fund_id_foreign` FOREIGN KEY (`fund_id`) REFERENCES `family_funds` (`id`),
  CONSTRAINT `family_fund_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family_fund_transactions`
--

LOCK TABLES `family_fund_transactions` WRITE;
/*!40000 ALTER TABLE `family_fund_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `family_fund_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `family_funds`
--

DROP TABLE IF EXISTS `family_funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_funds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fund_ulid` varchar(26) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `owner_user_id` bigint(20) unsigned NOT NULL,
  `balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `held_balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `status` enum('active','archived','frozen') NOT NULL DEFAULT 'active',
  `require_owner_approval_for_disbursement` tinyint(1) NOT NULL DEFAULT 1,
  `max_member_contribution_per_day` decimal(20,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `family_funds_fund_ulid_unique` (`fund_ulid`),
  KEY `family_funds_owner_user_id_index` (`owner_user_id`),
  KEY `family_funds_zone_code_status_index` (`zone_code`,`status`),
  CONSTRAINT `family_funds_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `family_funds`
--

LOCK TABLES `family_funds` WRITE;
/*!40000 ALTER TABLE `family_funds` DISABLE KEYS */;
/*!40000 ALTER TABLE `family_funds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `faq_categories`
--

DROP TABLE IF EXISTS `faq_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `faq_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `click_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `faq_categories_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faq_categories`
--

LOCK TABLES `faq_categories` WRITE;
/*!40000 ALTER TABLE `faq_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `faq_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `faqs`
--

DROP TABLE IF EXISTS `faqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `faqs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `readable_id` varchar(255) NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `question` text NOT NULL,
  `answer` text NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faqs`
--

LOCK TABLES `faqs` WRITE;
/*!40000 ALTER TABLE `faqs` DISABLE KEYS */;
/*!40000 ALTER TABLE `faqs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `favourite_numbers`
--

DROP TABLE IF EXISTS `favourite_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `favourite_numbers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `favourite_numbers`
--

LOCK TABLES `favourite_numbers` WRITE;
/*!40000 ALTER TABLE `favourite_numbers` DISABLE KEYS */;
/*!40000 ALTER TABLE `favourite_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_change_logs`
--

DROP TABLE IF EXISTS `fee_change_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_change_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(48) NOT NULL,
  `action` varchar(24) NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fee_change_logs_code_index` (`code`),
  KEY `fee_change_logs_fee_scheme_id_index` (`fee_scheme_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_change_logs`
--

LOCK TABLES `fee_change_logs` WRITE;
/*!40000 ALTER TABLE `fee_change_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_change_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_schemes`
--

DROP TABLE IF EXISTS `fee_schemes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_schemes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(48) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `applies_to` varchar(16) NOT NULL DEFAULT 'customer',
  `fee_type` varchar(24) NOT NULL DEFAULT 'percent',
  `percent_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `fixed_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `min_fee` decimal(20,4) DEFAULT NULL,
  `max_fee` decimal(20,4) DEFAULT NULL,
  `agent_commission_percent` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `agent_commission_fixed` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `bearer` varchar(16) NOT NULL DEFAULT 'sender',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` timestamp NULL DEFAULT NULL,
  `effective_to` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fee_active_lookup` (`code`,`zone_code`,`applies_to`,`is_active`),
  KEY `fee_schemes_code_version_index` (`code`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_schemes`
--

LOCK TABLES `fee_schemes` WRITE;
/*!40000 ALTER TABLE `fee_schemes` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_schemes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_company_accounts`
--

DROP TABLE IF EXISTS `fuel_company_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_company_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(32) DEFAULT NULL,
  `tax_number` varchar(64) DEFAULT NULL,
  `credit_limit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `current_balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `monthly_limit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `last_payment_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fuel_company_accounts_merchant_user_id_is_active_index` (`merchant_user_id`,`is_active`),
  KEY `fuel_company_accounts_merchant_user_id_index` (`merchant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_company_accounts`
--

LOCK TABLES `fuel_company_accounts` WRITE;
/*!40000 ALTER TABLE `fuel_company_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_company_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_company_cards`
--

DROP TABLE IF EXISTS `fuel_company_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_company_cards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_account_id` bigint(20) unsigned NOT NULL,
  `card_number` varchar(40) NOT NULL,
  `card_label` varchar(120) DEFAULT NULL,
  `vehicle_plate` varchar(32) DEFAULT NULL,
  `driver_name` varchar(120) DEFAULT NULL,
  `driver_phone` varchar(32) DEFAULT NULL,
  `daily_limit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `monthly_limit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `card_unique_per_company` (`company_account_id`,`card_number`),
  KEY `fuel_company_cards_company_account_id_is_active_index` (`company_account_id`,`is_active`),
  KEY `fuel_company_cards_company_account_id_index` (`company_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_company_cards`
--

LOCK TABLES `fuel_company_cards` WRITE;
/*!40000 ALTER TABLE `fuel_company_cards` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_company_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_meter_readings`
--

DROP TABLE IF EXISTS `fuel_meter_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_meter_readings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pump_id` bigint(20) unsigned NOT NULL,
  `reading` decimal(14,3) NOT NULL,
  `reading_type` varchar(16) NOT NULL DEFAULT 'manual',
  `taken_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `taken_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fuel_meter_readings_pump_id_taken_at_index` (`pump_id`,`taken_at`),
  KEY `fuel_meter_readings_pump_id_index` (`pump_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_meter_readings`
--

LOCK TABLES `fuel_meter_readings` WRITE;
/*!40000 ALTER TABLE `fuel_meter_readings` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_meter_readings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_products`
--

DROP TABLE IF EXISTS `fuel_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `station_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `product_code` varchar(32) DEFAULT NULL,
  `price_per_liter` decimal(12,4) NOT NULL,
  `color_hex` varchar(7) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fuel_products_station_id_is_active_index` (`station_id`,`is_active`),
  KEY `fuel_products_station_id_index` (`station_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_products`
--

LOCK TABLES `fuel_products` WRITE;
/*!40000 ALTER TABLE `fuel_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_pump_products`
--

DROP TABLE IF EXISTS `fuel_pump_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_pump_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pump_id` bigint(20) unsigned NOT NULL,
  `fuel_product_id` bigint(20) unsigned NOT NULL,
  `nozzle_number` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pump_product_unique` (`pump_id`,`fuel_product_id`),
  KEY `fuel_pump_products_pump_id_index` (`pump_id`),
  KEY `fuel_pump_products_fuel_product_id_index` (`fuel_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_pump_products`
--

LOCK TABLES `fuel_pump_products` WRITE;
/*!40000 ALTER TABLE `fuel_pump_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_pump_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_pumps`
--

DROP TABLE IF EXISTS `fuel_pumps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_pumps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `station_id` bigint(20) unsigned NOT NULL,
  `pump_number` int(11) NOT NULL,
  `pump_name` varchar(80) DEFAULT NULL,
  `pump_type` varchar(16) NOT NULL DEFAULT 'mechanical',
  `current_meter_reading` decimal(14,3) NOT NULL DEFAULT 0.000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_pumps_station_id_pump_number_unique` (`station_id`,`pump_number`),
  KEY `fuel_pumps_station_id_index` (`station_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_pumps`
--

LOCK TABLES `fuel_pumps` WRITE;
/*!40000 ALTER TABLE `fuel_pumps` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_pumps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_sales`
--

DROP TABLE IF EXISTS `fuel_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `sale_ulid` varchar(26) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `station_id` bigint(20) unsigned NOT NULL,
  `pump_id` bigint(20) unsigned NOT NULL,
  `fuel_product_id` bigint(20) unsigned NOT NULL,
  `sale_type` varchar(16) NOT NULL,
  `liters` decimal(12,4) NOT NULL,
  `price_per_liter` decimal(12,4) NOT NULL,
  `total_amount` decimal(14,4) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `paid_transaction_id` varchar(64) DEFAULT NULL,
  `company_account_id` bigint(20) unsigned DEFAULT NULL,
  `company_card_id` varchar(40) DEFAULT NULL,
  `vehicle_plate` varchar(32) DEFAULT NULL,
  `driver_name` varchar(120) DEFAULT NULL,
  `meter_reading_before` decimal(14,3) DEFAULT NULL,
  `meter_reading_after` decimal(14,3) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_sales_sale_ulid_unique` (`sale_ulid`),
  KEY `fuel_sales_merchant_user_id_created_at_index` (`merchant_user_id`,`created_at`),
  KEY `fuel_sales_station_id_created_at_index` (`station_id`,`created_at`),
  KEY `fuel_sales_merchant_user_id_index` (`merchant_user_id`),
  KEY `fuel_sales_pos_user_id_index` (`pos_user_id`),
  KEY `fuel_sales_station_id_index` (`station_id`),
  KEY `fuel_sales_pump_id_index` (`pump_id`),
  KEY `fuel_sales_fuel_product_id_index` (`fuel_product_id`),
  KEY `fuel_sales_company_account_id_index` (`company_account_id`),
  KEY `fs_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_sales`
--

LOCK TABLES `fuel_sales` WRITE;
/*!40000 ALTER TABLE `fuel_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_shift_pump_summaries`
--

DROP TABLE IF EXISTS `fuel_shift_pump_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_shift_pump_summaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` bigint(20) unsigned NOT NULL,
  `pump_id` bigint(20) unsigned NOT NULL,
  `opening_meter` decimal(14,3) NOT NULL DEFAULT 0.000,
  `closing_meter` decimal(14,3) DEFAULT NULL,
  `expected_liters` decimal(14,4) DEFAULT NULL,
  `recorded_liters` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `liters_variance` decimal(14,4) DEFAULT NULL,
  `total_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `sales_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_shift_pump_summaries_shift_id_pump_id_unique` (`shift_id`,`pump_id`),
  KEY `fuel_shift_pump_summaries_shift_id_index` (`shift_id`),
  KEY `fuel_shift_pump_summaries_pump_id_index` (`pump_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_shift_pump_summaries`
--

LOCK TABLES `fuel_shift_pump_summaries` WRITE;
/*!40000 ALTER TABLE `fuel_shift_pump_summaries` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_shift_pump_summaries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_shifts`
--

DROP TABLE IF EXISTS `fuel_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shift_ulid` varchar(26) NOT NULL,
  `station_id` bigint(20) unsigned NOT NULL,
  `opened_by_user_id` bigint(20) unsigned NOT NULL,
  `closed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `opened_at` timestamp NOT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `opening_cash` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `expected_cash` decimal(14,4) DEFAULT NULL,
  `actual_cash` decimal(14,4) DEFAULT NULL,
  `variance` decimal(14,4) DEFAULT NULL,
  `total_cash_sales` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_amial_pay_sales` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_company_sales` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_liters` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_sales_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `variance_reason` text DEFAULT NULL,
  `opening_notes` text DEFAULT NULL,
  `closing_notes` text DEFAULT NULL,
  `requires_admin_review` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_shifts_shift_ulid_unique` (`shift_ulid`),
  KEY `fuel_shifts_station_id_status_index` (`station_id`,`status`),
  KEY `fuel_shifts_opened_at_index` (`opened_at`),
  KEY `fuel_shifts_station_id_index` (`station_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_shifts`
--

LOCK TABLES `fuel_shifts` WRITE;
/*!40000 ALTER TABLE `fuel_shifts` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_stations`
--

DROP TABLE IF EXISTS `fuel_stations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_stations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `station_name` varchar(120) NOT NULL,
  `license_number` varchar(64) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_stations_merchant_user_id_unique` (`merchant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_stations`
--

LOCK TABLES `fuel_stations` WRITE;
/*!40000 ALTER TABLE `fuel_stations` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_stations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fuel_variance_records`
--

DROP TABLE IF EXISTS `fuel_variance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fuel_variance_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_ulid` varchar(26) NOT NULL,
  `shift_id` bigint(20) unsigned NOT NULL,
  `station_id` bigint(20) unsigned NOT NULL,
  `reported_by_user_id` bigint(20) unsigned NOT NULL,
  `variance_type` varchar(32) NOT NULL,
  `direction` varchar(16) NOT NULL,
  `amount` decimal(14,4) NOT NULL,
  `reason` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `resolution_status` varchar(32) NOT NULL DEFAULT 'pending',
  `resolved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_variance_records_record_ulid_unique` (`record_ulid`),
  KEY `fuel_variance_records_station_id_resolution_status_index` (`station_id`,`resolution_status`),
  KEY `fuel_variance_records_shift_id_index` (`shift_id`),
  KEY `fuel_variance_records_station_id_index` (`station_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fuel_variance_records`
--

LOCK TABLES `fuel_variance_records` WRITE;
/*!40000 ALTER TABLE `fuel_variance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `fuel_variance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funds`
--

DROP TABLE IF EXISTS `funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `funds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `payment_method` varchar(255) NOT NULL,
  `tran_id` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `funds`
--

LOCK TABLES `funds` WRITE;
/*!40000 ALTER TABLE `funds` DISABLE KEYS */;
/*!40000 ALTER TABLE `funds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `help_topics`
--

DROP TABLE IF EXISTS `help_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `help_topics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `question` text DEFAULT NULL,
  `answer` text DEFAULT NULL,
  `ranking` int(11) NOT NULL DEFAULT 1,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `help_topics`
--

LOCK TABLES `help_topics` WRITE;
/*!40000 ALTER TABLE `help_topics` DISABLE KEYS */;
/*!40000 ALTER TABLE `help_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `idempotency_keys`
--

DROP TABLE IF EXISTS `idempotency_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `idempotency_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(128) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `endpoint` varchar(191) NOT NULL,
  `request_hash` varchar(64) NOT NULL,
  `response_status` smallint(5) unsigned DEFAULT NULL,
  `response_body` longtext DEFAULT NULL,
  `transaction_id` varchar(64) DEFAULT NULL,
  `status` enum('processing','completed','failed') NOT NULL DEFAULT 'processing',
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_unique` (`key`,`user_id`,`endpoint`),
  KEY `idempotency_keys_user_id_status_index` (`user_id`,`status`),
  KEY `idempotency_keys_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `idempotency_keys`
--

LOCK TABLES `idempotency_keys` WRITE;
/*!40000 ALTER TABLE `idempotency_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `idempotency_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kyc_tier_limits`
--

DROP TABLE IF EXISTS `kyc_tier_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kyc_tier_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tier` tinyint(3) unsigned NOT NULL,
  `name_ar` varchar(100) NOT NULL,
  `max_balance` decimal(20,4) NOT NULL,
  `max_single_transaction` decimal(20,4) NOT NULL,
  `max_daily_total` decimal(20,4) NOT NULL,
  `max_monthly_total` decimal(20,4) NOT NULL,
  `required_documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_documents`)),
  `allowed_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_features`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kyc_tier_limits_tier_unique` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kyc_tier_limits`
--

LOCK TABLES `kyc_tier_limits` WRITE;
/*!40000 ALTER TABLE `kyc_tier_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `kyc_tier_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ledger_accounts`
--

DROP TABLE IF EXISTS `ledger_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_code` varchar(50) NOT NULL,
  `account_type` varchar(30) NOT NULL,
  `name_ar` varchar(200) NOT NULL,
  `owner_user_id` bigint(20) unsigned DEFAULT NULL,
  `owner_type` varchar(30) DEFAULT NULL,
  `current_balance` decimal(24,4) NOT NULL DEFAULT 0.0000,
  `normal_balance` enum('debit','credit') NOT NULL DEFAULT 'credit',
  `currency` varchar(8) NOT NULL DEFAULT 'YER',
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledger_accounts_account_code_unique` (`account_code`),
  KEY `ledger_accounts_owner_user_id_owner_type_index` (`owner_user_id`,`owner_type`),
  KEY `ledger_accounts_account_type_index` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ledger_accounts`
--

LOCK TABLES `ledger_accounts` WRITE;
/*!40000 ALTER TABLE `ledger_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `ledger_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ledger_entry_lines`
--

DROP TABLE IF EXISTS `ledger_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_entry_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `direction` enum('debit','credit') NOT NULL,
  `amount` decimal(24,4) NOT NULL,
  `balance_before` decimal(24,4) NOT NULL,
  `balance_after` decimal(24,4) NOT NULL,
  `description_ar` varchar(500) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ledger_entry_lines_account_id_created_at_index` (`account_id`,`created_at`),
  KEY `ledger_entry_lines_journal_entry_id_index` (`journal_entry_id`),
  CONSTRAINT `ledger_entry_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`),
  CONSTRAINT `ledger_entry_lines_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `ledger_journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ledger_entry_lines`
--

LOCK TABLES `ledger_entry_lines` WRITE;
/*!40000 ALTER TABLE `ledger_entry_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `ledger_entry_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ledger_journal_entries`
--

DROP TABLE IF EXISTS `ledger_journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_ulid` varchar(26) NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(80) DEFAULT NULL,
  `description_ar` text NOT NULL,
  `total_amount` decimal(24,4) NOT NULL,
  `is_reversal` tinyint(1) NOT NULL DEFAULT 0,
  `reverses_entry_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('posted','reversed') NOT NULL DEFAULT 'posted',
  `reversed_by_entry_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledger_journal_entries_entry_ulid_unique` (`entry_ulid`),
  UNIQUE KEY `ledger_journal_entries_idempotency_key_unique` (`idempotency_key`),
  KEY `ledger_journal_entries_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `ledger_journal_entries_status_posted_at_index` (`status`,`posted_at`),
  KEY `ledger_journal_entries_is_reversal_index` (`is_reversal`),
  KEY `ledger_journal_entries_reverses_entry_id_foreign` (`reverses_entry_id`),
  CONSTRAINT `ledger_journal_entries_reverses_entry_id_foreign` FOREIGN KEY (`reverses_entry_id`) REFERENCES `ledger_journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ledger_journal_entries`
--

LOCK TABLES `ledger_journal_entries` WRITE;
/*!40000 ALTER TABLE `ledger_journal_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `ledger_journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `legal_terms`
--

DROP TABLE IF EXISTS `legal_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `legal_terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'ar',
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `effective_at` timestamp NOT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `changelog` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legal_terms_version_locale_unique` (`version`,`locale`),
  KEY `legal_terms_current_idx` (`locale`,`is_current`),
  KEY `legal_terms_effective_idx` (`effective_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `legal_terms`
--

LOCK TABLES `legal_terms` WRITE;
/*!40000 ALTER TABLE `legal_terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `legal_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `linked_websites`
--

DROP TABLE IF EXISTS `linked_websites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `linked_websites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `status` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `linked_websites`
--

LOCK TABLES `linked_websites` WRITE;
/*!40000 ALTER TABLE `linked_websites` DISABLE KEYS */;
/*!40000 ALTER TABLE `linked_websites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_products`
--

DROP TABLE IF EXISTS `merchant_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(160) NOT NULL,
  `price` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `cost_price` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `offer_price` decimal(20,4) DEFAULT NULL,
  `quantity` decimal(20,3) NOT NULL DEFAULT 0.000,
  `production_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `merchant_products_merchant_user_id_is_active_index` (`merchant_user_id`,`is_active`),
  KEY `merchant_products_merchant_user_id_barcode_index` (`merchant_user_id`,`barcode`),
  KEY `merchant_products_merchant_user_id_index` (`merchant_user_id`),
  KEY `merchant_products_merchant_user_id_expiry_date_index` (`merchant_user_id`,`expiry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_products`
--

LOCK TABLES `merchant_products` WRITE;
/*!40000 ALTER TABLE `merchant_products` DISABLE KEYS */;
INSERT INTO `merchant_products` VALUES
(1,1,'Item0',10.0000,0.0000,NULL,996175.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:29','2026-06-06 19:58:47'),
(2,2,'Item1',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:29','2026-06-06 19:58:29'),
(3,3,'Item2',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:29','2026-06-06 19:58:29'),
(4,4,'Item3',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:29','2026-06-06 19:58:29'),
(5,5,'Item4',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:29','2026-06-06 19:58:29'),
(6,6,'Item5',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(7,7,'Item6',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(8,8,'Item7',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(9,9,'Item8',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(10,10,'Item9',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(11,11,'Item10',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(12,12,'Item11',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(13,13,'Item12',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(14,14,'Item13',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(15,15,'Item14',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(16,16,'Item15',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(17,17,'Item16',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(18,18,'Item17',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(19,19,'Item18',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30'),
(20,20,'Item19',10.0000,0.0000,NULL,1000000.000,NULL,NULL,NULL,NULL,1,'2026-06-06 19:58:30','2026-06-06 19:58:30');
/*!40000 ALTER TABLE `merchant_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_profiles`
--

DROP TABLE IF EXISTS `merchant_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `tier` varchar(24) NOT NULL DEFAULT 'micro',
  `risk_category` enum('low','standard','elevated','high') NOT NULL DEFAULT 'standard',
  `business_type` varchar(100) DEFAULT NULL,
  `subscription_plan` varchar(24) NOT NULL DEFAULT 'free',
  `subscription_expires_at` timestamp NULL DEFAULT NULL,
  `declared_monthly_volume` decimal(20,4) DEFAULT NULL,
  `declared_daily_customers` int(11) DEFAULT NULL,
  `verification_status` enum('unverified','pending_review','verified','rejected','resubmission_required','verification_suspended') NOT NULL DEFAULT 'unverified',
  `daily_receive_limit` decimal(20,4) NOT NULL DEFAULT 500000.0000,
  `single_receive_limit` decimal(20,4) NOT NULL DEFAULT 100000.0000,
  `monthly_receive_limit` decimal(20,4) NOT NULL DEFAULT 5000000.0000,
  `can_transfer_out` tinyint(1) NOT NULL DEFAULT 0,
  `requires_settlement_only` tinyint(1) NOT NULL DEFAULT 1,
  `verified_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `subscription_notes` text DEFAULT NULL,
  `extra_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_features`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `merchant_profiles_user_id_unique` (`user_id`),
  KEY `merchant_profiles_tier_risk_category_index` (`tier`,`risk_category`),
  KEY `merchant_profiles_verification_status_index` (`verification_status`),
  KEY `merchant_profiles_subscription_plan_index` (`subscription_plan`),
  CONSTRAINT `merchant_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_profiles`
--

LOCK TABLES `merchant_profiles` WRITE;
/*!40000 ALTER TABLE `merchant_profiles` DISABLE KEYS */;
INSERT INTO `merchant_profiles` VALUES
(1,1,'small','standard','retail','enterprise','2027-06-06 19:58:29',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL),
(2,2,'small','standard','retail','enterprise','2027-06-06 19:58:29',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL),
(3,3,'small','standard','retail','enterprise','2027-06-06 19:58:29',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL),
(4,4,'small','standard','retail','enterprise','2027-06-06 19:58:29',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL),
(5,5,'small','standard','retail','enterprise','2027-06-06 19:58:29',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL),
(6,6,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(7,7,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(8,8,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(9,9,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(10,10,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(11,11,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(12,12,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(13,13,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(14,14,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(15,15,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(16,16,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(17,17,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(18,18,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(19,19,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL),
(20,20,'small','standard','retail','enterprise','2027-06-06 19:58:30',NULL,NULL,'verified',500000.0000,100000.0000,5000000.0000,0,1,NULL,NULL,'SOUTH','2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL);
/*!40000 ALTER TABLE `merchant_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_refunds`
--

DROP TABLE IF EXISTS `merchant_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `refund_ulid` varchar(26) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `customer_user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `original_transaction_id` varchar(64) NOT NULL,
  `original_sale_ulid` varchar(26) DEFAULT NULL,
  `original_amount` decimal(20,4) NOT NULL,
  `refund_amount` decimal(20,4) NOT NULL,
  `refund_method` varchar(16) NOT NULL DEFAULT 'wallet',
  `credit_account_id` bigint(20) unsigned DEFAULT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `reason` varchar(500) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'completed',
  `approved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `ledger_entry_ulid` varchar(26) DEFAULT NULL,
  `receipt_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `merchant_refunds_refund_ulid_unique` (`refund_ulid`),
  KEY `merchant_refunds_merchant_user_id_status_index` (`merchant_user_id`,`status`),
  KEY `merchant_refunds_customer_user_id_created_at_index` (`customer_user_id`,`created_at`),
  KEY `merchant_refunds_original_transaction_id_index` (`original_transaction_id`),
  KEY `merchant_refunds_status_index` (`status`),
  KEY `merchant_refunds_original_sale_ulid_index` (`original_sale_ulid`),
  CONSTRAINT `merchant_refunds_customer_user_id_foreign` FOREIGN KEY (`customer_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `merchant_refunds_merchant_user_id_foreign` FOREIGN KEY (`merchant_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_refunds`
--

LOCK TABLES `merchant_refunds` WRITE;
/*!40000 ALTER TABLE `merchant_refunds` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchant_refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_risk_events`
--

DROP TABLE IF EXISTS `merchant_risk_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_risk_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `risk_contribution` decimal(6,2) NOT NULL DEFAULT 0.00,
  `description` varchar(500) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `transaction_ulid` varchar(32) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `merchant_risk_events_merchant_user_id_created_at_index` (`merchant_user_id`,`created_at`),
  KEY `merchant_risk_events_event_type_index` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_risk_events`
--

LOCK TABLES `merchant_risk_events` WRITE;
/*!40000 ALTER TABLE `merchant_risk_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchant_risk_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_risk_profiles`
--

DROP TABLE IF EXISTS `merchant_risk_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_risk_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `current_risk_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `risk_level` enum('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `avg_daily_volume` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `peak_daily_volume` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `avg_daily_customers` int(11) NOT NULL DEFAULT 0,
  `total_received_lifetime` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_transferred_out` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `aml_flags_count` int(11) NOT NULL DEFAULT 0,
  `volume_anomaly_count` int(11) NOT NULL DEFAULT 0,
  `last_flagged_at` timestamp NULL DEFAULT NULL,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `merchant_risk_profiles_merchant_user_id_unique` (`merchant_user_id`),
  KEY `merchant_risk_profiles_risk_level_current_risk_score_index` (`risk_level`,`current_risk_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_risk_profiles`
--

LOCK TABLES `merchant_risk_profiles` WRITE;
/*!40000 ALTER TABLE `merchant_risk_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchant_risk_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_sales`
--

DROP TABLE IF EXISTS `merchant_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_ulid` varchar(40) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `total_amount` decimal(20,4) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'completed',
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `customer_name` varchar(120) DEFAULT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `paid_transaction_id` varchar(40) DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `merchant_sales_sale_ulid_unique` (`sale_ulid`),
  KEY `merchant_sales_merchant_user_id_created_at_index` (`merchant_user_id`,`created_at`),
  KEY `merchant_sales_merchant_user_id_payment_method_index` (`merchant_user_id`,`payment_method`),
  KEY `merchant_sales_merchant_user_id_index` (`merchant_user_id`),
  KEY `merchant_sales_pos_user_id_index` (`pos_user_id`),
  KEY `merchant_sales_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3826 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_sales`
--

LOCK TABLES `merchant_sales` WRITE;
/*!40000 ALTER TABLE `merchant_sales` DISABLE KEYS */;
INSERT INTO `merchant_sales` VALUES
(1,'01KTF8847ATRSD4XD35JV0Q8WC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(2,'01KTF8847H59Q240JR2JZCPWJY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(3,'01KTF8847NA8PCMR66M26EPWNJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(4,'01KTF8847RNX9AAYCFR2NFG8YE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(5,'01KTF8847VYGZHJF57JZ1HDN3W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(6,'01KTF8847YWM989NTSN7151YAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(7,'01KTF88484DDV49ZTB7FSXC16W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(8,'01KTF88488BCQY34KZJ39D6A4N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(9,'01KTF884AQJXSHZG59D86H3N47',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(10,'01KTF884CAFN36FNJAWPVP4WJ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(11,'01KTF884EB4FVAQAHS16AFRB7W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:35','2026-06-06 19:58:35'),
(12,'01KTF884GBCAFF4N782MHTW8JJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(13,'01KTF884HQG4HFPYXCYSHZY373',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(14,'01KTF884K6SBPZG6KA4A8NQQ34',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(15,'01KTF884M7ZYRVMG2QJY25K22J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(16,'01KTF884N3JTC3XKQAE72V1TXP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(17,'01KTF884QQQTQY4ZRN9RHXY6C9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(18,'01KTF884TQED2MMVPBYSDM6AKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(19,'01KTF884WVMTVZ7SC4V1R4ZFSK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(20,'01KTF88505WXX7KQ4D267C0B44',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(21,'01KTF8852BWM3AQNMCN8Y7J6CE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(22,'01KTF8855FGNJ457WV1TVFNGDP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(23,'01KTF8857QHM0WB1PVNHJZZDXR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(24,'01KTF8858FHV09RADXQVFK9DA6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(25,'01KTF8859K6VX5PY221FZB806T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:36','2026-06-06 19:58:36'),
(26,'01KTF8878S09HCYNCTKTXTYQZP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:38','2026-06-06 19:58:38'),
(27,'01KTF887HKAPDYTZZQG6QSNPTB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(28,'01KTF887MWXQK39ZEVGBBZ27CF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(29,'01KTF887NYKJCTG8176BSE6D3H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(30,'01KTF887T3T1HV5D5XGYX5PYFT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(31,'01KTF887Y3459XVD2WBZFKPTAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(32,'01KTF887ZTDXHJMW8KN17NGE21',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(33,'01KTF888091SQJ3M0SQVWT9BSJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(34,'01KTF887Z4F51YBF3FNH2E2154',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(35,'01KTF88819T3YYF8ZQRSWXWBVT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(36,'01KTF8883DSBFSQ782R0227JKW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(37,'01KTF8883Z9PSZ8AP8H3N2FF7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(38,'01KTF88837BESDV0RYEJE49BM4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(39,'01KTF888372BR78SP4GQ53J99Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(40,'01KTF8886V7HG2NSNWWVCXM7KS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(41,'01KTF8883ETTZM2MEJ61NH1GMF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(42,'01KTF8887EVNGHTMDGR5HZB4MZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(43,'01KTF8888AFCE2QTP8BFWDDKHM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(44,'01KTF8888D2HRVHKANS9E3HD9M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(45,'01KTF8888GZE6XBJESPQCBS8KG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(46,'01KTF888AFD010VRRD926Y0HSF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(47,'01KTF888BF53STRPMN1KM20T0V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(48,'01KTF888CEJDGS79NPY8PNYQ45',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(49,'01KTF888CJHK5CY0GFJ36G0V59',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(50,'01KTF888C69P7XWD8SZHXZMSJ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(51,'01KTF888DY200DMKE36Y2M58JF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(52,'01KTF888EBKJZSKGRYZMPGV5TT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(53,'01KTF8889BGD8FZKFHYQGAA05A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:39','2026-06-06 19:58:39'),
(54,'01KTF888FA7SF2B3ZPJT5WT8S1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(55,'01KTF888FG1AZEBY3201QXBCGP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(56,'01KTF888F9CHS99CF3VHAGBRM0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(57,'01KTF888FZJEW5RHWS36XSNBCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(58,'01KTF888J3JD7876AWK9K0P2J8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(59,'01KTF888FQ7PA5KSZWFF1VSYK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(60,'01KTF888H3DVY9TJ1H5YTSB2B8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(61,'01KTF888HPNNTRET1QKPQFBVCS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(62,'01KTF888KPY61Q7S51T0C91YHH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(63,'01KTF888K273G53TF32CFV3PJD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(64,'01KTF888M6R4DAND2DFP7A02N8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(65,'01KTF888MAQJ3KK5W9R2CPK44A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(66,'01KTF888MXW3JJXD72BKDPHXFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(67,'01KTF888MX3WH82F3GEEA0XJ49',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(68,'01KTF888NY7JM4MNPK85HCRTE6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(69,'01KTF888GJNMNHYHTMTQNMWHDT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(70,'01KTF888KV1EMX31PYQ990JRB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(71,'01KTF888Q3P2677B9QAMRGYP9D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(72,'01KTF888Q3B1VCA896J3YM1S4N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(73,'01KTF888QJTH14P5PVE00ZMWWZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(74,'01KTF888QVTETB94F6FP0B9H32',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(75,'01KTF888J2F8E09K89DARG8RJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(76,'01KTF888Q63H0WKX51M5N31MYB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(77,'01KTF888SK3S5DN3X9GH2VK5A2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(78,'01KTF888T2BCZJTSM3ZVCDATP1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(79,'01KTF888T6EN8PY545ETK6TAZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(80,'01KTF888TEX2JG8VCZP5NQ9TBP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(81,'01KTF888TJ9MC6DFH6AP218SEM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(82,'01KTF888VYT34S56MYYP82DCZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(83,'01KTF888W5BDCVA6J751YWCY6W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(84,'01KTF888S3HRAPRMP3X2RKS9WW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(85,'01KTF888RRKHZDBT8PSVSWP5EP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(86,'01KTF888WT4BCG6CZ87K4V8Q8E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(87,'01KTF888X2N80MP7W56KMNME5R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(88,'01KTF888SCWA5WP1Y8W6BS7GEF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(89,'01KTF888T0PW2SJNGJMT94DVXA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(90,'01KTF888XBRZWHH92GK1DG6AG5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(91,'01KTF888V7B2F325ZYB7E709RY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(92,'01KTF888XJ5BW46854M1V7TXWY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(93,'01KTF888WS3AG4VT205RNJMCV6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(94,'01KTF888XVGJKVQSPWF6HY6E98',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(95,'01KTF888XRKDCVDY2473S1AJW5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(96,'01KTF888Y3B531QZNR7511GJQC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(97,'01KTF888YTYKVC0T2FWWH05E0H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(98,'01KTF888Z6STKA715XCSGYMXPA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(99,'01KTF888Z8F468RF9T9VJ7X9QN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(100,'01KTF888YH01GF3TS4DEK7ZSAY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(101,'01KTF888ZP9YFYBEAWD0VRCDXZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(102,'01KTF888ZBC3S5P38ZRD0T823R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(103,'01KTF888V22P9GFPZ2YXZZ5SFV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(104,'01KTF888TRWREBN6578QKDDGM9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(105,'01KTF88912PQSM0PAAAZS5ZX0F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(106,'01KTF888TYQ3KW3Q4ZNB9JT58C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(107,'01KTF8891B76BR16H9RCK02Q1Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(108,'01KTF888W7QGFGJ0R8GQARP47H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(109,'01KTF888W32R65FP1Z5FW2G65W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(110,'01KTF888XTV73VMMDDD6XSJ35H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(111,'01KTF8891VM1DMR7JH2X0HJG9M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(112,'01KTF888Z6S24PMSSYFSYGE396',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(113,'01KTF8892BB15GHGMZNEAJQBEM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(114,'01KTF8892DSRQVH388C1QT52J8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(115,'01KTF8890KX2P56XMTQ132S8ZT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(116,'01KTF8892FM0CC625XR4P15E72',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(117,'01KTF8892QVZW4X4FVZW5CH5X1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(118,'01KTF88939TBKHW81ATFG5AZ5J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(119,'01KTF88932J4JSFJMMKKENTWGJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(120,'01KTF8893J2VKH50JV1SZXRX8J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(121,'01KTF8893Q4XFS16PK55XYJDTR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(122,'01KTF88946PWBH7BWTEP52S74X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(123,'01KTF8894JQMJCW9M0AQV1GW7T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(124,'01KTF8893E1VAM4EYT6NRVA34A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(125,'01KTF88951RJQJZ72FVM8GDK1D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(126,'01KTF8894TPBH4AFGHYWV2E9ND',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(127,'01KTF8892E06ZM2J0JRT9B8K4G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(128,'01KTF889426AJ6BA2TV14CE7H7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(129,'01KTF88927FV3YZECSQA5F6CT9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(130,'01KTF8896AN9YSR607ZD9NWD10',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(131,'01KTF8892JVQZ1QCR6EZK6SJRW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(132,'01KTF88972VP9MVDTE5XB51K1P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(133,'01KTF889457219DT84C5W5YTV7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(134,'01KTF8897AMB20GTWS6V2H27KQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(135,'01KTF8897CF34NY7FEZGNFFT6R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(136,'01KTF8897E4PSS4P2G8BVDRWXE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(137,'01KTF8897VYTND239W3WZ3K1JQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(138,'01KTF88946VB0EJ9PR7CVWZXAQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(139,'01KTF88982YGD43PYC55ECXVXV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(140,'01KTF8895VK6DMX58FV0X8DF3E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(141,'01KTF8895PY044327SQ9T8EJCH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(142,'01KTF8895349T63QQ932EAZHXT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(143,'01KTF8898T98QKDKEDPTR8JMKX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(144,'01KTF8898WN03DV7C5Y6FSQ1GC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(145,'01KTF88993YDWNMW21ND2G16JZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(146,'01KTF88992ZKYQ55Z9A7G0873P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(147,'01KTF8897TGVN8A7BK4P98T16W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(148,'01KTF8896WMWV9G1Z2TF10DVCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(149,'01KTF88994B3X9TTTDS98RMWSB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(150,'01KTF889936DRF3W0HAS959HQ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(151,'01KTF88994TTSB9667T81X1Z6K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(152,'01KTF8899GZ5J31261W1FW4BN9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(153,'01KTF8899RWWTD2J7VXPFYCS3Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(154,'01KTF8899THN0M97PWRVX3DW08',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(155,'01KTF889A0NKK7B237EH0RCG2H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(156,'01KTF8898Q5CQ8T1XEJAHZV6PB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(157,'01KTF8898J6WT74J8F3MPN0QFG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(158,'01KTF889AC06KY191CRV2ERXC9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(159,'01KTF889B2HJHKW6RF2SWW88AC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(160,'01KTF889B38EFZTP1R6XHSRRJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(161,'01KTF889B84GKVMZTE33XR2DJA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(162,'01KTF889BBPRDWB7CZF3V10CGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(163,'01KTF889AXJG673ZN2J6AA75SQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(164,'01KTF889AHWYV4H065Y1YSJBT6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(165,'01KTF889BSF7P8QM4J8XEFD3PZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(166,'01KTF889BYDE903157N0MA2RY8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(167,'01KTF889BQTGC5C7S2YA46R47E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(168,'01KTF8899WSD5A7EV08F6MD9Y9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(169,'01KTF889ATENGMP6EPBNXMBW18',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(170,'01KTF889A5ZSWHKBYB93VRMXZ5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(171,'01KTF889CDYVNV68HSG54FZZV6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(172,'01KTF889AA64ES7EBC01H9GYYJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(173,'01KTF889BF6T77VKYABPY3TE7T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(174,'01KTF889AY2VGS2N03K5KDA2TA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(175,'01KTF889CFB3JB0B0T66N6Q8KY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(176,'01KTF889CBGWFESZ31R722ZN89',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(177,'01KTF889CDFHSTXKXN7MNTRZXH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(178,'01KTF889D2M5BG0SJ70M5XZCFB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(179,'01KTF889CZ2RYFENFSADRJBSKJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(180,'01KTF889A2WSR8517NM7EF2W87',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:40','2026-06-06 19:58:40'),
(181,'01KTF889CKXZA9XJ4FNKF6AJJ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(182,'01KTF889CTSKSJC4BG7RV33HC4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(183,'01KTF889DJA3E7475PPS4AHG01',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(184,'01KTF889DK283CH25ENC45CE31',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(185,'01KTF889D6P2ME0TF0N54MHN86',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(186,'01KTF889DP2JZ3FP3MQ9P4C4GX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(187,'01KTF889DQ820NMJETJ1QCNNMS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(188,'01KTF889BZP95MVPSTFCXEM215',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(189,'01KTF889DYTD5NP8KSC47A6CWM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(190,'01KTF889DXMEDP4DE12KE280SS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(191,'01KTF889E7GS01FF02A1R4ZRAS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(192,'01KTF889EKEQHFDQ4PSM39W4FX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(193,'01KTF889FE8J28C55VH5WNBVM3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(194,'01KTF889FFYWB2GXXT7ZD0CGFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(195,'01KTF889FG0F131VA6CCNVR9VA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(196,'01KTF889F1YDVQQ5F8MM7RJK19',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(197,'01KTF889FJ152345DJFD836566',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(198,'01KTF889FPDT2MJEHCH1VCF27S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(199,'01KTF889FRFKZTJQ0SZNXVEVWP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(200,'01KTF889FSMMQ7RQW5W4MQNCJ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(201,'01KTF889G2MYDEBSRVDEV1PD6V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(202,'01KTF889G7YTR8VRSSR6JCXSNJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(203,'01KTF889G2P38DWN2N0A45PKQF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(204,'01KTF889F7JJDVB749K9FQ5PFE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(205,'01KTF889H0BQZSZJ5GV0EEAEDF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(206,'01KTF889E3BE7KPDQHR2VJS2DJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(207,'01KTF889H8Y10ZQYSC4YRKEGCF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(208,'01KTF889H5PJ5ZPPYNYZHD7QT2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(209,'01KTF889HBQFJEX83E7131529X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(210,'01KTF889HZVJNEY9JM5ME9RV55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(211,'01KTF889JAH9AAVYR2B4VVRRFX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(212,'01KTF889HCSR8FMZYPZMAT8F5W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(213,'01KTF889J3E7D2A5TRP1BR21DR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(214,'01KTF889HQF0TPJ5KPSR6Q3W0Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(215,'01KTF889HK3CHHSWSBN1KG9M2R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(216,'01KTF889KYCM7QCG06NMKHHCBJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(217,'01KTF889MEP3SCRMJD0S793PPG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(218,'01KTF889MV17CTDP7KWDT39MD5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(219,'01KTF889MTW3QKK63CKECRA54A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(220,'01KTF889MYXCHZ3JJ24QWTPKQM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(221,'01KTF889N2YSWJA1QVFWPJRNCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(222,'01KTF889N875MN8PSTK3Z8ETQR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(223,'01KTF889MACP4WF1XC4M5P0RX7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(224,'01KTF889NCC0PZXYFYQ4R9XX2F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(225,'01KTF889NA5ZYTMT5M62T7QWAC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(226,'01KTF889N7JQGQZN67Y54ZD90K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(227,'01KTF889NDHXVR76QYFX51PWRR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(228,'01KTF889NVBQNZC5852794965G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(229,'01KTF889NX97HK4QF4P0A6XZ55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(230,'01KTF889P350VBZC14EC72MRHG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(231,'01KTF889P6F1Y0DGSQERV0SS9S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(232,'01KTF889PAYJKYBCAKX1YKRQX1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(233,'01KTF889PF0A3QVCYN89VYEF9X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(234,'01KTF889QXBK74H9QKS0WJJ7HY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(235,'01KTF889RWTT37Z4D3SF6M1MPW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(236,'01KTF889RV9P8QP5TVAWZ1K7GS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(237,'01KTF889RV0ER1BR54AVJCCH3P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(238,'01KTF889QBPX1KPABQ5Y0PDQZR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(239,'01KTF889S3N0XZ19PB16FHF7PT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(240,'01KTF889S34AV7BZBP0YQ0HQ9J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(241,'01KTF889SBNH89HR803DDG9CMF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(242,'01KTF889SD27HPFMHA2RKR61YF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(243,'01KTF889SG8CVW0X7GZ2NFF5N4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(244,'01KTF889S7RZ1JBJGDTKEQTP6J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(245,'01KTF889T3V6XSS5473RRMDCGD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(246,'01KTF889T1YA0JHMRXRH97YBB3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(247,'01KTF889T2KEG9C08YEFBTC1DA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(248,'01KTF889T66DD3NZTNMKAKA45K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(249,'01KTF889TMCG34GDD0MN24PEX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(250,'01KTF889TQSSY0A8M5CHDFH6B4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(251,'01KTF889VPABQ5NJGG3XF63AR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(252,'01KTF889VZ4G8YAE0Q7BXH2MVD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(253,'01KTF889W3G7SHXG3XKWBPQZAJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(254,'01KTF889WAXAXB5Z5J8D42YJ3R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(255,'01KTF889X6R5C4EWCZ9KYXJBBN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(256,'01KTF889X91DC0D9AJ5F81QAF9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(257,'01KTF889XW5ZJV11TNCJB312KQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(258,'01KTF889Y301QV0EKN1D13MTVE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(259,'01KTF889Y04X4ZAXB7VPRD5PA8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(260,'01KTF889Y0FFB96RSX30VH66PD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(261,'01KTF889Y2TWK2871HTVY586AP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(262,'01KTF889YB9C1ZN7ZZ7H3KD8QZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(263,'01KTF889Y604SWNBCJDREFMXNR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(264,'01KTF889YB7G0PST8N310CPNQP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(265,'01KTF889YDPZZHG7W50FFNNRKV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(266,'01KTF889YDKK8PDTBB2YB1JBPZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(267,'01KTF889YE696QRWX2ENF1CHW0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(268,'01KTF889YVGD2HSFMHWTDM466V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(269,'01KTF889YN5VNE07Z71F0FP077',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(270,'01KTF889Z2CED8S8V1WVY5MT8B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(271,'01KTF889ZBAES741615V3TE3ZE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(272,'01KTF889ZM5M965PJ7F96Z602H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(273,'01KTF889ZQ49ME6TZJJPBRWFEX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(274,'01KTF889ZQ33016VXMD2Q9C22K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(275,'01KTF889ZQN3DDBRW5595AK7VS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(276,'01KTF889ZTE0ACF13KNWTW811T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(277,'01KTF889ZWT54G6F5KSH1GAMHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(278,'01KTF889ZYABH5BB74HM208XN6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(279,'01KTF88A0050889FVTEW2YZ77D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(280,'01KTF88A0BFDTR8J48Z2HV6NKE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(281,'01KTF88A0EC7MKXYKCK98GXX8P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(282,'01KTF88A0JQN8MTRT0SDJPZS9Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(283,'01KTF88A0QRTREJ9M2DBRQA0G6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(284,'01KTF88A0X88199S7HKBA6NQCX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(285,'01KTF88A11SGSTC58G77SNTXTD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(286,'01KTF88A12SMJXWJSMDPM4R3AY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(287,'01KTF88A1EA597AQ6ZA38H02ET',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(288,'01KTF88A18SCPTSSSS8JTC913G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(289,'01KTF88A2NBX215A0G30JTWPTY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(290,'01KTF88A2NG0C48ZAYRKZGX1ZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(291,'01KTF88A2V95R39SCM82BMQCWG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(292,'01KTF88A2TSY6MA214Q2VF4EMG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(293,'01KTF88A2RTDA5E158TA200N1E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(294,'01KTF88A2YVJDPPDZ55JM5WJS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(295,'01KTF88A2QBHW7HPXGWFA0Z1YW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(296,'01KTF88A36T4QRR98SPYTZWW6P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(297,'01KTF88A394Z7MY1FV9MCR9V9K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(298,'01KTF88A39YDJWCZXG3EZVKH51',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(299,'01KTF88A38J2Z2NSRVRTVXYSX1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(300,'01KTF88A2RTCKSWXQHVA0ERDG5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(301,'01KTF88A3KZ1EDY6PXAT0BXQ5W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(302,'01KTF88A3MBMMQZR77BDD8K9FJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(303,'01KTF88A3PFYVVBKE0Q8C046MQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(304,'01KTF88A3R6PMCJC678XFDNYP4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(305,'01KTF88A3V2Y3TEG91H1HWHRVD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(306,'01KTF88A3TTBEEPKG1CXF57N4F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(307,'01KTF88A3XBN6601123HAVM2XM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(308,'01KTF88A409FAFHY0MY84B0NNB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(309,'01KTF88A43MZQ06Y8CE43WBMT6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(310,'01KTF88A43DA2FVJ4WN4BXCAWJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(311,'01KTF88A464DD32CQ405S7RYVZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(312,'01KTF88A47QJNVB9NCGJFYMCZE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(313,'01KTF88A4ATAHHRMS5SEJ5TGJD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(314,'01KTF88A4CJA6X7HPJY38S8E66',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(315,'01KTF88A4C0V3ST31PPPK38069',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(316,'01KTF88A4F5GY91414VNDTB6RN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(317,'01KTF88A4G4B0V72YTQHHK64A1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(318,'01KTF88A4KXD78S3DR20A443YM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(319,'01KTF88A4MPQPBH1GT6SKT2NX5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(320,'01KTF88A4PT16K9X0W5NGBDSWW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(321,'01KTF88A4QN339547JZ4DXJ7E0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(322,'01KTF88A4SGPH12J4XH42FDK63',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(323,'01KTF88A4VT6508M42FDRZK38P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(324,'01KTF88A4XSF9TD66PTKPP2GEQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(325,'01KTF88A4ZK35NHC1A75WGRHCA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(326,'01KTF88A5023V07WRRW12M5MJ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(327,'01KTF88A53MAKTVACT9ZY9GAEA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(328,'01KTF88A54NR6CDEBNTSPH33C0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(329,'01KTF88A56C1DM9JEZWVDSTFNZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(330,'01KTF88A57ZV7S9G7JJ6WB67HJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(331,'01KTF88A580HCQ2MS6RMKGF5Z4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(332,'01KTF88A5BF58YFHTAKMXE67KH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(333,'01KTF88A5DMBTC8N27KYRAGW40',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(334,'01KTF88A5FR87MWJK3NQWF6372',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(335,'01KTF88A5GVVPFE6E6ZPTQEQAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(336,'01KTF88A5JXMWR9758GXG06ZE0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(337,'01KTF88A5KTXNEVHQYHPCJ789X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(338,'01KTF88A5PR9XB79QTQ8S581WM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(339,'01KTF88A5QZDKZSCC76C37WBPA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(340,'01KTF88A5RDBPSYQ7PMMN6TPAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(341,'01KTF88A5TZWHDYP1284DES5BK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(342,'01KTF88A5VXBQEW8WSV0FMW2BP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(343,'01KTF88A5X8WG56WFMQP5VCCQG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(344,'01KTF88A60VXYAAJEZ3R3QA6HZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(345,'01KTF88A613XJS23KSXM6MGCRF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(346,'01KTF88A6263QFKPSJ2ZAQKYVY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(347,'01KTF88A64P2HWJARW2KGQ1JC2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(348,'01KTF88A654Y9R03YXFFVTVPYW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(349,'01KTF88A664TSXNQKDNTMFEJWM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(350,'01KTF88A6YTCVTMNX00CJRPNF8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(351,'01KTF88A6ZDMGRP1XF6F56WZT1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(352,'01KTF88A70YC9GHPWHDP1FKJQ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(353,'01KTF88A71C4PKV9CREGYXN5S1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(354,'01KTF88A7181WVWMZ5WVXQWR5R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(355,'01KTF88A7134XRWAPHK6V728XA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(356,'01KTF88A72XPP814FBYKAFBVNH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(357,'01KTF88A7199RBTZWT6EWJCGSS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(358,'01KTF88A71NPK86PSS4JQE31RD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(359,'01KTF88A741Y4ZY2XK80968TW9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(360,'01KTF88A74HS270Y309WGYFHA1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(361,'01KTF88A74R571VJWEDGDW1DEF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(362,'01KTF88A75P7PA0E44AKMKHGR6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(363,'01KTF88A75BM8EFG00TBK485SQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(364,'01KTF88A76861F8EAF8G4PZ9RR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(365,'01KTF88A7776TJV72V6HAJVHDY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(366,'01KTF88A78Y8E4HRD7PEH2W07Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(367,'01KTF88A7B3VMB26JY4Z1AE293',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(368,'01KTF88A7CSF9MS19PSN0AFA0G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(369,'01KTF88A7DSRH52DPR7MKVC08P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(370,'01KTF88A7E4P5766M03MSJHFEB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(371,'01KTF88A7G21ANERHQ4CDE29HM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(372,'01KTF88A7H1Q2K5JN7H1YHZXP1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(373,'01KTF88A7JBZECQXPHTS78B3NK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(374,'01KTF88A7M1KYW34N1Y6JAVKK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(375,'01KTF88A7NRYNXJ1FGK94919AN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(376,'01KTF88A7QVJ89AJQE7VQ2DFX3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(377,'01KTF88A7RADTKY59W02FWEKGB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(378,'01KTF88A7TJA92Y6HYAH2MWNJQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(379,'01KTF88A7VA5W0KTSGWS4Z9J5A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(380,'01KTF88A7X1RSS2STBYC6E148N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(381,'01KTF88A7YGBZH6GVP5H0B353Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(382,'01KTF88A80QHYXMKQDAGR143WN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(383,'01KTF88A81D3Y9E5WP2SZDMRS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(384,'01KTF88A83WRS10JPG9D26MPQG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(385,'01KTF88A85ZTYJKT45RQVKE6DW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(386,'01KTF88A867DZ89PE8619QM6VA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(387,'01KTF88A88802W44SQZQTT4SBT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(388,'01KTF88A89039F6YN8XEA9TE1P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(389,'01KTF88A8ASWPM1C9SZMXG3QXM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(390,'01KTF88A8CRN27ZZ1YXSAWAWSM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(391,'01KTF88A8DKZVB3C1PKRBQDB8B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(392,'01KTF88A8EP24AF9XZM7KJS7KK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(393,'01KTF88A8F6K60V42JQFHJHHVX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(394,'01KTF88A8H0M5ZWDKN811GRDE8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(395,'01KTF88A8JBDC5NATTMKTKJAT7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(396,'01KTF88A8MEBT492AGEBJ75GJJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(397,'01KTF88A8PSEG7A92QBJJ2Q9EX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(398,'01KTF88A8Q9TJQQS3Y0Q112C9F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(399,'01KTF88A8S5X8APWGRK36SZ3NG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(400,'01KTF88A8T2FTE1KWVNJ849JY1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(401,'01KTF88A8VA1QWX6XAD8H9GC75',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(402,'01KTF88A8XXRZJY5BF0CBXZJ06',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(403,'01KTF88A8ZKF91QN0EMW91XRDZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(404,'01KTF88A908AFSWT2BGKSWKP8S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(405,'01KTF88A91ZQHKMS4ERMD7SMD7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(406,'01KTF88A932269XXAPBVE9SH3K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(407,'01KTF88A943114NW9F82MPF81V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(408,'01KTF88A95ZPR76VY8V1ZJX8QZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(409,'01KTF88A97DWV3SYF09X2YVBGW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(410,'01KTF88A982SX7JPNGPZ654TSE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(411,'01KTF88A9AVNTT3BDCNTFD1RF5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(412,'01KTF88A9BVFSH7SHK2VT8JBJX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(413,'01KTF88A9CF5PK9B8K8N4THDYQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(414,'01KTF88A9E32Q9YTSR4V0202HG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(415,'01KTF88A9FN79H9EM65JJ2ENM9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(416,'01KTF88A9GGT2ZWX9XMGP65GB3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(417,'01KTF88A9JV1N2PE8D1WNVCMY4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(418,'01KTF88A9KZRE4XD7WX54WXE9E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(419,'01KTF88A9NZW0FNCNGSY1242KG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(420,'01KTF88A9P0KXAQFF0NET6YP09',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(421,'01KTF88A9R3DN6YER9ZGH9QFZS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(422,'01KTF88A9SS0P2NVVTH53FV4VM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(423,'01KTF88A9T9G75J6QSKVGK97NT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(424,'01KTF88A9W9M0FP0W10ANSC9KJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(425,'01KTF88A9YT1VAF2T3YS99SD6R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(426,'01KTF88A9Y5J0P7XWNFTF5VMFP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(427,'01KTF88AA0CZVZKWXBGJANC72P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(428,'01KTF88AA29BB6CHJDZJFG7RNH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(429,'01KTF88AA3XT5V0YM80JT7PN92',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(430,'01KTF88AA4EY944M6342WGP7A6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(431,'01KTF88AA5R24ZKXB73467A07H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(432,'01KTF88AA648HZGDAA2931Q9XR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(433,'01KTF88AA8NQQWK0BR35Z8X5S4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(434,'01KTF88AA9FTB7CJHBHSCW0JM5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(435,'01KTF88AAA4AD39FSCSDEH93N6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(436,'01KTF88AACCSP3E5NA04SZN0EW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(437,'01KTF88AAE3VYAMY1S5ZJBN6M8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(438,'01KTF88AAF4VWCMW8BVACN4EDG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:41','2026-06-06 19:58:41'),
(439,'01KTF88AAHGG1S51RVQ6N6D0A7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(440,'01KTF88AAHDT72W1NVSMXKR584',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(441,'01KTF88AAKAYAMFKYKT859FSWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(442,'01KTF88AAPR8SMH9JDE6EM0G28',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(443,'01KTF88AAQE2NQJWWT7WYKSN3Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(444,'01KTF88AASXG45J5B9RMJ2Y0T4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(445,'01KTF88AAT1W0SWYHMRV8628CY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(446,'01KTF88AAV0ASRVTCGCYDWT4PH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(447,'01KTF88AAXAZW7MQKDW858WXDV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(448,'01KTF88AAYK8QAHKK56BZY3WJF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(449,'01KTF88AAZKRNY2235813SG51Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(450,'01KTF88AB1AZ88H9B0M7R483SB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(451,'01KTF88AB2NQXH86E67J1GYC86',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(452,'01KTF88AB5EQFBV8HGRAX30440',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(453,'01KTF88AB5463A9AMYJK0WWWG4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(454,'01KTF88AB6KEJVHCYHNTAAAC56',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(455,'01KTF88AB9CGNMDRKX8GBDKZ9P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(456,'01KTF88ABA6BN8D0YWEE55W4ZT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(457,'01KTF88ABBRFPVZNNZ47NDE9DY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(458,'01KTF88ABDH9ER962GBYY26QR5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(459,'01KTF88ABETQT5DMYGY2SBRBBC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(460,'01KTF88ABFWWNAXT17MQZ87VYJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(461,'01KTF88ABH04CT9MT5CJJ21WQ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(462,'01KTF88ABM862C5NYB2WYCKCZ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(463,'01KTF88ABM56KRJZP6ZA97Y0Z0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(464,'01KTF88ABQMY9WGTE17V8NQS0J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(465,'01KTF88ABR07T58NF1GD10MXRV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(466,'01KTF88ABTY6AGR9BPNJ8V1JYM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(467,'01KTF88ABVWD1WA3EV1PR7E63H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(468,'01KTF88ABXZ1Z676DDG6QA93NG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(469,'01KTF88ABZMTY4F0JR5F8M0XYP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(470,'01KTF88ABZTHF3RAVDNDFZBGKT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(471,'01KTF88AC1VNPV5N0572F98RYZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(472,'01KTF88AC24FHWK6KQTKV1446D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(473,'01KTF88AC4KBDP6BHKQ1YAW796',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(474,'01KTF88AC44HBAAVAFQNTSESH8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(475,'01KTF88AC6ZJ252HS87RX4Y3KB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(476,'01KTF88AC8F2EE8AH7XE8446ME',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(477,'01KTF88ACATX5X24D50SQC80ET',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(478,'01KTF88ACBMKQWNJDJN30DQS6E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(479,'01KTF88ACDDG5GTWX8J30HTHTY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(480,'01KTF88ACE2PB8EH41WF1K08K4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(481,'01KTF88ACGMN7BFRTNHWQ938Y8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(482,'01KTF88ACJHSS5KSQ6JA1DHTVK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(483,'01KTF88ACMQF8F5119GQ2HR07V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(484,'01KTF88ACN9RXZT7CW1919XTP6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(485,'01KTF88ACPEEJ0X0B2KGB961N9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(486,'01KTF88ACRYAEJ5BHTQ8QDS3CD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(487,'01KTF88ACT46RKSJZ711FPWKN5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(488,'01KTF88ACVSBD592171MC43SMT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(489,'01KTF88ACWRMTGKRJE41326KBA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(490,'01KTF88ACY7HCGSHQXXTR4X69E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(491,'01KTF88AD0H2VXY9H12T633700',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(492,'01KTF88AD2S7VB834KZP54XNE9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(493,'01KTF88AD4E8WKYPGXWT17A93V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(494,'01KTF88AD6M2RAZYG9BBW6YKM9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(495,'01KTF88AD6NC8KVER5Y6Z26191',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(496,'01KTF88AD9ZMCRDQ9G2RT87WBX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(497,'01KTF88AD9WKVKYY3Y1Q73JWDD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(498,'01KTF88ADA33Y21H3JP8TEYBYM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(499,'01KTF88ADCEJSSSX9JDK606DCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(500,'01KTF88ADER40BDY6BFW8HJT59',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(501,'01KTF88ADF41N07X6C19S8J330',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(502,'01KTF88ADJC3XTXMDV4FFTNNXE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(503,'01KTF88ADK13CRM8Z9G2FANJVR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(504,'01KTF88ADMZTNWS530P2NVD10X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(505,'01KTF88ADNAS88C82AGJF66SFW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(506,'01KTF88ADRSYX6092HNEFFGW17',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(507,'01KTF88ADSQ6RVE2QHTNYMRJZ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(508,'01KTF88ADT6GC8DQVY14E8DBB7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(509,'01KTF88ADWHHWWY3G5TMY8VN6W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(510,'01KTF88ADZGZXNBT5FWXQE7VED',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(511,'01KTF88ADZ092EB7AFZ73MGGPW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(512,'01KTF88AE2705QF16A6Z537ES0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(513,'01KTF88AE4YWGYR7A2HGPQQ9PM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(514,'01KTF88AE54E22KKWKX81FAM4F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(515,'01KTF88AE78P14CSK82K5HPKYC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(516,'01KTF88AE9F6RBDCHYH6D9WTVC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(517,'01KTF88AEA16KZVAYRJWEFJHPW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(518,'01KTF88AECY2WY8K7KFHDHNA5F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(519,'01KTF88AEDWKHFVB3FRB9JBCYG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(520,'01KTF88AEF05BEZAEXPJKBA8JD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(521,'01KTF88AEG5KFM6Q8H0GH87H73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(522,'01KTF88AEJYR85WDDVAFBBEZFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(523,'01KTF88AEPJSZN537KYA664V3Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(524,'01KTF88AENWE2XWZ7FCAC40550',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(525,'01KTF88AEQ152DYDH2PAT2K0CX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(526,'01KTF88AEP42JY7X6HHSNSJGG1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(527,'01KTF88AESJGR73RHN25Z7XHR7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(528,'01KTF88AEVCFKDZPGXWH314AER',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(529,'01KTF88AEYVQN1QA0PXPECN18Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(530,'01KTF88AEYXNR73QZCFBC862EK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(531,'01KTF88AEYSCTE5JXJXA04VZBZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(532,'01KTF88AF1Z3ZBG86CZA3NZBJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(533,'01KTF88AF26ZM8KWHSDV2NE9VP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(534,'01KTF88AF47HE03X5QFNMFKAHE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(535,'01KTF88AF5X9TQ4NDB6V2Y2NFN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(536,'01KTF88AF67BM4XEMYTHC5T7JA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(537,'01KTF88AF81QWENVYAZPZF8V9C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(538,'01KTF88AFAYXRG0VZS13ZY1SXJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(539,'01KTF88AFBH78JZDWXE449FD6C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(540,'01KTF88AFCJ1JK9J28J5392G1B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(541,'01KTF88AFDX68M56F9ZWC2BGHG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(542,'01KTF88AFE7HKRPNCZ4RWME9JC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(543,'01KTF88AFGXA3FH926P5DC2GB9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(544,'01KTF88AFJMQWEA504W9RNZE00',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(545,'01KTF88AFK2Z85XT5N8C28NR4K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(546,'01KTF88AFMQRKS92269XK41929',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(547,'01KTF88AFPD24TPYWH4A16JAGT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(548,'01KTF88AFR8494NFCNXH7BGZCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(549,'01KTF88AFSDYDRDF511T9FT5BA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(550,'01KTF88AFT704B439PDGG6F9WS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(551,'01KTF88AFX7VM814PA2EMK2RFV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(552,'01KTF88AFYVGRT5QJSPRTGNK2B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(553,'01KTF88AFZVH1NQNNB42PYS7W0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(554,'01KTF88AG0974NS420F3C72YFM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(555,'01KTF88AG18TC3DA8NW5ACSNYE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(556,'01KTF88AG26GAC3E3YHFW1SKTN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(557,'01KTF88AG4DGFRWG3BA1VGTSHN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(558,'01KTF88AG5TFZJC67Z9X4V7VZJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(559,'01KTF88AG7PRP13Y7999HDPPHV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(560,'01KTF88AG8NQ1PZJEW6VKS9NVS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(561,'01KTF88AG9QD9P6XHJ15263PXV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(562,'01KTF88AGAD9RFZZM82JNMB6T3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(563,'01KTF88AGCGAMPHNCX7MHYE1S2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(564,'01KTF88AGDY7F42KNDYXZ1FXB0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(565,'01KTF88AGF9GF3NMMTJ164BCKY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(566,'01KTF88AGGVDSJNHPZD4ZB86H8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(567,'01KTF88AGHV14CG3SK3DD5N6Y7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(568,'01KTF88AGKMYFX8218KV77KG30',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(569,'01KTF88AGK89FWTF4D7N3C7MM9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(570,'01KTF88AGNHDS9R98CTHD80PZC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(571,'01KTF88AGP22XQGG5WBX3PG766',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(572,'01KTF88AGQ11AKVFW6FBT286N4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(573,'01KTF88AGR8Q2FRE9EMV67W3W2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(574,'01KTF88AGTNRCWNJQFQG206S9B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(575,'01KTF88AGVSD2ZWFQJQHH89TMB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(576,'01KTF88AGWA4YBETDH91KFPB3D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(577,'01KTF88AGY5GJQAHPWXVHVQ9ZK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(578,'01KTF88AH0R093R6JJZBXC11CY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(579,'01KTF88AH1TG8TW2D6DS8452AB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(580,'01KTF88AH2D0YSZXGTV0MPVD2Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(581,'01KTF88AH3G5746DMV5V4VXDWJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(582,'01KTF88AH5X6A2363BTKKQ42SN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(583,'01KTF88AH6G8WE9M1NVT08GQM7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(584,'01KTF88AH80ZXSAHHTNDA3GRPJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(585,'01KTF88AHAP2QSNA0XWS6AQ072',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(586,'01KTF88AH9HJM86CR0BD7SANQ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(587,'01KTF88AHBKBG88DHJSZB9Q8E6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(588,'01KTF88AHD0CHFV1T4CMSB8S1T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(589,'01KTF88AHE2BJMQCT7CM1Z5HPC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(590,'01KTF88AHFNV6TP8HEHW9PRGS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(591,'01KTF88AHHJN0JR78QS2B1BYKD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(592,'01KTF88AHJCT2BTCAM5P9NECCJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(593,'01KTF88AHMWSP2H2HZJTN6DYPX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(594,'01KTF88AHM3XW83Q3KXFFHRD5A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(595,'01KTF88AHRPFG3G30MCVECNBWV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(596,'01KTF88AHRYFYQW32QXS8Q8ZDA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(597,'01KTF88AHTP3CJQCERV3XEENH2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(598,'01KTF88AHVKF1H21BM6030AW9S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(599,'01KTF88AHWHFKWCXKMJYW7X03X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(600,'01KTF88AHX4Y2A07XWQZYCTQWE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(601,'01KTF88AHZ3FA08043KXA81BZF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(602,'01KTF88AJ1DBMP5WPMRPBD6DVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(603,'01KTF88AJ1H7KW8DJGVRED0Z2C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(604,'01KTF88AJ37SZMX1B7E5CYYQ8X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(605,'01KTF88AJ5AD4CB50P5NKCXZRP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(606,'01KTF88AJ63HEBDBGK425DHGR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(607,'01KTF88AJ6ZK9J3GHCP8BPJ1MX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(608,'01KTF88AJ723K60BKESRB0XR1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(609,'01KTF88AJ970PKCS3JCZJ2X9WA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(610,'01KTF88AJA1B50WPQ0VSGW3X9R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(611,'01KTF88AJC7K066MDRXA3ZPBVK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(612,'01KTF88AJD94YYXW1BS6X580KA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(613,'01KTF88AJF9YNWFBTPR27DPE9E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(614,'01KTF88AJGE0TSFP6BKJPPESJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(615,'01KTF88AJHWF1B8DTWK425ZBW7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(616,'01KTF88AJK7C5VFRECV132VXVK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(617,'01KTF88AJNZ6R7TWC8NMEMQ7YJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(618,'01KTF88AJPWC795EP5BVPN6648',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(619,'01KTF88AJQ7JMDP6DNX1KX5D1N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(620,'01KTF88AJS071CNGJ4CAN4NSE8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(621,'01KTF88AJT1TAVWW3XS9AS5PFX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(622,'01KTF88AJW6TVR96Q6QAEPKKR1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(623,'01KTF88AJWF8HD8NEQJG59TKJ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(624,'01KTF88AJYH7AE88G0N1930MPV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(625,'01KTF88AJZ0G15R8VP6VXSNTKM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(626,'01KTF88AK0P45475A8K3VH3XF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(627,'01KTF88AK1BR03HFPDD88STQ6A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(628,'01KTF88AK32NFM5ZXAX2M2AYM8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(629,'01KTF88AK49NVAR85RKW1B4VAN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(630,'01KTF88AK5W1PC11KCZXG1TYXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(631,'01KTF88AK8NS4C18BBG9520K2H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(632,'01KTF88AK9381XDQSV8E5BX7T5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(633,'01KTF88AKB0ZC4APPZHBA0YDMZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(634,'01KTF88AKC4DZY89BDR9DXDK03',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(635,'01KTF88AKDYSCK4MDR1Z7Z7NNA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(636,'01KTF88AKFWBZ3HT0V119ZGHE3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(637,'01KTF88AKF9VM187K30FMM8KN0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(638,'01KTF88AKHHRP3RJQKV9NYYAKW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(639,'01KTF88AKK9YQEBXZNB7M9D204',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(640,'01KTF88AKMT098P9A370TNS1T2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(641,'01KTF88AKN826HGQCMRTN1ASF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(642,'01KTF88AKP6Q104WY1NY0EW97X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(643,'01KTF88AKRKS9HM3DB8H46Q5XD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(644,'01KTF88AKS8Z026ACE75R57G0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(645,'01KTF88AKT8EZ8NT1GTKVDCK8G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(646,'01KTF88AKW9Y1BYQQ7X38RWB8Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(647,'01KTF88AKX87A8W5TTXK29KWXB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(648,'01KTF88AKYES4VD6039QF2ZC50',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(649,'01KTF88AKZ33YE9V4G34A1XRDA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(650,'01KTF88AM1W28NCRTJCH6PXJX2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(651,'01KTF88AM2AYXX56KNM6W9JG79',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(652,'01KTF88AM4D8YPZ185R7SZZ64A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(653,'01KTF88AM5Q9TC22HK7VH155QS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(654,'01KTF88AM6HAZADSYY88RM3J8D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(655,'01KTF88AM8NCVK1YJ7BHW6Q49D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(656,'01KTF88AM9VB7797AV2BATBMRA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(657,'01KTF88AMA44TFX6TXTXHNJRN7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(658,'01KTF88AMC321G3ATBB4E90MV3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(659,'01KTF88AMDAZ2KNED6ZG1BJ6GW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(660,'01KTF88AMEZS3ZPTS0Y7PCYYE6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(661,'01KTF88AMFTZHY2ZPKVC5K5MPV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(662,'01KTF88AMGYZM7S8V9G2DVESBW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(663,'01KTF88AMHQRKJQRGVNWPXJHVN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(664,'01KTF88AMKA5YCNNPZRZ0JFFN0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(665,'01KTF88AMN92WWYQ7Q3BNW7W5E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(666,'01KTF88AMPH5ZNZ882TPFKQQDE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(667,'01KTF88AMR74KCTHCGZ1XGESKW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(668,'01KTF88AMS2XC9N76KEV8YXK6E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(669,'01KTF88AMTGMYFWD4G6QKT1FZ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(670,'01KTF88AMW4TEQZ1H8ZW1ZZN4A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(671,'01KTF88AMXCDSJBTZTS5CEB326',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(672,'01KTF88AMYXJ7VKGGFWG569A3V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(673,'01KTF88AMZ5JGGTQKWGSGS1CTZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(674,'01KTF88AN1M7WWRJJ56X3KEKQ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(675,'01KTF88AN2819G2JJ1HGJ0PG1X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(676,'01KTF88AN4RP57R5SMQJB655WB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(677,'01KTF88AN5ZBMZGGYXN4BJ67JT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(678,'01KTF88AN6X8HQK9A42EHEMRX4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(679,'01KTF88AN8YNS6GZD9HYN0KCYP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(680,'01KTF88AN9B2A227H3656YK02P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(681,'01KTF88ANAJMXNXJ0SE9EC4QE9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(682,'01KTF88ANB72EE6VHV59H6PA3J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(683,'01KTF88ANDHX2W5CPA7R75BTYS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(684,'01KTF88ANDD1FP3DQR1F8JCBKN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(685,'01KTF88ANFMP9GT565Q286VFDN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(686,'01KTF88ANHKVDV40TG8X9032J3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(687,'01KTF88ANJ6TJAHKR80575VFN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(688,'01KTF88ANKNK18HRJA0P32PXAG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(689,'01KTF88ANMNP8MW12M5MSYMYRW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(690,'01KTF88ANN027PN37FJ7QQ2XXG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(691,'01KTF88ANQJA45BH43EYV7881J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(692,'01KTF88ANSWF0Q169R1YDKNBHK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(693,'01KTF88ANTQBRYVRHMWFK7NWQ6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(694,'01KTF88ANV13NTVYZ7E6X0YCH0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(695,'01KTF88ANX60WH202CX29T5BM4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(696,'01KTF88ANYEFAAQS0VZHZ237GP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(697,'01KTF88AP0A7F0XB9TCQ90GXE5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(698,'01KTF88AP1486097TA012CCTQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(699,'01KTF88AP34B9CMVPHZVM78CD9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(700,'01KTF88AP4ACGWA86PA6TE2E42',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(701,'01KTF88AP64F84YAA47JWJHQ92',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(702,'01KTF88AP67J633V4075ZM93D5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(703,'01KTF88AP9AXM759K9VJDATRJQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(704,'01KTF88APA36XB33MDWN6B1ETW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(705,'01KTF88APBD2ERYAM2ADYKT12T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(706,'01KTF88APEMP84EGPJX8RMG2FF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(707,'01KTF88APFBC3GHCW1B4ZBM7TG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(708,'01KTF88APG97WYRDPHBZEFJCKR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(709,'01KTF88APJZ00JZHJSZWKS40CG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(710,'01KTF88APKYCR38K6SVABR6FDC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(711,'01KTF88APM0GXCNTKYDR3PJZFE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(712,'01KTF88APP3AKQS091WY3KJSR6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(713,'01KTF88APSBD22E58WFXQVKQ9R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(714,'01KTF88APTX619Y95QR46PZXJN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(715,'01KTF88APTDST9EYS0KKSHREYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(716,'01KTF88APXMT222S44G5HN8HM4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(717,'01KTF88APYY6D5415Z7N0B5S1C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(718,'01KTF88AQ0T8B8AYWTYNY2GTR3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(719,'01KTF88AQ0BC3N563X34JMAR54',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(720,'01KTF88AQ2ZFR58T8GTVREWRZS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(721,'01KTF88AQ37NFAMVKGWDT2NABF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(722,'01KTF88AQ45C0P2PA64JZNBP2P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(723,'01KTF88AQ6G6YJ79JHAGEP27EG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(724,'01KTF88AQ70HS74QPC91F7CA29',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(725,'01KTF88AQ9T5MTC4EJ57G228MC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(726,'01KTF88AQAXK85XDFT8HP8SPCJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(727,'01KTF88AQBGQCXHYEAE1GGEWTR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(728,'01KTF88AQDRQB4ZTQCWYP6BHKD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(729,'01KTF88AQERAX5X54E02DWMH0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(730,'01KTF88AQF467TFBY28N2GJVGM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(731,'01KTF88AQJ35R9R60A5A286WR5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(732,'01KTF88AQKN8EFCWR51JC4D3E0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(733,'01KTF88AQM5PP5EB70RCRRA83A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(734,'01KTF88AQNYK0NKKFXTSMYBKCW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(735,'01KTF88AQPBCK14YBB6G1RWQDG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(736,'01KTF88AQST5ZPK51FY8SAAPE7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(737,'01KTF88AQS8KXTVAGQPWH0A7RZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(738,'01KTF88AQWMQV8XT17VVPRQ1E4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(739,'01KTF88AQX2W7RB3N1WKWPBCVT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(740,'01KTF88AQYTDVVPQKSXEQKDQJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(741,'01KTF88AQZ2BZFQ86V7ESB3FDM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(742,'01KTF88AR2YDSX204KEQW4QRZT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(743,'01KTF88AR3VC4T059SY735NHMN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(744,'01KTF88AR4Q7RZ0F7W5NPCXW65',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(745,'01KTF88AR5ESPFEXM5ABRA24MW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(746,'01KTF88AR63M2KA3K38XJV4B74',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(747,'01KTF88AR7TZAPM9MZD2WN595X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(748,'01KTF88AR9R4XFAHKC0VNBBY6P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(749,'01KTF88ARAFQVYJ590P3Q6J5DS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(750,'01KTF88ARBVWDVTP5W3F8VVHHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(751,'01KTF88ARDG3AYNC9HMM2CWZGJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(752,'01KTF88AREA4C0NQVB4TZEH4X4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(753,'01KTF88ARGF4AR3RC462A8GRP2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(754,'01KTF88ARJCM4GG3BQ85HBF1RT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(755,'01KTF88ARH3B4E8XYA538NKTF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(756,'01KTF88ARKBX6YAX9HK5YBT2G2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(757,'01KTF88ARNZFC48DFHEW04B84R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(758,'01KTF88ARPZXB6ZB6NDFZRX5EQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(759,'01KTF88ARSXH08T03J4PXJBSCW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(760,'01KTF88ARTK6YKEF3604SC14ER',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(761,'01KTF88ART00N3Z4X6M1X3YY35',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(762,'01KTF88ARX2S9XERTM8BSFPQT7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(763,'01KTF88ARZ5EA6TRFKVADDQHK3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(764,'01KTF88AS0T9PEPRYCCFM1H8DS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(765,'01KTF88AS2YEZB8SHHDEFSTMMM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(766,'01KTF88AS3WJZA00JKEJWQ0BK1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(767,'01KTF88AS4NNERG4JCZDNWEMXS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(768,'01KTF88AS53XJ27QCFW7CFGB3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(769,'01KTF88AS837GJNVRKY6X261KP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(770,'01KTF88AS7ZEY2EW5G01M382Q1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(771,'01KTF88AS9GDGJCC0AZJEZ3CCB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(772,'01KTF88ASBSPVMQVPJVS522E4D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(773,'01KTF88ASC68BY1J3HADCZ2QAW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(774,'01KTF88ASDKT6CD0TD4VXTH4A0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(775,'01KTF88ASE5DQFX7P6Y7X0CQ2G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(776,'01KTF88ASGNDZGQV5H7DJ37FXE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(777,'01KTF88ASHFY7KJQ9RD60TMRA2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(778,'01KTF88ASKVGGJPE7TMYNQ63WS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(779,'01KTF88ASMP3XP6NJD6FCRKANT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(780,'01KTF88ASN239KMMVJFEZ5D064',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(781,'01KTF88ASPB0BBDMS269EZW001',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(782,'01KTF88ASRNNV8HQE7QKCH37T7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(783,'01KTF88AST7P4YGMANZFQHA7A9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(784,'01KTF88ASVPJXB0VFXHPQ9G7PM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(785,'01KTF88ASXDNVCTFBV6FE3VEHW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(786,'01KTF88ASY5KVERXY0RASJZYF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(787,'01KTF88ASZWB8AE5RS3VE5P0FA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(788,'01KTF88AT1H91V72G3AQTMJZXH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(789,'01KTF88AT2P6R7ERD9RYHYT1PW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(790,'01KTF88AT39CADWXMKR23HN6Z4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(791,'01KTF88AT5NZPE8DS1QRWQ58QD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(792,'01KTF88AT698YMSF4W69KR5CQ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(793,'01KTF88AT76KH92NDM03DYTJYJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(794,'01KTF88AT9SPQS3BG648375XB0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(795,'01KTF88ATAGDX85YZCKH0H6M0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(796,'01KTF88ATCV58S34PD83VST07K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(797,'01KTF88ATDJGX7XJ6Z141Y8KY0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(798,'01KTF88ATF7EZCNGYAE1AKAGS5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(799,'01KTF88ATFR9NVB1PQ1YXBPARE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(800,'01KTF88ATHZVE86S8ERBCTKS6S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(801,'01KTF88ATJJ0CTTK3R3HP68XAJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(802,'01KTF88ATM17C2HJA57XDAKZ6X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(803,'01KTF88ATNV7E5EEQTKST8ZPC2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(804,'01KTF88ATPMQ9HHE2WZQ0V2G9J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(805,'01KTF88ATQNFP9A92T2038CJD4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(806,'01KTF88ATR2E19YHC5V6VSRYR9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(807,'01KTF88ATVPTM3WDZ1KBWXRXDW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(808,'01KTF88ATWPNCJ1JEHJ9NTN9MZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(809,'01KTF88ATXKTMFX8DDBKZVT24C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(810,'01KTF88ATYQ99SVFM30VCW50J5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(811,'01KTF88ATZHQJ4M5J8B7WHFH37',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(812,'01KTF88AV26K61WRP3A2JWGTZS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(813,'01KTF88AV3XGQBAPHNCD7AR6QX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(814,'01KTF88AV5T7H97P55Y0P7D95P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(815,'01KTF88AV6WBA36QNPHZSK9NN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(816,'01KTF88AV82J1PE26GSGYVZJTM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(817,'01KTF88AV9BN28HD1PVYMYHHE4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(818,'01KTF88AVAG3KRPGC6QHYG4GXG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(819,'01KTF88AVCRE5YXFFH5WV91M73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(820,'01KTF88AVE5HB6QR5C201Y5NE7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(821,'01KTF88AVEYMBGQR0D8WYGCGCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(822,'01KTF88AVF76NBSFZ79640JJBD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(823,'01KTF88AVHHAPADBFQM7Q1C0MH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(824,'01KTF88AVMCH4Y0VNWBX6XM10B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(825,'01KTF88AVJ2WCKV0PV7QZ64RXM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(826,'01KTF88AVPKTC1XCNC40T7F9SG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(827,'01KTF88AVQX1GC4DK50AEP4HPD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(828,'01KTF88AVRTEY0JV73FG16WR5W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(829,'01KTF88AVT4PNPBD1EKCX06K1K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(830,'01KTF88AVVZF6JG3HWPA9ZB9VC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(831,'01KTF88AVWTYMA5GF3NQA8NWDH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(832,'01KTF88AVX3NR8A63T133QGZ3F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(833,'01KTF88AVY0YT3AF3C69FYYZ4B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(834,'01KTF88AW0NMSAC041R6S903NB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(835,'01KTF88AW1D8VX8NJCDN7JQK9B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(836,'01KTF88AW257FZ10W4J05W7GMA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(837,'01KTF88AW5V7E6G8XE45ZHP65C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(838,'01KTF88AW4J5M46V0XCQEYRV7G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(839,'01KTF88AW7QPDJC4AJ1JZDTS4S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(840,'01KTF88AW86F4J5V9SYPTSMCYC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(841,'01KTF88AWAVHVNDDHFWJ3BPJQF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(842,'01KTF88AWBFHCS3FADSCSCQ5PA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(843,'01KTF88AWCK7429FGJW29PSMAS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(844,'01KTF88AWDGDH8VC7MEMZA65N3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(845,'01KTF88AWFWGANJMKBKZGV7VR6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(846,'01KTF88AWGHZ9V8R0JD4ME6A7K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(847,'01KTF88AWJE0K3M7HSS6ZWSVG2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(848,'01KTF88AWK3S6BG1STFEG56462',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(849,'01KTF88AWMYZ8GMHVGEEEHJ8EQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(850,'01KTF88AWPATXDS697VZ25XA6R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(851,'01KTF88AWRG30K2XVT7GSRPDYE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(852,'01KTF88AWSYNKXAFPSV5JDF6KT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(853,'01KTF88AWTWWKRKMNP3QKXS99T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(854,'01KTF88AWWCQHQZ7T1KWWH3YJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(855,'01KTF88AWX0PNPGNTHEHTXYQR0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(856,'01KTF88AWYHV9DXYZSTDMNY8H3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(857,'01KTF88AX09RFDB5YMN8YGP99Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(858,'01KTF88AX0B49TR9DGM3PZ62K3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(859,'01KTF88AX3QNZB3JENGKBPDW7N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(860,'01KTF88AX4W8GZGAZ13FCE56HS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(861,'01KTF88AX5JQWWKP1958ENN68A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(862,'01KTF88AX6JDXFYA9W63K1EQCQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(863,'01KTF88AX8BDV8TRT9Y0H5ZB7B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(864,'01KTF88AX9KZKAT994VDDAQYHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(865,'01KTF88AXAXT2ARGPP11EY90PP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(866,'01KTF88AXCDPCVWST4Y53TVN5N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(867,'01KTF88AXC0B14RH3F4JWYB484',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(868,'01KTF88AXE5ZYCEDFT7S6W9AT1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(869,'01KTF88AXG7WFWBM2A95M02K8E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(870,'01KTF88AXHQECAC8Q34NAQYGD5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(871,'01KTF88AXJQ0GZNQ2NED94MZAD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(872,'01KTF88AXK6ZTDK3JNC4CJHVWK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(873,'01KTF88AXNZV4DEZ105J4YAZDT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(874,'01KTF88AXPMN1NTAZDYGRXTQVA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(875,'01KTF88AXQM3MJKTCNSTGH5PZ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(876,'01KTF88AXS9E1XJ2PB468YKRH6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(877,'01KTF88AXTGXFSJ817665744FS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(878,'01KTF88AXWVGFPPMWG0BWXT0AQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(879,'01KTF88AXXDFRM4KBDQJ2HDZ9X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(880,'01KTF88AXZM3CN2FRPGQQ1SFGG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(881,'01KTF88AY0NBXP72XYFBSPCXHJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(882,'01KTF88AY1SPNK0XPEEN07GQ7N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(883,'01KTF88AY3R9H890HEQ92KZ3AN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(884,'01KTF88AY51TS96PDPX9925JF1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(885,'01KTF88AY746A3PNWQBFBNCTET',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(886,'01KTF88AY7P9WDY1D2GGN2Z3E6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(887,'01KTF88AY9JC4P1K5M6B5VG2XB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(888,'01KTF88AYA3N0B68T53C8T6EHB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(889,'01KTF88AYBCMK370SH2QJQS027',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(890,'01KTF88AYDX95C2B1B6K2T02H7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(891,'01KTF88AYFCWXFYQWC5EFN1BEW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(892,'01KTF88AYGS6K2AV8B587FC6JW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(893,'01KTF88AYHXY4C0ASEQTW1RAKK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(894,'01KTF88AYKJTDXKRAN3HXZVB1E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(895,'01KTF88AYKRYJYV0TD2PPWH6Z2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(896,'01KTF88AYNZYC606VAQRP72WQ6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(897,'01KTF88AYQJ7G0WJDK167F0S4A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(898,'01KTF88AYSZQ57ZCC8W2PJD6GC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(899,'01KTF88AYTEPFQ0BHJS7SNM2GY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(900,'01KTF88AYWKSFMCKTF37G21NCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(901,'01KTF88AYX9NBNBX6CPG7083AS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(902,'01KTF88AZ00J6C0SS5PTCRZ64N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(903,'01KTF88AZ138CRSZCQVSCZ7VDS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(904,'01KTF88AZ366ZE153TPXB3NA4T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(905,'01KTF88AZ4T6FENA5S4BRRCHEK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(906,'01KTF88AZ5YVKP1H992SZ3DSZX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(907,'01KTF88AZ63R0WKV2YZMV7R7V1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(908,'01KTF88AZ97S1CBVFH4DDWS096',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(909,'01KTF88AZ9857V6SS2QD2TQBVP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(910,'01KTF88AZA1HRPX7Q5FJPMGWJ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(911,'01KTF88AZDVWYK87KY9JJAZ4K3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(912,'01KTF88AZDQYF1V0PSB8AC5WSM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(913,'01KTF88AZFEA4FB7Z02BFT9E3D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(914,'01KTF88AZGJ38AYYK5ZZAFHZ9F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(915,'01KTF88AZHW7TF2FVJTV59F8GJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(916,'01KTF88AZKPBA0AY7RXG2HC88P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(917,'01KTF88AZMTDK9APAZVAPN748R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(918,'01KTF88AZNBBCC2T4MWCMVRY6G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(919,'01KTF88AZPK8KEQ58BRQE1Y1ZJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(920,'01KTF88AZQ6M9ZYHSVWGWTA5ZH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(921,'01KTF88AZS3E9QY8AS3X0NY94V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(922,'01KTF88AZVKRJ6FB2EVWJDQJ79',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(923,'01KTF88AZWDBV9CQ8QXCYWV2R8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(924,'01KTF88AZYK0JRKT22B9X5GNM1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(925,'01KTF88AZZ2SR6GN35BVDE7RW4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(926,'01KTF88B00FKZTH8BB3T8T41MC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(927,'01KTF88B0224EGV10T01XEJ4K5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(928,'01KTF88B03X699V2JBWYJFYK82',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(929,'01KTF88B04XYGGKRQNC2JATPA8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(930,'01KTF88B05V6JFGGXD2MSEZ2WN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(931,'01KTF88B07R9GZJHC5Q9XATKQY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(932,'01KTF88B09M99BSTAZA0WCKK7X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(933,'01KTF88B0ACWJQKXKRRW7V5X4F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(934,'01KTF88B0CBJHSXF5ZDAZ6CVFE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(935,'01KTF88B0EBYW5VXRQ284FWM6N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(936,'01KTF88B0FF9JNX5FBHF7J9AWF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(937,'01KTF88B0G5WHE914X6TV5AKM5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(938,'01KTF88B0J4NY7FWS82YFW8QBF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(939,'01KTF88B0MBAB5VZFPPTRGH6C2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(940,'01KTF88B0NQZWQETMAV693ZWF7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(941,'01KTF88B0QAVJNANG3XVYRQNAN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(942,'01KTF88B0PETQEQW1NSMN3C61F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(943,'01KTF88B0R6HVRFE31WJN3RX75',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(944,'01KTF88B0TQF5028HAJR2WWEQZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(945,'01KTF88B0WVJGEM3N1E8DMY2XN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(946,'01KTF88B0XGHVX8J1PG95XYZ8C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(947,'01KTF88B0ZHPBDX02X0G4M2C1A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(948,'01KTF88B10X52C36P98PJC7CN7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(949,'01KTF88B11BE06K893BBG1VJQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(950,'01KTF88B123VGJP0B33H6W2NMC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(951,'01KTF88B145KK0T05SA2S4BBPH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(952,'01KTF88B1569WCGK53893BX56W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(953,'01KTF88B1683TV6MPWJDW752DJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(954,'01KTF88B18SJXZ6J2XA0N4H9PF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(955,'01KTF88B19BP1WA5BW7R1MBV55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(956,'01KTF88B1BE4AHM2XPE5QXE97H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(957,'01KTF88B1CVE0MY9BM6RE1WFCV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(958,'01KTF88B1CVJ6ZJ7MYBD5E5T4F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(959,'01KTF88B1F7SP0S42K2NPJRVD0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(960,'01KTF88B1G4MX3BB5ZA63XYT23',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(961,'01KTF88B1H7YM40TQDM1FF46DP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(962,'01KTF88B1KEQETJQ9EYFVD4M1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(963,'01KTF88B1MMCH510EWFEZSXYY4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(964,'01KTF88B1PR4Z82AJB8XDEE3W0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(965,'01KTF88B1Q3W7FD3ZCM7X9BN34',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(966,'01KTF88B1R1SFJ2CSF78E7K7JA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(967,'01KTF88B1TK334QRV19VB2T1DX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(968,'01KTF88B1VDYRZY2TT4NDV9SJT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(969,'01KTF88B1W77MFWP823JGGZFAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(970,'01KTF88B1Y0W1802B2H1EASJ1S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(971,'01KTF88B1ZE3FR60Y3YR8SJ4DE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(972,'01KTF88B21W9YKZ253R0QFHAHA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(973,'01KTF88B2257D35NEY371YQBSQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(974,'01KTF88B24ZZT84ZE00P1SW8AH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(975,'01KTF88B24QG04BV32RAFXS5AC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(976,'01KTF88B27Z4FS7RPCKYY483MV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(977,'01KTF88B27M961Z78YPA4DC7SK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(978,'01KTF88B28SMD398KFV3MT53KJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(979,'01KTF88B2AYHHN27G6B634CK4E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(980,'01KTF88B2C3S56YFBAD9S0KEC0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(981,'01KTF88B2DNMJP10KXZ4X6FJ22',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(982,'01KTF88B2EA4A61PQQPBMMCHDJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(983,'01KTF88B2GWD35Z5ZP8QPVCH3F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(984,'01KTF88B2H3SV1D4NHQ84SMF9X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(985,'01KTF88B2KHW8AC7VR48BB4WS5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(986,'01KTF88B2N0QN756F2PQ9VKVGD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(987,'01KTF88B2P747CMS3N79P4BP2R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(988,'01KTF88B2RPH2Z0BC546Q1YGYJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(989,'01KTF88B2S3AMVWDDNG4FB2RKZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(990,'01KTF88B2TRNF0ZXFWBTMFAN3J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(991,'01KTF88B2WN3R1A5KSJE14TRN4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(992,'01KTF88B2XSX2EWDR73ZRVTB52',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(993,'01KTF88B2YKKNEFNXMWJ6E1W7S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(994,'01KTF88B30R0EZAWJ27WW2BS76',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(995,'01KTF88B31GWJ11JM4NEASXYTT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(996,'01KTF88B32TE4SY856N0W1S21F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(997,'01KTF88B34MWHKYPYNZ1Y2X1NB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(998,'01KTF88B35N9EE8S1RZG4X7XGS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(999,'01KTF88B36RHTX0K089QPZVG65',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1000,'01KTF88B37KMXVZ012QK7JNNY1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1001,'01KTF88B39MJ8HEM0QY67BS5HZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1002,'01KTF88B3A8DV2Z30ZRKFTZ98F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1003,'01KTF88B3BKX6H3DYYSZR3BTHG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1004,'01KTF88B3EEBKCYZ8S78H6QY91',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1005,'01KTF88B3EDA5GV0ECY24GY2FK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1006,'01KTF88B3GKQR2RYH3KWYMZE9D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1007,'01KTF88B3JAVV08D3MKGB2KTSN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1008,'01KTF88B3KY7JN2Y2GSQHSNCMH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1009,'01KTF88B3M4NXY9JVZ17RTTCWP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1010,'01KTF88B3MQ1RFZ2VRESHJ35TK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1011,'01KTF88B3P6NJ42RZEYFEDZBPK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1012,'01KTF88B3P77MJXPC4HJK7Q1S0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1013,'01KTF88B3RTQ97GNZ59HDYC5WP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1014,'01KTF88B3T29HJX1PREQP1QSXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1015,'01KTF88B3V6VBNCBQR0Y1CVA4C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1016,'01KTF88B3WT2MES0XTNR22VF8D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1017,'01KTF88B3YWBRKF3VZ2G2D5GZA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1018,'01KTF88B3ZSRKVHWVH3TZ35E1V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1019,'01KTF88B40YBV44FGA7WK23EMH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1020,'01KTF88B4225Q5RTA4JNPF4P81',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1021,'01KTF88B436VTDFWS2H8EDQXH9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1022,'01KTF88B440SMSH7E7RKD06MV7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1023,'01KTF88B45XBMZ587KESTWZYJJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1024,'01KTF88B460RPF5QB43KQFA2K7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1025,'01KTF88B48RCEG8YKX779N6NZ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1026,'01KTF88B49NDRCATB659DQHAN6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1027,'01KTF88B4BZ58S71YKDQ0TB13F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1028,'01KTF88B4CWK5Q7FJ0J1CBX9BD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1029,'01KTF88B4DQYGMS0RZJ9TTQGSM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1030,'01KTF88B4E1BBBRCRWVW6VWZJK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1031,'01KTF88B4GA0DG46RQ06KA2NXV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1032,'01KTF88B4JPKSNKP17SDDAEKKR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1033,'01KTF88B4KVNTYKE076D9FEEY0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1034,'01KTF88B4MF1E710AVVVJ1KSA3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1035,'01KTF88B4PMM64ZY3F1HGBQGB0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1036,'01KTF88B4Q2MGQBW71RE5RZ8CG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1037,'01KTF88B4S0C2XYM0A6H53YAZ9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1038,'01KTF88B4T59JFJD6SKZM19E1Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1039,'01KTF88B4VNPVH0BJNCC46JDWB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1040,'01KTF88B4X9D16Q51HSZ27SCCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1041,'01KTF88B4YV60ERN1GR3GNJM2Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1042,'01KTF88B4ZG613ZPFZ60GEDJP2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1043,'01KTF88B50J3A39JME9CHE2YHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1044,'01KTF88B51D78BV87JR6XHKC8F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1045,'01KTF88B53H4TVZJ7VDA45830C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1046,'01KTF88B548SR47E6S6CY1TEC5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1047,'01KTF88B55E8YB8040H9D24GMR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1048,'01KTF88B57YM2CHPKZ72EB3FMF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1049,'01KTF88B59YEMV6J9G0QCPEPT0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1050,'01KTF88B5AZXSZZTKA1ERC7075',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1051,'01KTF88B5BCDWHXD2WQBA4CC50',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1052,'01KTF88B5BCT3D3ZAK5N5KD2VR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1053,'01KTF88B5DACEK4QCJ2PQG7ADX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1054,'01KTF88B5FHV4AAY9CEPVY6ZJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1055,'01KTF88B5G0JV2J1BFJJW1SN3T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1056,'01KTF88B5JR3WHS5A5T3SWS5BB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1057,'01KTF88B5K5M5ACWPBV5ES39GG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1058,'01KTF88B5M0CGW66Y3R3SQSD0D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1059,'01KTF88B5P1E9C8VX0RGHCQ8J0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1060,'01KTF88B5QTA4780VC950WQR3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1061,'01KTF88B5RG8KV9TXM10PCMZQT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1062,'01KTF88B5TB1J2EEQ023QWHA83',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1063,'01KTF88B5VJ13PBCBVRD6XA5DJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1064,'01KTF88B5W264C0TGAWJ8V2MB2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1065,'01KTF88B5YHNJ5BN5CRCN5XWK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1066,'01KTF88B5ZM0CSGT85N7X33M6D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1067,'01KTF88B61B2QFHZW2FF4F09T3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1068,'01KTF88B63J4RE62EMVMKV7DJX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1069,'01KTF88B64SWJS1N48VVZKQ9DK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1070,'01KTF88B65AZJQYXX6ZQ4A3ZXR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1071,'01KTF88B6728XVBBY1V2MZYHVE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1072,'01KTF88B670CMKRJ7TZKX3AXTJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1073,'01KTF88B69S8KQX88VN5WSZFX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1074,'01KTF88B6B1PJTV3DQ6YSEJ3FJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1075,'01KTF88B6CQP06KWKGRRT315K5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1076,'01KTF88B6DCDC6WG02H4E9D42D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1077,'01KTF88B6EB98S4FA39FC7BFSM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1078,'01KTF88B6GPG9Z4WJKBKREDG70',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1079,'01KTF88B6HQ5N8FKA58DA7YE5Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1080,'01KTF88B6K8HYNZKFSJJX8SDKE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1081,'01KTF88B6NJQ7407KA98KMY8RY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1082,'01KTF88B6Q0W9FBXPR773TZC6Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1083,'01KTF88B6R5SK8R4ZVZ2KYNPW7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1084,'01KTF88B6SNHTCXHQ26JVGGKNF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1085,'01KTF88B6V5C61WH3XGJTYGM56',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1086,'01KTF88B6WVZQ1YBSGHP3QW3P3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1087,'01KTF88B6YKAMPC6N7HXV2FDJY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1088,'01KTF88B6ZAG6ZMW57YTPYREFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1089,'01KTF88B715YG2BHK4RZ3H84R0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1090,'01KTF88B72XDVM3R01NGW8X0QV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1091,'01KTF88B732M2RH723KVPB7FXW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1092,'01KTF88B75ZFQJEMYAN737XHRZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1093,'01KTF88B762ZS7JSKC9VPYN38J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1094,'01KTF88B78QTQ821531095MJS6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1095,'01KTF88B7ACHH5SEY5FV81212C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1096,'01KTF88B7BWMM1G535RGF7KDQP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1097,'01KTF88B7DNZ50P2K1Y5134VWN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1098,'01KTF88B7E7SGNCW0BAHMGKANZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1099,'01KTF88B7F0RDH7PSQ61SPRGEW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1100,'01KTF88B7GGBRZMANQJXJNJY6Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1101,'01KTF88B7JC5100DZ8XMBG60KR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1102,'01KTF88B7M0FTD2FPAE5E5TG0S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1103,'01KTF88B7MHBEC3TZ003MA6R7W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1104,'01KTF88B7N48YNEJ2HHB0S7R97',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1105,'01KTF88B7RGR6EGWE03H11EHQG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1106,'01KTF88B7RHT1NJSS8XADGKJTE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1107,'01KTF88B7V6FPGXBCBSA54P0EX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1108,'01KTF88B7WZ6JT97YS93K87C6D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1109,'01KTF88B7Y2QV6371H36TDJK2Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1110,'01KTF88B7Z23SDR5SB0AK0W0WQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1111,'01KTF88B810FMRC2X971EJ6ZXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1112,'01KTF88B820FMPQQQGXRJ10TBB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1113,'01KTF88B844M03FQZQ9Z554Y0S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1114,'01KTF88B86GH9DFH7FXGBSQ5GG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1115,'01KTF88B87EF3E1R2ACMMVM309',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1116,'01KTF88B8990091XE2HXG6HR5K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1117,'01KTF88B8AR5RS0XHMS2YN1Y81',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1118,'01KTF88B8BEBZSG72Y8M9175GV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1119,'01KTF88B8CK7FV19GY11RWWP2M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1120,'01KTF88B8ETJ7SVQPCG4ZDQVHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1121,'01KTF88B8EX920DAJGQAEJ8PZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1122,'01KTF88B8HBTX1323GVG1VTNZK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1123,'01KTF88B8J24EXTPMHJPFH3S1R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1124,'01KTF88B8M0D4Q8TR8SM6CCV0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1125,'01KTF88B8PA8P5EKFMGC08HSVM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1126,'01KTF88B8Q00KA10SYXQERFWK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1127,'01KTF88B8SMYVDCH2Q881Y0DEK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1128,'01KTF88B8VB96373FN1ZXPZX42',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1129,'01KTF88B8WZ78CX2R3PFZFP649',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1130,'01KTF88B8YNKGTMB3MW87HX4RK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1131,'01KTF88B8ZP3VJH1F2DDRWSJTV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1132,'01KTF88B9242S7QQYTCJRKPFDG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1133,'01KTF88B9383S81TBJZDNVM09G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1134,'01KTF88B94C3ZS6Y2J9512H4ZA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1135,'01KTF88B953A28TA2ENAZ9CB8Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1136,'01KTF88B97NFZY6G2QCK5Q4BEV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1137,'01KTF88B98EZ6BM5V2GZ4DJ27A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1138,'01KTF88B9A21BFFWPCDQ55HCM3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1139,'01KTF88B9BJ548QXBZQEWQMEVX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1140,'01KTF88B9EVN1J44NKZ9PHWD35',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1141,'01KTF88B9EDXBXEP9WKADDHQDZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1142,'01KTF88B9GDSZMKMQQ4XBXYK1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1143,'01KTF88B9G1QM64VCMC1CDG1F6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1144,'01KTF88B9JQ1TF4FMMDJFZ4YBP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1145,'01KTF88B9KF5WGYQBXZNQ1EW6V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1146,'01KTF88B9N4MZY6WMS9M7DYRKR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1147,'01KTF88B9QNHCD4ARSB9R2J6X8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:42','2026-06-06 19:58:42'),
(1148,'01KTF88B9R5H4TE17V3ZM8XMRC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1149,'01KTF88B9T97YKMED6Z20ASDK2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1150,'01KTF88B9V2V9C86CYH038X97J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1151,'01KTF88B9X2Q5NGYJYXF83NH2P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1152,'01KTF88B9Z21QYHECZ7FP7AF0A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1153,'01KTF88BA1B7YRD6JV4ZSA2TVV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1154,'01KTF88BA3VESV44VGED2QCWXQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1155,'01KTF88BA4QMAYT3C17TQ4X9YT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1156,'01KTF88BA7MZMSEARQB18JR9D2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1157,'01KTF88BA8HN2BKJ3RNRP8CNYC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1158,'01KTF88BAA3R4F4KYZ9391DA5H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1159,'01KTF88BABG69MCT2CCZN9X9MJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1160,'01KTF88BADECV23E56T8393SXG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1161,'01KTF88BAFFY7YG41H05JQGT2K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1162,'01KTF88BAGA8KBZ2JVE3VRF5A3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1163,'01KTF88BAHT32S3R2AKSS93VQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1164,'01KTF88BAM2MHCG2J5TQ7PWQWD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1165,'01KTF88BAN9EV11C1AAMEDT3M9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1166,'01KTF88BAPS676DB8Y5NYDVMMY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1167,'01KTF88BARH2DG1BCZ5S14TNK4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1168,'01KTF88BATCYNXBQM3N275GQT8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1169,'01KTF88BAW8B0T8KS1GW9KYPZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1170,'01KTF88BAW1HHBCK7J498W9JWR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1171,'01KTF88BB0AH50BM941W1W5133',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1172,'01KTF88BB03RSB6MVNCTMJSXHY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1173,'01KTF88BB8GBPH0DMZQ0PR1MF5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1174,'01KTF88BB8Q4EKGFQPB6MVSBFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1175,'01KTF88BB80NCZCQMFKGPMVMYF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1176,'01KTF88BBA11PXJ871RAAHAGZY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1177,'01KTF88BB9E4XH0C6R9TDSPV39',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1178,'01KTF88BBCFBBQD83Q4KPXAH25',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1179,'01KTF88BBBJSFCR8SH8HC0KQNT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1180,'01KTF88BBB0PKS045R3RAKKY8Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1181,'01KTF88BBD1A71S78DGV1BN9SE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1182,'01KTF88BBJ60H4PVP7P82YD9YZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1183,'01KTF88BBJ4A8VYJB5AKP1CN0X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1184,'01KTF88BBK8QTG0QPTYFR3HTH1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1185,'01KTF88BBNK2ZK5TS80B68YX8S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1186,'01KTF88BBPDXES5W9F91S1YC10',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1187,'01KTF88BBRQJHZ5NQ8BVNWWBKZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1188,'01KTF88BBTSE9BQ4B65KQQHCRW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1189,'01KTF88BBVE9JW6DX9CTZZ4T6X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1190,'01KTF88BBWRF179TS704TKEZ0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1191,'01KTF88BBYK1Z417Q60NR1WVPV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1192,'01KTF88BC1WJWPHCC4ZRXTDQP8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1193,'01KTF88BC15YYF3A70B8Z558ZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1194,'01KTF88BC3J19FN481WZCAXEDZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1195,'01KTF88BC44JJ36RWWHRC9MDPE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1196,'01KTF88BC61J6KHY4HBGQ41PGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1197,'01KTF88BC7VMGJB8R4NECWGWFG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1198,'01KTF88BC95D6Q7ZA7B3PEAJDM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1199,'01KTF88BCB4CWB7S4N4WB143GC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1200,'01KTF88BCCE6YKB7ZMWY8YQ639',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1201,'01KTF88BCE3FG55KXFSYQD0R6C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1202,'01KTF88BCF5R49131SERZ5KTK4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1203,'01KTF88BCHX7MXECZCN2EC9JTT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1204,'01KTF88BCJ426YQPA4QPGQ4DXD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1205,'01KTF88BCK1ZQ458FQDSPB3TZR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1206,'01KTF88BCM5HZYSCW5X39XJMTK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1207,'01KTF88BCP5ES1AF0TP2NZ4VZW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1208,'01KTF88BCQ2YB5PNQSMM0SEA43',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1209,'01KTF88BCRGJ9RM2AH5S8CFGVC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1210,'01KTF88BCVX59H5FV6KA9P4TNH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1211,'01KTF88BCV2XCD6QTPT8D4SHKE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1212,'01KTF88BCXFZYXY9H3F70DGX21',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1213,'01KTF88BCYFAWDAF6MXD681H46',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1214,'01KTF88BD091NGQ46RP251321D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1215,'01KTF88BD1ATASA537P83QCM55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1216,'01KTF88BD299PXV9GBS070H5FN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1217,'01KTF88BD33SC1JJR1TGEK8F48',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1218,'01KTF88BD55BBCC7E94NYM8WEH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1219,'01KTF88BD6SQSGGWYZ7K81R9HF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1220,'01KTF88BD91YRVJ2R5R826B4N7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1221,'01KTF88BD9J1GY5JXC82263WZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1222,'01KTF88BDBVK3SZG2MBHXVN520',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1223,'01KTF88BDB0W1D8MXE4H0RKVSQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1224,'01KTF88BDDYKNQX8T379TDBSCH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1225,'01KTF88BDE878NMHG1V8Q082JW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1226,'01KTF88BDFFSBK8302R3HZ4E8V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1227,'01KTF88BDHGNENQ7MWTC8H4CHV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1228,'01KTF88BDJW5G3KKZWRBH7V6WH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1229,'01KTF88BDK42C6Y29F0AW65FDY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1230,'01KTF88BDMFV6GP5JPEGG6A4JT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1231,'01KTF88BDP1AGMEKER60CN0EP0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1232,'01KTF88BDQRRM07C6DH473CCNW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1233,'01KTF88BDRGBVVN70DKWPNP1KG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1234,'01KTF88BDTSZGJ2THEMND21X3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1235,'01KTF88BDV2T1EH818391VN1TN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1236,'01KTF88BDWQARV1YBV0VX0KS6N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1237,'01KTF88BDYSCARKSKBQBQHRTGT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1238,'01KTF88BDZKNSMV6CXFF94SD9W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1239,'01KTF88BE04Y1R2ANG6DCKB12A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1240,'01KTF88BE13SJJY6SB6AM5AX54',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1241,'01KTF88BE24KGWFKQHGRNE0EAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1242,'01KTF88BE4D8F0HB0WSMVNSKZP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1243,'01KTF88BE6MDZDW5VPFXM0S1ZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1244,'01KTF88BE6KZ35Q809H8TXK6EP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1245,'01KTF88BE82KBSXAFV3261TZQ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1246,'01KTF88BE947KH6R2JRWT8PY4R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1247,'01KTF88BEB1T2927W2VRGVTVK7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1248,'01KTF88BECF3TSD2DNWDD6NRHY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1249,'01KTF88BEEJ87W61XXJ7CJ6X2R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1250,'01KTF88BEEGBED2EMTPHA3KR4Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1251,'01KTF88BEGW8RN35W6TC4THJ95',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1252,'01KTF88BEJT5J0J82TWRJJMXY9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1253,'01KTF88BEMD65R18GF9RA7233G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1254,'01KTF88BEK7FX91ZSHVYDX2ZW8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1255,'01KTF88BEN1WG3HM73AXH2VB8P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1256,'01KTF88BEQ7AT1F6JMNSY2SVSV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1257,'01KTF88BET3YTQSDEXC3YBBNZ1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1258,'01KTF88BET6KG2V2RZEZJD6VB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1259,'01KTF88BEW5638BQ6M0H4WZ8VJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1260,'01KTF88BEXEVRZXND7S0NE1GXV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1261,'01KTF88BEYVRKPZEJD5C43RP38',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1262,'01KTF88BF0X6BY5N052RJA4D3K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1263,'01KTF88BF2HE57RZ7EZHZHNPGP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1264,'01KTF88BF3XXSRC2T63TNB501D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1265,'01KTF88BF5155886MSAAWBGHBK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1266,'01KTF88BF6SN91Z5KP324P8SFJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1267,'01KTF88BF75GGB2TWTM53385F7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1268,'01KTF88BF96S0GTH96TPHA25QH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1269,'01KTF88BFAVH1F287XPRBR0JTS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1270,'01KTF88BFB2H59AFS30AY20YD4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1271,'01KTF88BFCVHR26T4BXXXNDJGJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1272,'01KTF88BFEG87N2W7VHE2D4JT1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1273,'01KTF88BFF7YPAAYQS5FQTKKFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1274,'01KTF88BFG14PRH1C4YPB77MJV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1275,'01KTF88BFHCDVW4JSYZCKCZXE2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1276,'01KTF88BFKKW3AS22GKY8T80PE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1277,'01KTF88BFM87DRAH99TRFHTX4W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1278,'01KTF88BFNDA2C8500PVY04G46',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1279,'01KTF88BFPMD1V7HX6VMBNFCQX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1280,'01KTF88BFR4WAK8GV2WMRHDZA0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1281,'01KTF88BFT5K4P29BXN7WQWK3Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1282,'01KTF88BFTXK1EJHFNJK602WB8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1283,'01KTF88BFWQ9AJNZ0TJTGDRAAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1284,'01KTF88BFYHC49XJFZWN3JXX9Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1285,'01KTF88BFZG9GV15ZY8Q4ZW6SN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1286,'01KTF88BG0M85N9N6Q9342HZ9F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1287,'01KTF88BG2XTXZ7T187CC9PW61',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1288,'01KTF88BG34EK8A00BB0A1YCD7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1289,'01KTF88BG5GZ9REZMJ7N3J3E5T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1290,'01KTF88BG61AFAB3ACPFA1MXN1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1291,'01KTF88BG9XRK3E80P6YRPS0M2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1292,'01KTF88BGBYHCDZYF7YNTFQRYV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1293,'01KTF88BGAWQYQZVJZC96NR4KD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1294,'01KTF88BGC3Q1VQZPEYCBFHVVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1295,'01KTF88BGDKH1B6Q7NWHMFKZPE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1296,'01KTF88BGFWERXTM4B1BMETYPS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1297,'01KTF88BGGSNH2AQXG02ANMT29',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1298,'01KTF88BGJ0C31V6YHYHY1JJQ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1299,'01KTF88BGKC9G9J3A2FEM3WNEA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1300,'01KTF88BGN0N6Y81N7R3PDN49X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1301,'01KTF88BGP2QBKX3TRGFYJVTX0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1302,'01KTF88BGQR71EX0Z17CA3CNTJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1303,'01KTF88BGSW9QRQZE9S5MG4TT3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1304,'01KTF88BGVP3Y1HW99MY369DJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1305,'01KTF88BGWRZWSR8C5D1XM3K9Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1306,'01KTF88BGXBVD8DJN9WT0VJABM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1307,'01KTF88BGYH4MSZ8RENCA27BGF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1308,'01KTF88BH0JEPB3009D8TX5Y6P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1309,'01KTF88BH1152TP0M66ETX1ZR7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1310,'01KTF88BH3F5JPKDM3MEBEYFKH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1311,'01KTF88BH4YGRNNZYWXFH7NA2A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1312,'01KTF88BH62YNVN07QND9KZHW2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1313,'01KTF88BH6Y9M43NXQ6R5Z6CSB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1314,'01KTF88BH916BJHRRD9WRA2MGV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1315,'01KTF88BHAAQZCHN8S4QQDJ4DK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1316,'01KTF88BHDJR1FSKQ592SVS9HE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1317,'01KTF88BHDT25TMAFD90KJ8V3W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1318,'01KTF88BHFAQ9N4QHZ50653ZPG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1319,'01KTF88BHHHYKSK301SDN51XW7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1320,'01KTF88BHJZNW06KV9J8C1H9G7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1321,'01KTF88BHKBBKG1KJX94HQP4HK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1322,'01KTF88BHN4MNJ3DH6RZQ0PBSD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1323,'01KTF88BHP85PGAZA8EQJHMZAW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1324,'01KTF88BHRA1Y3M7BY3MR1FV2P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1325,'01KTF88BHS61326JYCF36YGJK9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1326,'01KTF88BHT5WKX7BKDRZP14PRR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1327,'01KTF88BHVX767F15DSPRJYSSB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1328,'01KTF88BHWMWQMYPYZJT1QA37Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1329,'01KTF88BHZ2PC3PVGT1EKJRYYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1330,'01KTF88BHZJQYKY3GX476GJCR7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1331,'01KTF88BJ01RSFM9ETCJ518JF8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1332,'01KTF88BJ38972ES5PWB3SF43Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1333,'01KTF88BJ4D1MKVAPZNYS7HANS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1334,'01KTF88BJ5SCPYNVRK0EKCTMMH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1335,'01KTF88BJ7HRPQ1T0C4ABV2Q7N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1336,'01KTF88BJ92EPH9JKF8GKKJHX4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1337,'01KTF88BJA5JPCAMMDMY0AD7T6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1338,'01KTF88BJCGG7YW3EZ8F4566BG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1339,'01KTF88BJE096FW11B4896HFXQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1340,'01KTF88BJE8SNJG9GKV1M8ZGJG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1341,'01KTF88BJG66R4TEXW11W9V0R0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1342,'01KTF88BJJ5HPYY0SCFVRW304E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1343,'01KTF88BJMX5MEGPBVMFG6B3WV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1344,'01KTF88BJM35A9TK50022NKTFP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1345,'01KTF88BJQB8X8Y86NJB2F7RAF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1346,'01KTF88BJSH38A8J4HVCAG0TXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1347,'01KTF88BJTSD5C0B5W9X5VN59P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1348,'01KTF88BJWDGWCZV1VV0E1TEVJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1349,'01KTF88BJYR8R9V7WFP1ZN50M8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1350,'01KTF88BK0TWN0ZWKQ7225STR3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1351,'01KTF88BK17NHTVGQWX4527Q48',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1352,'01KTF88BK2016PG69YPFAFK4SM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1353,'01KTF88BK3WG30W3X5NGC4Y463',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1354,'01KTF88BK5WG7FQC5YB9PNAK40',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1355,'01KTF88BK7WMDQG0AXP2683SK3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1356,'01KTF88BK827K1YTK3WBS1V4EP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1357,'01KTF88BK9D2DFRD16WQZQG566',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1358,'01KTF88BKB2WWAF4MQC54HNQ2X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1359,'01KTF88BKDXYBT6VSZ2FS958A3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1360,'01KTF88BKFFN0HHBCB9PBW9G5D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1361,'01KTF88BKG2SZF57M2Q7TTBX51',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1362,'01KTF88BKKBRB32CD68BFE2BWD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1363,'01KTF88BKNJVX11ZRG8JD0JEQ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1364,'01KTF88BKNQZ1BEH94941TNZHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1365,'01KTF88BKMXQTTCMQE5A668GWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1366,'01KTF88BKR0200JTH12GM9HZPN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1367,'01KTF88BKSBY62E1METHQ5SP1C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1368,'01KTF88BKV6RZBSAR37KV5DDAW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1369,'01KTF88BKX2DCRXA8Y8W3KTGAZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1370,'01KTF88BKY5FZ934HPETXEFZSG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1371,'01KTF88BKZF5VKHDQN0WQ7JRMM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1372,'01KTF88BM1WRJ01Y2H399V3NCH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1373,'01KTF88BM3QMGA809TA6GFT8M0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1374,'01KTF88BM4Y3Z7ADQYXRSDE3GD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1375,'01KTF88BM5ZD6QPVPPV4RFRXS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1376,'01KTF88BM8D8YNWSMP67QASPWH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1377,'01KTF88BMAPN5DS9881GP9GK12',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1378,'01KTF88BMBBTN1KTXJQX39844B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1379,'01KTF88BMDYCCW137SH599A61R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1380,'01KTF88BMF7BPDHXNH3QF09NN9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1381,'01KTF88BMGT89KYY0XS9BHVM59',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1382,'01KTF88BMH9G7S05QSEJ2ZQ7YX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1383,'01KTF88BMKP06BCH98APVZPH0P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1384,'01KTF88BMPXTB8976DJJ9PWPYM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1385,'01KTF88BMPVJAW3PP0H4KRN6VS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1386,'01KTF88BMSAD9XC6RD5A4QD4XY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1387,'01KTF88BMT72JSEZZZ0D7V4NYX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1388,'01KTF88BMTZEP6RP25HKF293S2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1389,'01KTF88BMWMFHNGH1F0QXCNN7Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1390,'01KTF88BMY5E2S24S620N8TJNR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1391,'01KTF88BN0WRK3K928TADB3BJ1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1392,'01KTF88BN1GDCM36P9TM1N18Q6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1393,'01KTF88BN21A39Y8Y5EZ4XGAAD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1394,'01KTF88BN4SFYSSAZC9SK0K3G8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1395,'01KTF88BN52PPDDM47HBG3516J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1396,'01KTF88BN6160WE6CRTM2DQ3N3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1397,'01KTF88BN8XAJMN5P5K042Q5JM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1398,'01KTF88BN9BD9ZRKGDDHGRG2DN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1399,'01KTF88BNAEWSJDZNNHYZEQBEA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1400,'01KTF88BNC62FP3YCPR2Y8GF5S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1401,'01KTF88BNDMC8CMQ7R4SP32HEF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1402,'01KTF88BNFVFXS83TW7YWCBXMB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1403,'01KTF88BNGXYFGQAHGEMYE8SDH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1404,'01KTF88BNHS3MSN2TJP14YNPZG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1405,'01KTF88BNMXMZ70CYKSKW2KQBA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1406,'01KTF88BNN3T6S01XRHQBXAQ2G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1407,'01KTF88BNPJ0PGY2ZTAJJC7M90',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1408,'01KTF88BNQKR01WC3RKQ3PV9FY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1409,'01KTF88BNSERX4NHR2V9HRT86C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1410,'01KTF88BNV48FGBJ0VJF3DS3S4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1411,'01KTF88BNXK2E7DD742VDBZM8Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1412,'01KTF88BNYRBS7CZE89EHZ0MQE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1413,'01KTF88BNYCVJS3QE6J3R0AM0Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1414,'01KTF88BP14XCXKY33DD3G9AGS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1415,'01KTF88BP333YXK78P9R4Z3G16',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1416,'01KTF88BP4B33DH48WD42CF1AN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1417,'01KTF88BP52DSZ94PPMHXZJBPT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1418,'01KTF88BP74P2QVF61W9Q41M1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1419,'01KTF88BP88EN2N2YWQSAG645V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1420,'01KTF88BPAM7C1A1Q71FHMN8XA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1421,'01KTF88BPAWRVR0894Z320K5WB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1422,'01KTF88BPC2J7R6X9Z1AQRKWKB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1423,'01KTF88BPF5EGQD0JFM85H89FD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1424,'01KTF88BPFTTVD3R5RRM22CZRB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1425,'01KTF88BPHY7J59FH7X77GQJDD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1426,'01KTF88BPJ6PDD9YQB1YJT7A1T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1427,'01KTF88BPJ7885NT0DJ8J2G3KE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1428,'01KTF88BPN164TP3JVBNBZSW1B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1429,'01KTF88BPPA19S4KTRMG1TYQR7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1430,'01KTF88BPQKCSPNC2Q8X69SMTG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1431,'01KTF88BPRRXP2AHYZ6371WE3Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1432,'01KTF88BPTHW6VADE6AG9YGQ0Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1433,'01KTF88BPVKK02X08QVGA5AVZ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1434,'01KTF88BPWZEN11YPAFW1C6ZHV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1435,'01KTF88BPYWFQGPDTT0KDDPXB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1436,'01KTF88BPZZRY7F9V6E5JJG508',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1437,'01KTF88BQ0Z91T3ZV8Y8WGTW7Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1438,'01KTF88BQ1T18SGGNKB0M37ACP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1439,'01KTF88BQ2KDXWRYFJSACS72C9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1440,'01KTF88BQ4FF2V1SD77ST0S3KR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1441,'01KTF88BQ6NV2ESSE3K0QJPXR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1442,'01KTF88BQ6B7KK933052C8C0QA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1443,'01KTF88BQ8HWPHSA0833B6V2FH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1444,'01KTF88BQB454STFEGFVN7B8C9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1445,'01KTF88BQAXABCZ5PYR78EYW2E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1446,'01KTF88BQCE1KNC5E0JQKM1G5C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1447,'01KTF88BQE6215D8MST76CP4SB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1448,'01KTF88BQG8S5WCZCQW6DV8VGF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1449,'01KTF88BQHH595MBARTW079JBQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1450,'01KTF88BQJQCS89ZCQ33WK5K94',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1451,'01KTF88BQKSG0DCKK8RRA14GP3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1452,'01KTF88BQMQ7QPGHRKAJ94V641',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1453,'01KTF88BQPX105QWJPA18N94A0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1454,'01KTF88BQQMYFWD9PBPV3X4ZFM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1455,'01KTF88BQS12WR3RHMG3KSK0A0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1456,'01KTF88BQV7PGXCM05QKYZZ06S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1457,'01KTF88BQW5J5QMECXQWQRYNAY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1458,'01KTF88BQYSE3CDHVJPVHM33P5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1459,'01KTF88BQZCW18T63SWV6WAD6J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1460,'01KTF88BR1HVGV10PJ1ZHJVEAD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1461,'01KTF88BR2VWA1SG394PQRCV9A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1462,'01KTF88BR49QSFZG5214HZBWWW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1463,'01KTF88BR4EXHVMR32THSV7BCA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1464,'01KTF88BR62MV5T3PGMN8MJ2RY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1465,'01KTF88BR7H5KHH3YSE3Q3W6M5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1466,'01KTF88BR91S3583N441QQVVM2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1467,'01KTF88BR9XPPSRGXBHY7SBJKZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1468,'01KTF88BRB52JSJ8XNEE8K3MNW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1469,'01KTF88BRCNWAFVPTMBW388APW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1470,'01KTF88BREXZBWMV3RTG7RM9D1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1471,'01KTF88BRFV72NYGXF0436Y1EB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1472,'01KTF88BRGCYVB9QFMCH3J6VVZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1473,'01KTF88BRJQVK5EXEJKWM4VDCE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1474,'01KTF88BRMR88PJE209BQ5J9R9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1475,'01KTF88BRNP993CN62HGK8MM6Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1476,'01KTF88BRPXNXM4TFB1D60RVQT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1477,'01KTF88BRRG7MJ1PMW8KY1KEP2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1478,'01KTF88BRTE4GFHD3HH3CHYN7E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1479,'01KTF88BRV9Q77KRKY54ENSATX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1480,'01KTF88BRWSE5SRGW1RRD3D3CF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1481,'01KTF88BRY3XWNR4MYP4H3J437',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1482,'01KTF88BS0Q85TZ3J559BYXGP1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1483,'01KTF88BS0BC4D7YYS56YNDG9S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1484,'01KTF88BS3EA7DT4N2NGYTCTJS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1485,'01KTF88BS3H1P89A46D0GN57NC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1486,'01KTF88BS5H5KT9NHATBDTK8KB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1487,'01KTF88BS7A7T4735WHVBXD64H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1488,'01KTF88BS8VT1PVY7Y0RYW0G4G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1489,'01KTF88BS9FDQRQ3NCNSY02XF0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1490,'01KTF88BSB3TYWJX92N9HDDY4F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1491,'01KTF88BSDZBND8MVD4K1AVJE7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1492,'01KTF88BSF05RBY65FF2MTCJP5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1493,'01KTF88BSFB0G52AW8V8V8CGSE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1494,'01KTF88BSG16HRNDYVY49MFT87',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1495,'01KTF88BSH62NZZV0C3RTTK1HN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1496,'01KTF88BSKK3CSJ96PCBJR7DDP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1497,'01KTF88BSNWJQH2DR4EK4BCCVX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1498,'01KTF88BSPNSJKQ1BQ9TQGJRHS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1499,'01KTF88BSQ1RWXM538SM9N1CXA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1500,'01KTF88BSSRHY519Z5J3K66H4B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1501,'01KTF88BSTMMNPRGSPE9SFPN8S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1502,'01KTF88BSW7HWXTGJSVK15V6CX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1503,'01KTF88BSYP9VGZ5J8J8PFXPBM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1504,'01KTF88BSZS4DVM67S19ANRBKR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1505,'01KTF88BT0ASQQDX0WK3NQ6FX3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1506,'01KTF88BT28CMG9VAZ71GTVYDX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1507,'01KTF88BT3F1D09357GFXXZHG8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1508,'01KTF88BT4JQSW3Y1QH2GGHMBN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1509,'01KTF88BT6DB07R56BFPQDT86K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1510,'01KTF88BT78NWYN7JRJW4XPJB1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1511,'01KTF88BT82C5ZKNA3Y0WQE9BT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1512,'01KTF88BTB2X6XMV1AJGBQF73E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1513,'01KTF88BTBX8SET3902X8P51A0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1514,'01KTF88BTDPTGWA5H4P3QYZCFM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1515,'01KTF88BTED7QWX996G0WD14QR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1516,'01KTF88BTG1ZQZMBFW597W84MQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1517,'01KTF88BTJ7M3APHSQX7H1GTHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1518,'01KTF88BTJKNKA01E8C6CAF2Y1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1519,'01KTF88BTK30EBQRJ0DSAMEA4C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1520,'01KTF88BTN0NM3SD1QGEQZNC8N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1521,'01KTF88BTPNASNGHNWAJW7VS6E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1522,'01KTF88BTQZ9XNJWJKX8WQTDHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1523,'01KTF88BTSD3QDE8JYTYWVG8P1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1524,'01KTF88BTTS07JRS0GAESDW12X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1525,'01KTF88BTVCYXAJF7XYRMZBTJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1526,'01KTF88BTWD6QZQ2VANFBZQ3BH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1527,'01KTF88BTY7EVHZ5MAEQGC7XYK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1528,'01KTF88BTZZ0D5PAJ9VB0S2S4G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1529,'01KTF88BV2E26AK3C27HYGYCGE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1530,'01KTF88BV13R94G9V7HQ33JP1K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1531,'01KTF88BV4MQ08EAD3EVA7SCJ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1532,'01KTF88BV5EFMX1060ZBDR7XFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1533,'01KTF88BV682FQX1DDJ8K8FR5N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1534,'01KTF88BV7BZ934D2ABCDF9XXZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1535,'01KTF88BV9HB6JGQCTDS2SAT3W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1536,'01KTF88BVAR6BC5C13CHJWV2C5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1537,'01KTF88BVB3P53AJPVVTQM6YSV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1538,'01KTF88BVCF2K7D3AT7CR420FF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1539,'01KTF88BVDB36ZB6AQ9NAR01P7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1540,'01KTF88BVE34541TQTRJ372JG4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1541,'01KTF88BVF89W153T80ZWHTR5P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1542,'01KTF88BVHQ5MZ2R9MFRAFQ30D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1543,'01KTF88BVJH0KFBTBV7SP6CY8F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1544,'01KTF88BVNFC2MSYBXC8FWZCF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1545,'01KTF88BVPV1F5GTEH0XR58X3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1546,'01KTF88BVQ5EH44Z22AYA89766',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1547,'01KTF88BVSS8PNMYT5G6J2KA5B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1548,'01KTF88BVT29FCGVNZC570DA57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1549,'01KTF88BVTQQ078MQKZ156TPPN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1550,'01KTF88BVWYKHN3EX7SG4V5SN8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1551,'01KTF88BVX0E1RHTA163C81Z5F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1552,'01KTF88BVZXFMHBQBBWG40Y120',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1553,'01KTF88BW0NB1MNKK2HQP81DDK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1554,'01KTF88BW1754VPEK2SPJ7H516',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1555,'01KTF88BW2700PD2BYJ2DEH3YV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1556,'01KTF88BW40CC2NV1BPDW3NBN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1557,'01KTF88BW54ZHCW63VFD3ZDAXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1558,'01KTF88BW6GJE492564SAYZ5G6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1559,'01KTF88BW8E802F46Y7AB7AJHW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1560,'01KTF88BW9DNWJRH9J85PF9MHR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1561,'01KTF88BWACC2EWABJTRSNAYR6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1562,'01KTF88BWC653D2SRB1TYGRBJR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1563,'01KTF88BWDRQH2S5NTV6ABYX8F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1564,'01KTF88BWFJCA84NKDF3HG5TPR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1565,'01KTF88BWF12G2D6H1A71BKGA8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1566,'01KTF88BWGB7E0MXHHGGVA7YS8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1567,'01KTF88BWKBKKDWYSB9JJZ685N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1568,'01KTF88BWMCBMP2B2ECFDAKQRC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1569,'01KTF88BWNJKWE7HPW4YNH7KBV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1570,'01KTF88BWQ5Q1N727GJ5T661Q3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1571,'01KTF88BWRYQN5AEYPYVD70V83',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1572,'01KTF88BWSF0EXSDTZJ8RDTPGV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1573,'01KTF88BWTCDQ0QTAN7SXC4RB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1574,'01KTF88BWWT4C1PZDVPD56RVNQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1575,'01KTF88BWX27BVMMHW1JCJKNH6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1576,'01KTF88BWYDTMCVMYBAYNYD160',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1577,'01KTF88BX07DSWWSWSDAZJTMCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1578,'01KTF88BX1TMXAQB4RT05M1GXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1579,'01KTF88BX3FARVGEYZEMNBB1AC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1580,'01KTF88BX3NNTSPEQ0HB2MG344',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1581,'01KTF88BX5C2D0ZYX1S6A6YM96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1582,'01KTF88BX6HZY66W7EW304ZTKQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1583,'01KTF88BX7VCR21J359GSAM9K5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1584,'01KTF88BX95G9V1V3DNRQ5CZZ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1585,'01KTF88BX90JRZNF5R7M4DDKG4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1586,'01KTF88BXBVKXBX5ZGH8V1AN7B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1587,'01KTF88BXC7SR7JWZFYPMR4FDW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1588,'01KTF88BXDAN8WGZ7V2KY1PPVT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1589,'01KTF88BXEJJVTDF0J2YV3R1RV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1590,'01KTF88BXGYZQKJ223C3YQY3CP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1591,'01KTF88BXH40CN6KZNFZYJN6GJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1592,'01KTF88BXKF211D618CX80XPWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1593,'01KTF88BXKT72W8HBBW86VTE26',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1594,'01KTF88BXNRWDHQPNXS231TK18',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1595,'01KTF88BXPC5EPZJPH1AME11RX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1596,'01KTF88BXQCKNHR10QP1MEQ6YA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1597,'01KTF88BXSD7NCFRPQQ1XVDCDP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1598,'01KTF88BXTEC0XNZRT9WD4NACN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1599,'01KTF88BXVDJKWND2MMVTBSSAZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1600,'01KTF88BXXZ25KCDHN3M5Z290A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1601,'01KTF88BXY2GKDRXZNZ7GX3ZKP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1602,'01KTF88BXZ3AAEKJD6JT2M7DJZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1603,'01KTF88BY0SRVZGECN7BDVN3B0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1604,'01KTF88BY2P17H77F3JE1EY18R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1605,'01KTF88BY3DMYR458TC6J0FG8C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1606,'01KTF88BY4NV14TDHJ5DJJVJM2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1607,'01KTF88BY5CGZTBQR2X2ATMD8M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1608,'01KTF88BY7R4EJE0QCGJS1EGK8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1609,'01KTF88BY9C0TMXKGX49JW4N4P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1610,'01KTF88BY948B6AZ4FT67VSH7T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1611,'01KTF88BYA9C9DEQDK0Z92XXME',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1612,'01KTF88BYCK6BZS3N7DHYW959G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1613,'01KTF88BYDF5YAFCQQAW61W15K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1614,'01KTF88BYENWQ19ENDXNXMVKBY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1615,'01KTF88BYGCTZM3FN4XHJRE49Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1616,'01KTF88BYHDM0T5ZE0ABX6KWKY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1617,'01KTF88BYJ5SV57B1N8G6D9EGJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1618,'01KTF88BYKC2S9S427GYXRBM40',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1619,'01KTF88BYMS0JHVWAS0P9JTV7E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1620,'01KTF88BYP32CXB6KTJVGJ9PQY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1621,'01KTF88BYQHWNP1Y74J1H44RDR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1622,'01KTF88BYRBW69RAXMD1VPNCPZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1623,'01KTF88BYSCTH88NFEZK4MVWAQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1624,'01KTF88BYV4N7X85J9VWGGQHA4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1625,'01KTF88BYWKRWCQRNAB9ZCJY1F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1626,'01KTF88BYYC4FPG8PR3VW1C8M8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1627,'01KTF88BYZK24Y39KAHJ2W7YNA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1628,'01KTF88BZ07M58BEM32NMCDGR2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1629,'01KTF88BZ1YDNQE3V1XXFGZVCM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1630,'01KTF88BZ2Q9QG8FBK876CVEA9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1631,'01KTF88BZ4HYVFN9WHGN4KZYMD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1632,'01KTF88BZ5T330AVGZFD7TRV0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1633,'01KTF88BZ7X5AS7D4MF160Z9WX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1634,'01KTF88BZ8KSZAEAN500ZX6GC4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1635,'01KTF88BZ9N0AQHKM5ATYSX8SZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1636,'01KTF88BZBGVHJ9QR2TTQWYPPN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1637,'01KTF88BZC6VVE52HBRBKVAWW5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1638,'01KTF88BZETJJ500TDM97FMSDY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1639,'01KTF88BZE8Q7BYQV76HHR1E8Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1640,'01KTF88BZGQJETDRMAJCRG8HBA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1641,'01KTF88BZHYK13M075NY756T52',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1642,'01KTF88BZK0HRYPA042Q1V7JKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1643,'01KTF88BZMEEJT0QMX4H36WYQN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1644,'01KTF88BZPQFB8HD4CWNF9PYZ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1645,'01KTF88BZQ5W5BXTE0PPZHRRZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1646,'01KTF88BZQ18QQ8JB6Y4MDRAGM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1647,'01KTF88BZSNR89M6B3TJSV756Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1648,'01KTF88BZT9QNRSYA3DTC3ZFSW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1649,'01KTF88BZVF7B7BE06FNAGWZNK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1650,'01KTF88BZXV7N3T3HZ30P0XT7Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1651,'01KTF88BZYXXX2CNK8KXZPCRDE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1652,'01KTF88BZZM01HTPQ0QZ635C57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1653,'01KTF88C009GJKKN4BW62PJHCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1654,'01KTF88C01W1ZHPACSKD2BGWRX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1655,'01KTF88C03K43MR4705WMD7MYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1656,'01KTF88C04KZ64TJNASMC2SQT5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1657,'01KTF88C064YKQEMQPBTAPNJTV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1658,'01KTF88C070B4HF5DP3FA5KZPK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1659,'01KTF88C099ZTNAHCDDR14RNWX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1660,'01KTF88C0ARPEVXM24DVX89V3J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1661,'01KTF88C0C57V725TEHN0RKB7Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1662,'01KTF88C0CCGCDKAVCG5ND6FFV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1663,'01KTF88C0E3BKYYA7GADTD9EFM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1664,'01KTF88C0FAKW8G2AAD17HM3DW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1665,'01KTF88C0HSN8A87VJ5KV36ZAZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1666,'01KTF88C0JMJFY3Q7AENDY0SFA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1667,'01KTF88C0MRM33297KE84K25G8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1668,'01KTF88C0NHQEDG7YED8JN52JB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1669,'01KTF88C0Q0RXW0YJA0EKRPSYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1670,'01KTF88C0RR1E1KXHK0XB8V075',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1671,'01KTF88C0TX2XEA4HXVWVBZQQN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1672,'01KTF88C0VT4PFQ0RSDB7PAK09',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1673,'01KTF88C0W8N2452270R70NVY8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1674,'01KTF88C0X3GB393HPMATZTHT5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1675,'01KTF88C0ZTD55TAMXS34XESA3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1676,'01KTF88C0ZQ15WY5SVFXFCAFF7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1677,'01KTF88C113JEQZ75FDA5W8RBE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1678,'01KTF88C13BAAG3RSDZTMP6VW6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1679,'01KTF88C14RY1R8PVPKKGASXRX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1680,'01KTF88C15M2TSSRDHWJNHPSNP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1681,'01KTF88C16DA5YXE4P6815PZJS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1682,'01KTF88C18M2Y2PZGP2025V9F8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1683,'01KTF88C19T5YSHNDN5C5GR80R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1684,'01KTF88C1A9MG43NB98W22KWF9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1685,'01KTF88C1BTTS6WT9JM8V1C9JR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1686,'01KTF88C1DG3KNC1W1XF7VB7A7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1687,'01KTF88C1FVVCJNC6C1TFMA47B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1688,'01KTF88C1GM2XBYZDK06ZWQNZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1689,'01KTF88C1GB6CE623VWTJF9KBJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1690,'01KTF88C1HS91QES16B4K39ZZX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1691,'01KTF88C1KYBTHKBE993ZCM7RR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1692,'01KTF88C1MMWQABW2MAZNV9QFS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1693,'01KTF88C1NQ7FMSBCGNH62CB74',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1694,'01KTF88C1P0GGYM2EMGGKVX9T3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1695,'01KTF88C1R58CYYDT9TE61GQVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1696,'01KTF88C1SKFWSG96RSMSG9T0E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1697,'01KTF88C1VJ189FVCRTRSCK2GN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1698,'01KTF88C1WVSPWSGC28E87JZ5G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1699,'01KTF88C1XWCMXHX1BNHM73Q9X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1700,'01KTF88C1Y88Y1E336ZYV51A1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1701,'01KTF88C20WMFMTAKTQ8RGNXGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1702,'01KTF88C22X49JY97NASNBZ08R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1703,'01KTF88C23ZMC7YSMH1XX305Z2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1704,'01KTF88C25DJT5R9PFTQ73EAEA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1705,'01KTF88C28W94C22T6PZ09PW0T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1706,'01KTF88C28FFE4YDFW1270HPX5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1707,'01KTF88C2ASHD043MF9R7GG1VC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1708,'01KTF88C2BB6PE4XVC06V6N9W9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1709,'01KTF88C2E61MW5448SSE0D7G9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1710,'01KTF88C2EK7BV4ZJ0WVF6N7J6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1711,'01KTF88C2HPR55S26YPCFMMVCG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1712,'01KTF88C2HYZ3ERPBMNCQDTQ0T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1713,'01KTF88C2K94THBS6NRGRRFY04',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1714,'01KTF88C2M2A4YFK3P3ETNQN1N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1715,'01KTF88C2NZ134809SN5CE0KGJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1716,'01KTF88C2Q7KJWPZCE21T8VVEV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1717,'01KTF88C2RD2X39GVWV2S8AMV6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1718,'01KTF88C2SSBTM8PN0ZT5YN9J6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1719,'01KTF88C2VRMN5MRJTNNX7FWCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1720,'01KTF88C2WB8KB3H5DV4HFZFWY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1721,'01KTF88C2X3ZNYVM66GTCVM13S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1722,'01KTF88C2Z966GZ7PN7QVK97SE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1723,'01KTF88C30WNCXC4VCAAKT8JA6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1724,'01KTF88C31BB4S56E1QPXWF506',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1725,'01KTF88C32EEP3JVEEHQC4BG1K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1726,'01KTF88C33JRKGJHZHJK24CWN9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1727,'01KTF88C35V4DN18JDCZMYAG3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1728,'01KTF88C35HR92EM2GAZA43G1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1729,'01KTF88C373HCNQ6NFEE3BDWA5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1730,'01KTF88C39XSPF58EAJWTHX0AW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1731,'01KTF88C3A10FBDVM25NHG239F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1732,'01KTF88C3BHHGMEK7G9G58T5DY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1733,'01KTF88C3CFCRFVD7CHTNAGQ5M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1734,'01KTF88C3EV19EFPEDETDB39Y9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1735,'01KTF88C3FF65VT5HZ1YQ46QGY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1736,'01KTF88C3GS8M17FMJBFESQ591',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1737,'01KTF88C3HG0VFQBJ2SNDDX6D4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1738,'01KTF88C3KM0CVVEDRFEMKAD1X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1739,'01KTF88C3K4RDHEXZQJF1X510K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1740,'01KTF88C3NF0QYA7922H8746VJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1741,'01KTF88C3PPM5BH4DHD64FJE3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1742,'01KTF88C3QE74HXVX2NMGP6T6Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1743,'01KTF88C3SEHF6GNJ7VDE0P9E5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1744,'01KTF88C3T8XD0XZ15V2RKGAYW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1745,'01KTF88C3VTG8RRQFDH63648WM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1746,'01KTF88C3WZ1YFAXGE1B6KXEX2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1747,'01KTF88C3YM9FVDMXYDTG3CCYY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1748,'01KTF88C3ZWBT1H83DXDNT8FW0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1749,'01KTF88C40N628QT8QPXHX04TD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1750,'01KTF88C425TSE3FA8XMATAF00',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1751,'01KTF88C43JKP3Z4RMY406CXNF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1752,'01KTF88C444BVBV8BAWMEXZKJE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1753,'01KTF88C460Y5BT4WAGKSRAEJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1754,'01KTF88C487RDHSN52W5APZ99S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1755,'01KTF88C48CK9DTNKV7HGBHHFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1756,'01KTF88C492G7KK7Q4NC9RD1XH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1757,'01KTF88C4A26YRVXMKCP3ZFZ5F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1758,'01KTF88C4CNC8PPF50C63S6WYW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1759,'01KTF88C4DAM9QVNPEK1M1VJH1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1760,'01KTF88C4FRAAT5TPZCAE3BHFZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1761,'01KTF88C4G192H11S38W8BVSMQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1762,'01KTF88C4HYGPR4GW9AYX45CB0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1763,'01KTF88C4JC6K29XFQT2ZMBBNE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1764,'01KTF88C4MEHCEAYE7J7WPYN9K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1765,'01KTF88C4NME3ZMT6T2S9F8201',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1766,'01KTF88C4P4Q7T94TGTTYWKDH0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1767,'01KTF88C4QNJCA62X7225S7SB1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1768,'01KTF88C4S9V6GW2W2RCNR8VX4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1769,'01KTF88C4TFDRYHG4GYJAKP1YT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1770,'01KTF88C4WPZHB9ZM6YW13S1E9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1771,'01KTF88C4XR2QAQVMNE8MTM7WN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1772,'01KTF88C4YQ9YKF886ZMKAWB1Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1773,'01KTF88C50QK38S0TA0CZZH7NY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1774,'01KTF88C51W3C3QJVMZ7PMT0NX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1775,'01KTF88C528RQ5N2KG3AM7Z8HA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1776,'01KTF88C5412MAP0SJBGZPG30X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1777,'01KTF88C55YR60KK3MV11E8CPT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1778,'01KTF88C579J0DV2WG56846VTH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1779,'01KTF88C58MF8MJN2WW7S1AWDK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1780,'01KTF88C5AZYK8DE0VZGK8QF7H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1781,'01KTF88C5B00EWYP5VXSPWZTQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1782,'01KTF88C5BV4PJWC46SRGMRMVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1783,'01KTF88C5D3Q1XVCJ10MCKDNEN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1784,'01KTF88C5EZ7NASN7J6W5CH6C9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1785,'01KTF88C5GV8CBCHVE1TR5ZAS8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1786,'01KTF88C5HCTERZDHM5CT932WX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1787,'01KTF88C5JNBMMY1MMXGF2PZRH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1788,'01KTF88C5KJJ90AK9QZT5V6X0G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1789,'01KTF88C5PJJKXXY2ASKFDGT3W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1790,'01KTF88C5PXNA6HRSTB67XEBJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1791,'01KTF88C5RSMHADHYQFZXG5HMV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1792,'01KTF88C5SH3VNSNZ65CA14BAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1793,'01KTF88C5TGDHMXQ85VMYAEKJ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1794,'01KTF88C5VGRX0Y8CQV4J0E999',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1795,'01KTF88C5XEB5Q5QZWSCBTVTVN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1796,'01KTF88C5Y7C56H5AM4ET01K9A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1797,'01KTF88C5ZDNCDCDVSN42GRGXT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1798,'01KTF88C61H112H7QQCJDK3959',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1799,'01KTF88C62ZHZ3QY2D1TSWYPF7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1800,'01KTF88C63J4TAPSN68YM0JHFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1801,'01KTF88C648R4WNG463XBJ9X53',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1802,'01KTF88C66JVH7XSB48BDS8P6X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1803,'01KTF88C681XJVTN5F7KV5BD4M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1804,'01KTF88C69ZSH2QJW958GPB44K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1805,'01KTF88C6BCB7V2SC1YYZZ05Z9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1806,'01KTF88C6CJWVQEZVDQPHCTZB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1807,'01KTF88C6EPCW4ED56PRBNFER4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1808,'01KTF88C6FSH95Q939EDX2J0JD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1809,'01KTF88C6GG7VA4T4VK12T2CKY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1810,'01KTF88C6H5HX3CNW0WH05BSR6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1811,'01KTF88C6JC8J4WTT5FPFHM4FS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1812,'01KTF88C6MF8TSFPFTQRZSH475',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1813,'01KTF88C6NCE4NXJFS6WHRJPD8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1814,'01KTF88C6QF0R1WXH36AXCJ3R3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1815,'01KTF88C6RZ3F09R6C6CNWJ4B7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1816,'01KTF88C6SE66K3G5CZCGT8QF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1817,'01KTF88C6T5Y89H0CKX6C1DE80',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1818,'01KTF88C6WY2G3WQNM2DVKKKQ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1819,'01KTF88C6XE9Q8MFBWH9K7E8MY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1820,'01KTF88C6YZW6BQMW7CCFE0HXZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1821,'01KTF88C6ZNGY76M987CS2N935',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1822,'01KTF88C7160QVSH248VBCR7SV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1823,'01KTF88C72C18PNGJV61Z4E2AB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1824,'01KTF88C74ZEYT7Z072198WYVF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1825,'01KTF88C740X1PCR26Y046WX50',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1826,'01KTF88C76NFSBR3Y07KV77XWA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1827,'01KTF88C77ZQHBME3RWG3476C2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1828,'01KTF88C79JYK9T75Y3T1B7C88',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1829,'01KTF88C7AM8YQ4ARE357GV860',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1830,'01KTF88C7B0WNKXWA66V4ZA460',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1831,'01KTF88C7CQV29C0ZKB18HZ6RC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1832,'01KTF88C7EBNJ364A02M7Q4BH8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1833,'01KTF88C7FER39PBH6NRACV1C8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1834,'01KTF88C7G8021JQZNK30DY0X4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1835,'01KTF88C7HPSVD4NSX27KQERWT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1836,'01KTF88C7K0S9GH9FVXQHG0RQC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1837,'01KTF88C7MPNRC3B7DMPPQQ94Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1838,'01KTF88C7N7SFB31RYJ10PFZCG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1839,'01KTF88C7PJQKTQ09CBTYJHB5N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1840,'01KTF88C7RAFMF45YYG71YXH9P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1841,'01KTF88C7SYF5H02W20KKK1V91',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1842,'01KTF88C7TSXVV5PDYX4HQZQCQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1843,'01KTF88C7VS2Z4DYM8QJNHX4YC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1844,'01KTF88C7XX1C0654GZZ4X4SEC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1845,'01KTF88C7YV03YVRRBGKBATZ11',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1846,'01KTF88C7ZJTJZPJC03DZT3CS2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1847,'01KTF88C80ST4A6M92VN5VBS72',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1848,'01KTF88C814X9SSGYZDPY404CR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1849,'01KTF88C8257MSVNMJGCH449J6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1850,'01KTF88C849H8Z4BF775GNQ7Y1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1851,'01KTF88C85ZBT9C0R2EQVCMC8D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1852,'01KTF88C87WK4KBQMWTB3T2K67',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1853,'01KTF88C881HVADC6ZBCY0H6AT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1854,'01KTF88C8A42FXM1JR9AABP5HD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1855,'01KTF88C8BNTYT1B00YST5M48E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1856,'01KTF88C8C4XC60JSK8Y1A9ACT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1857,'01KTF88C8DYFBY0AQ80HNBF18K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1858,'01KTF88C8FKMNK8QTPMTFHBWEG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1859,'01KTF88C8GZ23K04Y4TSX8SKD7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1860,'01KTF88C8HTD8DDAYTNNEBKJH1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1861,'01KTF88C8JSCM7P395YF4ZJTNB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1862,'01KTF88C8M0RBF48HDDNGD7G7G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1863,'01KTF88C8N3BY6DCM76A89GZRE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1864,'01KTF88C8QZ75N1EVKXW0AVQGD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1865,'01KTF88C8RV46TNJ18SCYA49AA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1866,'01KTF88C8SSP4EGPYTTP6TYZAJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1867,'01KTF88C8T71KPRF480ZGJ255K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1868,'01KTF88C8V8EZPJJJ7DH9QB3ND',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1869,'01KTF88C8XQJ834JS00F8A4SVB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1870,'01KTF88C8Y7AB98W5JZ0T6SK9W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:43','2026-06-06 19:58:43'),
(1871,'01KTF88C90FKWWGQXD3A2X0C4Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1872,'01KTF88C91HSA46THXT8P02S5H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1873,'01KTF88C919J5309WF7TSDSM82',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1874,'01KTF88C94AQ95N5GDGWMA37FH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1875,'01KTF88C957JH7J2VGJSDK6PCD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1876,'01KTF88C96Z5K9Y2HK067TR0PT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1877,'01KTF88C97M2QP3MFF7P7QKDDN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1878,'01KTF88C98M5Z42V1BEEWYBD99',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1879,'01KTF88C99JYHC0EYE02Y0NZQX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1880,'01KTF88C9BF4F4K62ZKRVE72AH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1881,'01KTF88C9C1GG1YNGC145FVWST',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1882,'01KTF88C9EB0GD5A0YZX1TBK2A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1883,'01KTF88C9FTYVS98GX21E86A1S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1884,'01KTF88C9NVR3WME1A99P8Z3QB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1885,'01KTF88C9PQ4XDARAZ08C4XT24',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1886,'01KTF88C9P8XNFNPP5SDT0V656',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1887,'01KTF88C9QMAVYNZRK1FHY5X1Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1888,'01KTF88C9R1CPZSBN9DR7R8N8Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1889,'01KTF88C9R7GHV4D8MJW9PSHAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1890,'01KTF88C9TB0JFG7AT3998VP0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1891,'01KTF88C9VKN1T0SQ4F4ZYBGG0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1892,'01KTF88C9Y790QPJN7AF3CKYJS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1893,'01KTF88C9YZZ45MGE0VW7CC1Z3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1894,'01KTF88CA0WWNW0A697582MFMC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1895,'01KTF88CA0JZX4S0ZD0SB7XRZN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1896,'01KTF88CA2FY42VAYEFX8CR44J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1897,'01KTF88CA33V7F7D4J4AE9X3DG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1898,'01KTF88CA5GBSX66N7PXAQ9D9X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1899,'01KTF88CA6FNZJQ329ZEPZ11CH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1900,'01KTF88CA7DRS03E8T09JZW0H9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1901,'01KTF88CA9VFXZQ68MTH33N3WF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1902,'01KTF88CAAC0HGG46HSR0XK1PH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1903,'01KTF88CABHVY3Y2Z121FMRN2V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1904,'01KTF88CACNT5PKQ8KYJ50QAP0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1905,'01KTF88CAD1JDKG0S9A0EESC4B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1906,'01KTF88CAF78XD30EYH46JQMX7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1907,'01KTF88CAGDD5Z2F1G4PKH98HM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1908,'01KTF88CAJT93DKJ998D8TBZHW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1909,'01KTF88CAJZ3Z04AZB8T55JJ8K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1910,'01KTF88CAM6NFR35XX6E8ZBWAW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1911,'01KTF88CAN7V5CCXGW7XA4B126',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1912,'01KTF88CAQ2JK1WXYDGAXM7DTY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1913,'01KTF88CAR42G5761QY9F7Z0PR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1914,'01KTF88CAT2QM0PXYKA19NRRT6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1915,'01KTF88CAVECAVC7M0RZYTSBJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1916,'01KTF88CAX3M4BT2EA55DGMRSB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1917,'01KTF88CAYQW0M67R2TS4PQ06Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1918,'01KTF88CB0XNCR3NRA7YV4HND7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1919,'01KTF88CB1KH1DEKTY92NH1J87',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1920,'01KTF88CB3A5S70AGMBE3KTT1R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1921,'01KTF88CB4X8XQ4P8A7XQFVEV5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1922,'01KTF88CB6TT71Z5QYMQR2QZPP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1923,'01KTF88CB706NEZEPMVDNVWZM1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1924,'01KTF88CB845H46MMAV7SMCYE5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1925,'01KTF88CB93HDAJ29BTQVWZZ7R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1926,'01KTF88CBC0JMD08R0D2V3AH5T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1927,'01KTF88CBCZME1GJXTZERBXE60',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1928,'01KTF88CBEFY7R4187NHSGDFMB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1929,'01KTF88CBF9FP3NDTF1Y60RBMP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1930,'01KTF88CBHPCGFR15JE47RV8HM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1931,'01KTF88CBJ7MW0J2508QYQMV5N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1932,'01KTF88CBKRMQW9KK4VN3RKNS4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1933,'01KTF88CBM6NXTQWB7GG89EXFV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1934,'01KTF88CBP7BN4SYR6YDT4PJ7S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1935,'01KTF88CBR21N3BGCW185PJENZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1936,'01KTF88CBS7KMXD1HXG494JJ0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1937,'01KTF88CBT5QT9XY9PNQQ1J2NP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1938,'01KTF88CBV52QFK6RCREJ81YWZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1939,'01KTF88CBW6A57Z9QVKCTM7AN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1940,'01KTF88CBZG3C0J5BXZ4W4S67J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1941,'01KTF88CBZQC5XN5RZQZ8QQWXM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1942,'01KTF88CC1YRSCF2DAM9TF1S46',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1943,'01KTF88CC3NKZ8C69AP554KMC8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1944,'01KTF88CC46A9WJBXVVRMA1C0G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1945,'01KTF88CC697XM3NCASYGSWPF0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1946,'01KTF88CC6RWD16865RG38K60G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1947,'01KTF88CC8WT3BHF62PR5ETGEJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1948,'01KTF88CCBJR0SKJ7B9WY1ZR9C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1949,'01KTF88CCA7X4VR9QSQ1AEJNNA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1950,'01KTF88CCC6JXRZGSC3KEFDFKT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1951,'01KTF88CCDHYTYF0R7T0JH138E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1952,'01KTF88CCECXMHJE5A2RH66XJ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1953,'01KTF88CCGKFY638S6QXP8R23Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1954,'01KTF88CCHQ78NZBZWTMPA9MQG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1955,'01KTF88CCK1JWA8ZM58TK8AQCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1956,'01KTF88CCMP8KYB8MYZE64J2RJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1957,'01KTF88CCMCH3VQSPXD890SB6H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1958,'01KTF88CCP18XZN3EMJXN002F4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1959,'01KTF88CCQ3CJ86FGJC3R3PHGA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1960,'01KTF88CCRYMJ9HGDYH9KZMPJ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1961,'01KTF88CCT44NV2J8X700WGTN6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1962,'01KTF88CCVFTG79643VNCSJT9S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1963,'01KTF88CCXQ1SFQ0830FP40JQM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1964,'01KTF88CCX9EKN2Q53GW6G1EC0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1965,'01KTF88CCZVJY7DP7E8DKVCKB2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1966,'01KTF88CD0QWY75YNP12YSHSQ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1967,'01KTF88CD239APQ7P4JFKEKMAK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1968,'01KTF88CD33FNSHNV3W64P670B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1969,'01KTF88CD4M6T1NJTKCHPW7KHT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1970,'01KTF88CD5C57TXM4DJEZDRV34',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1971,'01KTF88CD71AP7TR68Y110QZCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1972,'01KTF88CD8Z4VPMHAAFNVNHSRY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1973,'01KTF88CD941S7YFXDVM8A6GWR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1974,'01KTF88CDB7GR6A3ZN5AWA7CDM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1975,'01KTF88CDC7Z54Q1SM45FMT27V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1976,'01KTF88CDD1DCRT9BVH50ZD759',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1977,'01KTF88CDEJVRKG808ZB2VYZEW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1978,'01KTF88CDGQSH2MR3Z9SMQ2B84',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1979,'01KTF88CDH4HEJQR8C266SB1PX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1980,'01KTF88CDJSD7YFJ5QHCYTHZG8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1981,'01KTF88CDMF2JWSPPW82K9TEV9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1982,'01KTF88CDNK718BT6G77746X95',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1983,'01KTF88CDPCANJE98K8XJ4NGJN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1984,'01KTF88CDR15ZGNHQG7WPKB19G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1985,'01KTF88CDSXTV22TTVACHK1FWH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1986,'01KTF88CDTGS6AKW9J3GY8JVEX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1987,'01KTF88CDWQZE450CMBSRQJYYS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1988,'01KTF88CDX07RAMWECVQ0TS33Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1989,'01KTF88CDY17J1B2MRV731TN8J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1990,'01KTF88CDZP33CFV06K1BT6HJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1991,'01KTF88CE0NDP55D9S2D8K2R98',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1992,'01KTF88CE2H9KHXS3DGXBA0MY0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1993,'01KTF88CE3FJS3TPGJFFV6GJR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1994,'01KTF88CE5MVZ61RPJQV57EKZH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1995,'01KTF88CE64C1PTKVMVPQNF1R1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1996,'01KTF88CE7KZ0W8JYMF0Y12NH9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1997,'01KTF88CE82J4ZEJZKCDREJAKW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1998,'01KTF88CEAHW26WTATQ3329VNF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(1999,'01KTF88CEB2R5YA10PRH9WSFAW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2000,'01KTF88CED6696BSWQ57NZ5EQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2001,'01KTF88CEEW5MTJ72KMXEPPF68',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2002,'01KTF88CEFA53NC5G5XBVW8DGD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2003,'01KTF88CEGM85P6W2TKYWFBC0K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2004,'01KTF88CEJ1VXT6M70HX3K0JS3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2005,'01KTF88CEJ3B9V6V9K963KX0SD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2006,'01KTF88CEMDFDTCFXCBWX8BPK5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2007,'01KTF88CENVHTK0SXRV59E49KK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2008,'01KTF88CEP7C5TZ4KJZHKC1GPE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2009,'01KTF88CEQDQWPNH2VHD516JV2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2010,'01KTF88CESY946HGT3SEXBW7GT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2011,'01KTF88CET74TA3TDFDR1BJWEM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2012,'01KTF88CEVCDMSD2DNFCM49BQN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2013,'01KTF88CEXVH7HBSAPEQ55V6PV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2014,'01KTF88CEYZJ5N2SKBV1HJP18F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2015,'01KTF88CF026GWCY61QMXD2K9K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2016,'01KTF88CF0JGCVRZZZMG375500',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2017,'01KTF88CF1BK44BK7YJ2J6YPA6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2018,'01KTF88CF479XD8453N2NB3MWM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2019,'01KTF88CF4M447VS8FD2AC6CGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2020,'01KTF88CF7Z9D9V46N05XMG7G0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2021,'01KTF88CF8HHG1EB8VAA89QTBJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2022,'01KTF88CF8R25K93E8JE9DG3Z6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2023,'01KTF88CFAF5JWEGGBV4C5C5R2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2024,'01KTF88CFCJ3VVSXG5FXECMZZ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2025,'01KTF88CFCFPVHXE7Y7QDPCS4E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2026,'01KTF88CFERN38KGNQQ0PX0AG4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2027,'01KTF88CFFF9RDT9GKKCZ08Z6A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2028,'01KTF88CFGZJAPAF3N7AR7J7ZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2029,'01KTF88CFHHZ4F17TD4808HBKD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2030,'01KTF88CFKP97YM7EF0Z6T5666',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2031,'01KTF88CFMY962E5QFPD2GFR0G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2032,'01KTF88CFPQHRVVTSBQ3DX7PYF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2033,'01KTF88CFQCT4ZGSPBZMPKEGKS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2034,'01KTF88CFRA2ZG5Q5DHG5XHKPM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2035,'01KTF88CFTDEGKDYV4EWJVF9GM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2036,'01KTF88CFVW7HEBT31A9SN2250',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2037,'01KTF88CFWMEFH7PS28AN56C57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2038,'01KTF88CFXD17GMNC3CCV2490B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2039,'01KTF88CFZF522RHJY1CHS2R21',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2040,'01KTF88CFZYWG00B3YDCT9BWMT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2041,'01KTF88CG2NN3MSKN6QT0HSXDN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2042,'01KTF88CG3JAXFGZ9VCB2JXWM2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2043,'01KTF88CG4BJEGTG709EXXPP57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2044,'01KTF88CG50P78FBWH08A089MV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2045,'01KTF88CG8BXMCCAC8EHQCDV7H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2046,'01KTF88CG8AJ11G46P0W28J6T9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2047,'01KTF88CG9DCGZ58ZYPBWX61VC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2048,'01KTF88CGBYTP45K8VK3DSE7RY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2049,'01KTF88CGC73QDJ78ZVCS4GN7Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2050,'01KTF88CGDQYR8FYTXBZ3KMM7V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2051,'01KTF88CGE1EW16HZSZ02KN95X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2052,'01KTF88CGFY8F0GR9FSJKW5KM0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2053,'01KTF88CGHVKVXV8DW3S15V9ZD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2054,'01KTF88CGJ3JBXM1JVX6RBWANE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2055,'01KTF88CGKEN57B9XM5M7DGRGB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2056,'01KTF88CGN24JF4WADGJMVKNX6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2057,'01KTF88CGPYFSVHCAFMJ7FV0ZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2058,'01KTF88CGQA8Z894D76HZ12G3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2059,'01KTF88CGSZ68V345DZT6MP961',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2060,'01KTF88CGT1S3WZYSY9SKTNA0H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2061,'01KTF88CGV7FZ8H8GR1AYG1CJ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2062,'01KTF88CGX2DGHJKYF11WCVJKM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2063,'01KTF88CGXE1VVW1ENAXSZNMRB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2064,'01KTF88CGZS03T0B3A2ZTMQHHD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2065,'01KTF88CH06H9GEMXR7YHEF4BB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2066,'01KTF88CH2ET9XW2C1DBTH8NRF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2067,'01KTF88CH36E9KQ6FM7KDE1AG5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2068,'01KTF88CH42C8FPF83PJERDJ7Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2069,'01KTF88CH64GEVCKF0FT60E3JM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2070,'01KTF88CH7RR7P6XV3VYCF4BJ5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2071,'01KTF88CH90WGXYP93XFZ6T1YD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2072,'01KTF88CHBXY8E4GV0TRVRJYGX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2073,'01KTF88CHBQSZ7CXAJAQQ4618A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2074,'01KTF88CHDSG7QAYR78TNEKNMP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2075,'01KTF88CHESW4V9FWY81QFGRZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2076,'01KTF88CHFYXDF3GQAKMVEX5YY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2077,'01KTF88CHHYBFQ9NMY6CA7AG4V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2078,'01KTF88CHJMD3ZDNNP60HEN9Y8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2079,'01KTF88CHKD1MH16M2XW6C0M3Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2080,'01KTF88CHNF30DJCPAYKCA766J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2081,'01KTF88CHPEV6SZF0WCDHFZRN0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2082,'01KTF88CHQGWSTR8PYQJ25Y0N6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2083,'01KTF88CHR6R46J1W2MPC9FFG9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2084,'01KTF88CHSYYG8HXFB3JC5V9ZE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2085,'01KTF88CHVTMGH32F3NZN8P5WK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2086,'01KTF88CHW4DAWJ5FYJ1T1TE2X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2087,'01KTF88CHX6KH56YPN4NRVC8YA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2088,'01KTF88CHZAPXX6TXF8BKSPK30',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2089,'01KTF88CJ02GPBBY8M7B8PN3N6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2090,'01KTF88CJ1PAS1JF8Y6PPMXQ2E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2091,'01KTF88CJ3AK5YWDXHC3CXCPJP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2092,'01KTF88CJ4694VJ4G9A3RTKTDE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2093,'01KTF88CJ6Y4FERDTY69KAWNSN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2094,'01KTF88CJ7MZ8NYMYJYXKW5CRJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2095,'01KTF88CJ9NJG8321CXFVV83EY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2096,'01KTF88CJA75NJYJWHAB2F0ASK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2097,'01KTF88CJC3C5T1GT5DR78PTY1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2098,'01KTF88CJDS8YVEXDWPQ8M6AXG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2099,'01KTF88CJEWYD9CCP8FXQZVZKS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2100,'01KTF88CJGG35QGRB2RX8FNEKG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2101,'01KTF88CJG2EMWDRXF1A507773',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2102,'01KTF88CJJ11EM645R85P69E56',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2103,'01KTF88CJKFRN1WVQ1H8NCSGZS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2104,'01KTF88CJMFW6Q01RYP9GQ1CT8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2105,'01KTF88CJPKKXFKRB7BWFQ80JF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2106,'01KTF88CJQ6TRMEJ5837XPX2AW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2107,'01KTF88CJR80Q6QNG8BZ0GV41S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2108,'01KTF88CJTK20RCVWQ1XXT87BQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2109,'01KTF88CJV8X7G2MTE13Q10D8Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2110,'01KTF88CJWH9R1HDJXCTBBBB4E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2111,'01KTF88CJY704H1DEHH6EWXJSR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2112,'01KTF88CJYFE3WG2D5EBC62AEV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2113,'01KTF88CK0XE6R5K8XXYE3QWGZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2114,'01KTF88CK10Z8KCK09H3KHTRPV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2115,'01KTF88CK23KW6F1KD11Y18P5R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2116,'01KTF88CK42NNQQ33GY7DR3CKR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2117,'01KTF88CK5EQ7D1SNQ41DE92GZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2118,'01KTF88CK756JP7VM22DY9H0XC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2119,'01KTF88CK8N5X8XRH1Q3GZ5MPC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2120,'01KTF88CK9NNQP7JGQ4PTQ4XEB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2121,'01KTF88CKA543NYE5227FAXGRX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2122,'01KTF88CKCJH6YHT1DGHW1QCHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2123,'01KTF88CKDQZD6HZ4CJQATCFN3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2124,'01KTF88CKF5KF54KJX5C6VMKC9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2125,'01KTF88CKGBF7XKYPAZKSW7JEW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2126,'01KTF88CKH3EEWFRZ0EPENJQSN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2127,'01KTF88CKKDS3V727KWTX7FRY8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2128,'01KTF88CKN3HJ15A9R4S6YGFFS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2129,'01KTF88CKNVC6HQHDKEQP1XTQR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2130,'01KTF88CKPFZC1XDBQA1EP69QN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2131,'01KTF88CKRFHKNZWDSMXC8XW1S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2132,'01KTF88CKSTFP0YJBZ3TM59YYX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2133,'01KTF88CKVFB39C4PNRXK5PE1E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2134,'01KTF88CKWEN77ZNPW58ZNQMYS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2135,'01KTF88CKX4XTYXVSH2QDGAPP9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2136,'01KTF88CKYJD8DCFNHDJDB14QB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2137,'01KTF88CKZR834NQR908XRR6TH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2138,'01KTF88CM17ERCGGSC1AQRR28K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2139,'01KTF88CM2Q84E09JS5KN1MGZD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2140,'01KTF88CM35YKQE7A4WBQB4D7P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2141,'01KTF88CM5M2QR7ETQ03SQXV5K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2142,'01KTF88CM6BE5W1HBME11CFZHK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2143,'01KTF88CM8H6BRRCCCNGYCTKQ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2144,'01KTF88CM9T0YR47TD2PDQAZ48',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2145,'01KTF88CMA2WEABVN2WM8TJ3MH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2146,'01KTF88CMBEQW5B0CVHR7DT8P7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2147,'01KTF88CMDVZ22BGRDKW1BJZ36',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2148,'01KTF88CMEXVV2S65MBGK1NXYB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2149,'01KTF88CMGETHXQVBXBM7CJNMN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2150,'01KTF88CMHRQJTRK1A83095CAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2151,'01KTF88CMJVE3TAYFERVJ0XJ2N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2152,'01KTF88CMKEZFQ8A6G3GBJMYRW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2153,'01KTF88CMMXKW5YY6GSE5YS7PN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2154,'01KTF88CMPS065Y1V3TQ41CEZW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2155,'01KTF88CMPYGSQTS095CBC0388',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2156,'01KTF88CMRBJ3SJAQ8HX738KBW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2157,'01KTF88CMT1QT2DN9MQBBQ5KFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2158,'01KTF88CMV6GHAE12CBM22GSJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2159,'01KTF88CMWPAEZ97KP78J140CN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2160,'01KTF88CMX8EFE0WQ62J5TEBZ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2161,'01KTF88CMZ0JW35RMRH1FBJR36',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2162,'01KTF88CN0JKNZD03ZTHFSBQ1C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2163,'01KTF88CN2H71N0BNGJHR342TF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2164,'01KTF88CN3JQ5GB7T6SR761FD9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2165,'01KTF88CN4XY3HGEV2ZVSCYN91',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2166,'01KTF88CN68XM44FE5V1NKQ5DR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2167,'01KTF88CN7MR52SW7YQRES0TFP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2168,'01KTF88CN943VHE5D5B3R78D4H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2169,'01KTF88CNA1JGYF1SFDAACCC7V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2170,'01KTF88CNCD5TD4A9ZP3ZQXJVB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2171,'01KTF88CNDG92T1V5P5HSGT8HP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2172,'01KTF88CNE68Y0N6KE10XFBG5Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2173,'01KTF88CNGEGKD4N76G4WMAYKE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2174,'01KTF88CNHKRF1TS2CE3DA3EE5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2175,'01KTF88CNK0WES7WD2E125W3ZC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2176,'01KTF88CNMH50JFPMD12YNHN7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2177,'01KTF88CNNZJ3TYP4FFG8A0DCS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2178,'01KTF88CNPZ30MFE019TVD7EXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2179,'01KTF88CNQQAZMMTV2ZKSA1Z1Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2180,'01KTF88CNS6KHN73TF03P7CRDM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2181,'01KTF88CNTE7BDQYZ7ETEW1ADR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2182,'01KTF88CNVN9EM0P06DC3FSPDQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2183,'01KTF88CNX5B08ZRP02XDFDXSX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2184,'01KTF88CNX6DT12KCFM9ZZ4MNA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2185,'01KTF88CNY160NCH326F9FEEQT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2186,'01KTF88CNZD6ZS0K3EE3AWMYRG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2187,'01KTF88CP1BAW343FTE4ZHBRXJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2188,'01KTF88CP271HRB2MH4QRS1RW3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2189,'01KTF88CP3VZYX235S4A9ZT3FX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2190,'01KTF88CP5SYEB1WTAWZS4ZW1K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2191,'01KTF88CP6708P6CYQSGPYRTSZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2192,'01KTF88CP7JKTTX4JMSWMZF2FE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2193,'01KTF88CP8F89VB4X7B1B2G5VE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2194,'01KTF88CP9MVPJ8RVZXGTX3ZNM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2195,'01KTF88CPBM8KD90BAFDHPVGPS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2196,'01KTF88CPCBVVF1S5FB3ZBDDX6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2197,'01KTF88CPD41HQVATH5N62RJGX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2198,'01KTF88CPFSK559B665EC2RE55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2199,'01KTF88CPF6HA6VSE9J5QR6KKW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2200,'01KTF88CPHFV3T0ZQP9Y7V1K0D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2201,'01KTF88CPHHHEKSHC5VX5P5SZ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2202,'01KTF88CPKH7YW2WYES4R9SDRD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2203,'01KTF88CPMWF7PQWHNH5EZYFP0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2204,'01KTF88CPPD8E1EA4R3KHKQXXY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2205,'01KTF88CPQN47TCDDYD00DF5YP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2206,'01KTF88CPRT4H1VNAW0JWN0XZK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2207,'01KTF88CPS42V2Z3ZYYSP1BEK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2208,'01KTF88CPVQD8GKPTHF21Z8Y94',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2209,'01KTF88CPWQTWX4K2SBNMYST77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2210,'01KTF88CPY0B546SHGM904WF83',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2211,'01KTF88CPZ89G9W3CH7Y95V7HK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2212,'01KTF88CQ0063RXYXH5WP7FM57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2213,'01KTF88CQ1NE8N92KMAYJGABAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2214,'01KTF88CQ37ZK10JGSH02QEEDW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2215,'01KTF88CQ5G5EFVQVE73Z8KTC2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2216,'01KTF88CQ56942FY9KP2YCMKD7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2217,'01KTF88CQ64GBAPEBRJVNN1N0X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2218,'01KTF88CQ9WG8RF64F4WBG5ZWD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2219,'01KTF88CQAV19S4TCCYQ583CAQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2220,'01KTF88CQBW8X7E77NF6FK9Y1T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2221,'01KTF88CQDFJTMRS4WR0G4BT53',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2222,'01KTF88CQDGWRZH2XPC1XWZPX7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2223,'01KTF88CQGXY7XR64N7K5Z86PA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2224,'01KTF88CQHJC55GBHJDR787650',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2225,'01KTF88CQJ3JGCFSK551922AJC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2226,'01KTF88CQK6N1GVTR42S6M4X7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2227,'01KTF88CQM764E2KB3114GW37V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2228,'01KTF88CQP6N4AK51EC6YXEMZX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2229,'01KTF88CQQATZ85XP9C7BCKFM2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2230,'01KTF88CQSYSZKRQHV897MH6FD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2231,'01KTF88CQT4KJ1SPHHE0BQFY7S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2232,'01KTF88CQV3DR0JPVFTJBYRFE7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2233,'01KTF88CQW2KGG6H0P6C9GZVXH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2234,'01KTF88CQY6R8NH6QE5C7AAK4W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2235,'01KTF88CQZGZCQ26NJ6MKN26HM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2236,'01KTF88CR1DCZ0QKDHX65CMVYX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2237,'01KTF88CR2AG993FDA2PS4EEKM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2238,'01KTF88CR3DTXF2TZXPB9BFGA9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2239,'01KTF88CR52K6SA6NABB46FBH3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2240,'01KTF88CR6H8ACNHPZTTKJY503',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2241,'01KTF88CR7NWMRENWZ6S9BGZ7K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2242,'01KTF88CR81YSNGBPHJA1XTNNK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2243,'01KTF88CRA4A404VJ1GWNQHPNC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2244,'01KTF88CRCY25WP3WZTX5W3RF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2245,'01KTF88CRCCSBCP6RR8KSBQC5V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2246,'01KTF88CRE3VTR4JFRGMMMH913',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2247,'01KTF88CRG0JVJC5S0D14PH7YY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2248,'01KTF88CRH1J2RZGR44NNX9FBY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2249,'01KTF88CRKVYY37YSN01C4RGSK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2250,'01KTF88CRMR2WKWFE9KKQ1WSAX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2251,'01KTF88CRP0T12FYVJBPAHG6W8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2252,'01KTF88CRQJQ7ZYK6SNMZVDRNC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2253,'01KTF88CRRX6HECPV3YXFRP7ER',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2254,'01KTF88CRSRAK38C9YRHXH7MBM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2255,'01KTF88CRVE7B17VW012J66X70',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2256,'01KTF88CRW8C1TE3TD55ZY2FA5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2257,'01KTF88CRX8BAFN4RKFT5FKQ0K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2258,'01KTF88CRYVGRFV31EV0HY9MNC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2259,'01KTF88CS093BQKW898S5QWY3K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2260,'01KTF88CS1YRVST8XERJH3G5KM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2261,'01KTF88CS3CGHSXZET1TWR110G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2262,'01KTF88CS4QW8SNY1W4TE59G4R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2263,'01KTF88CS57GFSCFGHE2YQ7917',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2264,'01KTF88CS7TB38TA12V6HB259V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2265,'01KTF88CS9D9VZQ33VH195DX38',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2266,'01KTF88CSAGSVS5SCY9HSDDXWF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2267,'01KTF88CSDZY7S20522WJJMT8M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2268,'01KTF88CSEY578ZPQPEGX9AMX2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2269,'01KTF88CSGKC4JFV0SGEKXACXX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2270,'01KTF88CSHKJ07JWX6QQ70VFVS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2271,'01KTF88CSKBWPH71WNAQRJ95A2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2272,'01KTF88CSNMJ5GV08DWFNWMFET',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2273,'01KTF88CSPEVKZYP03G2SBDAZ1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2274,'01KTF88CSPTJM9M1T5SM2W28JT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2275,'01KTF88CSRCN4PMPV9TK2VSDCE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2276,'01KTF88CSSTCYDXJVWQGPFAVS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2277,'01KTF88CSTN8M3N3YBKFZ3GG3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2278,'01KTF88CSW5HC1QYYS40K27KS8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2279,'01KTF88CSX5KQYCSY43ARYHSAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2280,'01KTF88CSX3QPGM76J55WDX1RN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2281,'01KTF88CSZAWVQSCTTQNKHSSQ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2282,'01KTF88CT06BBR06DPY6K709FH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2283,'01KTF88CT2YE9BEA5WMY5W4NAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2284,'01KTF88CT33KGPCZ2ZR13W4FNT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2285,'01KTF88CT45P2JY0AHMQ4Z9SDB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2286,'01KTF88CT6HAJSHY8WDNBAZZJ6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2287,'01KTF88CT7PZEPG71XAH6BDPVA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2288,'01KTF88CT8KK0EYD4ERGZCB4N7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2289,'01KTF88CTBN78WBEZH0KCG39VE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2290,'01KTF88CTBTS3KJM1BWV5MR8HP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2291,'01KTF88CTDNEB4B55WDB5YDGMG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2292,'01KTF88CTEFAT5XY4BFF12TYJQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2293,'01KTF88CTFHH8HCQ4TMVJPCEV3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2294,'01KTF88CTHKH7X06R58BB85GR9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2295,'01KTF88CTJ3MYY56GSAR1ZTE77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2296,'01KTF88CTKS1ZBVH1HQ6B0N5JA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2297,'01KTF88CTMV3V2DR24661F72RC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2298,'01KTF88CTNA628Q12EYGEJ8HS5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2299,'01KTF88CTQ327DFG2TQB9V7GDS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2300,'01KTF88CTRCRV11FK90S5SXA1W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2301,'01KTF88CTTYX5HSS5DJZMWREH3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2302,'01KTF88CTVRZE2RTBXKEFNAV64',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2303,'01KTF88CTWW5FMJDFQ6MN8MHAV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2304,'01KTF88CTYAKM1TJ3RP175GT96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2305,'01KTF88CTZGPX5GVFGC2Y463KK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2306,'01KTF88CV1FD8KFDH0HA4PE606',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2307,'01KTF88CV2EGS80KXA8X1EY92B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2308,'01KTF88CV3TXXKJPFWAHNW3PN8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2309,'01KTF88CV4PCYA5YFYJS4XH6CR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2310,'01KTF88CV6QT2ENYV4Y6R94DKG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2311,'01KTF88CV8TK5ESGN0Q5CJC23A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2312,'01KTF88CV9G0MJ2XF66PFTMH3N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2313,'01KTF88CV9DT13TFGNM2ZSPHJN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2314,'01KTF88CVB13NQHDGASZFBYGNR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2315,'01KTF88CVCCM6P4G55NZFR5E01',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2316,'01KTF88CVE1Z67QFZFXXZRPG5Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2317,'01KTF88CVFNDRBZ42Z5VPGBV0H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2318,'01KTF88CVGV54G48WJWX02DFMM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2319,'01KTF88CVHKNAAM9J7PHY02NM4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2320,'01KTF88CVKRB8W15B4RWBJZ586',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2321,'01KTF88CVM9KAH3GKCD01TK604',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2322,'01KTF88CVN7DBVZEFR5V3H5EDR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2323,'01KTF88CVQS7BK3RZBJMTFWE7A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2324,'01KTF88CVR7FQBEPP24KTFFDCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2325,'01KTF88CVS3TSB0028E9XB2J0J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2326,'01KTF88CVT0XXXK0W6D3YSDM77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2327,'01KTF88CVWJFEHXNBKX54YSK0F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2328,'01KTF88CVXEGJWM1Q6FX79QWB2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2329,'01KTF88CVZW12DC0PQBWGWFEAN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2330,'01KTF88CW0M7G1C8KRTZTP8CQQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2331,'01KTF88CW205GXX2967KCHSA44',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2332,'01KTF88CW3M27G4SF7VFMHPV1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2333,'01KTF88CW4G4D8N5BTFG5HEHP6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2334,'01KTF88CW6DGFEQ4EJNABX1ZY9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2335,'01KTF88CW78TJPDJRKDWNERD96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2336,'01KTF88CW96Y4D1FAYSNKPWSAK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2337,'01KTF88CWAMHHEYVQY8ZNZ0B67',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2338,'01KTF88CWBP6D81HR7VFG56ACP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2339,'01KTF88CWDD6A19WQ8HMQ1EGY5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2340,'01KTF88CWE95SGWVG62632F7YT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2341,'01KTF88CWF5D8BBV6AKE5CBK7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2342,'01KTF88CWGF9PYJ7MFCQPA4K90',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2343,'01KTF88CWHSX6DYJECKTS55Z1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2344,'01KTF88CWKAF0AZC7WRD5G1CTS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2345,'01KTF88CWMME3F4XE72QJSJYX6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2346,'01KTF88CWPEN3PMC9F108PCTW6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2347,'01KTF88CWPKH1ZQNPSSSAYRPQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2348,'01KTF88CWRQNNC5TRFB2QEN60X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2349,'01KTF88CWSAKFFNNAEJ9YQZ1XH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2350,'01KTF88CWTEZ6DR6452GPSVMG9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2351,'01KTF88CWWFS08ZNENDMF7NX7M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2352,'01KTF88CWXQ6CQHQCSXSPA130K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2353,'01KTF88CWZJQ54FT62SW2YNRC1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2354,'01KTF88CX003QZ35CP9AKGPFQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2355,'01KTF88CX11PK363412ENS2AWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2356,'01KTF88CX3BBA82ST1XKYAESNE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2357,'01KTF88CX45HQ1N9B8KEH5983C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2358,'01KTF88CX52YFKGMYWH9ER1VW1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2359,'01KTF88CX6Q80RPD0V6MCYR2NH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2360,'01KTF88CX7YEX8NCEEEV3HG1MZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2361,'01KTF88CX999C6Y8PE65BX4NT6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2362,'01KTF88CXA3XDCP1B6Z1AR4Y1Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2363,'01KTF88CXCWHE3A6XJ7D0DCV9W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2364,'01KTF88CXE7RYPCKCQ3P00FZQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2365,'01KTF88CXFPBSK54TS94MGW5MD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2366,'01KTF88CXHV3K7RJ790B0YT232',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2367,'01KTF88CXJW4MJKM758ACX6AEV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2368,'01KTF88CXJ8YFPSECYSBPA5A21',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2369,'01KTF88CXM6738FH4EJ44HBJF5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2370,'01KTF88CXN7EK10JNS9TCYZK7Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2371,'01KTF88CXQ3ERB6P3J5HXFMB73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2372,'01KTF88CXRH1HKC4DBMWA1CEXQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2373,'01KTF88CXT5XD8CDE1MB1CRYZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2374,'01KTF88CXTKBAZ9906RK5NF5QG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2375,'01KTF88CXVTQYEGDM6V9J62VND',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2376,'01KTF88CXXVDZEQVK3MGKYCSD7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2377,'01KTF88CXZYMJ0P9G1RZRSJ71G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2378,'01KTF88CY0N6PRY1V6NCSN1458',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2379,'01KTF88CY1TS9MDM4J7C0K5MT4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2380,'01KTF88CY293K72KBRC0RCRTSM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2381,'01KTF88CY410MRVHDPE9DZWTKH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2382,'01KTF88CY5S0Z1HS3H95HWPTRH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2383,'01KTF88CY6NTG1C2A0ASNWFD26',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2384,'01KTF88CY8VF1EQA4PEYKR65SN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2385,'01KTF88CY9HJCR8EYZTZFAR3TW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2386,'01KTF88CYBMK9W4KTGXQJJK0GR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2387,'01KTF88CYBZEHVB1W7WHRMRRFC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2388,'01KTF88CYDSX0W9W9J405A9VCZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2389,'01KTF88CYEGTYTPZ3M4A9YF9HZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2390,'01KTF88CYF0XEVH5YY9FT8FVWJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2391,'01KTF88CYHCE5TMJDP6KQXC3WN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2392,'01KTF88CYK7DHSJVSVAPPDXBWT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2393,'01KTF88CYKYJVJMCFN8AKGC72Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2394,'01KTF88CYNJ954CTTF7M7Q99MT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2395,'01KTF88CYQHWVHDJ5HK8CXC38G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2396,'01KTF88CYQY7PW6V6C69AH65R2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2397,'01KTF88CYSVBRGN5PZAY9JZAJV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2398,'01KTF88CYVPTJ6AA5CZGP866T2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2399,'01KTF88CYV2XP1GTJ7W3V22SHK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2400,'01KTF88CYX8B2JQAW297PHZ5JH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2401,'01KTF88CYY6SE5B0FWMF6Q8M6Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2402,'01KTF88CYZ04AS5ZY7MZRP6622',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2403,'01KTF88CZ0JS04W02YHHSV2033',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2404,'01KTF88CZ2JGYEFXKHJBNDD96G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2405,'01KTF88CZ3VGMTW7A21VR7GA96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2406,'01KTF88CZ4BQPPB93RGK3Z5KHB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2407,'01KTF88CZ6TPP6SCCC45X7CEAE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2408,'01KTF88CZ68V3H7YM6GV4M56NS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2409,'01KTF88CZ85T6FVK2ND3WE7GKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2410,'01KTF88CZ9HZD2SACSG9HVP05P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2411,'01KTF88CZAJNKBGBABPSFEMW7P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2412,'01KTF88CZBQRQWWPMQ259DF0XB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2413,'01KTF88CZC7VAVFMMG5W478JKC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2414,'01KTF88CZEF49357ZNVJFWBDYP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2415,'01KTF88CZFYW4WTCG6S7CR8STF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2416,'01KTF88CZGD0N3K1MX7A05W3S4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2417,'01KTF88CZJP242K5BZ7CZCNQR3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2418,'01KTF88CZK34MSKSFCAEM8K5VW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2419,'01KTF88CZM0YEAQQHWQRVKZPZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2420,'01KTF88CZN9NTK08DQYSXHCYEK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2421,'01KTF88CZQJFS3EKR70SWCYPB5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2422,'01KTF88CZR2A5715YT81F00PT1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2423,'01KTF88CZS85RPE90F73FA4C9Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2424,'01KTF88CZWAV4SR90QPF0QJ6Y9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2425,'01KTF88CZWPYHKP67FNPV7HYS6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2426,'01KTF88CZX6HXSEKZ5G2KHJC0E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2427,'01KTF88CZYTJ77N283CQB46ZTJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2428,'01KTF88D00QMVV9MHTBVRM9NPJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2429,'01KTF88D0280ZDDG7KVMT0AH22',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2430,'01KTF88D02AXH4SVWHGJ4ETWK9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2431,'01KTF88D04BEK20AF8VFHAY285',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2432,'01KTF88D059GCWKDQE4BN44T7V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2433,'01KTF88D06QCF4TYCB0Y9TJNCG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2434,'01KTF88D07TK2SG5KP5HA9BR5J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2435,'01KTF88D08Q5YBE6VKGQJAKBC0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2436,'01KTF88D09Z8644YQ5BEJGSS3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2437,'01KTF88D0B8XWSX9XC3NBXVJ9R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2438,'01KTF88D0DQ5HKJ0YNZ4QX2VWD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2439,'01KTF88D0EYJGTAAZMVR1HD0XP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2440,'01KTF88D0FE46EC4Y73Q6VEWA7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2441,'01KTF88D0GAF8MXZDW1EB5SCS2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2442,'01KTF88D0JHB3D7C4QRK296ZV9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2443,'01KTF88D0JQ4K1EH2G4S42HNP2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2444,'01KTF88D0NPC6QQY26MZF7SA47',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2445,'01KTF88D0P4XQBH43ES36MP33J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2446,'01KTF88D0QBFMJY85TVFSPMHVR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2447,'01KTF88D0RK41NZYQDQ6K2KEZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2448,'01KTF88D0TP6APXZ1VAC2SCJHH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2449,'01KTF88D0TKNP5JRV1G9VMJ6HR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2450,'01KTF88D0WWC3GY6RD8R01RT93',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2451,'01KTF88D0XBT6QWQGDAV5ADV9B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2452,'01KTF88D0YCN40KRQCEEMWH4MT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2453,'01KTF88D108RM4APRP8ZZ8KG7V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2454,'01KTF88D112P1YEX34EHBS9BHA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2455,'01KTF88D13GY120DTHK8AE5KCX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2456,'01KTF88D14K77QANC8P13CC2W6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2457,'01KTF88D159A64WD4QCN733QGB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2458,'01KTF88D16079HAZ3XYAXP75KB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2459,'01KTF88D18MMYHYAZP6EF7VGQM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2460,'01KTF88D19306SMMGJZ9YYTXNW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2461,'01KTF88D1BK45B2158VS0S5GFX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2462,'01KTF88D1DNFQP9FRGC201FPGY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2463,'01KTF88D1DSR8C7GQYG089RXKZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2464,'01KTF88D1FMVK30PP6ZAC2E5VA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2465,'01KTF88D1FAHJFDJGX9BFF8MH3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2466,'01KTF88D1JEVTZW08A50PQFRV6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2467,'01KTF88D1K35ZH3WPCSSESME6W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2468,'01KTF88D1MEF3P32G4KD5GD27A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2469,'01KTF88D1P89VW9S41C5FFSRMC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2470,'01KTF88D1QJWGBGD18HZHBX04T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2471,'01KTF88D1RD3KKC7Y6DFTPJGBC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2472,'01KTF88D1S3ADFDPPDWZBK8WP7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2473,'01KTF88D1V9K9AFSM7RE776R91',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2474,'01KTF88D1WQ6PR5KY5ZRMN08WM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2475,'01KTF88D1X2A7VS4W4VACPF4X2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2476,'01KTF88D1YX5SH37AJ30ND8B54',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2477,'01KTF88D20V2VC22NPZXAXB7PT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2478,'01KTF88D21WR4MTS59QHHJ706J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2479,'01KTF88D22JV5ZJTGDA9P2ZQZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2480,'01KTF88D23BR2HSPDA5WN6HWFS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2481,'01KTF88D25G6P8GXCCDZ93VM73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2482,'01KTF88D26D4MKA1JT8AF6S7XM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2483,'01KTF88D28R9EDJ768Y44D5KYX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2484,'01KTF88D29ATFFCEPSZAS690J0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2485,'01KTF88D2AW4V38MH3B6C7D5YV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2486,'01KTF88D2C41P0EPYJY0DJ0771',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2487,'01KTF88D2DQHZMQE7SFT5M6BSH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2488,'01KTF88D2EV0ZNHXNCPV3J6SFW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2489,'01KTF88D2F5W18VWTFFGCASN1V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2490,'01KTF88D2GCEG0393046P5DA21',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2491,'01KTF88D2JJ968TBBJ8D55DFEQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2492,'01KTF88D2KQWBWW76CFGCF06WW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2493,'01KTF88D2N1J0WJTJ7A074B5KQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2494,'01KTF88D2PD0YCR4X7W8F5SW39',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2495,'01KTF88D2RA220BSA7MK9HKMYG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2496,'01KTF88D2R95S8HAXJH4Z09HRB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2497,'01KTF88D2TCQTK3XJWKJ2B1G8Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2498,'01KTF88D2W8XNVAWCKYKTCG1XN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2499,'01KTF88D2XD7TQV4Z8TDRB6P4Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2500,'01KTF88D2ZFYD6QXBFBJNQTG7X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2501,'01KTF88D2ZADH698SBV7GXDRY3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2502,'01KTF88D32YQV929A1JHWH0WVK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2503,'01KTF88D32KQRMB9SA3QW90223',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2504,'01KTF88D345197A1VBTCB5BVPP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2505,'01KTF88D35BCYE7J0WX17KBPJA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2506,'01KTF88D373DCQX6XP1A0MVGVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2507,'01KTF88D37VV8X484QXXKN7HQX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2508,'01KTF88D39MT6MY4WFP9S4TP6C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2509,'01KTF88D3AJ3YADR3R8014ASCW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2510,'01KTF88D3BFXVE9GT361SZFPHD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2511,'01KTF88D3CZMY52RWXZ0KX6MWF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2512,'01KTF88D3D0AF72317CFR0RZQT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2513,'01KTF88D3FYG9ERQ4617JBPPHX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2514,'01KTF88D3HZDJSN3VEGHZ930M2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2515,'01KTF88D3H57GJYYBAT10K001K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2516,'01KTF88D3J15YKCHXJA4166N96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2517,'01KTF88D3KSXESCMXGYPTD19WK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2518,'01KTF88D3NG0XBS2NSZP6GNJXZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2519,'01KTF88D3QBTRP6M1Q12803H0V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2520,'01KTF88D3RS3DEBMPCP6HT31P2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2521,'01KTF88D3R75QSFRRG3XGEVBBM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2522,'01KTF88D3SWFB4F2XKZ2HAPJTX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2523,'01KTF88D3TDZGFC5P4TZA9KBWD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2524,'01KTF88D3WDJTVMEZH66CF8SAA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2525,'01KTF88D3X2AJNZXNJZ0C43G4Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2526,'01KTF88D3YBXYAPAYB8T881ZAF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2527,'01KTF88D403H8W45SC8025578A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2528,'01KTF88D41YNY605RF34S2K8Y4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2529,'01KTF88D426SEKNXHXXM06JJ71',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2530,'01KTF88D449C4JT5FWQCCP3WJ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2531,'01KTF88D45JQY5E2CA75DG24XV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2532,'01KTF88D46Y0D8ETXGHHSHH6QP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2533,'01KTF88D47S906DGXVD5NR092R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2534,'01KTF88D49G4T52DKRSKXH0CEW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2535,'01KTF88D4AQ2AHM92CMXDXR4Q9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2536,'01KTF88D4BVH0K6XZDC93THC40',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2537,'01KTF88D4CXJ814D5JZKVSVTVX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2538,'01KTF88D4E6B713NMM93ZD9X33',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2539,'01KTF88D4ED8E78NYSNYZ38442',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2540,'01KTF88D4GASS1BKKP9PTXTQ0M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2541,'01KTF88D4H486VXX001Y1B1W8R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2542,'01KTF88D4JB0QWP5S74J9CNWPR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2543,'01KTF88D4KBDJNZ3A8QA0R6HR5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2544,'01KTF88D4NYAH2HV4Y4Q32NM0H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2545,'01KTF88D4PR4PDH9YRMPP7P6ZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2546,'01KTF88D4Q1FKEPFWJE55GNEQ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2547,'01KTF88D4S380T3R9RPF03VCK7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2548,'01KTF88D4TGWZ9NAHKZ3JBPXD1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2549,'01KTF88D4VB4C4RQ9BQHKACTFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2550,'01KTF88D4WPBMQ8BHYQ270W3F4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2551,'01KTF88D4Y7PAAWTH2V241AVT7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2552,'01KTF88D4Z6W2CQVF4SYBC3AAV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2553,'01KTF88D50GQVTRPYAS3SZJP0V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2554,'01KTF88D511ATGZYZ1C9Z1ZXXF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2555,'01KTF88D53DCN5AJZMP1GZD41F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2556,'01KTF88D54GBZAA5MTPZ8YDZRC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2557,'01KTF88D55RSQ38GSC8TFX50FG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2558,'01KTF88D57F5310NK3JQ9Y9MXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2559,'01KTF88D587T8XERGNQXVE3977',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2560,'01KTF88D59YNY9PPWJFWT66M7E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2561,'01KTF88D5AQF4TX7QGXHH4Y45K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2562,'01KTF88D5C0HG69HHQVV5RW4HV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2563,'01KTF88D5DGC0KBXJSSGR2C4D8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2564,'01KTF88D5EWD21AM7RSH6XXQRX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2565,'01KTF88D5FZ62YX09FG7THQ6RT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2566,'01KTF88D5JTQZC30WSPFZACT73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2567,'01KTF88D5KEKADWV4J8J0QNS25',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2568,'01KTF88D5MYFD6A8RKFT6GZZKQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2569,'01KTF88D5PBBSK0EW6JR0EK2WR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2570,'01KTF88D5RJGDS3GRJ637RSWNY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2571,'01KTF88D5S01J336JWKHQ7MCNY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2572,'01KTF88D5VK55FNT6S3P5F7P3M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2573,'01KTF88D5WV9DMFTX7SEGCWRJN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2574,'01KTF88D5XM4K1FHV3B6WQ548G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2575,'01KTF88D5YWBC767XH8YZ1MF02',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2576,'01KTF88D5ZNBB5WHRXTJ7XT7QK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2577,'01KTF88D61RWKRK1AK0C2P0N3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2578,'01KTF88D629X71BHYWVK9ZJVTV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2579,'01KTF88D64Q50QWJTQ1FDMW2K8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2580,'01KTF88D656QH1W9KA8825H4KC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2581,'01KTF88D67SAB9NRF3Z412RQ3A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2582,'01KTF88D68DM5KT0NFZKMPAP01',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2583,'01KTF88D6AY47Y5690NWC2JG4J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2584,'01KTF88D6BK57W69RYCSDK5BF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2585,'01KTF88D6DGKRXZ6Y4R95JS9JF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2586,'01KTF88D6EJFXZ91X2NHBTYWK9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2587,'01KTF88D6FMC4GH52948W76CH4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2588,'01KTF88D6GK1S5GM69H22ZJ362',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2589,'01KTF88D6JV257DCYM53C71HH1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2590,'01KTF88D6KGGW6JWX65EC3AXSS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2591,'01KTF88D6M6SBA8BWTH713298X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2592,'01KTF88D6P6ZHKSQQV4WYZS8RX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2593,'01KTF88D6QBVQ3BDY8B3E36STN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2594,'01KTF88D6SA23VNMC0CR948V71',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2595,'01KTF88D6T7D8DSWPVRACH0HRY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2596,'01KTF88D6VM9K216YA6WWE35VC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2597,'01KTF88D6W9BNQB7GH3FTRWDS7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2598,'01KTF88D6XQGTMNJKVZESE5FEZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2599,'01KTF88D6ZYKFB6E7H5FJDPSYX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2600,'01KTF88D70CTSACJ5XQTJM0M5A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2601,'01KTF88D71XXHVSYCNW985N540',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2602,'01KTF88D7276BKDYZYK6HB6K01',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2603,'01KTF88D74X7NV8XSFXW03EBS4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2604,'01KTF88D750SEZY5MQRZ6ACVVF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2605,'01KTF88D76SH6FEFCGQR0K0H6M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2606,'01KTF88D77YQ57T855TNKP0JKS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2607,'01KTF88D79P2T9Y9Z8AR7FP0YX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2608,'01KTF88D7A30XDKD10BS20SFZD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2609,'01KTF88D7BF0DBQTKDHD8XQ4BM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2610,'01KTF88D7C9HJYW5SR8AMRVX7S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2611,'01KTF88D7ENKMADSEZJ9RWRZKG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2612,'01KTF88D7FM2Y4V6Y5V658BV3K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2613,'01KTF88D7G85G44A0ABC3QEZ3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2614,'01KTF88D7HNSHCDCT8XJ1YQPHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2615,'01KTF88D7K2CMZWX90V333VNAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2616,'01KTF88D7MFH4RFX4ZX543Y1GZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2617,'01KTF88D7NHNGG0XDJ94EYKD17',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2618,'01KTF88D7Q9ZWG7YSC444A98ZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2619,'01KTF88D7QZXE0Y6C4501X51DT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2620,'01KTF88D7RRBXKHN3WXASAGC4J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2621,'01KTF88D7SCNY5X4EHS9M69Q8V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2622,'01KTF88D7VTT40RW9EWQG50WNW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2623,'01KTF88D7WNZ73YETG8GXJQNGW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2624,'01KTF88D7XD8R803FR9JS3F8GA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2625,'01KTF88D7Z45GEMY9T71443S4X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2626,'01KTF88D7ZNABWE450JH9Z3659',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2627,'01KTF88D81V0CAVDTRSB9PMB0Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2628,'01KTF88D81AVAAZECE1Z9EHP5A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2629,'01KTF88D83ZGPHXVEMBZ4KE21G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2630,'01KTF88D85VNZG4P4K45F9ZP3F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2631,'01KTF88D8542JEA78GSAPK5KPD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2632,'01KTF88D86T16B74YTBPCXQCYH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:44','2026-06-06 19:58:44'),
(2633,'01KTF88D871GFASJZ00S9J5ZQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2634,'01KTF88D89HCPZJXH2C45494XN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2635,'01KTF88D8BFS8DWRG0CXGM1J2T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2636,'01KTF88D8CD2MT5N9QRBXG0SDJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2637,'01KTF88D8DJ0TAHC3N81R3EFHT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2638,'01KTF88D8E0MBWXMG9FMAYYDZ1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2639,'01KTF88D8GG88P0NDGFM71SDXE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2640,'01KTF88D8H4JVFYR33GBSNZMBZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2641,'01KTF88D8JXQMPJZ07X05MFSB1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2642,'01KTF88D8NGSZ71D676V6D9X1M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2643,'01KTF88D8NPSVKQJHA3QBX8A31',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2644,'01KTF88D8PN71E84CA297WBG77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2645,'01KTF88D8Q6FCPKB00SF6KYSWA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2646,'01KTF88D8RXSCEMXW4MKWTSW32',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2647,'01KTF88D8T9Z0NW1DSMVRQ38G0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2648,'01KTF88D8VJMF1K9XJ5HNJS8MZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2649,'01KTF88D8WAWD10EPQVK48VW9Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2650,'01KTF88D8XF4CSRVZSC2MHKAVB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2651,'01KTF88D8YVGDART75DDNPX922',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2652,'01KTF88D90SNSVSR45RHDDE4D1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2653,'01KTF88D91G0GZGNMQH36DYVCF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2654,'01KTF88D92J6M1XHDH3A4BHMHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2655,'01KTF88D93GGBRQJ1ZNBWVNGZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2656,'01KTF88D941FGM9VSW93HYJZMC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2657,'01KTF88D96V7EY06GX40F4RFZF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2658,'01KTF88D979D1CJJ2007KJ6DCR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2659,'01KTF88D98H9W3ANCNQCAM9GZN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2660,'01KTF88D99XSYRQ0QMWBHGB13H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2661,'01KTF88D9BBH0S9SXJJ0WRGEYC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2662,'01KTF88D9C1SC6CY04QX89N5J5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2663,'01KTF88D9D1T2ZJJA2QSMC458K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2664,'01KTF88D9EEAE1NJS52R9WV05M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2665,'01KTF88D9F1BHFTZD9MB551Y80',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2666,'01KTF88D9HCZ29H14AK2Z5DN50',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2667,'01KTF88D9H85MKJ0FAXF66T8EP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2668,'01KTF88D9KWSXAGHNZR4PW42VE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2669,'01KTF88D9MKJBNDPB5GKV8P6AA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2670,'01KTF88D9NEGZZVA2PXXQF9J31',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2671,'01KTF88D9QGJS3WKCWGBYVDB8N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2672,'01KTF88D9QGHHDK49FPR8DC4NM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2673,'01KTF88D9TYPMZCD5K47XV4KGX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2674,'01KTF88D9T55SEDTPXWZ1AEVSW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2675,'01KTF88D9VXXGVQCEZBYQK9V6K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2676,'01KTF88D9XMP5XVWTCHKPEEXWA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2677,'01KTF88D9Y855ST0KE9NG49R8B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2678,'01KTF88D9ZW2JJY9VY1DBK95WR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2679,'01KTF88DA1MBBQBKEBZFWBB6JM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2680,'01KTF88DA2Y74Q66D7MWYT4DF1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2681,'01KTF88DA3F7YHZDBBFD33D4MW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2682,'01KTF88DA4H5ZS243HQFPH8KZC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2683,'01KTF88DA5K0M2JJTW0DQW4MY9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2684,'01KTF88DA70ZMBMVSTW9G2XFYB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2685,'01KTF88DA826WRFES84PWCV65X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2686,'01KTF88DAASYZ5DY0VQTP2964W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2687,'01KTF88DABKVSAD52S74VP681F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2688,'01KTF88DAD5TJP3ZYE8SH9DVZ2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2689,'01KTF88DADWKG82X0B4DPZRV1W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2690,'01KTF88DAFF1PDDTW3FPW0GRNJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2691,'01KTF88DAG3Y1P6TVEHYYE66V1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2692,'01KTF88DAHVC7CNAB0BG800E98',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2693,'01KTF88DAJYWF47TEQ3T6C95WT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2694,'01KTF88DAKW5HRY0ZSY72YFM2K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2695,'01KTF88DANQDGPYVJGF19XE0BT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2696,'01KTF88DAP0GH0A38D8MKRXNMV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2697,'01KTF88DAQ6WKEY33HR61XHXKN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2698,'01KTF88DAR7195Q6QGQ1X0FVS9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2699,'01KTF88DATCWFN77XHJBXQB6KK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2700,'01KTF88DAVHK8YMPDF8B9NYT6Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2701,'01KTF88DAW8CCEHQWC5M5J2RC4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2702,'01KTF88DAXVQGSS5QDXV9VK00N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2703,'01KTF88DAZ2T571VHVAJTS6BCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2704,'01KTF88DB0N6KFHHHQYR6R4J3V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2705,'01KTF88DB1FR5BKSA3GWAEYM0J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2706,'01KTF88DB24Y54ND6G0XNAX8FF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2707,'01KTF88DB33FXRMJC1EKEF85WQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2708,'01KTF88DB4C2MWBXKD5HP2N5AP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2709,'01KTF88DB5H35Q686W7APMZ2KP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2710,'01KTF88DB63VH72T7BKRD7DVD0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2711,'01KTF88DB8FSTWVED3KH8FDET9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2712,'01KTF88DBA42S3XYBZKDX3DYJG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2713,'01KTF88DBB6RPYNA3A1EJEJQNY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2714,'01KTF88DBDW4D7AZ52ZX70A5WJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2715,'01KTF88DBEC21F149FQNERFZSD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2716,'01KTF88DBG46HQ39SQEX3BKVAV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2717,'01KTF88DBHY5NFW58GZMNH0A32',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2718,'01KTF88DBJATJX6JF46ZKCB466',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2719,'01KTF88DBMAVTVHR7WPY6PHFZ5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2720,'01KTF88DBN3D1KJ4E4XQ57SCEZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2721,'01KTF88DBQ34BJ3PZY2BBJMRBF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2722,'01KTF88DBR9D1W4W0R5TCYSM3M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2723,'01KTF88DBSQ6SSVKHNNEVK79XT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2724,'01KTF88DBT58K640CXE2J8M0XP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2725,'01KTF88DBVW9JC1XM2TSEAD4D9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2726,'01KTF88DBW21FV4X4FQSB2VEMG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2727,'01KTF88DBYHPJY4FCEP205S9HG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2728,'01KTF88DBZBYD25GWT1H7D2V74',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2729,'01KTF88DC0TBZS4E4NWFTNTFVT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2730,'01KTF88DC2DT0AR1NKC64SXC14',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2731,'01KTF88DC2ARHQAN9BD2Z0XWZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2732,'01KTF88DC5VANJHWNPJDX0MDAP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2733,'01KTF88DC5HVKDM4NFSP09AV67',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2734,'01KTF88DC6S840Y11N26AR1P5F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2735,'01KTF88DC8APCE831KE3KG6NQ1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2736,'01KTF88DC90H0MV7EXSQS4GZ68',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2737,'01KTF88DCAKGRR3KCWXKVPS4QJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2738,'01KTF88DCBC9MFAJAT23VNR3KX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2739,'01KTF88DCDCZC1XSMCKASKXRF9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2740,'01KTF88DCDF1R2PECTD0VYRXYF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2741,'01KTF88DCG28C7AKGNBAFGEWT3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2742,'01KTF88DCHQY6A4QEFZ6MSVYSC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2743,'01KTF88DCJS14W3XJD09YFBE3N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2744,'01KTF88DCKGY5XB66YGPACHZZD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2745,'01KTF88DCP8B15GXN16503NXAE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2746,'01KTF88DCPVNAWPS0QBBCGGSRD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2747,'01KTF88DCR7ZKQ5HAQPR52NDF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2748,'01KTF88DCSMB6E0V1NV5B44N4T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2749,'01KTF88DCTC8P20JZ78PKBMAYS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2750,'01KTF88DCW8MYEWC05Q80ZN1S8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2751,'01KTF88DCXDD1P53YPNGYXEDMA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2752,'01KTF88DCYKT3BK9PEWGMZA53M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2753,'01KTF88DD0Y2VQXXRFYTM4EZ54',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2754,'01KTF88DD2DJXD1D4TG4G6TMFA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2755,'01KTF88DD27WC1FK5YQ2XDDE87',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2756,'01KTF88DD49NMCFDBZ2J96S72N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2757,'01KTF88DD5HY8XVZ9RJNTDY0G4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2758,'01KTF88DD6GDGHJHBWRD9FD7FY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2759,'01KTF88DD76N2KZXVK4J5KTK97',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2760,'01KTF88DD82ZZMN1JPSW27YVPQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2761,'01KTF88DDAEJ4MRX25B2CAREAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2762,'01KTF88DDBJ2A4C0PKS2RTM24K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2763,'01KTF88DDDH7JYAW3SRN60MQ6R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2764,'01KTF88DDEVZQKBZ17REKPC1CP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2765,'01KTF88DDFHQXDZRMAGDT746EP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2766,'01KTF88DDG9VKHKP9SR894Q7B8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2767,'01KTF88DDJXN6RN486NRHHE2FF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2768,'01KTF88DDKM07QWXBGDMMNEYYG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2769,'01KTF88DDMK42E95W7AAVWD5CR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2770,'01KTF88DDPT356WNRPT7SDB554',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2771,'01KTF88DDQGB3KKTYQ27ECG0TW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2772,'01KTF88DDR34597GCQ2XW5NTZ5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2773,'01KTF88DDTFYS8AXQCNR2QQZ7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2774,'01KTF88DDVV1EAKEA65NYKMX2M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2775,'01KTF88DDWFF13QPYB66ZNCW1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2776,'01KTF88DDXECBFP4R5BR68XQX7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2777,'01KTF88DDZ5PBY0NKAGFVSMZ88',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2778,'01KTF88DE0ZCQFD25QMJFY6Z9R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2779,'01KTF88DE18PE7QKQ7GH2F1ABT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2780,'01KTF88DE24YYDS1JRK7MATKE3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2781,'01KTF88DE3QZ9PKNY853SJ83SK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2782,'01KTF88DE417KJPVM7YGCA27SD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2783,'01KTF88DE6HRZQBZPSPTD5FA9E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2784,'01KTF88DE7VDN9YJ6T1W6WZGYH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2785,'01KTF88DE87WWNE8M9CXJHS5TG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2786,'01KTF88DE9QPQXCKKY45AS2GWG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2787,'01KTF88DEBT4EQK9JM6QDT5K5D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2788,'01KTF88DECBQ5ZY1MY77FP7RHV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2789,'01KTF88DED2YXNNR2M36STDQ00',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2790,'01KTF88DEFD7995AMJ3PV8VTV5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2791,'01KTF88DEG4ACG3F3V0D59G46M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2792,'01KTF88DEH1SEBBM1G83WV6YBY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2793,'01KTF88DEKVZY6DQFMVS60PM28',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2794,'01KTF88DEKEAVC1RQ8GPZTYQ6Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2795,'01KTF88DEMNFTNBW6AMHP6R7EK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2796,'01KTF88DEQA7N7DH4GH1EDXTY8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2797,'01KTF88DEQFZJFD7CKXSFXPY2Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2798,'01KTF88DERJ5Z518F15WYNEBRA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2799,'01KTF88DESZCBTEJQD32NAYTEN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2800,'01KTF88DEVH7M2PBRPGD6SPP3K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2801,'01KTF88DEW4T7WRZ87RM2ZRZ62',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2802,'01KTF88DEXYGZWM2SMHZ04FV9V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2803,'01KTF88DEYRHC4QWMJ20GGNJNX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2804,'01KTF88DF0TJ345WJMBNZXRGZW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2805,'01KTF88DF1G1AVMXYB9WN9TE93',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2806,'01KTF88DF2F0QFN85ZQV01V4H6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2807,'01KTF88DF3G9WAA8NBQM1B2GME',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2808,'01KTF88DF5NX9C6XB5Z6C2VSXC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2809,'01KTF88DF6VZN10HZV378KPBNZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2810,'01KTF88DF7ZX2V5WAW8A7R0E55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2811,'01KTF88DF8C2HM0T53P0X3JM4X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2812,'01KTF88DF9Z7BY6NCYZT4EP6M8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2813,'01KTF88DFBP71MDR8VXEE8P7BX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2814,'01KTF88DFCXB3JGTHG4K4MY9DH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2815,'01KTF88DFEND7VBKMWEWNSMKNV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2816,'01KTF88DFE4EFSVK7X7RXCVDE6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2817,'01KTF88DFGS4NK52VP7BM6477H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2818,'01KTF88DFH5VZ4M6R3A0ZVMNP2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2819,'01KTF88DFJSWV8H1VZKX91RSR9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2820,'01KTF88DFKKCTSB41CAGSA22BK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2821,'01KTF88DFMBRTAM9ASWV6KS111',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2822,'01KTF88DFN93W6028V6Y6K0P59',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2823,'01KTF88DFQ0PRHBS3S17RN0SX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2824,'01KTF88DFS6P7WPNH1K6S70VX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2825,'01KTF88DFTJ0BCP62C3MTTH5GB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2826,'01KTF88DFWDN8NEYKMRFFJWQFE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2827,'01KTF88DFW1W8TMCKNMVBZKWMP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2828,'01KTF88DFY2EPG2CQVDH257RBV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2829,'01KTF88DG0SNEHPPJ77MJJJC2W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2830,'01KTF88DG14M23E3G98RE6GWNF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2831,'01KTF88DG2WQ14YPBNK47MJPN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2832,'01KTF88DG4ATP5FX5KNABKV5WK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2833,'01KTF88DG4EHAP03CHE5RE6P8T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2834,'01KTF88DG7RE7ZNPFY3R9XFNQ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2835,'01KTF88DG8PQ9W9FVHM2YP07MG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2836,'01KTF88DG9DV01EKW7714715F3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2837,'01KTF88DGAX7JB6BEJ545VEE6M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2838,'01KTF88DGB201M10YH3QXDSXNR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2839,'01KTF88DGCVMJ8R6VHWNREEFW4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2840,'01KTF88DGE4EEW1C0V5FS7MY7Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2841,'01KTF88DGFR9T08HR28CB1RX0X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2842,'01KTF88DGGAXTV0Y3EA0Q7SKQH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2843,'01KTF88DGH41W08TC8PA1S1TDE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2844,'01KTF88DGJH8MST60FF6GYW9FN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2845,'01KTF88DGK0XCWQ5R0PSZDNQBT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2846,'01KTF88DGNQKAD6K19WDJAECVH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2847,'01KTF88DGPFFREH09N43ZDZV22',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2848,'01KTF88DGQ5JR9GEE0ZFH9BV27',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2849,'01KTF88DGRCQVVDB7ERD6K8ATW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2850,'01KTF88DGTMP3NYHXR2MPBHK4X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2851,'01KTF88DGVG6XMKABE2RQVH5HY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2852,'01KTF88DGWSH5M8F7ZPNZW6C03',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2853,'01KTF88DGXJ3NZQSW03QCR5CAJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2854,'01KTF88DGZXBEE3FVG3CQ88XAK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2855,'01KTF88DH03B1A1F4YG34J2DFX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2856,'01KTF88DH10Q0W6QHE6WC665EQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2857,'01KTF88DH2JCB1QYMH94SDE2C1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2858,'01KTF88DH4NRKD8N4KV3PCRGGW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2859,'01KTF88DH5NRRMQRSW0NC4BKCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2860,'01KTF88DH7VAVBXS6YPA665DX2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2861,'01KTF88DH8P4BK1B85S35R38YN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2862,'01KTF88DH90TFS7RG78TWSYXC2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2863,'01KTF88DHAVDQ2M8HF8MQ2MXMW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2864,'01KTF88DHCK1BVCFACG3P1342T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2865,'01KTF88DHDK6AW3H1VAMGNZY8N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2866,'01KTF88DHF4PN9J1TNXGB76XA3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2867,'01KTF88DHG2WNBARH30WVN046C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2868,'01KTF88DHHTPZK19472X56X9TR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2869,'01KTF88DHJHP7Q9CXM17KBBE0D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2870,'01KTF88DHKCGQPGRQX2253T2K1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2871,'01KTF88DHNP4HXBH7Y197E33S8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2872,'01KTF88DHP7SM3V3X4ZD64BDCV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2873,'01KTF88DHQHQ36JDX6TNSC9Q76',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2874,'01KTF88DHRCZG2MK3RSG864M5M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2875,'01KTF88DHTTKRR17KKJBJCXCCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2876,'01KTF88DHVPDMQE0KPPBW4HP8K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2877,'01KTF88DHXJXTHDRDQF43JBK7M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2878,'01KTF88DHYQVWW5TC0YZNWR03P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2879,'01KTF88DHZS9QHMH4KAK43FCJY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2880,'01KTF88DJ1JKJBFHJ3CHAHNSBW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2881,'01KTF88DJ2BP3ZAQKGWECB3T4Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2882,'01KTF88DJ3D7RBKREBGZHSXRRJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2883,'01KTF88DJ4Y8QVYZ3364AC0EZ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2884,'01KTF88DJ61HBD8JQV1FDNBMSF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2885,'01KTF88DJ73W1JA6ZSVEZ7YC2G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2886,'01KTF88DJ8EFJFHFJYCAF3SZ8C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2887,'01KTF88DJANFWVTQQH6BKP0AY8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2888,'01KTF88DJBG3PMW9T2CRTE5ZM3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2889,'01KTF88DJCZPGWH2Y957PQJNHB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2890,'01KTF88DJDB0NDYC0TCAXYDNPT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2891,'01KTF88DJE4NB1J37AQXCR27T6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2892,'01KTF88DJF7AF0S5HPS5JQ6K9G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2893,'01KTF88DJH47QK0VCSYRXZKCHE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2894,'01KTF88DJJB68P0Q67SHWBR02F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2895,'01KTF88DJMFNE4GG7FW1150QR3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2896,'01KTF88DJNWM4TNQ5P9375WYRY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2897,'01KTF88DJQF886FZVW5WK575NY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2898,'01KTF88DJRZGRYEX3XP5GZCDDK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2899,'01KTF88DJS7R3302AMD9F671TP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2900,'01KTF88DJTK4CPGGMMBYAAQF3Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2901,'01KTF88DJW0Z9FBB9TY41B05WD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2902,'01KTF88DJXB1A3V4FDBZ71Q34X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2903,'01KTF88DJYW6ZRQ7S8QERWHP4X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2904,'01KTF88DJZ8YBJHW551EC53VYJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2905,'01KTF88DK26B0K5E179Y677A49',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2906,'01KTF88DK2NGKZ8Y42SAZGB0EH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2907,'01KTF88DK56N7MNENCNXAWESDC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2908,'01KTF88DK7Z37GAADHQG5M72BN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2909,'01KTF88DK7YZR6N385J3MGVRW7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2910,'01KTF88DK86H7SMY4ANJ0EHSR0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2911,'01KTF88DK9GQGHFWJXEPRQK31C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2912,'01KTF88DKA244JHJXK3FH43MDB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2913,'01KTF88DKCQVX3R6Z4J9WBMG7D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2914,'01KTF88DKDH32KPS79Y73B7EBJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2915,'01KTF88DKE9V3YYCWJMJQKGJ3W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2916,'01KTF88DKGSQSS2B9KX689482J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2917,'01KTF88DKH17621QXXM49MZA3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2918,'01KTF88DKJENYZND1EW799WYTE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2919,'01KTF88DKMJP6Z97EF86GE16QD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2920,'01KTF88DKNN5P0P2WY9Z554KXJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2921,'01KTF88DKPXTGHJYSR47HWDGRB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2922,'01KTF88DKQ3MY92A5PAXMVTE05',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2923,'01KTF88DKRNK2HV5GCHBPM5ECN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2924,'01KTF88DKSDZGQ2QBRVKNAC6RT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2925,'01KTF88DKTQ4806NENKKT82Y8E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2926,'01KTF88DKVYJAK9ZEYPRCX61C1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2927,'01KTF88DKX1587TEPS9Y7TDVVP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2928,'01KTF88DKZW9FW3AP69AERTE0D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2929,'01KTF88DKZFQTXQ2RBFGFR5S1N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2930,'01KTF88DM0PC5NCG5G15ATNHVE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2931,'01KTF88DM281WFE2AB4F29YQF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2932,'01KTF88DM33FP4472MYXYFQDD8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2933,'01KTF88DM4ZPRM7EDQKEBACTBT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2934,'01KTF88DM59DNET4075ZYPX93W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2935,'01KTF88DM6QJNBF4YHRDPQHS04',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2936,'01KTF88DM8PWWAYWZS1HTD5AM1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2937,'01KTF88DM90ZKF888GM3DXB8JE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2938,'01KTF88DMBA7DGJ4FB25Y5W4WP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2939,'01KTF88DMBTHAHNTJ0VRWX8Q9B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2940,'01KTF88DMDAE8SSB0Q3SVHGACM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2941,'01KTF88DMERCPHS6K64Y2ZTSGZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2942,'01KTF88DMFKA592EFJX7CS6Z3D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2943,'01KTF88DMHQ2JKP880XGZ1634E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2944,'01KTF88DMH236TSHSN6EHGDNKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2945,'01KTF88DMKW81C72N9P5ARH7H9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2946,'01KTF88DMMFR7TJQCP0Z3YT828',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2947,'01KTF88DMNJ8JWAZVN5D70SZQ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2948,'01KTF88DMPXEYV5RVEQ2YSJ3D3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2949,'01KTF88DMQ9A6NK8WEW72R8GSC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2950,'01KTF88DMSNGSNG3YM19JEYZ9T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2951,'01KTF88DMT2CP2PKT9T2H438TC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2952,'01KTF88DMW78HS1RKQTEEHDJME',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2953,'01KTF88DMX32J7FWT2MAQGA700',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2954,'01KTF88DMYMCC1ZRYCKGB2J7HA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2955,'01KTF88DMZK0W86SK9RXR4HRGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2956,'01KTF88DN1J9TFP7YYAWQA67NX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2957,'01KTF88DN316Q0FAD2ZDRT46N8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2958,'01KTF88DN3QC7NS35X45FK44Q5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2959,'01KTF88DN4CJF4WR9VQRHEKTXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2960,'01KTF88DN6KKWDH7S36CVGMMJZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2961,'01KTF88DN7WBFGN79AXKF460X7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2962,'01KTF88DN8RYSJA7AWF3594F64',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2963,'01KTF88DNAADEDYHK8RR8P832Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2964,'01KTF88DNB59XGY8FJAAE7SD5Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2965,'01KTF88DND9C82HY6JQDG3GPE7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2966,'01KTF88DNEGQ2TQJ3HRJXYCMR9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2967,'01KTF88DNFAXKTPC7DYFER5NR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2968,'01KTF88DNGYTVS1PZAY0NH25ZA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2969,'01KTF88DNH7PYY2PE7KXNMCQD1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2970,'01KTF88DNJC3VXBES8RTYE3C6V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2971,'01KTF88DNM9RVXYH6MKRBDJAH0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2972,'01KTF88DNNPJ13PV3BA8NNARQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2973,'01KTF88DNPBDTG12CM5HEREY2W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2974,'01KTF88DNRZS0JE8SCTKVV3H4H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2975,'01KTF88DNR3P6E9Y952S8HQ0AA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2976,'01KTF88DNTPHT5CM61E7355X9D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2977,'01KTF88DNVBV94GJMZZ0B0Y8MQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2978,'01KTF88DNWA3W0P4DC345A7M9P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2979,'01KTF88DNYVFSB41YG6J8AY6DP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2980,'01KTF88DNZNGW7EBXG3XRKJB4W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2981,'01KTF88DP0E49WGC71KP0179VT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2982,'01KTF88DP2SSGX2EVK3DRSDG64',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2983,'01KTF88DP3DHQMFVBQA2S5KR2H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2984,'01KTF88DP4B9G41WFSYMV0D0SQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2985,'01KTF88DP69A2AAJ1RMEMQNWPP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2986,'01KTF88DP6H1532ZTMKJDA3K4S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2987,'01KTF88DP83Z4SWXAXNNG95SQC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2988,'01KTF88DP9DXYE5JRBDY4J7NH6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2989,'01KTF88DPBD1EMZ7WAFDQA0907',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2990,'01KTF88DPDMKJH82PZ75RSNDPW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2991,'01KTF88DPEVT2AADNEHD98ZGPD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2992,'01KTF88DPGFNS2RG6QFV1JWVJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2993,'01KTF88DPK37T65JQFW4RAFJ60',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2994,'01KTF88DPMTAV6WG676D8X1M0V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2995,'01KTF88DPN106VVYQ8SRZRN28B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2996,'01KTF88DPNCGRCV4B5ZCGDFSPY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2997,'01KTF88DPR049WJYW1XA3W49C6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2998,'01KTF88DPSBTFJXS52PC120VZP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(2999,'01KTF88DPTNFYHG70PMRBT4F8Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3000,'01KTF88DPVV935WJ4J8CDJQ3SC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3001,'01KTF88DPXY1QWMR91ENERCW0X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3002,'01KTF88DPYJ952KQ0P0F25W8NJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3003,'01KTF88DPZ4H8DV7P35YPEB4CT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3004,'01KTF88DQ06A08SMEJ02XNBP0S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3005,'01KTF88DQ21FZF8TSKPHJY27TV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3006,'01KTF88DQ39EK02GVK8ZCXPEMN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3007,'01KTF88DQ4CT1ZPZ7NZHEHDFW4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3008,'01KTF88DQ6WJTEDC1FTWPAFYBN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3009,'01KTF88DQ706PQHT2VDT0D0SS7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3010,'01KTF88DQ9Z8QJWY3HA20P3S56',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3011,'01KTF88DQANJE0QH0XTX0XCXTP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3012,'01KTF88DQB0TKD36QA4J5WZWCS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3013,'01KTF88DQCSH0JQXHC51JQT46S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3014,'01KTF88DQDZV5WGQHTGQV4RPN9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3015,'01KTF88DQF9NMTJ6FK87MW4B5Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3016,'01KTF88DQHN3DFNH3CXVDXG4TA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3017,'01KTF88DQHS5KA31DFN88SZ099',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3018,'01KTF88DQJJM6VE77CTWBHT8Y3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3019,'01KTF88DQMHVE05196STKKCQC5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3020,'01KTF88DQPH6AZDM6WER5J2DM1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3021,'01KTF88DQQ0FHEC7AW2KNAT16J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3022,'01KTF88DQRTDHY3RAJSM8T0Q4N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3023,'01KTF88DQSB4QN3WPJ093EJQ8W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3024,'01KTF88DQTW45PZMPFXZJESX9C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3025,'01KTF88DQVFCT2JJ9PWC54SJMJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3026,'01KTF88DQYAXCB6TMA52R7NX29',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3027,'01KTF88DQYP11C9QNJZYEATY2K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3028,'01KTF88DQZKHRBFRDB1M82VHQK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3029,'01KTF88DR1JBBC0BZET5ZPP613',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3030,'01KTF88DR2V6YM4JYYAVM4MNGV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3031,'01KTF88DR3FQS0EGBKTE9NWZVY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3032,'01KTF88DR5AXK5QJ4GY17V122R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3033,'01KTF88DR6P7G5J3C79YA6MS23',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3034,'01KTF88DR8353KKWVSDXMC7RMC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3035,'01KTF88DRACNMZDH6T9MW4KQ31',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3036,'01KTF88DRAACX66TEE94ENX21R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3037,'01KTF88DRBT9TZ3VVYMM7ZZEQ9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3038,'01KTF88DRDM32RK9Z84V4N4ABT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3039,'01KTF88DREQDX7XRQQQHQKFYJ4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3040,'01KTF88DRFFDXRSQTBVQE7PQVB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3041,'01KTF88DRGG6YN98R9EJ3MJPX5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3042,'01KTF88DRJZ75SG2P8ZBW4BFPT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3043,'01KTF88DRK9GVBBABBPTNG4TKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3044,'01KTF88DRM9S2Z05K2J2HBCZKE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3045,'01KTF88DRNSYYN7BNS9MT8KY11',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3046,'01KTF88DRP5W6Q53JF8QGNDB3N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3047,'01KTF88DRQRC0CWTT3PVH61VC7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3048,'01KTF88DRS0NE9VPY4GZ67N6XY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3049,'01KTF88DRT1CS58NVJS0W29W65',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3050,'01KTF88DRV22RQHNW42DQS0FCS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3051,'01KTF88DRXVY52NFWQY90G6376',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3052,'01KTF88DRY4YGVFNAMBQHP04PZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3053,'01KTF88DRZA76G7GVSG0NT3DTQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3054,'01KTF88DS00XS5R7QFCGD5QCAT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3055,'01KTF88DS2JYC43G3GSX58X0FA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3056,'01KTF88DS38SHNKQHE6CQMDFDZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3057,'01KTF88DS4NPFZ14F35Z7V2F27',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3058,'01KTF88DS59QM2BW9GFR6XTMGA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3059,'01KTF88DS6FSDKBFAZE0133NCV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3060,'01KTF88DS74CHZ1SV5KM53KHAP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3061,'01KTF88DS98VPCEZR1084HXVMH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3062,'01KTF88DSA0WBVERRECHPAFCH9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3063,'01KTF88DSCHM804K3KW81M1WVV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3064,'01KTF88DSD0MWA5PS17YJ0BQBJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3065,'01KTF88DSERQFR2VX2H5AVQS3J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3066,'01KTF88DSF7871TEP81HA1X8CB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3067,'01KTF88DSGHKTAF1AGWVYMXVYV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3068,'01KTF88DSJ1A0BVP403MDPRP0S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3069,'01KTF88DSK59RW4FM31JVQD1QM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3070,'01KTF88DSK1FBSFH5HXDE9PKHY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3071,'01KTF88DSNFVGZDH5KNQ6DG58Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3072,'01KTF88DSPEQSG597BD1W4JKW7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3073,'01KTF88DSQPFET34E4N2EF5JS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3074,'01KTF88DSRKN79P3JWN0QRD72K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3075,'01KTF88DST7ZQ0DCT0179CAR46',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3076,'01KTF88DSWYZ5JC7Y1HPVTCZMN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3077,'01KTF88DSWDF8DG6JSCQ6FR3GM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3078,'01KTF88DSYWGBWX8EYAF3PVP8N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3079,'01KTF88DT0Y084VMCH00VCQHE8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3080,'01KTF88DT11D676BVH7M1AD3GE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3081,'01KTF88DT3QY2W6QSD1Z8PKXFY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3082,'01KTF88DT4F4DB0MHH3RA3EQ9Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3083,'01KTF88DT5EPZ9T8D708JJWAS5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3084,'01KTF88DT6CMMRQ16R64FHMA7N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3085,'01KTF88DT8KHBA6Q5AQZZWFCNF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3086,'01KTF88DT9PTCA70W43MBNRHPQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3087,'01KTF88DTA5P78EDKWZW8JSTTT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3088,'01KTF88DTBF9RQP0WAJW4XFB3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3089,'01KTF88DTFTWKZREDHA0GVM575',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3090,'01KTF88DTE0EHNRNSWM9J3HC5E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3091,'01KTF88DTHN287J1WCTMGQTFMN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3092,'01KTF88DTHM9653B5RR08WAKQK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3093,'01KTF88DTJYEVPTEY0MENS782A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3094,'01KTF88DTMC272NXHQM6AM9SCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3095,'01KTF88DTN8JXB6HNTB1WP9H9Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3096,'01KTF88DTP89F1DMP7HSZVYRJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3097,'01KTF88DTR72GMSHGNEZY40YQA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3098,'01KTF88DTSCNPDNF62H4YGWN73',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3099,'01KTF88DTTXDCQRF68SAJ0ZSQS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3100,'01KTF88DTV3CRMEPCWNGZ5BVVQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3101,'01KTF88DTWNG9QPYE2M694EMC8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3102,'01KTF88DTZX6CDW6MJKT78TGFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3103,'01KTF88DV0PKF65S8J35G7RDDP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3104,'01KTF88DV119PD2PS9GM5GWASV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3105,'01KTF88DV3BZ1WSW3S5REABMH5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3106,'01KTF88DV4E7SE7DJZDXDMYYAK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3107,'01KTF88DV52ME0Q6DN9DMRZVV8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3108,'01KTF88DV6TQCJ9RYQZJ5HHCPF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3109,'01KTF88DV8Q61HWC5JQF4EQV3X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3110,'01KTF88DV9PDPX1NHMESKW0R66',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3111,'01KTF88DVA99WP5NBC61RRHS5R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3112,'01KTF88DVBJKQQDP526KMJASSP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3113,'01KTF88DVCW67JNA15F2FSG3FQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3114,'01KTF88DVE6RH31DHAS1JPPK7N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3115,'01KTF88DVFZBHEJZ8GB9B3VRGC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3116,'01KTF88DVG23F2TH5DV7XG9CJ7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3117,'01KTF88DVHNPTTX99GDGRZ2VA5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3118,'01KTF88DVK97EAGW4G3SGCSPCP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3119,'01KTF88DVMKZQAZ7MY0VK42WMH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3120,'01KTF88DVN2VESEZCBX4ZBXKF6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3121,'01KTF88DVP7V9EVPY55J50X4ZA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3122,'01KTF88DVRAQY8988NE9YH3FBV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3123,'01KTF88DVSX9JGNE5A3SA6AG6G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3124,'01KTF88DVVFYPKWBSS69C1ZJTB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3125,'01KTF88DVWE1465HRHA3GN78K7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3126,'01KTF88DVXH2MH0YPDCHC1FFSY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3127,'01KTF88DVZRH0CR6B2ZDRSC1CJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3128,'01KTF88DW1H5SVNS4F2NQ2MSKA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3129,'01KTF88DW1CD8PW2G06EXCTVVX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3130,'01KTF88DW2C6DDYV4M2XGW6PQG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3131,'01KTF88DW3EMGAF8QNPK3XYTK5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3132,'01KTF88DW54Y62446Z3BZSG92X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3133,'01KTF88DW7FNJRKQC6GRRC7PD4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3134,'01KTF88DW8KVVNB0H9WD2JXGE8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3135,'01KTF88DW9BSRPGTGDYXFS6R7E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3136,'01KTF88DWAY5FDYSY7CNHGEQKF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3137,'01KTF88DWCH1MK7YYAGAH33QP5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3138,'01KTF88DWDW16JS4A6E9FJZGR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3139,'01KTF88DWEXJ9M80WWGGFAV0X5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3140,'01KTF88DWFV8A9HACF62M6C88W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3141,'01KTF88DWGS813ZFE8V4X6T7F7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3142,'01KTF88DWJTBK0E3A1PMYJ5AKK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3143,'01KTF88DWK0VKFKCT30D2PB2TJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3144,'01KTF88DWNMN35A6RKKZKY1MST',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3145,'01KTF88DWPE1G8JYC6Y173VNT5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3146,'01KTF88DWQSEWHGR981F9S93TS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3147,'01KTF88DWSF7T55A8AM7JPTFWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3148,'01KTF88DWSJTZ530QE71995X30',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3149,'01KTF88DWVGR50M8PFYRR6ZFND',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3150,'01KTF88DWWBYYSP570P8EQBKBC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3151,'01KTF88DWY95BSKBV1ZBTVWJM7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3152,'01KTF88DWZQRTWCPNRXTPNP4R4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3153,'01KTF88DX19Z0YF82GX5XVXSK9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3154,'01KTF88DX2HBNC671ZY0R9STZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3155,'01KTF88DX3FWKXNAXEQ3CFNT1Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3156,'01KTF88DX472WMY3JY2F6X9NS2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3157,'01KTF88DX5X5XWP2M7XXKQWA23',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3158,'01KTF88DX7S9F93MZ2EWPJQ8J8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3159,'01KTF88DX8WYZNAS837MJ17H15',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3160,'01KTF88DX9Q5VJKQZN708D6XQY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3161,'01KTF88DXABZV08SSNXXCBW2JG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3162,'01KTF88DXC28G33SNRMDMHEPX1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3163,'01KTF88DXDBMYTG36K1XGEMTMR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3164,'01KTF88DXEC8M5K2MF7KQ2BNYF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3165,'01KTF88DXFHQDX1SFBX4TN1D0P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3166,'01KTF88DXJNG92G4M3BJZHDVWW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3167,'01KTF88DXHMBQAMACMER5T73WZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3168,'01KTF88DXKEHWXVQ8W1FFF5ZSZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3169,'01KTF88DXNFRV7F9ZGDT2PTJZG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3170,'01KTF88DXQNBSHQMQ8NFBN3NN1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3171,'01KTF88DXQHFEW8X0A4RC2XF6Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3172,'01KTF88DXTFDVD5QZYD1BZ64DG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3173,'01KTF88DXTFHK1V5S44G4A5SHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3174,'01KTF88DXWKS7BP0V680FDF2GN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3175,'01KTF88DXXVQB9AHBHDA8Z39H5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3176,'01KTF88DXYMNKDFBN0X5EY7EN1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3177,'01KTF88DY0G3X5EYYYV6VHMTWZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3178,'01KTF88DY1Q3S8GE85EF72KVG2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3179,'01KTF88DY3RFRR95XAK9TY2S1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3180,'01KTF88DY5M4TZA1R3GZK1AF9B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3181,'01KTF88DY6Z69VSJ7823TYABGT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3182,'01KTF88DY7858X47QHE8W468TS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3183,'01KTF88DY9MK35B4TZKRV2REZW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3184,'01KTF88DYA5MXAVF6K1HER1MQC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3185,'01KTF88DYBYV416QZQFHEY9PJR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3186,'01KTF88DYC0J0MYVVVXW4XJ2G5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3187,'01KTF88DYE176VJ1C55VJYDC7E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3188,'01KTF88DYFFY51QTBVFRTN9FK3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3189,'01KTF88DYGNWKEBR1PBDKGQ7EK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3190,'01KTF88DYHZ96A0S0KEBR0ZW1Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3191,'01KTF88DYJJ9MWWB7BEN5J1W60',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3192,'01KTF88DYM2N30K073M979J0WN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3193,'01KTF88DYN5ZQH83ZG53KNB79A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3194,'01KTF88DYP7DENZRPNHQSBK2CY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3195,'01KTF88DYQY5D9WZ65G4MSA0S1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3196,'01KTF88DYRRYCK34XRCFAM0KW4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3197,'01KTF88DYTP0FRNAEHTTJFYY5T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3198,'01KTF88DYV4QKZ988H47GK5ZYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3199,'01KTF88DYXC0Z2VN1JYDD5MFK4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3200,'01KTF88DYYRPGBYN6J6139926C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3201,'01KTF88DYZSAQX3ZJ0Z9RX3HRZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3202,'01KTF88DZ0T0MEXQ6ZW62AD7N6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3203,'01KTF88DZ1TPG8KQDPGW8BR2X9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3204,'01KTF88DZ2MMSVDCTRG5Y9F0JR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3205,'01KTF88DZ4YAWCZGQR1VCJ44K2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3206,'01KTF88DZ5JWSA5DW8FH1QMJ23',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3207,'01KTF88DZ7S4M8G0VD37XJRFG8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3208,'01KTF88DZ7GNJDYWQ9YB5BMWR0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3209,'01KTF88DZ9V19KKRSS9H3H52QS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3210,'01KTF88DZAEMC2N5A442YSH4Y0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3211,'01KTF88DZB6V3ZQQY3ECCC1P8Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3212,'01KTF88DZCZVBESE7DSEVM6WHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3213,'01KTF88DZDCKFB2N0N8RW0388J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3214,'01KTF88DZF0HZ93GXPS0NHMJVD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3215,'01KTF88DZGBWEKN2SNVRW4DBQC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3216,'01KTF88DZH8E4AQC8EDBS9BMVQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3217,'01KTF88DZJ2F213JB2PKT0QE2B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3218,'01KTF88DZMCRBJ35HTJFTYSTG0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3219,'01KTF88DZN05ZFRM3FET5V6HM9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3220,'01KTF88DZPPET3GYNX11FPYFKD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3221,'01KTF88DZRDXA6H3C3DQHN65EG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3222,'01KTF88DZS877GHGTE282F1WAX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3223,'01KTF88DZTKQWRQ684M9AYMYD1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3224,'01KTF88DZVS4GANGE2RN89139G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3225,'01KTF88DZX7JGYY7KRHS8YG9HP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3226,'01KTF88DZX5DGJVFMWBQ43M82E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3227,'01KTF88E00BCH8X5S0NR3P4XB7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3228,'01KTF88E008TKFMR5P5C49EY44',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3229,'01KTF88E04KJTAB4PQ1GXJAMTM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3230,'01KTF88E022KBXTVJZYB2EVQ77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3231,'01KTF88E088V5TQ0GYJ7PMQ791',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3232,'01KTF88E08ZCN3BXPX6DZT0ENJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3233,'01KTF88E0BH8B0EDB22Y1Z842N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3234,'01KTF88E0CJ8SBHC64X0WCM702',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3235,'01KTF88E0E0BKT9FC3JZFQQ7C5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3236,'01KTF88E0FXTAFFSVXB6STV8JF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3237,'01KTF88E0HJ3B78ZWVJ67HN5K4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3238,'01KTF88E0J5ZE5PJ4A7X87FEQQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3239,'01KTF88E0K1218PRYFQVHB1H4P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3240,'01KTF88E0NAEFM2G4G62TTWBKT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3241,'01KTF88E0PD3HPB3ZGJGVBFWV5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3242,'01KTF88E0Q4TMGD7CJV199ZJFR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3243,'01KTF88E0SBDNMVY4PN59GEBFM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3244,'01KTF88E0TF5CF48B5R0HE3FB8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3245,'01KTF88E0V5M5GZ81CJXBWZ9C1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3246,'01KTF88E0W9YCHGM1YY05WCY6W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3247,'01KTF88E0X5RWD2BW0NDYG72RR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3248,'01KTF88E0YPGFB7XZY7GPM1YQW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3249,'01KTF88E10M3YR5FCSRT3YC5SV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3250,'01KTF88E11KS16E670QX8AFX7Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3251,'01KTF88E12WH0YPTFMTCX905AX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3252,'01KTF88E13NZ979XX29DWXE8VX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3253,'01KTF88E15NFDPFT4KK0K2V43R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3254,'01KTF88E16JRCTY7WE40RJVV9E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3255,'01KTF88E18MXQ05J5J93MPVPN1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3256,'01KTF88E19A5G34PSR8XSBC1VP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3257,'01KTF88E1AFZQDBMSW9YA3YE9V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3258,'01KTF88E1B5TE92PTGSN5CS46B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3259,'01KTF88E1C5W107MJ6T4QJJ85D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3260,'01KTF88E1D30B2HW4D9D7FZYAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3261,'01KTF88E1FQJ5EBQ9JT0GW9AC8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3262,'01KTF88E1GBVJVKDW9R8AE7WES',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3263,'01KTF88E1JPVS3TZ5SKGX4R9E5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3264,'01KTF88E1J2M6KG624WVAPET8X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3265,'01KTF88E1KEPQBF1S1532GCYKM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3266,'01KTF88E1NZHKXMH9WF5EH684D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3267,'01KTF88E1PN0M2094VCCZWKXXF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3268,'01KTF88E1QEKQ0ZY2RXPQWDX38',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3269,'01KTF88E1SCY4KE0P6J6M5DK0P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3270,'01KTF88E1TFNWBYBW23CJKC8XK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3271,'01KTF88E1V3773Q42W87PEBJ0B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3272,'01KTF88E1XY7PRTA8QT7KY97HQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3273,'01KTF88E1ZKX390R4R002RY1E1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3274,'01KTF88E20PSACDSGKRV1K0JFG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3275,'01KTF88E22DZCVCJ07NFM3XRDN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3276,'01KTF88E24RAYNH7YC225FWBS9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3277,'01KTF88E25MN62W2DWGEQJAMHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3278,'01KTF88E268WPK2T5MNQPNWEWS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3279,'01KTF88E27AYHAJN38JVHQYVTW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3280,'01KTF88E2919SA5KXTTAYNEZWM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3281,'01KTF88E2A2EHB5A7AS691VEGA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3282,'01KTF88E2BFSGKHWDWRBSGPN15',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3283,'01KTF88E2CH17VDFKSQ8GKN2F2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3284,'01KTF88E2EVZ0MNC2Z431JXRVE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3285,'01KTF88E2EF3M6E5FK45EMVRX2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3286,'01KTF88E2GM9Q77W04MSB7BWRH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3287,'01KTF88E2JST59RGTFDCW1T12Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3288,'01KTF88E2KJJ4Z8DQ50H4EEP77',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3289,'01KTF88E2M05TJ173P7G5FKS2P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3290,'01KTF88E2NFSRVTFA158JC5X2B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3291,'01KTF88E2QNBMXFMAN116YAKAM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3292,'01KTF88E2RHFDFKWQ27S2N6BRM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3293,'01KTF88E2SA5ZQ41HF6G5JKZS0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3294,'01KTF88E2TNE9MCSDWSR22HMCP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3295,'01KTF88E2VY2QKYYXX66HE2WEJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3296,'01KTF88E2XKEGWQFCDB2GZX473',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3297,'01KTF88E2ZBHAHDXZGJ5QWFHXS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3298,'01KTF88E30TJ9G4VG4HYXVPKYB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3299,'01KTF88E31TV347BY24SMR5SVM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3300,'01KTF88E33ZHJ3EK82C0BEB8EJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3301,'01KTF88E34V3676HYZ13Z7Y9R6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3302,'01KTF88E35EX3G6CB5B88GQAJN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3303,'01KTF88E368GNE77WE9FHBV5RE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3304,'01KTF88E3882ZFD8JQ3FT3ZEBQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3305,'01KTF88E39C5EDTMC4ZE0A17R2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3306,'01KTF88E3BR281RGN3A84YK8F1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3307,'01KTF88E3DR2636GC18Y92HD8J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3308,'01KTF88E3EDHYJ2AD4DKGKNKZY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3309,'01KTF88E3F320S462MCYMWRF65',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3310,'01KTF88E3G3DNV6P3TWKX6JXAY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3311,'01KTF88E3H0XZHSQ7QTZP64MKM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3312,'01KTF88E3JBEEHF2R7VZFD1YZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3313,'01KTF88E3KYG2J4XRAZXSKYC6T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3314,'01KTF88E3NZD98P4Q79BFD4607',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3315,'01KTF88E3NG6F9F0B998HWWVZH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3316,'01KTF88E3QJK57K87S2H43WDY5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3317,'01KTF88E3RF8HH7PE76RR5G2DW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3318,'01KTF88E3TMRQJ695ZQR7N1VJM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3319,'01KTF88E3T8EWDTKMHT97Z1TA7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3320,'01KTF88E3X1P2JE9K4MX3E0X6B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3321,'01KTF88E3Y88TVDQEV4YV9NCNG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3322,'01KTF88E3ZNKXHBMCW72A99JTG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3323,'01KTF88E40XYWAG9ZFYVNDE53J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3324,'01KTF88E41FNAE34SE3CJVPE23',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3325,'01KTF88E427YMBW9CGFHZB7Q8G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3326,'01KTF88E43S3TGNBQR2WW3WZZB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3327,'01KTF88E456D69WFA0VC741V57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3328,'01KTF88E46HMN9Z40SP78X36H5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3329,'01KTF88E474EBTM3WRD4GXCQMB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3330,'01KTF88E4AH3YA39QEHEP3GQHZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3331,'01KTF88E4ARTYFFHKXS1J6RHCK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3332,'01KTF88E4CKQFF983GWKEKABC3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3333,'01KTF88E4DG8B86N2GB3J8C0GR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3334,'01KTF88E4FE4RY3SBR1QBBMH57',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3335,'01KTF88E4GQP7C4D16ZTHEJSQM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3336,'01KTF88E4K7TJFDWSHN3FSPS8K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3337,'01KTF88E4MBPNMNP19C2TQGWG3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3338,'01KTF88E4N4A8AAFQJCNF1CC6G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3339,'01KTF88E4Q7QEYMCDT6Z236B0E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3340,'01KTF88E4RABFNREC6R47X4CVZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3341,'01KTF88E4V8ZSEARWQG90ERF5Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3342,'01KTF88E4VTEDPFCVQX3T2SQWE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3343,'01KTF88E4W4Z86YGC73V1RMPHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3344,'01KTF88E4Y9A57XB5GM1DDNDPG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3345,'01KTF88E4ZKP6CRA30CZZ85XBV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3346,'01KTF88E50T2CHNV6X1PJSNF6F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3347,'01KTF88E53VTNXXAR3ZQWF2EN6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3348,'01KTF88E54Y70E7H8DJAEWSX9J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3349,'01KTF88E56MVJ8XEKRX5BD2KZ9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3350,'01KTF88E569M8B69Y4SMRSN7JS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3351,'01KTF88E585EXWJ98XPGXMH9HQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3352,'01KTF88E5983FGT51T8PVBQM1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3353,'01KTF88E5AKF1P8AG2134AXT6V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3354,'01KTF88E5C55T3V6EYYMRVG6D7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3355,'01KTF88E5D48K06NPN7VC56ZK6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3356,'01KTF88E5FB2VT4ZND05GP3337',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3357,'01KTF88E5GT8TD8MN6E8XGKFWH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3358,'01KTF88E5JRF5YP0MQ5KYPFNFG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3359,'01KTF88E5MP7F7S00SWR6TRSJQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3360,'01KTF88E5NA26STCER0WQ0CSY6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3361,'01KTF88E5Q41E2S7658A0AYCBW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3362,'01KTF88E5Q9SS9EF1E8AW0XMRY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3363,'01KTF88E5SQF2TX3EKK054FK1A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3364,'01KTF88E5TAD89JG1T809DAWHD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3365,'01KTF88E5VMEA8QKG7XA2G1KRN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3366,'01KTF88E5X5S60KHF84H54Q2TR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3367,'01KTF88E5YSGZPMG93Y9YZR0YC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3368,'01KTF88E5ZPNNX4JB6D6ECEQH8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3369,'01KTF88E61RY5TNY6RXK32THKG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3370,'01KTF88E629YAWNWVG6594Y6EM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3371,'01KTF88E64JJ1ASJRV4MVBDX1E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3372,'01KTF88E645VMK78Z110R7ZGES',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3373,'01KTF88E655RJMCG6PEC8X32VY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3374,'01KTF88E68T37M76Z91A8QAB1Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3375,'01KTF88E69NTMSN973FE3QSEED',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3376,'01KTF88E6A5J0JPD7CQP8K46ZM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3377,'01KTF88E6CRVXECN4BBZKNWDV4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3378,'01KTF88E6DTNT3PDN0RQHCCFK2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3379,'01KTF88E6EDQEFS4K63XB1TRGS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3380,'01KTF88E6G33S1DWP3W7XJJ9XD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3381,'01KTF88E6G7CPRNTGJEF5PRBES',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3382,'01KTF88E6J16B2Z7NF43YCD7CY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3383,'01KTF88E6MJ52W37YD2G7R19JA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3384,'01KTF88E6PMQM6VS73FDA6RTHE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3385,'01KTF88E6QH5CAQH7HG3AXMPQ6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3386,'01KTF88E6R5AA7XBDDV3AN0XJR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3387,'01KTF88E6SDVA2RZZA1V191MCB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3388,'01KTF88E6TGGJMJHVEW9N8MYR7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3389,'01KTF88E6WAENVF2H95TSC031N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3390,'01KTF88E6WA5KP87X1P22VC7PP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3391,'01KTF88E6Z1W1CNVZJKVCCTXT3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3392,'01KTF88E701CNJBF8VEZVY8RND',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3393,'01KTF88E72ZKNPHKW9TFRPP5R2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3394,'01KTF88E729T09DTW3CSZZ6YZJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3395,'01KTF88E75SKPFD1KK997QQ2HT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3396,'01KTF88E753C7X5CAGQACX82FQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3397,'01KTF88E77H670SJC7CC8RSH9V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3398,'01KTF88E7865PBSGR4FJSBEDN4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3399,'01KTF88E79QZQNX851D61XN0XK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3400,'01KTF88E7AYSCM5H6BYQJGQFJ9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3401,'01KTF88E7C021X8GDGKYTKG08C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3402,'01KTF88E7DHS8XNCJJQQTQKMP4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3403,'01KTF88E7E4F1BEGGKJB64422V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3404,'01KTF88E7F82BNTSZVC3W6ARV4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:45','2026-06-06 19:58:45'),
(3405,'01KTF88E7G6C7BM3CSKB0XERR5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3406,'01KTF88E7JCT0R56QPWC8KMTA5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3407,'01KTF88E7KMZYR2NWYHX832AT0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3408,'01KTF88E7MA8G71KAGQNWTKQ8G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3409,'01KTF88E7P7QBSMVNCFTR6R7V8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3410,'01KTF88E7QMC4T6M4DEJJ84SWQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3411,'01KTF88E7SF14ZZ9GZ3BPBABRZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3412,'01KTF88E7SYMAT2M5PA0DV0SQK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3413,'01KTF88E7VC9M5HH4K25CXV827',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3414,'01KTF88E7WJZAVD6JCZ2VNWZF2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3415,'01KTF88E7XEP3SJDMMWJMH5NXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3416,'01KTF88E7Z14BRPHRZV0QMGM25',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3417,'01KTF88E81B03G9CX09NGZ50N3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3418,'01KTF88E8370E8ZB7MXD5EJWWV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3419,'01KTF88E84YYP7KZH00BMX662X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3420,'01KTF88E85ZQZH6JF9WW71CTB0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3421,'01KTF88E86MVYBFPMCMJA5G76F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3422,'01KTF88E88ZC9AYB44ZNVHDH10',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3423,'01KTF88E8AFSHEZZ9WV4CRSHB7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3424,'01KTF88E8A9ZXGG2PYZY6AENRP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3425,'01KTF88E8CPP977VXR5YJJ1BVC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3426,'01KTF88E8D9NQZRPZVAXAAV6D9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3427,'01KTF88E8ER0Y58D3G8NZHWKEK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3428,'01KTF88E8G3R19PASNT25GDT9C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3429,'01KTF88E8JWX47C9HBB4JM5X9N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3430,'01KTF88E8K6N1Q3ADD9GTFHD33',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3431,'01KTF88E8NQ6S6ZN3EYZRHDXAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3432,'01KTF88E8P6S7NMC25KNX2Y2SW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3433,'01KTF88E8QK7HPVRFCS9RM8KKD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3434,'01KTF88E8R72400AZKA3QH3C1H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3435,'01KTF88E8TYXTW16VFGMZQPGZC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3436,'01KTF88E8T49DG7JCB56QVCPPR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3437,'01KTF88E8VDH9E3CQRNZYKJGP3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3438,'01KTF88E8X2YDXHPNE06E55EG1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3439,'01KTF88E8ZRS5KQZF52TFRWHDW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3440,'01KTF88E8ZC5F9478J6H893KEN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3441,'01KTF88E91G7QEK9ZF2PEWHNR0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3442,'01KTF88E92TZZPRE22ZT7E2SM1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3443,'01KTF88E93VW7KXEDS6ERYAD6Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3444,'01KTF88E94YD1T0VG21M1S5FFF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3445,'01KTF88E96PJHC54K8PX440E81',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3446,'01KTF88E98KP0BJGYXFMHA7F42',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3447,'01KTF88E99K91K12XYVHEMS38F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3448,'01KTF88E9AJS9RH4FXGECQ0DF9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3449,'01KTF88E9C7MBZ9W6JWZ69RTAY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3450,'01KTF88E9DNDGCNSVG2ZFXN7QS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3451,'01KTF88E9EAQMMW2MWMNPPKMV5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3452,'01KTF88E9GM5CD0KD5RZJDGEF4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3453,'01KTF88E9HT035QEX017T766RD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3454,'01KTF88E9K2ATA282J7JGTZNTA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3455,'01KTF88E9MQZBVV328SZKK1129',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3456,'01KTF88E9MWA87KCEQ8W3HNFCP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3457,'01KTF88E9PMDD8KJFE9JTNV90Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3458,'01KTF88E9Q84AABY81AH9TFJV2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3459,'01KTF88E9SH0PSNF6RJC17W3P3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3460,'01KTF88E9S1VNASK18RB2AGDVF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3461,'01KTF88E9VGJVA2BBB09VVQ4FE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3462,'01KTF88E9XF4Y3C6F55VGH0WE6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3463,'01KTF88E9XC8FZ3QDK9MY40TFD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3464,'01KTF88E9Z6E9EBBR9EY9EP2QJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3465,'01KTF88EA120D0AYMECM5T2721',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3466,'01KTF88EA2PBKMGBR25KDHT81K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3467,'01KTF88EA4DDMJ6D3JNF41GDPS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3468,'01KTF88EA52KEBN7B7R119VQKP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3469,'01KTF88EA64BN7DHEP88TN60S2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3470,'01KTF88EA8ZHQ97HMSDRXRAJW9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3471,'01KTF88EA9NDQPXY3EPXAGHTN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3472,'01KTF88EAARKSVGR3VAWVPB4YC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3473,'01KTF88EAB2FWBSSHF619Y6ACZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3474,'01KTF88EADR7QHWGBEZBNQ5JCC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3475,'01KTF88EAE46JJQBP9C50KW8TH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3476,'01KTF88EAFHTNN6XMMR38PXNYK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3477,'01KTF88EAG3YKKX3FNSEAQK27P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3478,'01KTF88EAJMRF54AH9BBQ2CRAA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3479,'01KTF88EAKZ5FWVZYRKV5VPZTM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3480,'01KTF88EAMWZ5NJPBB6Y7F89H5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3481,'01KTF88EANZB4XQ6QKJPCMVK65',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3482,'01KTF88EAQ0FEHPY4S9FCGD850',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3483,'01KTF88EASNYX58XXT1JAEB27N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3484,'01KTF88EAT71783X4PYY0SKQBR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3485,'01KTF88EAT3FT1RVC2Q3PT7YKV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3486,'01KTF88EAWAMWA4J4X9CK2AJ3E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3487,'01KTF88EAW3RZZ0GA03PZVN8RQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3488,'01KTF88EAYN0A688KV4DXP56YM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3489,'01KTF88EB0EVGMEY7VRY3C547F',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3490,'01KTF88EB0NFPRN3V667VEREFR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3491,'01KTF88EB21CNRBJBN8Y5W6TTX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3492,'01KTF88EB45R0X8PVCCNF1S3VH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3493,'01KTF88EB4KBJM1N3XZSYBR4KB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3494,'01KTF88EB5SYRB4HCSKY1V80XW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3495,'01KTF88EB7CMK3VSJYPW4DAC24',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3496,'01KTF88EB8SEEH3GYT81TH9624',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3497,'01KTF88EB9KEXHYV4RKBQPAXXM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3498,'01KTF88EBBFTXX2M84PESY8JHN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3499,'01KTF88EBCQPTRQMV0A16N7YAK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3500,'01KTF88EBDHAAP3R79V7VH7M4V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3501,'01KTF88EBFSE21QKX9SYXNX6MT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3502,'01KTF88EBG036HRBEEQ2KMN0YB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3503,'01KTF88EBJ8H8FXZQC7F0R54NA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3504,'01KTF88EBK973NVTV6GYKYFEYS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3505,'01KTF88EBKZ6NJ31MGZNHS3P05',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3506,'01KTF88EBNMKG2HFYA913P078D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3507,'01KTF88EBPV1F8GRV3JFRTWFH3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3508,'01KTF88EBQTW84K3VFCEEMRMF0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3509,'01KTF88EBS9GY2Y6EF72G25FF1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3510,'01KTF88EBTE6BQRN5KHY5NF7TF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3511,'01KTF88EBW6C1BZ51FRGT4VJBY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3512,'01KTF88EBXGEHTSWYFSHXY4CY7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3513,'01KTF88EBY0W3PQ9B33NW04XE9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3514,'01KTF88EC0APD0FCWV7P7N0AE9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3515,'01KTF88EC2JEC6TYSP1K02A2Y1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3516,'01KTF88EC2NJVDA1BZVZV6WD44',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3517,'01KTF88EC3H2Q6QDKC6ZJHXB7C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3518,'01KTF88EC5442W5SXDTT2E54EH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3519,'01KTF88EC6XBTMYQ3FDSFW7TTY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3520,'01KTF88EC7DZDMC6MQXJ3JRPG0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3521,'01KTF88EC9X89NMYAJAS6DNS8W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3522,'01KTF88ECAVKNBR1SXS1RBDC42',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3523,'01KTF88ECBXEZR1SXZH027C7HT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3524,'01KTF88ECCRN69SPNGSKY6XD98',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3525,'01KTF88ECED0CN3ANSA8KP8RYT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3526,'01KTF88ECF691ZCE4A9RYHVDN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3527,'01KTF88ECJ54MXEQE1N23VKATC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3528,'01KTF88ECKBP61NG58XV90EA33',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3529,'01KTF88ECMRPD95MCBP507QE17',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3530,'01KTF88ECPBDSMB4WABY5TV2A8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3531,'01KTF88ECSM2C5TRKBMEHS40H0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3532,'01KTF88ECSKVBC2YX2W69FSA96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3533,'01KTF88ECVD13WEK1BKP67WTSE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3534,'01KTF88ECXMWR0CBBFFDYJ2F3M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3535,'01KTF88ECYKVTF8RV4YWV8Z7PF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3536,'01KTF88ECZ49EVQM6KMFYT29FD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3537,'01KTF88ED01E20ZD2H7V34FE0S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3538,'01KTF88ED280E26DPTF31WF6S6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3539,'01KTF88ED38JNF8994HVD34SE5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3540,'01KTF88ED40TFP92SCV0Z9EMHW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3541,'01KTF88ED5B16V0096JF24G07S',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3542,'01KTF88ED66A9QT9S9RV3B0PYA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3543,'01KTF88ED71QAXAVC0NH9385YV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3544,'01KTF88ED8ZT7C1H6G0KWKQ98V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3545,'01KTF88EDB5JXDTPWQ566D4H61',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3546,'01KTF88EDC4RRBJRCMC5JMSQ5C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3547,'01KTF88EDD3ETY5D6ERAQ11BA8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3548,'01KTF88EDERMN26K2QEZ49QB9C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3549,'01KTF88EDG0Z68WYCSJKZJHNAC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3550,'01KTF88EDHPPRACNKDPDWGE3EG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3551,'01KTF88EDK269ZD76AKZKNP3Y8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3552,'01KTF88EDNV1B856ZE89R14YT0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3553,'01KTF88EDPJD0GVMJBBYYCDGXK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3554,'01KTF88EDP9SBCZY769T2S7TEK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3555,'01KTF88EDRZG101S7YWGSX1KXN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3556,'01KTF88EDTHBBDRDF1S6WJRX2P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3557,'01KTF88EDVXB4XB7FAHJV4445E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3558,'01KTF88EDW6Y9RKYY4TXKQ9JTT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3559,'01KTF88EDXFQEAMNP6E0R08V0A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3560,'01KTF88EDYWFVDC9DN9YK7G84W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3561,'01KTF88EE0G0KS8PC4GYZDMETH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3562,'01KTF88EE1N79D8F2V2KJWET55',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3563,'01KTF88EE20FAX7DY2ECDK73DX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3564,'01KTF88EE38WYE6QXT5XBCVPHR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3565,'01KTF88EE47B3QG97RWJ9V325Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3566,'01KTF88EE5Q9MFKHWY03Z5ZY6B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3567,'01KTF88EE6KVFRKG1RFG043DMT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3568,'01KTF88EE8FJBK3ZA4BATRZX46',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3569,'01KTF88EE9PDJ7FEXC2KDM6CVH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3570,'01KTF88EEADVFY7DAKFTHQZFG8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3571,'01KTF88EEB5JWJ8K8ZQYAY2N9A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3572,'01KTF88EECDC36M8GKJ65Z8P62',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3573,'01KTF88EEEHC8H3JFC8V09ZKXV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3574,'01KTF88EEFX46TASYV5H0KGF4E',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3575,'01KTF88EEGRHRMZEXFVG1FBDNX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3576,'01KTF88EEHQ10VEVJXS4N77WJB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3577,'01KTF88EEJFM70PBN5VT8C7H26',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3578,'01KTF88EEMT7D4XGYGGZTYTD80',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3579,'01KTF88EENX99YHVW6DQCSHBWT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3580,'01KTF88EEP2NGQEPV4359JG1KB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3581,'01KTF88EEQT2GMHA6Q30Y8WRR1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3582,'01KTF88EERRNHF8XBYPH3XTJV0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3583,'01KTF88EESCCG17ZAWMJGH8P67',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3584,'01KTF88EEVNW4PSNXG7WNZSA1G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3585,'01KTF88EEXD74YYGXXE3DM0ZRW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3586,'01KTF88EEXVYPBRY1DRCR0JYYG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3587,'01KTF88EF0NA3KMQBRE3FBAW9G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3588,'01KTF88EF1T8XV5JCQKK82AFFN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3589,'01KTF88EF2BT3HS3T1WN96F00Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3590,'01KTF88EF33ZKMNJS4M7DB0TSP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3591,'01KTF88EF4DDB98EQGQQH80HF2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3592,'01KTF88EF6AX0BJ27WVFHHH9KM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3593,'01KTF88EF73FJMP0NGEWA8JC3R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3594,'01KTF88EF84XV2JJM9QHG417DS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3595,'01KTF88EFAP1F8D7KQ1WTH8XS1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3596,'01KTF88EFBW0SGJFFAWZ9XCCY1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3597,'01KTF88EFCV8QFXGC72E61QEE3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3598,'01KTF88EFEJ36TYXEZSBHRYT6J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3599,'01KTF88EFFS8RAFCT5BH6R8BS2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3600,'01KTF88EFG683W6GW6WWCS5R6N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3601,'01KTF88EFJ6FGMFK616S304CG0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3602,'01KTF88EFMNEV5GRVRXMMMN7AV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3603,'01KTF88EFP2GTSDMVSWFMSH95Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3604,'01KTF88EFPXSJ13Q2WXC17DFB4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3605,'01KTF88EFRKBWB58ZK6D58HVWZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3606,'01KTF88EFSH22EX4C1GN0E7C0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3607,'01KTF88EFV15XVG9Z01J1ZCPJ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3608,'01KTF88EFW8JF2S82VGHBHS3TT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3609,'01KTF88EFX9MPYZ7Z13PFWR83Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3610,'01KTF88EFZCHCRTGZSZA7PP9W4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3611,'01KTF88EFZ4KW2ADD9TE3QQEF2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3612,'01KTF88EG097X564ZJVTY9VHDG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3613,'01KTF88EG2YPMCP213FQC9J8HC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3614,'01KTF88EG4DC88VMW0B4ZVGYJ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3615,'01KTF88EG5WQ517G6Y698FDZQB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3616,'01KTF88EG7M45NDT22879KV9W6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3617,'01KTF88EG8XCGYNZ8HW2FK20WZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3618,'01KTF88EGBEGPKEHAAZNT3MKHA',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3619,'01KTF88EGABHNNTRYSJ8SBSZ4N',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3620,'01KTF88EGC5PCWNW59F9K2HGMR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3621,'01KTF88EGEBKM6Y6S6RP4MY9N9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3622,'01KTF88EGFV8NBCMKA64GRSHKK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3623,'01KTF88EGJDBE364XPWMZD7GX3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3624,'01KTF88EGMWMGRK2T21TANYWJH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3625,'01KTF88EGN9GRHERGBQWZHVDA6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3626,'01KTF88EGPNN16P60S558HP4SZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3627,'01KTF88EGRY1VDVGS38D7PF4QD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3628,'01KTF88EGSGG6VQWMT7VJC67SP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3629,'01KTF88EGT7CXHDK9ZNYDEHMFE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3630,'01KTF88EGWZ3G0V5AA03N74V39',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3631,'01KTF88EGXYCDBMB7N2GXN2AEX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3632,'01KTF88EGY63SGTHHZYKM8D0CJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3633,'01KTF88EGZSS5G1KZJ35K3G6NB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3634,'01KTF88EH04W5EG69JQ6EXMBK3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3635,'01KTF88EH20V32XF9YTA8YXRHE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3636,'01KTF88EH34FHAEWMQKK5J5825',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3637,'01KTF88EH41WAPSADDGFBZM0XB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3638,'01KTF88EH629EG863MPPH2817X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3639,'01KTF88EH6HF36W2ECEGR01QAG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3640,'01KTF88EH8D4Q79D3TC22HREDC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3641,'01KTF88EH92EQWNR0DQJ2471DF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3642,'01KTF88EHBY7MNS7FTPZQATQ95',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3643,'01KTF88EHC5BQWVR4BEQEYVSZN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3644,'01KTF88EHDN6DS48CA6KDZ5DSV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3645,'01KTF88EHEPQYAGSA8Y8J72KZ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3646,'01KTF88EHGT629RY6J32PGD4FR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3647,'01KTF88EHHF590MCQRXF3A3BTF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3648,'01KTF88EHK915R3R97PJB0CBAR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3649,'01KTF88EHN0B32NVYVFH6W9KCD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3650,'01KTF88EHPNGJ842E5QGRX6J8Z',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3651,'01KTF88EHRW3GQDGRW69DT6PX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3652,'01KTF88EHSPB131PFPT6KGA278',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3653,'01KTF88EHT83AQWNWG1H8GXVCH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3654,'01KTF88EHVMT7MRX2NXPKDYY4Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3655,'01KTF88EHXNJXA2KFQ2Q4YS9H6',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3656,'01KTF88EHXVP0W4FHZ6PZ650DC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3657,'01KTF88EJ1A32FSVTGG5YBJQHM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3658,'01KTF88EJ2ZBAM3A74R4WEM9K3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3659,'01KTF88EJ5Y2S1MZ3NN4EE9YKK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3660,'01KTF88EJ50K97KY344PV0964G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3661,'01KTF88EJ8Y8V2FPCMX08MK9XK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3662,'01KTF88EJ9J7T4W44DNJ4Q8TTW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3663,'01KTF88EJB90213ZVHWDSSGGEQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3664,'01KTF88EJB4WVMZ25HNP4BYCZV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3665,'01KTF88EJDK67EQ7Y6K3WBHBX8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3666,'01KTF88EJECMTW48ZPGHJYBTZZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3667,'01KTF88EJGYVDEDNW5GJ4B4TJR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3668,'01KTF88EJHEAF8T760D641GCC8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3669,'01KTF88EJKSQD6Z7MR1S02CGXD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3670,'01KTF88EJN50HK2BFH1YFAA3NY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3671,'01KTF88EJNPP4VTYFQTJDZEQSC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3672,'01KTF88EJQAQWEQ5C7XARSEDBR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3673,'01KTF88EJSCRQNC1TSEWTXK2FY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3674,'01KTF88EJTKXP1THCV34JHD6XF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3675,'01KTF88EJVE4KM24637HV5RXC2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3676,'01KTF88EJWY035H8A7X8MFBX5B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3677,'01KTF88EJY2ZG6M04ZKDVBS3ZG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3678,'01KTF88EJZ2NSJAGS900TSSE5H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3679,'01KTF88EK0DF5SM2GBJY7GSP2H',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3680,'01KTF88EK14YFDF5STSK62378Y',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3681,'01KTF88EK3144A4WW9B6VX3TSW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3682,'01KTF88EK430P138FXKBZ3Q3R5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3683,'01KTF88EK5C0GM4GZR9525G53X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3684,'01KTF88EK7GRQH2H3BADC8MNPE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3685,'01KTF88EK80PM5FPSMF01058ET',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3686,'01KTF88EKBRAJWT2C33P251ZPC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3687,'01KTF88EKBCXX0M712058TY5A9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3688,'01KTF88EKDZQYEQ2AHGQWBMP5M',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3689,'01KTF88EKFERQR34DSBE9D7YWN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3690,'01KTF88EKG64W7FCATAMREYQ2Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3691,'01KTF88EKHFTTN30TWR9NGB0YF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3692,'01KTF88EKJT8VP2VYZ37QB7222',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3693,'01KTF88EKMZ61ZJQPK4T4F00KE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3694,'01KTF88EKN5B0MPMMXR1WJXPVW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3695,'01KTF88EKPCMB67558AJDK96WD',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3696,'01KTF88EKQQ2Z2J9KJWYE2YF67',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3697,'01KTF88EKS798CWR2YY4R96BQ0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3698,'01KTF88EKTH966JVQBZB1TZAXC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3699,'01KTF88EKV8ZEBZR5M8MNKWH43',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3700,'01KTF88EKWG0SN45JKDXDX8J26',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3701,'01KTF88EKYRPCGW6S1PYVYZVPX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3702,'01KTF88EKZM1D5E4Z817XPYY17',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3703,'01KTF88EM04T7QSYN100SRT8MR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3704,'01KTF88EM1V46PADED5KGAY3C2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3705,'01KTF88EM5DWJKWN7MPTR29PR8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3706,'01KTF88EMAM8XSWRPY5PW01217',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3707,'01KTF88EMAVX44AJMYE1SNQZTC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3708,'01KTF88EMBSW47E787AQEDMMHV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3709,'01KTF88EMFWTFQYY1YWFKM37X4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3710,'01KTF88EMJMK9BQ0AXCQWEY7NV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3711,'01KTF88EMMAC355QJ6FMECA8XS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3712,'01KTF88EMNFK3A7JNW066N95J4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3713,'01KTF88EMP6YP9VDPYAV1YBFCE',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3714,'01KTF88EMRRZ9FP5B0XDB07DK1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3715,'01KTF88EMS3H3B2117VJ0NBQAB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3716,'01KTF88EMVDKAPEQVDJ03KDX42',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3717,'01KTF88EMWG5QZZK8FKTFR01WY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3718,'01KTF88EMXSS30KWQXDWJYACFC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3719,'01KTF88EMYZ7XF8XS7C4N9M1FB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3720,'01KTF88EN0YCEEWFGNJ5ERYKJP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3721,'01KTF88EN1PGGP1WEY9J25HMPQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3722,'01KTF88EN34PTDD8WWQ95BJQFT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3723,'01KTF88EN53F84E27ZBVA3CRXF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3724,'01KTF88EN7NBKVEZRVKE4T8HGV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3725,'01KTF88EN8GGPF2340D4RFMT0R',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3726,'01KTF88ENEEBZ4HTSAWXEAQ726',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3727,'01KTF88ENF1GZEQ20ANP0JBB6V',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3728,'01KTF88ENMG7K9HS3YVFAWFP7G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3729,'01KTF88ENPRWQ934A2CYSE4SES',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3730,'01KTF88ENRDCXC49KC7VMV9VCN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3731,'01KTF88ENTGAXA0GVRPVJ86V2B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3732,'01KTF88ENWWNHE3A3NN8FS7P5B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3733,'01KTF88ENYQ0JH4WFRC0FAGCCT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3734,'01KTF88EP4EAKG07GNQ30BVTNT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3735,'01KTF88EP60Q0HZTRJM9S75P6K',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3736,'01KTF88EP6A67C3007QX9R6BYK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3737,'01KTF88EP854NCE5FRW987HTKC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3738,'01KTF88EPAWFE358G5BD37EV6T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3739,'01KTF88EPA9R638RNG5QPR99XC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3740,'01KTF88EPCAG7XFC8V69N8F6HG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3741,'01KTF88EPDG2JSS9HZGSKKX1WS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3742,'01KTF88EPE67ZHCG0XQGFSCZ70',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3743,'01KTF88EPGNMDY3N8KS00NWHAB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3744,'01KTF88EPHRC212ZB2PNXX0041',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3745,'01KTF88EPKM28TYHJ09KHFDR81',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3746,'01KTF88EPMBPC8QTMMWXFV8YWB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3747,'01KTF88EPN5W31X55GGF8CGA0A',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3748,'01KTF88EPPAYY4EAWZ0XANGRYH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3749,'01KTF88EPRFBVXWWEERQEPPWHF',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3750,'01KTF88EPS32P19NHVDBG9WRZT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3751,'01KTF88EPTZN3EFH3V8MV0M9H8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3752,'01KTF88EPXVC14VBTXJKPWQ8AC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3753,'01KTF88EPY1G6SPHE3FSSEPN62',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3754,'01KTF88EPZM9XWGJRKCK05YF60',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3755,'01KTF88EQ1K13ZGRK7NGPB55S4',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3756,'01KTF88EQ27PXHHCGY5FCHN88X',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3757,'01KTF88EQ3TVMQH1XBQC2KQG8P',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3758,'01KTF88EQ4HX3QTH6NY23KZVN2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3759,'01KTF88EQ6NXMJ37GM2DXH9KEN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3760,'01KTF88EQ7K3P37D68Q36N1MWZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3761,'01KTF88EQACHB1E51QK5K75GJ8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3762,'01KTF88EQBZV4RTN148DCQCDBG',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3763,'01KTF88EQF38WH16XV3JG6HN0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3764,'01KTF88EQFFZKYQGA9GS5996DR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3765,'01KTF88EQJN3WYVWVF13T2C4MK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3766,'01KTF88EQPMF8T5GZEGZP98SHQ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3767,'01KTF88EQQBMJCAHV2SATJWC3G',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3768,'01KTF88EQV8P4BFNJRMGNHFMVZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3769,'01KTF88EQWMEQ8TVB3CVBM3JGH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3770,'01KTF88EQYFVC9HAK8MK6ST4D2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3771,'01KTF88ER03H3EFMXQPBN5SA5W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3772,'01KTF88ER4QV5F26VDETPEFX9J',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3773,'01KTF88ER7CGE9QCQVHK55YYFK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3774,'01KTF88ERAB8AWJMQK57E1C3TN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3775,'01KTF88ERCWNEFNAWN0JRNA5RP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3776,'01KTF88ERFMSCJHC01M2A4EC4Q',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3777,'01KTF88ERJCYQ01NY86BHYCYMV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3778,'01KTF88ERJ7G372W3HCHE1HC0W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3779,'01KTF88ERNFJZREWRA4MZ0X5E5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3780,'01KTF88ERPXTMATJNB7416QE2B',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3781,'01KTF88ERSWB0TMWFZQNQVN2VV',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3782,'01KTF88ES7E8W8EDTJX2DEZDP9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3783,'01KTF88ESBQFBBJFW332JB4NR1',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3784,'01KTF88ESCYJMNP386ZZZ1BKYP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3785,'01KTF88ESCCNTKN5KZPRHHJGZJ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3786,'01KTF88ESEG60GH05VGHSN8BBP',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3787,'01KTF88ESFY147Y0RTT0157MZ3',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3788,'01KTF88ESGPHA00GQ7PBFD8TKH',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3789,'01KTF88ESJHYK85V0G8B05J0CM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3790,'01KTF88ESKTRM51602VQY9VRFT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3791,'01KTF88ESNTZ1KQWFSEWQN5RXW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3792,'01KTF88ESP86E4QPW30KYN8RCX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3793,'01KTF88ESRV5ZD8VNKE7KBMHPS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3794,'01KTF88ESRX2H897AK63NDFGTB',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3795,'01KTF88ESXG0AX4GJ4TEWZM0MW',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3796,'01KTF88ESWWH90DAS0VGVG1XVK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3797,'01KTF88ET4HW75JNVQBRW23SWS',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3798,'01KTF88ET8BYKXSFAN33HCKJWT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3799,'01KTF88ETATVGCYZR6S2DVDF75',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3800,'01KTF88ETDJRXE8VH8H2GT52X2',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3801,'01KTF88ETE56HSHR95DXYRXNH0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3802,'01KTF88ETHZQDPQ58ES1560FA8',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3803,'01KTF88ETJHN8DNB1F3QK8PZY0',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3804,'01KTF88ETPCT9YHQYEVXG5JN38',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3805,'01KTF88ETVP4XVH57H8NXXQ04T',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3806,'01KTF88ETVYF9Y4SVX91842BJK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3807,'01KTF88ETYTA79KM57N837C6V5',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3808,'01KTF88EV823VS4TBZP9Q62WHY',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3809,'01KTF88EV9CYX4DV2A4Q0ACYMX',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3810,'01KTF88EVAHPN378JTSY6Z42YM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3811,'01KTF88EVCCS4FJ9ZHZCMEA5YZ',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3812,'01KTF88EVDGQ5CDY0837FB11HN',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3813,'01KTF88EVGE7NX73WN5C9T3E9D',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3814,'01KTF88EVHYA6FBN8C1Z7XWVTM',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3815,'01KTF88EVKGDVHF5QWXREK3RHC',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3816,'01KTF88EVMS1EWYH936ZVJ9P3C',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3817,'01KTF88EVQQB0SJD8YRNNGB0A7',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3818,'01KTF88EVRAGV447J9NQWATY92',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3819,'01KTF88EVS9FMW2CD4KVDRQ6B9',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3820,'01KTF88EWXH77T7K55G3WXQE96',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3821,'01KTF88EX2QYSGR6PSR9FWQ9ZT',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3822,'01KTF88EX4E33NTA05G61VSQ4W',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3823,'01KTF88EXC801NHPVDX07KBVDR',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3824,'01KTF88EXK25NS2GFH2EQM2FNK',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46'),
(3825,'01KTF88EZ7CY62557E2DSYF606',1,NULL,10.0000,'cash','completed','[{\"product_id\":1,\"qty\":1,\"price\":10}]',NULL,NULL,NULL,NULL,'SOUTH','2026-06-06 19:58:46','2026-06-06 19:58:46');
/*!40000 ALTER TABLE `merchant_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_usage_counters`
--

DROP TABLE IF EXISTS `merchant_usage_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_usage_counters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `counter_type` varchar(32) NOT NULL,
  `period_key` varchar(16) NOT NULL,
  `count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_incremented_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `muc_unique` (`merchant_user_id`,`counter_type`,`period_key`),
  KEY `muc_merchant_period` (`merchant_user_id`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_usage_counters`
--

LOCK TABLES `merchant_usage_counters` WRITE;
/*!40000 ALTER TABLE `merchant_usage_counters` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchant_usage_counters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchant_verification_requests`
--

DROP TABLE IF EXISTS `merchant_verification_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchant_verification_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_ulid` varchar(26) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `business_name` varchar(255) NOT NULL,
  `commercial_register_number` varchar(64) DEFAULT NULL,
  `business_category` varchar(80) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `id_card_front_path` varchar(500) DEFAULT NULL,
  `id_card_back_path` varchar(500) DEFAULT NULL,
  `commercial_register_path` varchar(500) DEFAULT NULL,
  `store_photo_path` varchar(500) DEFAULT NULL,
  `address_proof_path` varchar(500) DEFAULT NULL,
  `profession_license_path` varchar(500) DEFAULT NULL,
  `optional_document_path` varchar(500) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account_number` varchar(64) DEFAULT NULL,
  `bank_account_holder` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(32) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_review',
  `admin_note` text DEFAULT NULL,
  `reviewed_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `merchant_verification_requests_request_ulid_unique` (`request_ulid`),
  KEY `merchant_verification_requests_merchant_user_id_status_index` (`merchant_user_id`,`status`),
  KEY `merchant_verification_requests_reviewed_at_index` (`reviewed_at`),
  KEY `merchant_verification_requests_merchant_user_id_index` (`merchant_user_id`),
  KEY `merchant_verification_requests_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchant_verification_requests`
--

LOCK TABLES `merchant_verification_requests` WRITE;
/*!40000 ALTER TABLE `merchant_verification_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchant_verification_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchants`
--

DROP TABLE IF EXISTS `merchants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) DEFAULT NULL,
  `store_name` varchar(255) DEFAULT NULL,
  `callback` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `bin` varchar(255) DEFAULT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `secret_key` varchar(255) DEFAULT NULL,
  `merchant_number` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchants`
--

LOCK TABLES `merchants` WRITE;
/*!40000 ALTER TABLE `merchants` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'2014_10_12_000000_create_users_table',1),
(2,'2014_10_12_100000_create_password_resets_table',1),
(3,'2016_06_01_000001_create_oauth_auth_codes_table',1),
(4,'2016_06_01_000002_create_oauth_access_tokens_table',1),
(5,'2016_06_01_000003_create_oauth_refresh_tokens_table',1),
(6,'2016_06_01_000004_create_oauth_clients_table',1),
(7,'2016_06_01_000005_create_oauth_personal_access_clients_table',1),
(8,'2019_08_19_000000_create_failed_jobs_table',1),
(9,'2019_12_14_000001_create_personal_access_tokens_table',1),
(10,'2021_06_17_054551_create_soft_credentials_table',1),
(11,'2021_11_18_104105_create_business_settings_table',1),
(12,'2021_11_20_090531_create_currencies_table',1),
(13,'2021_11_22_065212_add_last_active_at_to_users_table',1),
(14,'2021_11_23_090107_create_linked_websites_table',1),
(15,'2021_11_23_104425_add_reference_columns_to_users_table',1),
(16,'2021_11_23_123056_create_notifications_table',1),
(17,'2021_11_27_041913_create_phone_verifications_table',1),
(18,'2021_11_27_052236_add_columns_to_users_table',1),
(19,'2021_11_29_100204_create_transfers_table',1),
(20,'2021_12_01_053955_create_transactions_table',1),
(21,'2021_12_01_063108_create_e_money_table',1),
(22,'2021_12_04_113130_create_request_money_table',1),
(23,'2021_12_05_051247_create_funds_table',1),
(24,'2021_12_06_101224_create_purposes_table',1),
(25,'2021_12_14_104755_add_note_column_to_transaction',1),
(26,'2021_12_19_071059_add_twofactor_and_fcmtoken_to_users_table',1),
(27,'2021_12_21_110529_create_banners_table',1),
(28,'2021_12_22_121505_add_receiver_column_to_notifications',1),
(29,'2021_12_26_061202_create_help_topics_table',1),
(30,'2022_02_01_041254_add_transaction_i_d_to_transactions',1),
(31,'2022_02_01_065231_type_change_of_ref_trans_id_to_transactions',1),
(32,'2022_04_07_045435_add_receiver_to_banner_table',1),
(33,'2022_04_07_060244_add_is_active_column_to_to_users_table',1),
(34,'2022_06_30_051435_add_column_to_user_table',1),
(35,'2022_07_05_102531_change_data_type_of_transfer_table',1),
(36,'2022_10_16_063545_create_withdrawal_methods_table',1),
(37,'2022_10_18_040302_create_withdraw_requests_table',1),
(38,'2022_10_18_141838_create_user_log_histories_table',1),
(39,'2022_11_08_055006_change_default_kyc_status',1),
(40,'2022_12_08_045549_create_merchants_table',1),
(41,'2022_12_11_050638_create_payment_records_table',1),
(42,'2022_12_21_041139_add_column_dail_country_code_to_users_table',1),
(43,'2022_12_26_122524_add_expired_at_column_in_payment_records_table',1),
(44,'2023_01_23_065548_add_pending_balance_in_e_money_table',1),
(45,'2023_03_25_082756_create_bonuses_table',1),
(46,'2023_03_29_085117_add_col_to_withdraw_requests_table',1),
(47,'2023_04_03_030436_add_column_to_transactions_table',1),
(48,'2023_05_11_084421_change_notifications_table_column_type',1),
(49,'2023_05_15_153550_add_otp_hist_counts_column_in_phone_verification_tabel',1),
(50,'2023_05_25_083248_add_multiple_column_to_password_resets',1),
(51,'2023_05_25_083248_add_multiple_column_to_users',1),
(52,'2023_05_28_085211_create_transaction_limits_table',1),
(53,'2023_05_31_051107_add_soft_delete_in_users',1),
(54,'2023_09_18_042428_create_news_letters_table',1),
(55,'2023_09_18_054929_create_contact_messages_table',1),
(56,'2023_09_19_045232_change_identification_image_to_users_table',1),
(57,'2023_09_20_095151_create_social_media_table',1),
(58,'2024_11_25_100547_create_faqs_table',1),
(59,'2024_11_25_100656_create_blogs_table',1),
(60,'2024_11_27_170730_create_blog_categories_table',1),
(61,'2024_11_30_155612_create_faq_categories_table',1),
(62,'2024_12_03_114540_add_slug_in_blogs',1),
(63,'2024_12_03_114849_add_slug_in_blog_categories',1),
(64,'2024_12_10_191010_add_is_published_in_blogs',1),
(65,'2025_05_31_151928_create_disputes_table',1),
(66,'2025_05_31_161629_create_favourite_numbers_table',1),
(67,'2025_06_03_145000_create_dispute_reasons_table',1),
(68,'2025_06_19_155540_add_charge_in_transactions_table',1),
(69,'2025_10_20_155620_change_draft_data_column_type_in_blogs',1),
(70,'2026_05_15_100001_amial_create_idempotency_keys_table',1),
(71,'2026_05_15_100002_amial_add_transaction_pin_to_users',1),
(72,'2026_05_15_100003_amial_refactor_transactions_table',1),
(73,'2026_05_15_100004_amial_refactor_e_money_table',1),
(74,'2026_05_15_100005_amial_create_audit_decisions_table',1),
(75,'2026_05_15_100006_amial_create_account_security_events_table',1),
(76,'2026_05_15_110001_amial_add_zone_to_users',1),
(77,'2026_05_15_110002_amial_create_legal_terms_table',1),
(78,'2026_05_15_110003_amial_create_user_legal_acceptances_table',1),
(79,'2026_05_15_110004_amial_create_account_recovery_requests_table',1),
(80,'2026_05_16_120001_amial_create_receipts_table',1),
(81,'2026_05_16_120002_amial_create_family_funds_table',1),
(82,'2026_05_16_120003_amial_create_family_fund_members_table',1),
(83,'2026_05_16_120004_amial_create_family_fund_transactions_table',1),
(84,'2026_05_16_120005_amial_create_bill_pay_structure_table',1),
(85,'2026_05_16_120006_amial_create_bill_pay_orders_table',1),
(86,'2026_05_17_100001_amial_create_rbac_tables',1),
(87,'2026_05_17_140001_amial_create_safe_payments_table',1),
(88,'2026_05_17_140002_amial_create_safe_payment_events_table',1),
(89,'2026_05_17_180001_amial_create_charity_organizations_table',1),
(90,'2026_05_17_180002_amial_create_charity_campaigns_donations_settlements',1),
(91,'2026_05_17_180003_amial_add_donation_receipt_types',1),
(92,'2026_05_18_100001_amial_add_pii_encryption_columns',1),
(93,'2026_05_18_100002_amial_create_pii_access_logs_table',1),
(94,'2026_05_18_120001_amial_create_aml_rules_evaluations',1),
(95,'2026_05_18_120002_amial_create_aml_flagged_profiles_alerts',1),
(96,'2026_05_18_140001_amial_unified_login_columns',1),
(97,'2026_05_18_140002_amial_performance_indexes',1),
(98,'2026_05_19_100001_amial_create_double_entry_ledger',1),
(99,'2026_05_19_100002_amial_create_merchant_refunds',1),
(100,'2026_05_19_120001_amial_add_two_factor_auth',1),
(101,'2026_05_19_130001_amial_sanction_and_kyc_tiers',1),
(102,'2026_05_22_100001_amial_fix_zone_default',1),
(103,'2026_05_22_110001_amial_create_agent_network',1),
(104,'2026_05_22_120001_amial_aml_shadow_mode',1),
(105,'2026_05_22_140001_amial_create_pending_transfers',1),
(106,'2026_05_23_100001_amial_create_merchant_risk',1),
(107,'2026_05_23_110001_amial_create_report_exports',1),
(108,'2026_05_23_120001_amial_create_fee_engine',1),
(109,'2026_05_23_130001_amial_add_fee_snapshot_to_transactions',1),
(110,'2026_05_23_140001_amial_add_fee_snapshot_to_safe_payments',1),
(111,'2026_05_23_150001_amial_add_pos_user_to_transactions',1),
(112,'2026_05_23_160001_amial_create_split_bills',1),
(113,'2026_05_23_170001_amial_create_cashier',1),
(114,'2026_05_23_180001_amial_extend_cashier_products',1),
(115,'2026_05_24_120001_amial_add_account_number_to_users',1),
(116,'2026_05_24_120002_amial_create_withdrawal_requests',1),
(117,'2026_05_24_130001_amial_create_platform_fee_entries',1),
(118,'2026_05_24_140001_amial_create_customer_credit',1),
(119,'2026_05_24_150001_amial_create_notifications',1),
(120,'2026_05_24_160001_amial_extend_merchant_refunds',1),
(121,'2026_05_25_100001_amial_create_payment_requests',1),
(122,'2026_05_25_110001_amial_merchant_verification_requests',1),
(123,'2026_05_31_100001_amial_create_fuel_station_tables',1),
(124,'2026_05_31_120001_amial_fuel_shifts_cards_variance',1),
(125,'2026_06_01_100001_amial_create_pharmacy_tables',1),
(126,'2026_06_02_100001_amial_critical_role_subscription_features',1),
(127,'2026_06_03_100001_amial_create_wholesale_tables',1),
(128,'2026_06_04_100001_amial_create_usage_counters',1),
(129,'2026_06_04_200001_amial_create_subscription_changes',1),
(130,'2026_06_05_100001_amial_create_branches',1),
(131,'2026_06_05_100002_amial_link_branches',1),
(132,'2026_06_05_100003_amial_create_rbac',1),
(133,'2026_06_06_100001_amial_create_sentinel_events',1),
(134,'2026_06_06_100002_amial_create_sentinel_blocked_ips',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `news_letters`
--

DROP TABLE IF EXISTS `news_letters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `news_letters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL COMMENT 'Subscribers email',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `news_letters_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `news_letters`
--

LOCK TABLES `news_letters` WRITE;
/*!40000 ALTER TABLE `news_letters` DISABLE KEYS */;
/*!40000 ALTER TABLE `news_letters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` text NOT NULL,
  `description` text NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `receiver` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_access_tokens`
--

DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` varchar(100) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_access_tokens`
--

LOCK TABLES `oauth_access_tokens` WRITE;
/*!40000 ALTER TABLE `oauth_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_auth_codes`
--

DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` varchar(100) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_auth_codes`
--

LOCK TABLES `oauth_auth_codes` WRITE;
/*!40000 ALTER TABLE `oauth_auth_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_auth_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_clients`
--

DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `secret` varchar(100) DEFAULT NULL,
  `provider` varchar(255) DEFAULT NULL,
  `redirect` text NOT NULL,
  `personal_access_client` tinyint(1) NOT NULL,
  `password_client` tinyint(1) NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_clients`
--

LOCK TABLES `oauth_clients` WRITE;
/*!40000 ALTER TABLE `oauth_clients` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_clients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_personal_access_clients`
--

DROP TABLE IF EXISTS `oauth_personal_access_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_personal_access_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_personal_access_clients`
--

LOCK TABLES `oauth_personal_access_clients` WRITE;
/*!40000 ALTER TABLE `oauth_personal_access_clients` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_personal_access_clients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_refresh_tokens`
--

DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` varchar(100) NOT NULL,
  `access_token_id` varchar(100) NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_refresh_tokens`
--

LOCK TABLES `oauth_refresh_tokens` WRITE;
/*!40000 ALTER TABLE `oauth_refresh_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_refresh_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `phone` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `otp_hit_count` tinyint(4) NOT NULL DEFAULT 0,
  `is_temp_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `temp_block_time` timestamp NULL DEFAULT NULL,
  KEY `password_resets_phone_index` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_records`
--

DROP TABLE IF EXISTS `payment_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_records` (
  `id` char(36) NOT NULL,
  `merchant_user_id` bigint(20) DEFAULT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `callback` varchar(255) DEFAULT NULL,
  `is_paid` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=unpaid, 1=paid',
  `expired_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_records`
--

LOCK TABLES `payment_records` WRITE;
/*!40000 ALTER TABLE `payment_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_requests`
--

DROP TABLE IF EXISTS `payment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_ulid` varchar(26) NOT NULL,
  `short_code` varchar(8) NOT NULL,
  `requester_user_id` bigint(20) unsigned NOT NULL,
  `recipient_user_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_phone` varchar(32) DEFAULT NULL,
  `recipient_name` varchar(120) DEFAULT NULL,
  `amount` decimal(20,4) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `share_method` varchar(16) NOT NULL DEFAULT 'link',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `paid_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `paid_transaction_id` varchar(64) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `recurring_period` varchar(16) DEFAULT NULL,
  `parent_request_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_requests_request_ulid_unique` (`request_ulid`),
  UNIQUE KEY `payment_requests_short_code_unique` (`short_code`),
  KEY `payment_requests_requester_user_id_status_index` (`requester_user_id`,`status`),
  KEY `payment_requests_recipient_user_id_status_index` (`recipient_user_id`,`status`),
  KEY `payment_requests_expires_at_index` (`expires_at`),
  KEY `payment_requests_requester_user_id_index` (`requester_user_id`),
  KEY `payment_requests_recipient_user_id_index` (`recipient_user_id`),
  KEY `payment_requests_status_index` (`status`),
  KEY `payment_requests_parent_request_id_index` (`parent_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_requests`
--

LOCK TABLES `payment_requests` WRITE;
/*!40000 ALTER TABLE `payment_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pending_transfers`
--

DROP TABLE IF EXISTS `pending_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pending_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_ulid` varchar(26) NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `recipient_user_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(20,4) NOT NULL,
  `fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_debited` decimal(20,4) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `status` enum('holding','completed','cancelled','failed') NOT NULL DEFAULT 'holding',
  `releasable_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(300) DEFAULT NULL,
  `hold_transaction_id` varchar(64) DEFAULT NULL,
  `release_transaction_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pending_transfers_transfer_ulid_unique` (`transfer_ulid`),
  KEY `pending_transfers_status_releasable_at_index` (`status`,`releasable_at`),
  KEY `pending_transfers_sender_user_id_created_at_index` (`sender_user_id`,`created_at`),
  KEY `pending_transfers_recipient_user_id_status_index` (`recipient_user_id`,`status`),
  KEY `pending_transfers_status_index` (`status`),
  CONSTRAINT `pending_transfers_recipient_user_id_foreign` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `pending_transfers_sender_user_id_foreign` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pending_transfers`
--

LOCK TABLES `pending_transfers` WRITE;
/*!40000 ALTER TABLE `pending_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `pending_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `label_ar` varchar(100) NOT NULL,
  `category` varchar(32) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_code_unique` (`code`),
  KEY `permissions_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacies`
--

DROP TABLE IF EXISTS `pharmacies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pharmacy_name` varchar(200) NOT NULL,
  `license_number` varchar(64) DEFAULT NULL,
  `pharmacist_name` varchar(120) DEFAULT NULL,
  `pharmacist_license` varchar(64) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pharmacies_merchant_user_id_unique` (`merchant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacies`
--

LOCK TABLES `pharmacies` WRITE;
/*!40000 ALTER TABLE `pharmacies` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_batches`
--

DROP TABLE IF EXISTS `pharmacy_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_ulid` varchar(26) NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_number` varchar(64) NOT NULL,
  `expiry_date` date NOT NULL,
  `received_date` date DEFAULT NULL,
  `quantity_received` decimal(14,4) NOT NULL,
  `quantity_remaining` decimal(14,4) NOT NULL,
  `cost_per_unit` decimal(14,4) DEFAULT NULL,
  `supplier_name` varchar(200) DEFAULT NULL,
  `supplier_invoice` varchar(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pharmacy_batches_batch_ulid_unique` (`batch_ulid`),
  KEY `pharmacy_batches_product_id_status_expiry_date_index` (`product_id`,`status`,`expiry_date`),
  KEY `pharmacy_batches_product_id_index` (`product_id`),
  KEY `pharmacy_batches_expiry_date_index` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_batches`
--

LOCK TABLES `pharmacy_batches` WRITE;
/*!40000 ALTER TABLE `pharmacy_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_categories`
--

DROP TABLE IF EXISTS `pharmacy_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `icon` varchar(40) DEFAULT NULL,
  `color_hex` varchar(7) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pharmacy_categories_pharmacy_id_index` (`pharmacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_categories`
--

LOCK TABLES `pharmacy_categories` WRITE;
/*!40000 ALTER TABLE `pharmacy_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_customers`
--

DROP TABLE IF EXISTS `pharmacy_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(8) DEFAULT NULL,
  `is_pregnant` tinyint(1) NOT NULL DEFAULT 0,
  `is_breastfeeding` tinyint(1) NOT NULL DEFAULT 0,
  `allergies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allergies`)),
  `chronic_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`chronic_conditions`)),
  `regular_medications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`regular_medications`)),
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pharmacy_customer_phone_unique` (`pharmacy_id`,`phone`),
  KEY `pharmacy_customers_pharmacy_id_index` (`pharmacy_id`),
  KEY `pharmacy_customers_phone_index` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_customers`
--

LOCK TABLES `pharmacy_customers` WRITE;
/*!40000 ALTER TABLE `pharmacy_customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_products`
--

DROP TABLE IF EXISTS `pharmacy_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `trade_name` varchar(200) NOT NULL,
  `generic_name` varchar(200) DEFAULT NULL,
  `manufacturer` varchar(120) DEFAULT NULL,
  `unit` varchar(32) NOT NULL DEFAULT 'قطعة',
  `sale_price` decimal(14,4) NOT NULL,
  `cost_price` decimal(14,4) DEFAULT NULL,
  `requires_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 10,
  `description` text DEFAULT NULL,
  `dosage_instructions` text DEFAULT NULL,
  `current_stock` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `image_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pharmacy_products_pharmacy_id_is_active_index` (`pharmacy_id`,`is_active`),
  KEY `pharmacy_products_pharmacy_id_trade_name_index` (`pharmacy_id`,`trade_name`),
  KEY `pharmacy_products_pharmacy_id_index` (`pharmacy_id`),
  KEY `pharmacy_products_category_id_index` (`category_id`),
  KEY `pharmacy_products_barcode_index` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_products`
--

LOCK TABLES `pharmacy_products` WRITE;
/*!40000 ALTER TABLE `pharmacy_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_sale_items`
--

DROP TABLE IF EXISTS `pharmacy_sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_sale_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `product_trade_name` varchar(200) NOT NULL,
  `quantity` decimal(14,4) NOT NULL,
  `unit_price` decimal(14,4) NOT NULL,
  `total_price` decimal(14,4) NOT NULL,
  `required_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pharmacy_sale_items_sale_id_index` (`sale_id`),
  KEY `pharmacy_sale_items_product_id_index` (`product_id`),
  KEY `pharmacy_sale_items_batch_id_index` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_sale_items`
--

LOCK TABLES `pharmacy_sale_items` WRITE;
/*!40000 ALTER TABLE `pharmacy_sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_sales`
--

DROP TABLE IF EXISTS `pharmacy_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `sale_ulid` varchar(26) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `pharmacy_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `prescription_number` varchar(64) DEFAULT NULL,
  `prescribing_doctor` varchar(200) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `subtotal` decimal(14,4) NOT NULL,
  `discount_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(14,4) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `paid_transaction_id` varchar(64) DEFAULT NULL,
  `warnings_acknowledged` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`warnings_acknowledged`)),
  `status` varchar(16) NOT NULL DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pharmacy_sales_sale_ulid_unique` (`sale_ulid`),
  KEY `pharmacy_sales_pharmacy_id_created_at_index` (`pharmacy_id`,`created_at`),
  KEY `pharmacy_sales_customer_id_created_at_index` (`customer_id`,`created_at`),
  KEY `pharmacy_sales_merchant_user_id_index` (`merchant_user_id`),
  KEY `pharmacy_sales_pharmacy_id_index` (`pharmacy_id`),
  KEY `pharmacy_sales_customer_id_index` (`customer_id`),
  KEY `ps_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_sales`
--

LOCK TABLES `pharmacy_sales` WRITE;
/*!40000 ALTER TABLE `pharmacy_sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pharmacy_stock_alerts`
--

DROP TABLE IF EXISTS `pharmacy_stock_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pharmacy_stock_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `alert_type` varchar(32) NOT NULL,
  `severity` varchar(16) NOT NULL,
  `message` text NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pharmacy_stock_alerts_pharmacy_id_status_severity_index` (`pharmacy_id`,`status`,`severity`),
  KEY `pharmacy_stock_alerts_pharmacy_id_index` (`pharmacy_id`),
  KEY `pharmacy_stock_alerts_product_id_index` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pharmacy_stock_alerts`
--

LOCK TABLES `pharmacy_stock_alerts` WRITE;
/*!40000 ALTER TABLE `pharmacy_stock_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `pharmacy_stock_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `phone_verifications`
--

DROP TABLE IF EXISTS `phone_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(255) DEFAULT NULL,
  `otp` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `otp_hit_count` tinyint(4) NOT NULL DEFAULT 0,
  `is_temp_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `temp_block_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `phone_verifications`
--

LOCK TABLES `phone_verifications` WRITE;
/*!40000 ALTER TABLE `phone_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `phone_verifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pii_access_logs`
--

DROP TABLE IF EXISTS `pii_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pii_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `subject_type` varchar(50) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `access_reason` varchar(500) DEFAULT NULL,
  `access_type` enum('view','decrypt_file','export') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pii_access_logs_actor_user_id_created_at_index` (`actor_user_id`,`created_at`),
  KEY `pii_access_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `pii_access_logs_field_name_created_at_index` (`field_name`,`created_at`),
  CONSTRAINT `pii_access_logs_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pii_access_logs`
--

LOCK TABLES `pii_access_logs` WRITE;
/*!40000 ALTER TABLE `pii_access_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `pii_access_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_fee_entries`
--

DROP TABLE IF EXISTS `platform_fee_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_fee_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(20,4) NOT NULL,
  `source_type` varchar(32) DEFAULT NULL,
  `transaction_id` varchar(40) DEFAULT NULL,
  `from_user_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_fee_entries_reconciled_id_index` (`reconciled`,`id`),
  KEY `platform_fee_entries_admin_user_id_index` (`admin_user_id`),
  KEY `platform_fee_entries_transaction_id_index` (`transaction_id`),
  KEY `platform_fee_entries_reconciled_index` (`reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_fee_entries`
--

LOCK TABLES `platform_fee_entries` WRITE;
/*!40000 ALTER TABLE `platform_fee_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_fee_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_user_roles`
--

DROP TABLE IF EXISTS `pos_user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pos_user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `branch_scope_id` bigint(20) unsigned DEFAULT NULL,
  `granted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pur_unique` (`pos_user_id`,`role_id`,`branch_scope_id`),
  KEY `pur_pos` (`pos_user_id`),
  KEY `pur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_user_roles`
--

LOCK TABLES `pos_user_roles` WRITE;
/*!40000 ALTER TABLE `pos_user_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_users`
--

DROP TABLE IF EXISTS `pos_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pos_number` varchar(20) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_merchant_number_unique` (`merchant_user_id`,`pos_number`),
  KEY `pos_users_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `pu_branch` (`branch_id`),
  CONSTRAINT `pos_users_merchant_user_id_foreign` FOREIGN KEY (`merchant_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_users`
--

LOCK TABLES `pos_users` WRITE;
/*!40000 ALTER TABLE `pos_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purposes`
--

DROP TABLE IF EXISTS `purposes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `purposes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `logo` varchar(255) NOT NULL,
  `color` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purposes`
--

LOCK TABLES `purposes` WRITE;
/*!40000 ALTER TABLE `purposes` DISABLE KEYS */;
/*!40000 ALTER TABLE `purposes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rbac_audit_log`
--

DROP TABLE IF EXISTS `rbac_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rbac_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `subject_type` varchar(50) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `before_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_state`)),
  `after_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_state`)),
  `reason` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rbac_audit_log_actor_user_id_index` (`actor_user_id`),
  KEY `rbac_audit_log_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `rbac_audit_log_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rbac_audit_log`
--

LOCK TABLES `rbac_audit_log` WRITE;
/*!40000 ALTER TABLE `rbac_audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `rbac_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rbac_permissions`
--

DROP TABLE IF EXISTS `rbac_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rbac_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `group` varchar(50) NOT NULL,
  `name_ar` varchar(200) NOT NULL,
  `description_ar` varchar(500) DEFAULT NULL,
  `is_sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rbac_permissions_code_unique` (`code`),
  KEY `rbac_permissions_group_index` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rbac_permissions`
--

LOCK TABLES `rbac_permissions` WRITE;
/*!40000 ALTER TABLE `rbac_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `rbac_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rbac_role_permissions`
--

DROP TABLE IF EXISTS `rbac_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rbac_role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `granted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `rbac_role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`),
  KEY `rbac_role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `rbac_role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `rbac_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rbac_role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rbac_role_permissions`
--

LOCK TABLES `rbac_role_permissions` WRITE;
/*!40000 ALTER TABLE `rbac_role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `rbac_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rbac_roles`
--

DROP TABLE IF EXISTS `rbac_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rbac_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name_ar` varchar(100) NOT NULL,
  `description_ar` varchar(500) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rbac_roles_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rbac_roles`
--

LOCK TABLES `rbac_roles` WRITE;
/*!40000 ALTER TABLE `rbac_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `rbac_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rbac_user_roles`
--

DROP TABLE IF EXISTS `rbac_user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rbac_user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `assigned_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_role_unique` (`user_id`,`role_id`),
  KEY `rbac_user_roles_user_id_revoked_at_index` (`user_id`,`revoked_at`),
  KEY `rbac_user_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `rbac_user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `rbac_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rbac_user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rbac_user_roles`
--

LOCK TABLES `rbac_user_roles` WRITE;
/*!40000 ALTER TABLE `rbac_user_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `rbac_user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receipts`
--

DROP TABLE IF EXISTS `receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(32) NOT NULL,
  `verification_code` varchar(16) NOT NULL,
  `receipt_type` enum('send_money','cash_in','cash_out','add_money','withdraw','pay_merchant','pos_payment','qr_payment','refund','safe_payment_funded','safe_payment_released','safe_payment_refunded','split_bill_payment','family_fund_contribute','family_fund_disburse','bank_settlement','fee_charge','donation','charity_settlement') NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `counterparty_user_id` bigint(20) unsigned DEFAULT NULL,
  `reference_transaction_id` varchar(32) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(20,4) NOT NULL,
  `fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `net_amount` decimal(20,4) NOT NULL,
  `direction` enum('debit','credit') NOT NULL,
  `status` enum('pending_pdf','pdf_generated','pdf_failed','voided') NOT NULL DEFAULT 'pending_pdf',
  `pdf_storage_path` varchar(500) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pdf_generated_at` timestamp NULL DEFAULT NULL,
  `download_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_downloaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipts_receipt_number_unique` (`receipt_number`),
  UNIQUE KEY `receipts_verification_code_unique` (`verification_code`),
  KEY `receipts_user_date_idx` (`user_id`,`issued_at`),
  KEY `receipts_ref_tx_idx` (`reference_transaction_id`),
  KEY `receipts_ref_entity_idx` (`reference_type`,`reference_id`),
  KEY `receipts_counterparty_user_id_foreign` (`counterparty_user_id`),
  KEY `receipts_receipt_type_index` (`receipt_type`),
  KEY `receipts_direction_index` (`direction`),
  KEY `receipts_status_index` (`status`),
  KEY `receipts_zone_code_index` (`zone_code`),
  KEY `idx_receipts_user_created` (`user_id`,`created_at`),
  KEY `idx_receipts_ref_type` (`reference_type`,`reference_id`),
  CONSTRAINT `receipts_counterparty_user_id_foreign` FOREIGN KEY (`counterparty_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `receipts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receipts`
--

LOCK TABLES `receipts` WRITE;
/*!40000 ALTER TABLE `receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_exports`
--

DROP TABLE IF EXISTS `report_exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_exports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `export_ulid` varchar(26) NOT NULL,
  `requested_by_user_id` bigint(20) unsigned NOT NULL,
  `requester_type` varchar(20) NOT NULL DEFAULT 'user',
  `report_type` varchar(50) NOT NULL,
  `format` enum('csv','pdf','excel') NOT NULL DEFAULT 'csv',
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `status` enum('pending','processing','ready','failed','expired') NOT NULL DEFAULT 'pending',
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `row_count` int(11) DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `report_exports_export_ulid_unique` (`export_ulid`),
  KEY `report_exports_requested_by_user_id_created_at_index` (`requested_by_user_id`,`created_at`),
  KEY `report_exports_report_type_status_index` (`report_type`,`status`),
  KEY `report_exports_status_index` (`status`),
  KEY `report_exports_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_exports`
--

LOCK TABLES `report_exports` WRITE;
/*!40000 ALTER TABLE `report_exports` DISABLE KEYS */;
/*!40000 ALTER TABLE `report_exports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_money`
--

DROP TABLE IF EXISTS `request_money`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_money` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_user_id` bigint(20) NOT NULL,
  `to_user_id` bigint(20) NOT NULL,
  `type` varchar(255) NOT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_money`
--

LOCK TABLES `request_money` WRITE;
/*!40000 ALTER TABLE `request_money` DISABLE KEYS */;
/*!40000 ALTER TABLE `request_money` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rp_unique` (`role_id`,`permission_id`),
  KEY `rp_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `label_ar` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `merchant_user_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `r_merchant_code_unique` (`merchant_user_id`,`code`),
  KEY `r_merchant` (`merchant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `safe_payment_events`
--

DROP TABLE IF EXISTS `safe_payment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `safe_payment_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `safe_payment_id` bigint(20) unsigned NOT NULL,
  `event_type` enum('created','seller_accepted','seller_rejected','in_delivery_marked','delivered_marked','buyer_confirmed','released_to_seller','buyer_disputed','buyer_cancelled','admin_resolved_release','admin_resolved_refund','admin_resolved_partial','expired','attachment_added','note_added') NOT NULL,
  `from_status` varchar(50) DEFAULT NULL,
  `to_status` varchar(50) DEFAULT NULL,
  `actor_type` enum('buyer','seller','admin','system') NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `safe_payment_events_safe_payment_id_created_at_index` (`safe_payment_id`,`created_at`),
  KEY `safe_payment_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `safe_payment_events_actor_type_index` (`actor_type`),
  CONSTRAINT `safe_payment_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `safe_payment_events_safe_payment_id_foreign` FOREIGN KEY (`safe_payment_id`) REFERENCES `safe_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `safe_payment_events`
--

LOCK TABLES `safe_payment_events` WRITE;
/*!40000 ALTER TABLE `safe_payment_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `safe_payment_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `safe_payments`
--

DROP TABLE IF EXISTS `safe_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `safe_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_ulid` varchar(26) NOT NULL,
  `buyer_user_id` bigint(20) unsigned NOT NULL,
  `seller_user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `delivery_terms` text DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `amount` decimal(20,4) NOT NULL,
  `platform_fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `fee_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `fee_scheme_version` int(10) unsigned DEFAULT NULL,
  `held_amount` decimal(20,4) NOT NULL,
  `status` enum('pending_seller_acceptance','seller_rejected','funded','in_delivery','delivered','buyer_confirmed','released_to_seller','disputed','refunded_to_buyer','partially_refunded','cancelled','expired') NOT NULL DEFAULT 'pending_seller_acceptance',
  `buyer_debit_tx_id` varchar(32) DEFAULT NULL,
  `seller_credit_tx_id` varchar(32) DEFAULT NULL,
  `buyer_refund_tx_id` varchar(32) DEFAULT NULL,
  `refunded_to_buyer_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `released_to_seller_amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `seller_response_deadline` timestamp NULL DEFAULT NULL,
  `seller_accepted_at` timestamp NULL DEFAULT NULL,
  `seller_rejected_at` timestamp NULL DEFAULT NULL,
  `in_delivery_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `buyer_confirmed_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `is_disputed` tinyint(1) NOT NULL DEFAULT 0,
  `disputed_at` timestamp NULL DEFAULT NULL,
  `admin_resolved_by` bigint(20) unsigned DEFAULT NULL,
  `admin_resolved_at` timestamp NULL DEFAULT NULL,
  `admin_resolution_note` text DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `safe_payments_payment_ulid_unique` (`payment_ulid`),
  KEY `safe_payments_buyer_user_id_status_index` (`buyer_user_id`,`status`),
  KEY `safe_payments_seller_user_id_status_index` (`seller_user_id`,`status`),
  KEY `safe_payments_status_seller_response_deadline_index` (`status`,`seller_response_deadline`),
  KEY `safe_payments_is_disputed_index` (`is_disputed`),
  KEY `safe_payments_admin_resolved_by_foreign` (`admin_resolved_by`),
  KEY `safe_payments_status_index` (`status`),
  KEY `idx_safe_pay_buyer_status` (`buyer_user_id`,`status`,`created_at`),
  KEY `idx_safe_pay_seller_status` (`seller_user_id`,`status`,`created_at`),
  CONSTRAINT `safe_payments_admin_resolved_by_foreign` FOREIGN KEY (`admin_resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `safe_payments_buyer_user_id_foreign` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `safe_payments_seller_user_id_foreign` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `safe_payments`
--

LOCK TABLES `safe_payments` WRITE;
/*!40000 ALTER TABLE `safe_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `safe_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sanction_list_entries`
--

DROP TABLE IF EXISTS `sanction_list_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sanction_list_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `list_source` varchar(50) NOT NULL,
  `entry_type` varchar(20) NOT NULL DEFAULT 'individual',
  `full_name` varchar(300) NOT NULL,
  `normalized_name` varchar(300) NOT NULL,
  `aliases` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`aliases`)),
  `national_id_hash` varchar(64) DEFAULT NULL,
  `passport_hash` varchar(64) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `program` varchar(200) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sanction_list_entries_list_source_is_active_index` (`list_source`,`is_active`),
  KEY `sanction_list_entries_normalized_name_index` (`normalized_name`),
  KEY `sanction_list_entries_national_id_hash_index` (`national_id_hash`),
  KEY `sanction_list_entries_passport_hash_index` (`passport_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sanction_list_entries`
--

LOCK TABLES `sanction_list_entries` WRITE;
/*!40000 ALTER TABLE `sanction_list_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `sanction_list_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sanction_screening_logs`
--

DROP TABLE IF EXISTS `sanction_screening_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sanction_screening_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `screened_name` varchar(300) NOT NULL,
  `result` enum('clear','potential_match','confirmed_match') NOT NULL DEFAULT 'clear',
  `match_score` decimal(5,2) DEFAULT NULL,
  `matched_entry_id` bigint(20) unsigned DEFAULT NULL,
  `screening_context` varchar(50) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `screened_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sanction_screening_logs_user_id_result_index` (`user_id`,`result`),
  KEY `sanction_screening_logs_result_screened_at_index` (`result`,`screened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sanction_screening_logs`
--

LOCK TABLES `sanction_screening_logs` WRITE;
/*!40000 ALTER TABLE `sanction_screening_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `sanction_screening_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sentinel_blocked_ips`
--

DROP TABLE IF EXISTS `sentinel_blocked_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sentinel_blocked_ips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `blocked_until` timestamp NULL DEFAULT NULL,
  `created_by` varchar(64) NOT NULL DEFAULT 'auto',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sentinel_blocked_ips_ip_address_unique` (`ip_address`),
  KEY `sentinel_blocked_until_idx` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sentinel_blocked_ips`
--

LOCK TABLES `sentinel_blocked_ips` WRITE;
/*!40000 ALTER TABLE `sentinel_blocked_ips` DISABLE KEYS */;
/*!40000 ALTER TABLE `sentinel_blocked_ips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sentinel_events`
--

DROP TABLE IF EXISTS `sentinel_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sentinel_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `threat_score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `severity` enum('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `signatures` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`signatures`)),
  `action` varchar(16) NOT NULL DEFAULT 'monitor',
  `request_id` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sentinel_ip_idx` (`ip_address`,`created_at`),
  KEY `sentinel_severity_idx` (`severity`,`created_at`),
  KEY `sentinel_user_idx` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sentinel_events`
--

LOCK TABLES `sentinel_events` WRITE;
/*!40000 ALTER TABLE `sentinel_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `sentinel_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `social_media`
--

DROP TABLE IF EXISTS `social_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `link` varchar(255) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `social_media`
--

LOCK TABLES `social_media` WRITE;
/*!40000 ALTER TABLE `social_media` DISABLE KEYS */;
/*!40000 ALTER TABLE `social_media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `soft_credentials`
--

DROP TABLE IF EXISTS `soft_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `soft_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) DEFAULT NULL,
  `value` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `soft_credentials`
--

LOCK TABLES `soft_credentials` WRITE;
/*!40000 ALTER TABLE `soft_credentials` DISABLE KEYS */;
/*!40000 ALTER TABLE `soft_credentials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `split_bill_participants`
--

DROP TABLE IF EXISTS `split_bill_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `split_bill_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `split_bill_id` bigint(20) unsigned NOT NULL,
  `customer_user_id` bigint(20) unsigned NOT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `share_amount` decimal(20,4) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `paid_transaction_id` varchar(40) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `split_participant_unique` (`split_bill_id`,`customer_user_id`),
  KEY `split_bill_participants_split_bill_id_index` (`split_bill_id`),
  KEY `split_bill_participants_customer_user_id_index` (`customer_user_id`),
  KEY `split_bill_participants_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `split_bill_participants`
--

LOCK TABLES `split_bill_participants` WRITE;
/*!40000 ALTER TABLE `split_bill_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `split_bill_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `split_bills`
--

DROP TABLE IF EXISTS `split_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `split_bills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `split_ulid` varchar(40) NOT NULL,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `total_amount` decimal(20,4) NOT NULL,
  `participant_count` int(10) unsigned NOT NULL,
  `channel` varchar(8) NOT NULL DEFAULT 'qr',
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `split_bills_split_ulid_unique` (`split_ulid`),
  KEY `split_bills_merchant_user_id_index` (`merchant_user_id`),
  KEY `split_bills_pos_user_id_index` (`pos_user_id`),
  KEY `split_bills_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `split_bills`
--

LOCK TABLES `split_bills` WRITE;
/*!40000 ALTER TABLE `split_bills` DISABLE KEYS */;
/*!40000 ALTER TABLE `split_bills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_changes`
--

DROP TABLE IF EXISTS `subscription_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(32) DEFAULT NULL,
  `action` varchar(24) NOT NULL,
  `old_plan` varchar(32) DEFAULT NULL,
  `old_expires_at` timestamp NULL DEFAULT NULL,
  `new_plan` varchar(32) NOT NULL,
  `new_expires_at` timestamp NULL DEFAULT NULL,
  `price_paid_sar` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(24) DEFAULT NULL,
  `payment_reference` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sc_merchant` (`merchant_user_id`),
  KEY `sc_merchant_time` (`merchant_user_id`,`created_at`),
  KEY `sc_action` (`action`),
  KEY `sc_time` (`created_at`),
  KEY `sc_expires` (`new_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_changes`
--

LOCK TABLES `subscription_changes` WRITE;
/*!40000 ALTER TABLE `subscription_changes` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_changes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_limits`
--

DROP TABLE IF EXISTS `transaction_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) DEFAULT NULL,
  `todays_count` int(11) NOT NULL DEFAULT 0,
  `todays_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `this_months_count` int(11) NOT NULL DEFAULT 0,
  `this_months_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `type` varchar(255) DEFAULT NULL COMMENT 'add_money, send_money, cash_out, send_money_request, withdraw_request',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_limits`
--

LOCK TABLES `transaction_limits` WRITE;
/*!40000 ALTER TABLE `transaction_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `transaction_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `ref_trans_id` varchar(255) DEFAULT NULL,
  `transaction_type` varchar(255) NOT NULL,
  `debit` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `charge` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `decision_code` varchar(64) DEFAULT NULL,
  `decision_reason` varchar(255) DEFAULT NULL,
  `fee_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `fee_scheme_version` int(10) unsigned DEFAULT NULL,
  `pos_user_id` bigint(20) unsigned DEFAULT NULL,
  `split_bill_id` bigint(20) unsigned DEFAULT NULL,
  `split_participant_id` bigint(20) unsigned DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `request_zone` varchar(16) DEFAULT NULL,
  `counterparty_zone` varchar(16) DEFAULT NULL,
  `balance` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `from_user_id` bigint(20) DEFAULT NULL,
  `to_user_id` bigint(20) DEFAULT NULL,
  `bonus_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(64) NOT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transactions_transaction_id_unique` (`transaction_id`),
  KEY `transactions_from_user_idx` (`from_user_id`),
  KEY `transactions_to_user_idx` (`to_user_id`),
  KEY `transactions_type_idx` (`transaction_type`),
  KEY `transactions_user_created_idx` (`user_id`,`created_at`),
  KEY `transactions_idempotency_idx` (`idempotency_key`),
  KEY `transactions_pos_user_id_index` (`pos_user_id`),
  KEY `transactions_split_bill_id_index` (`split_bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transfers`
--

DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(255) DEFAULT NULL,
  `sender` bigint(20) NOT NULL,
  `receiver` bigint(20) NOT NULL,
  `receiver_type` varchar(255) NOT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transfers`
--

LOCK TABLES `transfers` WRITE;
/*!40000 ALTER TABLE `transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `two_factor_attempts`
--

DROP TABLE IF EXISTS `two_factor_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `two_factor_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `method` enum('totp','recovery_code') NOT NULL DEFAULT 'totp',
  `ip_address` varchar(45) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `two_factor_attempts_user_id_attempted_at_index` (`user_id`,`attempted_at`),
  CONSTRAINT `two_factor_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `two_factor_attempts`
--

LOCK TABLES `two_factor_attempts` WRITE;
/*!40000 ALTER TABLE `two_factor_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `two_factor_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unified_login_attempts`
--

DROP TABLE IF EXISTS `unified_login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unified_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(20) NOT NULL,
  `identifier` varchar(100) NOT NULL,
  `identifier_masked` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `failure_reason` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `unified_login_attempts_ip_address_attempted_at_index` (`ip_address`,`attempted_at`),
  KEY `unified_login_attempts_role_success_attempted_at_index` (`role`,`success`,`attempted_at`),
  KEY `unified_login_attempts_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unified_login_attempts`
--

LOCK TABLES `unified_login_attempts` WRITE;
/*!40000 ALTER TABLE `unified_login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `unified_login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_legal_acceptances`
--

DROP TABLE IF EXISTS `user_legal_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_legal_acceptances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `legal_term_id` bigint(20) unsigned NOT NULL,
  `accepted_version` varchar(32) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `device_id` varchar(128) DEFAULT NULL,
  `accepted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_legal_unique` (`user_id`,`legal_term_id`),
  KEY `user_legal_user_idx` (`user_id`,`accepted_at`),
  KEY `user_legal_term_idx` (`legal_term_id`),
  CONSTRAINT `user_legal_acceptances_legal_term_id_foreign` FOREIGN KEY (`legal_term_id`) REFERENCES `legal_terms` (`id`),
  CONSTRAINT `user_legal_acceptances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_legal_acceptances`
--

LOCK TABLES `user_legal_acceptances` WRITE;
/*!40000 ALTER TABLE `user_legal_acceptances` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_legal_acceptances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_log_histories`
--

DROP TABLE IF EXISTS `user_log_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_log_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(255) DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `browser` varchar(255) DEFAULT NULL,
  `os` varchar(255) DEFAULT NULL,
  `device_model` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_log_histories`
--

LOCK TABLES `user_log_histories` WRITE;
/*!40000 ALTER TABLE `user_log_histories` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_log_histories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `f_name` varchar(255) DEFAULT NULL,
  `l_name` varchar(255) DEFAULT NULL,
  `dial_country_code` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `account_number` varchar(12) DEFAULT NULL,
  `phone_encrypted` text DEFAULT NULL,
  `phone_blind_index` char(64) DEFAULT NULL,
  `phone_masked` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `type` tinyint(1) NOT NULL COMMENT '[''Admin''=>0, ''Agent''=>1, ''Customer''=>2, ''Merchant''=>3]',
  `agent_number` varchar(20) DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'UNKNOWN',
  `role` varchar(24) NOT NULL DEFAULT 'user',
  `verification_level` varchar(16) NOT NULL DEFAULT 'basic',
  `password` varchar(255) NOT NULL,
  `transaction_pin` varchar(191) DEFAULT NULL,
  `transaction_pin_set_at` timestamp NULL DEFAULT NULL,
  `pin_failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pin_locked_until` timestamp NULL DEFAULT NULL,
  `security_hold_until` timestamp NULL DEFAULT NULL,
  `security_hold_reason` varchar(100) DEFAULT NULL,
  `requires_pin_setup` tinyint(1) NOT NULL DEFAULT 1,
  `is_phone_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  `referral_id` varchar(255) DEFAULT NULL,
  `gender` varchar(255) DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `two_factor` tinyint(1) NOT NULL DEFAULT 0,
  `fcm_token` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `identification_type` varchar(255) DEFAULT NULL,
  `identification_number` varchar(255) DEFAULT NULL,
  `identification_image` varchar(255) DEFAULT NULL,
  `is_kyc_verified` tinyint(1) NOT NULL DEFAULT 3 COMMENT '[''Pending''=>0, ''Approved''=>1, ''denied''=>2, ''YetToApply''=>3]',
  `login_hit_count` tinyint(4) NOT NULL DEFAULT 0,
  `is_temp_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `temp_block_time` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `email_encrypted` text DEFAULT NULL,
  `email_blind_index` char(64) DEFAULT NULL,
  `email_masked` varchar(100) DEFAULT NULL,
  `f_name_encrypted` text DEFAULT NULL,
  `l_name_encrypted` text DEFAULT NULL,
  `national_id_encrypted` text DEFAULT NULL,
  `national_id_blind_index` char(64) DEFAULT NULL,
  `national_id_masked` varchar(30) DEFAULT NULL,
  `address_encrypted` text DEFAULT NULL,
  `dob_encrypted` text DEFAULT NULL,
  `pii_migrated_at` timestamp NULL DEFAULT NULL,
  `identity_image_encrypted_path` varchar(500) DEFAULT NULL,
  `kyc_files_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `kyc_tier` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `kyc_tier_updated_at` timestamp NULL DEFAULT NULL,
  `sanction_checked` tinyint(1) NOT NULL DEFAULT 0,
  `sanction_status` enum('clear','flagged','blocked') NOT NULL DEFAULT 'clear',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_account_number_unique` (`account_number`),
  KEY `users_zone_idx` (`zone_code`),
  KEY `users_security_hold_idx` (`security_hold_until`),
  KEY `idx_users_phone_blind` (`phone_blind_index`),
  KEY `idx_users_email_blind` (`email_blind_index`),
  KEY `idx_users_nid_blind` (`national_id_blind_index`),
  KEY `users_pii_migrated_at_index` (`pii_migrated_at`),
  KEY `idx_users_agent_number` (`agent_number`),
  KEY `users_kyc_tier_index` (`kyc_tier`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Mer','0',NULL,'+96771C000000','43083211',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$0.6RNy9f0vM7sEmVCsYnDufE990nLj0BJXQSFBuF97pJMaEBQsO0u',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(2,'Mer','1',NULL,'+96771C000001','34911669',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$qtXxhZ7UKAxMTQ0UxDEY2e31n4y.PgLrUmI.RdI/FpjmJk74YZ1LG',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(3,'Mer','2',NULL,'+96771C000002','86565835',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$vIA.fWao6atmdUdXFCInN.0rnrJHMAd.vw0CwBoR566bXvYiyWGom',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(4,'Mer','3',NULL,'+96771C000003','11256948',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$FEWWBAJJ4h3nBOc6dRdA7.p5jfsAb8aggsFUIAowU0HptgCvtQGzC',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(5,'Mer','4',NULL,'+96771C000004','49352271',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$.7XOi.sB3euXYaB7m6IJNOtB.5UlGcbmGZzb2Ay8PNku19SHlMvMW',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:29','2026-06-06 19:58:29',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(6,'Mer','5',NULL,'+96771C000005','14314942',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$VecmiSGLulAepLK6vQHPLONGWTY3qj4uNR/MrDAUhu5.YO6byJnCm',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(7,'Mer','6',NULL,'+96771C000006','60019239',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$3m.FdXUGMmb48H3pq1ogZOrwU4UWqx.8ODVfNJPMb1.7/leKN39iO',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(8,'Mer','7',NULL,'+96771C000007','34081554',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$s.Ox4nXk6FBI4piAVRirtuKTsYSvV.lsLGWHwI38XzV.VI4IpA1eG',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(9,'Mer','8',NULL,'+96771C000008','25226572',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$xeztpMcG5lvlw/hzuWbOC.Ir0dUSl1WGQYdiyCURg6bn2Y81dhBFq',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(10,'Mer','9',NULL,'+96771C000009','95936050',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$F/NWq0s0pvUBLDecU1FXFeGQXZMx96CSU658nk.yAthI1SRDXbXWq',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(11,'Mer','10',NULL,'+96771C000010','14938823',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$TCyqs6/vf3RjWigZo96R8ekAlBi2cg7bLerLoOAl15D555e04o1kG',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(12,'Mer','11',NULL,'+96771C000011','93523835',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$MMKj6V8VnwftJGn3w4KdA..YnSWQXHal9WWN/u7YNZ4632wP7Itx.',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(13,'Mer','12',NULL,'+96771C000012','18308882',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$MYHjh4HbjOkRfVBxDMzyKeR6hzrGq7uM7XjnxfSJ2UyCVkXKg6kTK',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(14,'Mer','13',NULL,'+96771C000013','87774576',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$/BWvL9W3MCoGD6b.gw3YcuI4oLZN6EzFZZLSVQbheLY6jU40/QIXe',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(15,'Mer','14',NULL,'+96771C000014','49645393',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$oip8xPmKYQw/dO4.CjhL2.cSIvVuRIv7sToFb3Qgi5ud8vRu1g3CG',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(16,'Mer','15',NULL,'+96771C000015','36343101',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$fJcch/GTMeD/2wSf3N4p.uies96qixaYj9Zn5D8d56xg.dCyIOhmG',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(17,'Mer','16',NULL,'+96771C000016','85264232',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$03Tc2U21TxgjjjHempiQseV9pHgGVZRCZ/hCK5GuSJSWoyxOzHk8m',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(18,'Mer','17',NULL,'+96771C000017','81261455',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$a2DK0T8b6dnFXKYihrIK5eMnqKoXwa760gRsVBqbBa3VMMKMCrdOi',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(19,'Mer','18',NULL,'+96771C000018','80405822',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$75fmvwp4PEJd8/mgR7VlWe8sjYA4dljuMyAKGxdvEnQ9zXVWcc2Va',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear'),
(20,'Mer','19',NULL,'+96771C000019','74398850',NULL,NULL,NULL,NULL,NULL,3,NULL,'SOUTH','user','basic','$2y$10$c2xohn6aiua6GJRcR0NRjeMvcPyCbVIfBq3sHfrsXFdkaWatQ16ey',NULL,NULL,0,NULL,NULL,NULL,1,0,0,NULL,'2026-06-06 19:58:30','2026-06-06 19:58:30',NULL,NULL,NULL,NULL,NULL,0,NULL,1,NULL,NULL,NULL,3,0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,NULL,0,NULL,0,'clear');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_businesses`
--

DROP TABLE IF EXISTS `wholesale_businesses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_businesses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `merchant_user_id` bigint(20) unsigned NOT NULL,
  `business_name` varchar(200) NOT NULL,
  `commercial_register` varchar(64) DEFAULT NULL,
  `tax_number` varchar(64) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `default_tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `invoice_prefix` varchar(16) NOT NULL DEFAULT 'INV',
  `next_invoice_number` int(11) NOT NULL DEFAULT 1,
  `default_payment_terms_days` int(11) NOT NULL DEFAULT 30,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_businesses_merchant_user_id_unique` (`merchant_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_businesses`
--

LOCK TABLES `wholesale_businesses` WRITE;
/*!40000 ALTER TABLE `wholesale_businesses` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_businesses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_collections`
--

DROP TABLE IF EXISTS `wholesale_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_collections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_ulid` varchar(26) NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `received_by_user_id` bigint(20) unsigned NOT NULL,
  `collection_date` date NOT NULL,
  `amount` decimal(14,4) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `reference_number` varchar(64) DEFAULT NULL,
  `paid_transaction_id` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_collections_collection_ulid_unique` (`collection_ulid`),
  KEY `wholesale_collections_invoice_id_index` (`invoice_id`),
  KEY `wholesale_collections_customer_id_index` (`customer_id`),
  KEY `wholesale_collections_business_id_index` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_collections`
--

LOCK TABLES `wholesale_collections` WRITE;
/*!40000 ALTER TABLE `wholesale_collections` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_collections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_customers`
--

DROP TABLE IF EXISTS `wholesale_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `default_tier_id` bigint(20) unsigned DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_number` varchar(64) DEFAULT NULL,
  `credit_limit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `current_balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `payment_terms_days` int(11) NOT NULL DEFAULT 30,
  `total_purchases` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `last_purchase_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wc_phone_unique` (`business_id`,`phone`),
  KEY `wholesale_customers_business_id_index` (`business_id`),
  KEY `wholesale_customers_default_tier_id_index` (`default_tier_id`),
  KEY `wholesale_customers_phone_index` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_customers`
--

LOCK TABLES `wholesale_customers` WRITE;
/*!40000 ALTER TABLE `wholesale_customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_invoice_items`
--

DROP TABLE IF EXISTS `wholesale_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `product_sku` varchar(64) DEFAULT NULL,
  `unit` varchar(32) NOT NULL,
  `quantity` decimal(14,4) NOT NULL,
  `unit_price` decimal(14,4) NOT NULL,
  `discount_per_unit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(14,4) NOT NULL,
  `tier_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wholesale_invoice_items_invoice_id_index` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_invoice_items`
--

LOCK TABLES `wholesale_invoice_items` WRITE;
/*!40000 ALTER TABLE `wholesale_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_invoices`
--

DROP TABLE IF EXISTS `wholesale_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_ulid` varchar(26) NOT NULL,
  `invoice_number` varchar(32) NOT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `sales_rep_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(14,4) NOT NULL,
  `discount_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(14,4) NOT NULL,
  `paid_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `balance_due` decimal(14,4) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'issued',
  `payment_type` varchar(16) NOT NULL DEFAULT 'credit',
  `sales_rep_commission_rate` decimal(5,2) DEFAULT NULL,
  `sales_rep_commission_amount` decimal(14,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_invoices_business_id_invoice_number_unique` (`business_id`,`invoice_number`),
  UNIQUE KEY `wholesale_invoices_invoice_ulid_unique` (`invoice_ulid`),
  KEY `wholesale_invoices_business_id_status_index` (`business_id`,`status`),
  KEY `wholesale_invoices_customer_id_status_index` (`customer_id`,`status`),
  KEY `wholesale_invoices_business_id_due_date_index` (`business_id`,`due_date`),
  KEY `wholesale_invoices_invoice_number_index` (`invoice_number`),
  KEY `wholesale_invoices_business_id_index` (`business_id`),
  KEY `wholesale_invoices_customer_id_index` (`customer_id`),
  KEY `wi_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_invoices`
--

LOCK TABLES `wholesale_invoices` WRITE;
/*!40000 ALTER TABLE `wholesale_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_price_tiers`
--

DROP TABLE IF EXISTS `wholesale_price_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_price_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_price_tiers_business_id_code_unique` (`business_id`,`code`),
  KEY `wholesale_price_tiers_business_id_index` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_price_tiers`
--

LOCK TABLES `wholesale_price_tiers` WRITE;
/*!40000 ALTER TABLE `wholesale_price_tiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_price_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_product_prices`
--

DROP TABLE IF EXISTS `wholesale_product_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_product_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `tier_id` bigint(20) unsigned NOT NULL,
  `price` decimal(14,4) NOT NULL,
  `min_quantity` decimal(14,4) NOT NULL DEFAULT 1.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wp_unique` (`product_id`,`tier_id`,`min_quantity`),
  KEY `wholesale_product_prices_product_id_index` (`product_id`),
  KEY `wholesale_product_prices_tier_id_index` (`tier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_product_prices`
--

LOCK TABLES `wholesale_product_prices` WRITE;
/*!40000 ALTER TABLE `wholesale_product_prices` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_product_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_products`
--

DROP TABLE IF EXISTS `wholesale_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `manufacturer` varchar(120) DEFAULT NULL,
  `unit` varchar(32) NOT NULL DEFAULT 'قطعة',
  `current_stock` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 10,
  `cost_price` decimal(14,4) DEFAULT NULL,
  `base_price` decimal(14,4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wholesale_products_business_id_is_active_index` (`business_id`,`is_active`),
  KEY `wholesale_products_business_id_name_index` (`business_id`,`name`),
  KEY `wholesale_products_business_id_index` (`business_id`),
  KEY `wholesale_products_barcode_index` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_products`
--

LOCK TABLES `wholesale_products` WRITE;
/*!40000 ALTER TABLE `wholesale_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wholesale_sales_reps`
--

DROP TABLE IF EXISTS `wholesale_sales_reps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wholesale_sales_reps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `default_commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_sales` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_commission_earned` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_commission_paid` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wholesale_sales_reps_business_id_index` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wholesale_sales_reps`
--

LOCK TABLES `wholesale_sales_reps` WRITE;
/*!40000 ALTER TABLE `wholesale_sales_reps` DISABLE KEYS */;
/*!40000 ALTER TABLE `wholesale_sales_reps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `withdraw_requests`
--

DROP TABLE IF EXISTS `withdraw_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `withdraw_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) DEFAULT NULL,
  `amount` float NOT NULL DEFAULT 0,
  `admin_charge` float NOT NULL DEFAULT 0,
  `request_status` varchar(255) NOT NULL DEFAULT 'pending',
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `sender_note` varchar(255) DEFAULT NULL,
  `admin_note` varchar(255) DEFAULT NULL,
  `withdrawal_method_id` bigint(20) DEFAULT NULL,
  `withdrawal_method_fields` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `withdraw_requests`
--

LOCK TABLES `withdraw_requests` WRITE;
/*!40000 ALTER TABLE `withdraw_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `withdraw_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `withdrawal_methods`
--

DROP TABLE IF EXISTS `withdrawal_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `withdrawal_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `method_name` varchar(255) NOT NULL,
  `method_fields` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `withdrawal_methods`
--

LOCK TABLES `withdrawal_methods` WRITE;
/*!40000 ALTER TABLE `withdrawal_methods` DISABLE KEYS */;
/*!40000 ALTER TABLE `withdrawal_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `withdrawal_requests`
--

DROP TABLE IF EXISTS `withdrawal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `withdrawal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `op_code` varchar(16) NOT NULL,
  `customer_user_id` bigint(20) unsigned NOT NULL,
  `agent_user_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(20,4) NOT NULL,
  `fee` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `agent_commission` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `platform_profit` decimal(20,4) NOT NULL DEFAULT 0.0000,
  `total_debit` decimal(20,4) NOT NULL,
  `fee_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `fee_scheme_version` int(10) unsigned DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `transaction_id` varchar(40) DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `zone_code` varchar(16) NOT NULL DEFAULT 'SOUTH',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `withdrawal_requests_op_code_unique` (`op_code`),
  KEY `withdrawal_requests_customer_user_id_status_index` (`customer_user_id`,`status`),
  KEY `withdrawal_requests_customer_user_id_index` (`customer_user_id`),
  KEY `withdrawal_requests_agent_user_id_index` (`agent_user_id`),
  KEY `withdrawal_requests_status_index` (`status`),
  KEY `withdrawal_requests_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `withdrawal_requests`
--

LOCK TABLES `withdrawal_requests` WRITE;
/*!40000 ALTER TABLE `withdrawal_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `withdrawal_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `zone_assignment_logs`
--

DROP TABLE IF EXISTS `zone_assignment_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `zone_assignment_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `assigned_zone` varchar(16) NOT NULL,
  `method` varchar(30) NOT NULL,
  `signals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`signals`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `zone_assignment_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `zone_assignment_logs_assigned_zone_index` (`assigned_zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `zone_assignment_logs`
--

LOCK TABLES `zone_assignment_logs` WRITE;
/*!40000 ALTER TABLE `zone_assignment_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `zone_assignment_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'amial_pay'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-10  7:20:47
