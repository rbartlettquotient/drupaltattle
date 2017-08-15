<?php
/**
 * Check for pending security updates and other issues on Drupal websites.
 *
 * @author Quotient, Inc. for The Smithsonian
 * @version 1.0
 * @property date 8/14/17
 */

# Run on cli
# $ php -f drupaltattle.php

// To clean the db ***when in test environment only***:
/*
  delete from job_error;
  delete from drupal_site_module_log;
  delete from drupal_module_release;
  delete from job;
 */

//@todo Drupal 8

//---------------------------------------------//
//-----------       Get Ready       -----------//
//---------------------------------------------//

//--------- Database Connection Info ----------//

// User must have read access on the databases which are enumerated in the MySQL table drupal_site.
// User must have r/w access on the drupal_log database.

$dbserver = '127.0.0.1';     // Do not use 'localhost' - use 127.0.0.1 or other IP.
$dbport = '33067';
$dbname = 'drupal_log';
$dbuser = 'user_all';
$dbpass = 'littlesnitch';

//---------     A few global vars    ----------//

global $db;           // The logging database.
global $job_id;       // Generated ID for this job.
global $drupal_sites; // Associative array of Drupal websites.


//---------------------------------------------//
//----------- Run Checks and Tattle -----------//
//---------------------------------------------//

// Can we connect to the logging database?
$db = _dt_connect($dbserver, $dbport, $dbname, $dbuser, $dbpass);
if($db == NULL) {
  die('Could not connect to logging database. Check the settings and re-run this script.');
}

// Get an array of sites that we will inspect.
$drupal_sites = _dt_get_drupalsites();
if(count($drupal_sites) < 1) {
  _dt_dblog_error('No Drupal sites found.', 'notice');
  die('No Drupal sites found.');
}

// Create a new job and get the ID.
$job_id = _dt_create_logging_job();
if(NULL == $job_id) {
  die('Could not create logging job. Check write access to the database.');
}

// Which modules are in use across all of these websites?
_dt_record_modules_inuse($dbuser, $dbpass);

// Which Drupal version is in use for each website?
_dt_record_drupalcore_version();

// Calculate which modules are not secure, not supported etc - query updates.drupal.org for the info, and parse XML.
//_dt_record_modules_status();

// Finally, update the status for each module, for all websites.
_dt_record_site_modules_status();

// Complete the job and write the timestamp.
_dt_complete_logging_job();

$db = NULL; // Be kind.

// fin.

//---------------------------------------------//
//----------- Supporting Functions ------------//
//---------------------------------------------//

/***
 * Connect to a database and return either the PDO object or NULL on failure.
 * @param $dbserver
 * @param $dbport
 * @param $dbname
 * @param $dbuser
 * @param $dbpass
 * @return null|\PDO
 */
function _dt_connect($dbserver, $dbport, $dbname, $dbuser, $dbpass) {
  $_db = NULL;
  try {
    $_db = new PDO('mysql:host=' . $dbserver . ';port=' . $dbport . ';dbname=' . $dbname . ';charset=utf8mb4', $dbuser, $dbpass);
  } catch(PDOException $ex) {
    _dt_dblog_error('Unable to connect to database ' . $dbname . ' because: ' . $ex->getMessage(), 'error');
  }
  return $_db;
}

/***
 * Get an array of drupal sites to check.
 * @return array
 */
function _dt_get_drupalsites() {

  global $db;

  $sites = array();
  try {
    $sites = $db->query('SELECT ds_id, ds_name, ds_address, ds_db_server, ds_db_port, ds_db, api_version, ds_site_filesystem_root FROM drupal_site where active=1')->fetchAll(PDO::FETCH_ASSOC);
  }
  catch(Exception $ex) {
      _dt_dblog_error('Unable to retrieve list of Drupal sites because: ' . $ex->getMessage(), 'error');
  }
  return $sites;
}

/***
 * Create a new entry for this job and return the ID.
 * @return int
 */
function _dt_create_logging_job() {

  global $db;
  $_job_id = NULL;

  try {
    $stmt = $db->prepare("INSERT INTO job(job_type) VALUES(:job_type)");
    $stmt->execute(array(':job_type' => 'log_drupalmodules_inuse'));
    if($stmt->rowCount() == 1) {
      $jobs = $db->query("SELECT job_id from job WHERE job_type = 'log_drupalmodules_inuse' AND complete_time = '0000-00-00 00:00:00' order by job_id desc limit 0,1")->fetchAll(PDO::FETCH_ASSOC);
      if(count($jobs) > 0) {
        $_job_id = $jobs[0]['job_id'];
      }
    }
  }
  catch(Exception $ex) {
    _dt_dblog_error('Unable to create logging job: ' . $ex->getMessage(), 'error');
  }
  return $_job_id;

}

