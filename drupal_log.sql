-- phpMyAdmin SQL Dump
-- version 4.4.14
-- http://www.phpmyadmin.net
--
-- Host: 127.0.0.1:33067
-- Generation Time: Aug 15, 2017 at 02:48 AM
-- Server version: 5.5.48-37.8-log
-- PHP Version: 5.5.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `drupal_log`
--

-- --------------------------------------------------------

--
-- Table structure for table `drupal_modules_bundled`
--

CREATE TABLE IF NOT EXISTS `drupal_modules_bundled` (
  `dmb_id` int(11) NOT NULL,
  `module_machinename` varchar(500) NOT NULL,
  `module_parent_machinename` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `drupal_module_release`
--

CREATE TABLE IF NOT EXISTS `drupal_module_release` (
  `dmuid` int(11) NOT NULL,
  `job_id` int(255) NOT NULL,
  `module_name` varchar(255) DEFAULT NULL,
  `module_machinename` varchar(500) NOT NULL,
  `api_version` varchar(10) DEFAULT NULL,
  `version_major` int(10) DEFAULT NULL,
  `version_patch` int(10) DEFAULT NULL,
  `security_update_available` bit(1) DEFAULT NULL,
  `supported_major_versions` varchar(255) DEFAULT NULL,
  `release_date` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `drupal_site`
--

CREATE TABLE IF NOT EXISTS `drupal_site` (
  `ds_id` int(11) NOT NULL,
  `ds_name` varchar(255) NOT NULL,
  `ds_address` varchar(255) NOT NULL,
  `ds_site_filesystem_root` varchar(1000) DEFAULT NULL,
  `ds_db_server` varchar(255) NOT NULL,
  `ds_db_port` varchar(25) NOT NULL,
  `ds_db` varchar(255) NOT NULL,
  `api_version` varchar(10) NOT NULL,
  `active` bit(1) NOT NULL DEFAULT b'1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `drupal_site_module_log`
--

CREATE TABLE IF NOT EXISTS `drupal_site_module_log` (
  `dsmlid` int(11) NOT NULL,
  `ds_id` int(11) NOT NULL,
  `job_id` int(255) NOT NULL,
  `website` varchar(500) NOT NULL,
  `module_name` varchar(255) DEFAULT NULL,
  `module_machinename` varchar(500) NOT NULL,
  `module_baseversion` varchar(10) NOT NULL,
  `module_version` varchar(20) NOT NULL,
  `version_major` int(10) DEFAULT NULL,
  `version_patch` int(10) DEFAULT NULL,
  `version_extra` varchar(10) DEFAULT NULL,
  `status` int(11) NOT NULL,
  `update_available` bit(1) DEFAULT NULL,
  `security_update_available` bit(1) DEFAULT NULL,
  `unsupported` bit(1) DEFAULT NULL,
  `modules_status_outdated` bit(1) DEFAULT NULL COMMENT 'This flag is set if release info could not be found for the Drupal module.',
  `checked` timestamp NULL DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `drupal_site_module_okay`
--

CREATE TABLE IF NOT EXISTS `drupal_site_module_okay` (
  `okid` int(11) NOT NULL,
  `ds_id` int(11) NOT NULL,
  `module_machinename` varchar(500) NOT NULL,
  `api_version` varchar(10) DEFAULT NULL,
  `version_major` int(10) DEFAULT NULL,
  `version_patch` int(10) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `job`
--

CREATE TABLE IF NOT EXISTS `job` (
  `job_id` int(11) NOT NULL,
  `job_type` varchar(255) NOT NULL,
  `start_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `complete_time` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `detail` varchar(8000) DEFAULT NULL,
  `error` varchar(8000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `job_error`
--

CREATE TABLE IF NOT EXISTS `job_error` (
  `je_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `error_message` varchar(1000) NOT NULL,
  `severity` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `drupal_modules_bundled`
--
ALTER TABLE `drupal_modules_bundled`
  ADD PRIMARY KEY (`dmb_id`);

--
-- Indexes for table `drupal_module_release`
--
ALTER TABLE `drupal_module_release`
  ADD PRIMARY KEY (`dmuid`);

--
-- Indexes for table `drupal_site`
--
ALTER TABLE `drupal_site`
  ADD PRIMARY KEY (`ds_id`);

--
-- Indexes for table `drupal_site_module_log`
--
ALTER TABLE `drupal_site_module_log`
  ADD PRIMARY KEY (`dsmlid`);

--
-- Indexes for table `drupal_site_module_okay`
--
ALTER TABLE `drupal_site_module_okay`
  ADD PRIMARY KEY (`okid`);

--
-- Indexes for table `job`
--
ALTER TABLE `job`
  ADD PRIMARY KEY (`job_id`);

--
-- Indexes for table `job_error`
--
ALTER TABLE `job_error`
  ADD PRIMARY KEY (`je_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `drupal_modules_bundled`
--
ALTER TABLE `drupal_modules_bundled`
  MODIFY `dmb_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `drupal_module_release`
--
ALTER TABLE `drupal_module_release`
  MODIFY `dmuid` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `drupal_site`
--
ALTER TABLE `drupal_site`
  MODIFY `ds_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `drupal_site_module_log`
--
ALTER TABLE `drupal_site_module_log`
  MODIFY `dsmlid` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `drupal_site_module_okay`
--
ALTER TABLE `drupal_site_module_okay`
  MODIFY `okid` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `job`
--
ALTER TABLE `job`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `job_error`
--
ALTER TABLE `job_error`
  MODIFY `je_id` int(11) NOT NULL AUTO_INCREMENT;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
