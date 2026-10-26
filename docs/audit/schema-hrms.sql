-- Phase 0 schema-only audit, 2026-10-26. NOT a data backup.
-- No table rows exported. Trigger DEFINER identities replaced with CURRENT_USER.
-- Sensitive non-null defaults, if any, omitted. Do not execute on live databases.

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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `scope` enum('company_wide','department') NOT NULL DEFAULT 'company_wide',
  `department` varchar(100) DEFAULT NULL,
  `status` enum('draft','pending_approval','published','rejected','expired') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ann_status` (`status`),
  KEY `idx_ann_scope` (`scope`),
  KEY `idx_ann_department` (`department`),
  KEY `idx_ann_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `applicant_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `applicant_id` int(11) NOT NULL,
  `document_type` varchar(60) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`document_id`),
  UNIQUE KEY `uniq_applicant_doctype` (`applicant_id`,`document_type`),
  KEY `applicant_id` (`applicant_id`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_form_fields` (
  `field_id` int(11) NOT NULL AUTO_INCREMENT,
  `field_key` varchar(60) NOT NULL COMMENT 'Permanent internal key, hindi na nagbabago kahit palitan ang label',
  `label` varchar(150) NOT NULL,
  `field_type` enum('text','textarea','tel','number','email','date','select','checkbox') NOT NULL DEFAULT 'text',
  `options` text DEFAULT NULL COMMENT 'Comma-separated choices, para sa select/checkbox type lang',
  `placeholder` varchar(150) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`field_id`),
  UNIQUE KEY `field_key` (`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `late_minutes` int(11) DEFAULT 0,
  `time_in_latitude` decimal(10,7) DEFAULT NULL,
  `time_in_longitude` decimal(10,7) DEFAULT NULL,
  `time_in_accuracy_meters` decimal(8,2) DEFAULT NULL,
  `time_in_address` varchar(255) DEFAULT NULL,
  `corrected_by` int(11) DEFAULT NULL,
  `correction_reason` varchar(255) DEFAULT NULL,
  `corrected_at` timestamp NULL DEFAULT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  `undertime_minutes` int(11) DEFAULT 0,
  `overtime_minutes` int(11) DEFAULT 0,
  `status` enum('present','absent','leave','holiday','day_off') NOT NULL DEFAULT 'absent',
  `shift_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uniq_employee_date` (`employee_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2140 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_subject_requests` (
  `request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requester_name` varchar(150) NOT NULL,
  `requester_email` varchar(254) NOT NULL,
  `request_type` enum('access','correction','deletion','portability','restriction','objection','other') NOT NULL,
  `request_details` text NOT NULL,
  `status` enum('pending','identity_verification','identity_verified','in_review','on_hold','completed','fulfilled','denied') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `identity_verified_at` datetime DEFAULT NULL,
  `response_filename` varchar(255) DEFAULT NULL,
  `response_original_name` varchar(255) DEFAULT NULL,
  `response_expires_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_dsr_status_submitted` (`status`,`submitted_at`),
  KEY `idx_dsr_email` (`requester_email`),
  KEY `idx_dsr_reviewer` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `discount_approval_codes` (
  `code_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(6) NOT NULL,
  `generated_by_employee_id` int(11) NOT NULL,
  `generated_by_name` varchar(150) NOT NULL,
  `status` enum('active','used','cancelled') NOT NULL DEFAULT 'active',
  `used_by_cashier_id` int(11) DEFAULT NULL,
  `used_by_employee_id` int(11) DEFAULT NULL,
  `discount_type` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`code_id`),
  KEY `idx_code_status` (`code`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `document_type` varchar(60) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `employee_id` (`employee_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `employee_documents_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `employee_documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_status_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `employee_id` (`employee_id`),
  KEY `performed_by` (`performed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_weekly_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_emp_day` (`employee_id`,`day_of_week`),
  KEY `fk_ews_shift` (`shift_id`),
  CONSTRAINT `fk_ews_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ews_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=351 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `place_of_birth` varchar(150) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `highest_educational_attainment` varchar(60) DEFAULT NULL,
  `year_graduated` varchar(4) DEFAULT NULL,
  `character_references` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `sss_number` varchar(20) DEFAULT NULL,
  `philhealth_number` varchar(20) DEFAULT NULL,
  `pagibig_number` varchar(20) DEFAULT NULL,
  `tin_number` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `employment_type` enum('regular','probationary','contractual') DEFAULT 'probationary',
  `schedule_type` enum('full_time','part_time') NOT NULL DEFAULT 'full_time',
  `date_hired` date NOT NULL,
  `basic_salary` decimal(10,2) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_account_name` varchar(100) DEFAULT NULL,
  `payment_method` enum('bank','cash') NOT NULL DEFAULT 'bank',
  `status` enum('active','resigned','terminated','on-leave') DEFAULT 'active',
  `offboarded_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `suspension_start_date` date DEFAULT NULL,
  `suspension_end_date` date DEFAULT NULL,
  `suspension_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `employee_code` (`employee_code`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`holiday_id`),
  UNIQUE KEY `uniq_holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module` varchar(50) NOT NULL DEFAULT 'announcements',
  `record_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hral_module` (`module`),
  KEY `idx_hral_record` (`module`,`record_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_letter_email_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `letter_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_email` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `letter_id` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_letter_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `letter_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `details` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `letter_id` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_letter_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `letter_id` bigint(20) unsigned NOT NULL,
  `reminder_type` varchar(40) NOT NULL,
  `due_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `letter_reminder` (`letter_id`,`reminder_type`),
  KEY `due_at` (`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_letter_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `letter_type` varchar(60) NOT NULL DEFAULT 'official',
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `letter_type` (`letter_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_letters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(40) NOT NULL,
  `template_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `applicant_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `recipient_name` varchar(150) NOT NULL,
  `recipient_email` varchar(150) DEFAULT NULL,
  `letter_type` varchar(60) NOT NULL DEFAULT 'official',
  `contract_start_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `contract_duration_label` varchar(100) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `status` enum('draft','pending_approval','approved','rejected','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  `rejection_reason` varchar(500) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `response_note` varchar(500) DEFAULT NULL,
  `recipient_signature` longtext DEFAULT NULL,
  `recipient_signature_at` datetime DEFAULT NULL,
  `access_token` char(64) NOT NULL,
  `signed_file` varchar(255) DEFAULT NULL,
  `signed_original_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  UNIQUE KEY `access_token` (`access_token`),
  KEY `employee_id` (`employee_id`),
  KEY `status` (`status`),
  KEY `applicant_id` (`applicant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_applicant_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `applicant_id` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `applicant_id` (`applicant_id`),
  KEY `performed_by` (`performed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=191 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_applicants` (
  `applicant_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(150) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `home_address` varchar(255) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `position_applied` varchar(100) NOT NULL,
  `employment_type` varchar(20) DEFAULT NULL,
  `highest_educational_attainment` varchar(60) DEFAULT NULL,
  `year_graduated` varchar(4) DEFAULT NULL,
  `character_references` text DEFAULT NULL,
  `privacy_consent` tinyint(1) NOT NULL DEFAULT 0,
  `privacy_notice_version` varchar(32) DEFAULT NULL,
  `privacy_consent_at` datetime DEFAULT NULL,
  `resume_filename` varchar(255) NOT NULL,
  `resume_original_name` varchar(255) NOT NULL,
  `cover_note` text DEFAULT NULL,
  `custom_responses` longtext DEFAULT NULL COMMENT 'JSON: {field_key: value} para sa custom fields na dinagdag ng HR',
  `status` enum('for_resume','for_initial','for_final','for_requirements','for_admin_approval','hired','rejected','disqualified') NOT NULL DEFAULT 'for_resume',
  `initial_interview_date` date DEFAULT NULL,
  `final_interview_date` date DEFAULT NULL,
  `requirements_deadline` date DEFAULT NULL,
  `requirements_token` varchar(64) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `orientation_date` date DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`applicant_id`),
  UNIQUE KEY `uq_requirements_token` (`requirements_token`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `decided_by` (`decided_by`),
  CONSTRAINT `job_applicants_ibfk_1` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `job_applicants_ibfk_2` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `late_timein_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `requested_shift_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_employee_date` (`employee_id`,`request_date`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_credits` (
  `credit_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('sick','vacation','emergency') NOT NULL,
  `year` int(4) NOT NULL,
  `allocated_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`credit_id`),
  UNIQUE KEY `uniq_employee_leave_year` (`employee_id`,`leave_type`,`year`),
  CONSTRAINT `leave_credits_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_request_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `leave_id` (`leave_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `leave_request_logs_ibfk_1` FOREIGN KEY (`leave_id`) REFERENCES `leave_requests` (`leave_id`),
  CONSTRAINT `leave_request_logs_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `leave_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('sick','vacation','emergency','others') NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`leave_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_ip_throttle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `official_business_requests` (
  `ob_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `ob_date` date NOT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `purpose` varchar(255) NOT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ob_latitude` decimal(10,7) DEFAULT NULL,
  `ob_longitude` decimal(10,7) DEFAULT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ob_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `overtime_requests` (
  `ot_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `ot_date` date NOT NULL,
  `hours_requested` decimal(4,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `payroll_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `performed_by` (`performed_by`),
  CONSTRAINT `payroll_logs_ibfk_1` FOREIGN KEY (`payroll_id`) REFERENCES `payroll_records` (`payroll_id`),
  CONSTRAINT `payroll_logs_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2909 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_records` (
  `payroll_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `payout_date` date DEFAULT NULL,
  `basic_pay` decimal(10,2) NOT NULL,
  `overtime_pay` decimal(10,2) DEFAULT 0.00,
  `allowances` decimal(10,2) DEFAULT 0.00,
  `gross_pay` decimal(10,2) NOT NULL,
  `sss_deduction` decimal(10,2) DEFAULT 0.00,
  `philhealth_deduction` decimal(10,2) DEFAULT 0.00,
  `pagibig_deduction` decimal(10,2) DEFAULT 0.00,
  `tax_deduction` decimal(10,2) DEFAULT 0.00,
  `other_deductions` decimal(10,2) DEFAULT 0.00,
  `net_pay` decimal(10,2) NOT NULL,
  `approval_status` enum('draft','n/a','pending','approved','rejected') NOT NULL DEFAULT 'n/a',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `payment_status` enum('unpaid','processing','paid') NOT NULL DEFAULT 'unpaid',
  `payment_reference_number` varchar(100) DEFAULT NULL,
  `payment_processed_by` int(11) DEFAULT NULL,
  `payment_processed_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `cash_ack_file` varchar(255) DEFAULT NULL,
  `cash_ack_original_name` varchar(255) DEFAULT NULL,
  `cash_ack_note` varchar(255) DEFAULT NULL,
  `cash_ack_uploaded_by` int(11) DEFAULT NULL,
  `cash_ack_uploaded_at` timestamp NULL DEFAULT NULL,
  `generated_by` int(11) NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payroll_id`),
  UNIQUE KEY `uniq_employee_period` (`employee_id`,`pay_period_start`,`pay_period_end`),
  KEY `generated_by` (`generated_by`),
  CONSTRAINT `payroll_records_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `payroll_records_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_records` (
  `perf_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `rated_by` int(11) NOT NULL,
  `record_type` enum('merit','demerit') NOT NULL DEFAULT 'merit',
  `period_label` varchar(40) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`perf_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `position_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `old_position` varchar(100) DEFAULT NULL,
  `new_position` varchar(100) DEFAULT NULL,
  `old_department` varchar(100) DEFAULT NULL,
  `new_department` varchar(100) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `employee_id` (`employee_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `position_history_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `position_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_swaps` (
  `swap_id` int(11) NOT NULL AUTO_INCREMENT,
  `swap_date` date NOT NULL,
  `covering_employee_id` int(11) NOT NULL,
  `original_employee_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by` int(11) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`swap_id`),
  UNIQUE KEY `uniq_covering_date` (`covering_employee_id`,`swap_date`),
  KEY `fk_swap_original` (`original_employee_id`),
  KEY `fk_swap_shift` (`shift_id`),
  CONSTRAINT `fk_swap_covering` FOREIGN KEY (`covering_employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_swap_original` FOREIGN KEY (`original_employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_swap_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`shift_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `shift_id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_name` varchar(60) NOT NULL,
  `shift_type` enum('full_time','part_time') NOT NULL DEFAULT 'full_time',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`shift_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supervisor_teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervisor_employee_id` int(11) NOT NULL,
  `member_employee_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member` (`member_employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `role` enum('admin','hr','hr_manager','supervisor','accountant','employee') NOT NULL DEFAULT 'employee',
  `force_logout` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_email` varchar(150) DEFAULT NULL,
  `two_factor_backup_codes` text DEFAULT NULL,
  `two_factor_failed_attempts` int(11) NOT NULL DEFAULT 0,
  `two_factor_locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