/***
 * Inspect the databases for all Drupal sites, and note which modules are currently in use.
 * @param $job_id
 * @param $drupal_sites
 * @return bool
 */
function _dt_record_modules_inuse($db_user, $db_pass) {

  global $job_id;
  global $db;
  global $drupal_sites;

  $records_total = 0;

  # Get the sites to check from a db table, and loop through them.
  foreach($drupal_sites as $site_id => $site_data) {
    # Write to drupal_site_module table for each enabled (status=1) module we find, for each site.

    $site_id = $site_data['ds_id'];
    $site = $site_data['ds_name'];
    $site_db_port = $site_data['ds_db_port'];
    $site_db_name = $site_data['ds_db'];
    $site_api_version = $site_data['api_version'];

    _dt_dblog_error('Getting modules for ' . $site, 'info');

    $this_db = _dt_connect("127.0.0.1", $site_db_port, $site_db_name, $db_user, $db_pass);

    # @todo: D8 stores this info in key_value table.
    $site_modules = $this_db->query("SELECT `name`, filename, status, bootstrap, schema_version, CONVERT(info using utf8) as info_blob FROM `system` where type='module' and status=1 and filename not like 'modules/%' order by name")
      ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($site_modules as $idx => $module_data) {
      $module_machinename = $module_data['name'];
      $module_status = $module_data['status'];

      $uns_blob = unserialize($module_data['info_blob']);

      $module_name = $uns_blob['name'];
      $module_coreversion = $uns_blob['core'];
      $module_version = $uns_blob['version'];
      $module_package = $uns_blob['package'];
      $module_mtime = array_key_exists('mtime', $uns_blob) ? $uns_blob['mtime'] : NULL;
      $module_datestamp = array_key_exists('datestamp', $uns_blob) ? $uns_blob['datestamp'] : NULL;

      $module_version_array = explode('-', $module_version);
      $module_api_version = $module_version_details = $module_version_extra = '';
      $module_version_major = $module_version_patch = 'NULL';
      if(count($module_version_array) > 0) {
        $module_api_version = $module_version_array[0];
        if(count($module_version_array) > 1) {
          $module_version_details = $module_version_array[1];
          if(count($module_version_array) > 2) {
            $module_version_details = $module_version_array[2];
          }
          $mvd_array = explode('.', $module_version_details);
          if(count($mvd_array) > 0) {
            if(is_numeric($mvd_array[0])) {
              $module_version_major = $mvd_array[0];
            }
            if(count($mvd_array) > 1) {
              if(is_numeric($mvd_array[1])) {
                $module_version_patch = $mvd_array[1];
              }
            }
          }
        }
      } // Module version cruft.


      try {
        $stmt = $db->prepare("INSERT INTO drupal_site_module_log (ds_id, job_id, website, module_name, module_machinename, module_baseversion, module_version,
          version_major, version_patch, version_extra, status)
          values (:ds_id, :job_id, :website, :module_name, :module_machinename, :module_baseversion, :module_version, 
          :version_major, :version_patch, :version_extra, :status)");

        $stmt->execute(array(':ds_id' => $site_id,
          ':job_id' => $job_id,
          ':website' => $site,
          ':module_name' => $module_name,
          ':module_machinename' => $module_machinename,
          ':module_baseversion' => $module_coreversion,
          ':module_version' => $module_version,
          ':version_major' => $module_version_major,
          ':version_patch' => $module_version_patch,
          ':version_extra' => $module_version_extra,
          ':status' => $module_status,
          ));
      }
      catch(Exception $ex) {
        _dt_dblog_error('Unable to write drupal_site_module_log with Drupal core version for ' . $site_data['ds_name'] . ' because: ' . $ex->getMessage(), 'error');
      }

      $this_db = NULL; // Play nice.
    } // Each module.
  } // Each Drupal site.

  return true;
}

/***
 * Inspect bootstrap.inc (ew) to figure out which version of Drupal core is in use on each site.
 * @return bool
 */
