-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 06, 2026 at 10:24 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `icu_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('staff','admin','super_admin') NOT NULL DEFAULT 'staff',
  `show_welcome` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int(10) UNSIGNED NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `revoked_at` int(10) UNSIGNED DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beds`
--

CREATE TABLE `beds` (
  `bed_id` int(11) NOT NULL,
  `bed_number` int(11) NOT NULL,
  `floor` int(11) NOT NULL,
  `status` enum('available','occupied') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `beds`
--

INSERT INTO `beds` (`bed_id`, `bed_number`, `floor`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'available', '2025-11-30 16:02:51', '2026-03-06 08:51:08'),
(2, 2, 1, 'available', '2025-11-30 16:02:51', '2026-02-12 03:43:40'),
(3, 3, 1, 'available', '2025-11-30 16:02:51', '2026-02-17 04:36:47'),
(4, 4, 1, 'available', '2025-11-30 16:02:51', '2026-01-22 03:51:20'),
(5, 5, 1, 'available', '2025-11-30 16:02:51', '2026-01-21 08:42:51'),
(6, 6, 1, 'available', '2025-11-30 16:02:51', '2026-01-21 08:45:33'),
(7, 7, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:04'),
(8, 8, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:07'),
(9, 9, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:10'),
(10, 10, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:13'),
(11, 11, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:16'),
(12, 12, 1, 'available', '2025-11-30 16:02:51', '2026-02-12 03:48:56'),
(13, 13, 1, 'available', '2025-11-30 16:02:51', '2026-01-21 09:07:36'),
(14, 14, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:25'),
(15, 15, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:31'),
(16, 16, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:34'),
(17, 17, 1, 'available', '2025-11-30 16:02:51', '2026-01-28 06:35:11'),
(18, 18, 1, 'available', '2025-11-30 16:02:51', '2026-01-21 08:45:38'),
(19, 19, 1, 'available', '2025-11-30 16:02:51', '2026-01-19 04:43:43'),
(20, 20, 1, 'available', '2025-11-30 16:02:51', '2026-02-12 02:54:42');

-- --------------------------------------------------------

--
-- Table structure for table `bed_bookings`
--

CREATE TABLE `bed_bookings` (
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `bed_id` int(11) NOT NULL,
  `booking_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','discharged','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cancellations`
--

CREATE TABLE `cancellations` (
  `id` int(11) NOT NULL,
  `public_request_id` int(11) DEFAULT NULL,
  `queue_no` int(11) DEFAULT NULL,
  `hn` varchar(64) DEFAULT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `bed_number` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `cancelled_by` varchar(255) DEFAULT NULL,
  `user_id_cb` int(11) DEFAULT NULL,
  `cancelled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `restored_at` datetime DEFAULT NULL,
  `booked_by` varchar(255) DEFAULT NULL,
  `user_id_bb` int(11) DEFAULT NULL,
  `appointment_at` datetime DEFAULT NULL,
  `urgency` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'canceled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `age` int(11) NOT NULL,
  `gender` varchar(10) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `public_requests`
--

CREATE TABLE `public_requests` (
  `id` int(11) NOT NULL,
  `queue_no` int(11) DEFAULT NULL,
  `queue_date` date DEFAULT NULL,
  `hn` varchar(64) NOT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `bed_number` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `patient_age` int(11) DEFAULT NULL,
  `patient_gender` varchar(32) DEFAULT NULL,
  `status` enum('pending','confirmed','done','canceled','handled') NOT NULL DEFAULT 'pending',
  `urgency` tinyint(1) NOT NULL DEFAULT 2 COMMENT '1=Walk-in, 2=Appointment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `user_rh` varchar(255) DEFAULT NULL,
  `user_id_bb` int(11) DEFAULT NULL,
  `original_queue_no` int(11) DEFAULT NULL COMMENT 'เลขคิวตอนลงทะเบียนครั้งแรก',
  `waiting_queue_no` int(11) DEFAULT NULL COMMENT 'เลขคิวรอจริง (รีเลขได้)',
  `stale_queue_type` tinyint(1) NOT NULL DEFAULT 1,
  `stale_flagged_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles_map`
--

CREATE TABLE `roles_map` (
  `id` int(11) NOT NULL,
  `loginname` varchar(255) DEFAULT NULL,
  `role` enum('staff','admin','super_admin') NOT NULL DEFAULT 'staff',
  `name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `roles_map`
--
DELIMITER $$
CREATE TRIGGER `trg_roles_map_ai` AFTER INSERT ON `roles_map` FOR EACH ROW BEGIN
  UPDATE users
  SET role = NEW.role
  WHERE email = NEW.loginname;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_roles_map_au` AFTER UPDATE ON `roles_map` FOR EACH ROW BEGIN
  IF NEW.role <> OLD.role THEN
    UPDATE users
    SET role = NEW.role
    WHERE email = NEW.loginname;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `role` enum('staff','admin','super_admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff' COMMENT 'admin | staff | super_admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_users_bi` BEFORE INSERT ON `users` FOR EACH ROW BEGIN
  DECLARE r VARCHAR(50);
  SET r = NULL;

  SELECT role INTO r
  FROM roles_map
  WHERE loginname = NEW.email
  LIMIT 1;

  IF r IS NOT NULL AND r <> '' THEN
    SET NEW.role = r;
  ELSEIF NEW.role IS NULL OR NEW.role = '' THEN
    SET NEW.role = 'staff';
  END IF;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_revoked` (`revoked`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `beds`
--
ALTER TABLE `beds`
  ADD PRIMARY KEY (`bed_id`),
  ADD UNIQUE KEY `bed_number` (`bed_number`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `bed_bookings`
--
ALTER TABLE `bed_bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `bed_id` (`bed_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `cancellations`
--
ALTER TABLE `cancellations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cancellations_user_id_bb` (`user_id_bb`),
  ADD KEY `idx_cancellations_user_id_cb` (`user_id_cb`),
  ADD KEY `idx_user_id_cb` (`user_id_cb`),
  ADD KEY `idx_user_id_bb` (`user_id_bb`),
  ADD KEY `idx_public_request_id` (`public_request_id`),
  ADD KEY `idx_cancelled_at` (`cancelled_at`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`);

--
-- Indexes for table `public_requests`
--
ALTER TABLE `public_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_public_requests_user_id_bb` (`user_id_bb`);

--
-- Indexes for table `roles_map`
--
ALTER TABLE `roles_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_map_loginname` (`loginname`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_users_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `beds`
--
ALTER TABLE `beds`
  MODIFY `bed_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `bed_bookings`
--
ALTER TABLE `bed_bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=285;

--
-- AUTO_INCREMENT for table `cancellations`
--
ALTER TABLE `cancellations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=160;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=290;

--
-- AUTO_INCREMENT for table `public_requests`
--
ALTER TABLE `public_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=412;

--
-- AUTO_INCREMENT for table `roles_map`
--
ALTER TABLE `roles_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bed_bookings`
--
ALTER TABLE `bed_bookings`
  ADD CONSTRAINT `bed_bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `bed_bookings_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `bed_bookings_ibfk_3` FOREIGN KEY (`bed_id`) REFERENCES `beds` (`bed_id`);

--
-- Constraints for table `cancellations`
--
ALTER TABLE `cancellations`
  ADD CONSTRAINT `fk_cancellations_user_bb` FOREIGN KEY (`user_id_bb`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cancellations_user_cb` FOREIGN KEY (`user_id_cb`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `public_requests`
--
ALTER TABLE `public_requests`
  ADD CONSTRAINT `fk_public_requests_user_bb` FOREIGN KEY (`user_id_bb`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_public_requests_user_id_bb` FOREIGN KEY (`user_id_bb`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