function _dt_record_drupalcore_version() {
  global $job_id;
  global $db;
  global $drupal_sites;

  foreach($drupal_sites as $idx => $site_data) {
    _dt_dblog_error('Searching for Drupal core version for site ' . $site_data['ds_name'], 'info');

    // Look for includes/bootstrap.inc in the site root.
    $this_site_dir = $site_data['ds_site_filesystem_root'];
    $bootstrap_file = $this_site_dir . '/includes/bootstrap.inc';

    if(!is_readable($bootstrap_file)) {
      _dt_dblog_error('Cannot find or read bootstrap.inc for site ' . $site_data['ds_name'] . ' at location ' . $bootstrap_file, 'error');
    }
    else {
      try {
        $found = false;
        $handle = fopen($bootstrap_file, "r");
        $bootstrap_all = fread($handle, filesize($bootstrap_file));
        fclose($handle);

        $core_version = $core_version_patch = $core_version_base = NULL;
        $core_version_extra = NULL;

        // Look for the line that looks like:
        // define('VERSION', '7.43');
        $ver_string = "define('VERSION',";
        $start_pos = strpos($bootstrap_all, $ver_string);
        if(false !== $start_pos) {
          $end_pos = strpos($bootstrap_all, ');', $start_pos);
          if(false !== $end_pos) {
            $version_string = substr($bootstrap_all, $start_pos, $end_pos - $start_pos - 1);
            $version_string = substr($version_string, strlen($ver_string) + 2);

            // If all went well we now have a value like "7.43" without the quotes.
            $version_array = explode('.', $version_string);

            if(count($version_array) > 0) {
              $core_version_base = $version_array[0];
              if(count($version_array) > 1) {
                $core_version_patch = $version_array[1];

                //@todo: what about alpha, beta, etc.? put in $core_version_extra
              }

              $found = true;

              // Write Drupal core version to the database.
              try {
                $stmt = $db->prepare("INSERT INTO drupal_site_module_log (ds_id, job_id, website, module_name, module_machinename, module_baseversion, module_version,
                  version_major, version_patch, version_extra, status)
                  values (:ds_id, :job_id, :website, :module_name, :module_machinename, :module_baseversion, :module_version, 
                  :version_major, :version_patch, :version_extra, :status)");

                $rows = $stmt->execute(array(
                  ':ds_id' => $site_data['ds_id'],
                  ':job_id' => $job_id,
                  ':website' => $site_data['ds_name'],
                  ':module_name' => 'Drupal core',
                  ':module_machinename' => 'drupal',
                  ':module_baseversion' => $core_version_base . '.x',
                  ':module_version' => $core_version_base . '.x-' . $core_version_patch,
                  ':version_major' => $core_version_base,
                  ':version_patch' => $core_version_patch,
                  ':version_extra' => $core_version_extra,
                  ':status' => 1,
                ));
              }
              catch(Exception $ex) {
                _dt_dblog_error('Unable to write drupal_site_module_log with Drupal core version for ' . $site_data['ds_name'] . ' because: ' . $ex->getMessage(), 'error');
              }

            }

          }
        }
        if(!$found) {
          _dt_dblog_error('Drupal core version not found for site ' . $site_data['ds_name'], 'warning');
        }
      }
      catch(Exception $ex) {
        _dt_dblog_error('Could not read bootstrap.inc for site ' . $site_data['ds_name'] . ' at location ' . $bootstrap_file . ' because: ' . $ex->getMessage(), 'error');
      }

    }

  }

  return true;
}

/***
 * Contact updates.drupal.org to get the status for all modules in use.
 */
function _dt_record_modules_status() {

  global $db;
  global $job_id;

  try {

    _dt_dblog_error('Making a bunch of cURL requests to update.drupal.org to get module release info.', 'info');

    // Get a distinct list of module + api_version that has status=1 (enabled), corresponding to the current job id.
    $sql = "SELECT DISTINCT dsml.module_machinename, dsml.module_baseversion, module_parent_machinename FROM drupal_site_module_log dsml
      left join drupal_modules_bundled on dsml.module_machinename = drupal_modules_bundled.module_machinename
      where dsml.status = 1 order by dsml.module_machinename, dsml.module_baseversion";
    // was last 2 days:
    // and unix_timestamp(dsml.timestamp) + 172800 > unix_timestamp()
    $modules_to_check = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach($modules_to_check as $module) {

      $module_machinename = array_key_exists('module_parent_machinename', $module) && strlen($module['module_parent_machinename']) > 0 ? $module['module_parent_machinename'] : $module['module_machinename'];
      $module_baseversion = $module['module_baseversion'];

      //print("<hr>Module: $module_machinename $module_baseversion<br />");

      // Default major and patch to 0.
      // This will cause the script to pull all release data for that module, by default.
      $module_db_latest_major = 0;
      $module_db_latest_patch = 0;

      // Get the most recent module release info we have- version major and patch.
      $sql_patch = "SELECT max(concat(version_major, concat('.', version_patch))) FROM drupal_module_release "
        . " WHERE module_machinename='" . $module_machinename . "' and api_version='" . $module_baseversion
        . "' AND version_major is not null AND version_patch is not null;";
      $max_patch = $db->query($sql_patch)->fetchColumn();

      if(NULL !== $max_patch && strlen($max_patch) > 0) {
        $max_patch_array = explode('.', $max_patch);
        if(count($max_patch_array) == 2) {
          if(is_numeric($max_patch_array[0])) {
            $module_db_latest_major = $max_patch_array[0];
          }
          if(is_numeric($max_patch_array[1])) {
            $module_db_latest_patch = $max_patch_array[1];
          }
        }
      }

      $xml_data = _dt_get_module_update_info($module_machinename, $module_baseversion);

      $xml_object = simplexml_load_string($xml_data);
      if(isset($xml_object->releases)) {

        foreach($xml_object->releases->release as $release) {
          if($release->version_major >= $module_db_latest_major && $release->version_patch >= $module_db_latest_patch) {

            /*
              print("Title: " . $xml_object->title . '<br />');
              print("Machine Name: " . $module_machinename . '<br />');
              print("Supported Majors: " . $xml_object->supported_majors . '<br />');
              print("API Version: " . $module_baseversion . '<br />');
              print("Version Major: " . $release->version_major . '<br />');
              print("Version Patch: " . $release->version_patch . '<br />');
              print("Security: " . $security_covered . '<br />');
              print("Date: " . $release->date . '<br />');
             */
            // Only if this release is published and is stable (not alpha, beta rc).
            if(strtolower($release->status) == 'published' && !isset($release->version_extra)) {
              $security_covered = 0;
              if(isset($release->security) && isset($release->security['covered'])) {
                $security_covered = (string)($release->security['covered']);
              }
              $release_date = isset($release->date) ? $release->date : 'NULL';

              //print("Writing release v." . $release->version_major . "." . $release->version_patch . "<br />");
              $module_release_sql = "INSERT INTO drupal_module_release (job_id, module_name, module_machinename, "
                . " api_version, version_major, version_patch, security_update_available, supported_major_versions, release_date) "
                . " values (" . $job_id . ", '" . $xml_object->title . "', '" . $module_machinename
                . "', '" . $module_baseversion . "', " . $release->version_major . ", " . $release->version_patch
                . ", " . $security_covered . ", '" . $xml_object->supported_majors . "', " . $release_date . ")";

              $db->prepare($module_release_sql)->execute();
            }
            /*
            else {
              print("IGNORING:<br />");
              print("Version Major: " . $release->version_major . '<br />');
              print("Version Patch: " . $release->version_patch . '<br />');
              print("Status: " . $release->status . '<br />');
              print("Version Extra: " . $release->version_extra . '<br />');
              $security_covered = 0;
              if(isset($release->security) && isset($release->security['covered'])) {
                $security_covered = (string)($release->security['covered']);
              }
              print("Security: " . $security_covered . '<br />');
            }
          */
          } // If this version and patch is > the most recent info in the database, add the newer info.
          else {
            break;
          }
        } // for each release
      } // if we have some release data

    } // foreach module

  }
  catch(Exception $ex) {
    db_set_active();
    die($ex->getMessage());
  }

}

/***
 * Use the info we got from updates.d.o to note the status for each enabled modules, across all sites.
 * @return bool
 */
function _dt_record_site_modules_status() {
  global $job_id;
  global $db;

  # Query for a distinct list of modules/versions and Drupal core in use across all websites.

  $sql = "SELECT distinct dsml.module_machinename, dsml.module_baseversion, dsml.version_major, dsml.version_patch, ok.okid
      from drupal_site_module_log dsml 
      left join drupal_site_module_okay ok on dsml.ds_id = ok.ds_id 
      and dsml.module_machinename = ok.module_machinename 
      and dsml.module_baseversion = ok.api_version 
      and dsml.version_major = ok.version_major 
      and dsml.version_patch = ok.version_patch
      where job_id=" . $job_id . " and dsml.version_patch is not null 
      order by dsml.module_machinename, dsml.module_baseversion, dsml.version_major, dsml.version_patch";

  $modules_to_update = array();
  try {
      $modules_to_update = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    catch(Exception $ex) {
      _dt_dblog_error('Unable to get a distinct list of modules across all sites because: ' . $ex->getMessage(), 'error');
    }

  _dt_dblog_error('Updating module data based on update.drupal.org info.', 'info');
  foreach($modules_to_update as $module) {

    $module_machinename = $module['module_machinename'];
    $api_version = $module['module_baseversion'];
    $version_major = $module['version_major'];
    $version_patch = $module['version_patch'];
    $has_freepass = isset($module['okid']) ? true : false; // If an admin manually cleared update/issue for this module on this site, ignore it.

    $outdated = 0;
    $has_update = 0;
    $has_secupdate = 0;
    $has_unsuprelease = 0;

    // Only check further if this site/module does not have a free pass.
    if(!$has_freepass) {
      # Determine and record the update status for each enabled module and distinct version, using the table that has that info.
      # - do we have enough info- do we have update info up to this version (at a minimum) and how recently have we checked for updates?
      # - is there a pending update
      # - is there a pending security update.
      # - is this module cleared/okay for this site in spite of any listed issues?
      # - is this module base version supported?

      // , unix_timestamp(start_time) as st, unix_timestamp() as nw
      $sql = "SELECT distinct security_update_available, supported_major_versions, version_major, version_patch "
        . " from drupal_module_release "
        . " where module_machinename='" . $module_machinename . "' and api_version='" . $api_version
        . "' and version_major >= " . $version_major
        . " order by version_patch desc";
      $updates_avail = array();

      try {
        $updates_avail = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
      }
      catch(Exception $ex) {
        _dt_dblog_error('Unable to set update information for ' . $module_machinename . ' because: ' . $ex->getMessage(), 'error');
      }

      if (count($updates_avail) > 0 && is_array($updates_avail)) {
        foreach($updates_avail as $module_update) {
          $outdated = 0; # At least we have info about this version and patch.

          if(
          ($module_update['version_major'] == $version_major && (int)$module_update['version_patch'] > (int)$version_patch)
          || ($module_update['version_major'] > $version_major))
          {
            $has_update = 1;

            if((int)$module_update['security_update_available'] == 1) {
              $has_secupdate = 1;
            }
            # Is the current version in the supported versions array?
            $supported_major_versions = $module_update['supported_major_versions'];
            $supported_versions_array = explode(',', $supported_major_versions);
            if(!in_array($version_major, $supported_versions_array)) {
              $has_unsuprelease = 1;
            }
          }
        }
      }
      else {
        $outdated = 1;
      }
    }

    # At a minimum we update checked and stale/outdated fields.
    $sql = "UPDATE drupal_site_module_log SET checked=NOW(), modules_status_outdated=" . $outdated
      . ", update_available=" . $has_update . ", security_update_available=" . $has_secupdate
      . ", unsupported=" . $has_unsuprelease
      . " where job_id=" . $job_id . " and module_machinename ='" . $module_machinename . "' and module_baseversion='"
    . $api_version . "' and version_major=" . $version_major . " and version_patch=" . $version_patch;

    try {
      $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    catch(Exception $ex) {
      _dt_dblog_error('Unable to get a distinct list of modules across all sites because: ' . $ex->getMessage(), 'error');
    }

  }

  return true;
}

/***
 * CURL updates.d.o for updates.
 * @param $module_machinename
 * @param $module_baseversion
 * @return mixed
 */
function _dt_get_module_update_info($module_machinename, $module_baseversion) {

  $curl_uri = 'https://updates.drupal.org/release-history/' . $module_machinename . '/' . $module_baseversion;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $curl_uri);

  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

  $response = curl_exec($ch);
  $info = curl_getinfo($ch);

  if ($info['http_code'] !== 200) {
    _dt_dblog_error("Could not retrieve update info from " . 'warning');
  }

  curl_close($ch);

  return $response;
}

/***
 * Write the completion timestamp for this job.
 */
function _dt_complete_logging_job() {
  global $job_id;
  global $db;

  try {
    $stmt = $db->prepare("UPDATE job SET complete_time=NOW() where job_id=:job_id");
    $stmt->execute(array(':job_id' => $job_id));
  }
  catch(Exception $ex) {
    _dt_dblog_error('Unable to write completion time for job with id ' . $job_id . ' because: ' . $ex->getMessage(), 'error');
  }
  return;
}

/***
 * Rudimentary error logging.
 * @param $msg
 * @param $type
 */
function _dt_dblog_error($msg, $type) {
  global $db;
  global $job_id;

  if($type == 'error') {
    echo($msg);
  }

  try {
    $stmt = $db->prepare("INSERT INTO job_error(job_id, error_message, severity) VALUES(:job_id, :error_message, :severity)");
    $stmt->execute(array(':job_id' => $job_id, ':error_message' => $msg, ':severity' => $type));
  }
  catch(Exception $ex) {
    echo($ex->getMessage());
  }

}