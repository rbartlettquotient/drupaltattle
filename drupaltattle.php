<?php
/**
 * Quotient, Inc. for The Smithsonian
 * Version: 1.0
 * Date: 8/14/17
 * Description: Check for pending security updates and other issues on Drupal websites.
 */

# Run on cli
# $ php -f drupaltattle.php

// Username and pass.
// User must have read access on the databases enumerated in the table drupal_site.
// User must have r/w access on the drupal_log database.
$dbserver = '127.0.0.1';     // Do not use 'localhost' - use 127.0.0.1 or other IP.
$dbport = '33067';
$dbname = 'drupal_log';
$dbuser = 'user_all';
$dbpass = 'littlesnitch';

global $db;           // The logging database.
global $job_id;       // Generated ID for this job.
global $drupal_sites; // Associative array of Drupal websites.

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

// Which modules are in use across all of these websites?
_dt_record_modules_inuse($dbuser, $dbpass);

// Which Drupal version is in use for each website?
_dt_record_drupalcore_version();

// Calculate which modules are not secure, not supported etc.
_dt_record_modules_status();

// Finally, update the status for each module, for all websites.
_dt_record_site_modules_status();

// Complete the job and write the timestamp.
_dt_complete_logging_job();

$db = NULL; // Be nice.

//----------- Supporting Functions ------------//

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
    _dt_dblog_error('Unable to retrieve list of Drupal sites because: ' . $ex->getMessage(), 'error');
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
      $module_mtime = $uns_blob['mtime'];
      $module_datestamp = $uns_blob['datestamp'];

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
        _dt_dblog_error('Unable to retrieve list of Drupal sites because: ' . $ex->getMessage(), 'error');
      }

      $this_db = NULL; // Play nice.
    } // Each module.
  } // Each Drupal site.

  return true;
}

/***
 * Inspect bootstrap.inc (ew) to figure out which version of Drupal core is in use.
 * @return bool
 */
function _dt_record_drupalcore_version() {
  global $job_id;
  global $drupal_sites;

//@todo Check bootstrap for drupal core

# @todo- we actually have to extract this from [siteroot]/includes/bootstrap.inc
# define('VERSION', '7.56');
#	my $mlog_sql = "INSERT INTO drupal_site_module_log (ds_id, job_id, website, module_name, module_machinename, module_baseversion, module_version,
#		 version_major, version_patch, version_extra, status)
#	values (" . $site_id . ", " . $job_id . ", '" . $site . "', 'Drupal core', 'drupal', '" . $site_api_version . "', '" . $module_version . "', "
#		. $module_version_major . ", " . $module_version_patch . ", '" . $module_version_extra . "', 1)";
#	my $mlogst = $log_db->prepare($mlog_sql);
#	$mlogst->execute
#	   or die "Could not insert module log record for drupal site " . $site . ", module '" . $module_name . "'. SQL Error: $DBI::errstr\n";

  return true;
}

/***
 * Contact updates.drupal.org to get the status for all modules in use.
 */
function _dt_record_modules_status() {
  //@todo Release date for module update- not being written

  global $job_id;
  /*
  try {

    $database_info = array(
      'host' => '127.0.0.1',
      'port' => '33067',
      'database' => 'drupal_log',
      'username' => 'user_all',
      'password' => 'atlas123',
      'driver' => 'mysql',
    );
    Database::addConnectionInfo('drupal_log_dbkey', 'default', $database_info);
    db_set_active('drupal_log_dbkey');

    // Create a new job and get that job id.
    $sql = "INSERT INTO job (job_type) values ('get_drupalmodules_releases')";
    db_query($sql)->execute();
    $sql = "SELECT job_id from job where job_type='get_drupalmodules_releases' order by job_id desc limit 0,1";
    $job_id = db_query($sql)->fetchField();

    // Get a distinct list of module + api_version that has status=1 (enabled) within the last 48 hours.
    $sql = "SELECT DISTINCT module_machinename, module_baseversion FROM drupal_site_module_log "
      . " where status = 1 and unix_timestamp(timestamp) + 172800 > unix_timestamp() order by module_machinename, module_baseversion";
    $modules_to_check = db_query($sql)->fetchAll();

  //@todo: left join to drupal_modules_bundled to see if we have a module_parent_machinename to use for getting status

    foreach($modules_to_check as $module) {
      $module_machinename = $module->module_machinename;
      $module_baseversion = $module->module_baseversion;

      print("<hr>Module: $module_machinename $module_baseversion<br />");

      // If we get no major set it to 0. Ditto for patch.
      // This will cause the script to pull all release data for that module.
      $module_db_latest_major = 0;
      $module_db_latest_patch = 0;

      // Get the most recent module release info we have- version major and patch.
      $sql_patch = "SELECT max(concat(version_major, concat('.', version_patch))) FROM drupal_module_release "
        . " WHERE module_machinename='" . $module_machinename . "' and api_version='" . $module_baseversion
        . "' AND version_major is not null AND version_patch is not null;";
      $max_patch = db_query($sql_patch)->fetchField();
      $max_patch_array = implode('.', $max_patch);
      if(count($max_patch_array) == 2) {
        if(is_numeric($max_patch_array[0])) {
          $module_db_latest_major = $max_patch_array[0];
        }
        if(is_numeric($max_patch_array[1])) {
          $module_db_latest_patch = $max_patch_array[1];
        }
      }

      $xml_data = _dt_get_module_update_info($module_machinename, $module_baseversion);

      $xml_object = simplexml_load_string($xml_data);
      foreach($xml_object->releases->release as $release) {
        if($release->version_major >= $module_db_latest_major && $release->version_patch >= $module_db_latest_patch) {

          // Only if this release is published and is stable (not alpha, beta rc).
          if(strtolower($release->status) == 'published' && !isset($release->version_extra)) {
            $security_covered = 0;
            if(isset($release->security) && isset($release->security['covered'])) {
              $security_covered = (string)($release->security['covered']);
            }
            print("Writing release v." . $release->version_major . "." . $release->version_patch . "<br />");
            $module_release_sql = "INSERT INTO drupal_module_release (job_id, module_name, module_machinename, "
              . " api_version, version_major, version_patch, security_update_available, supported_major_versions) "
              . " values (" . $job_id . ", '" . $xml_object->title . "', '" . $module_machinename
              . "', '" . $module_baseversion . "', " . $release->version_major . ", " . $release->version_patch
              . ", " . $security_covered . ", '" . $xml_object->supported_majors . "')";

            db_query($module_release_sql)->execute();
          }
        } // If this version and patch is > the most recent info in the database.
        else {
          break;
        }
      }

    } // foreach module

    $sql = "UPDATE job set complete_time=NOW() where job_id=" . $job_id;
    db_query($sql)->execute();

    db_set_active();
  }
  catch(Exception $ex) {
    db_set_active();
    die($ex->getMessage());
  }
  */

  /*        else {
  print("Title: " . $xml_object->title . '<br />');
  print("Machine Name: " . $module_machinename . '<br />');
  print("Supported Majors: " . $xml_object->supported_majors . '<br />');
  print("API Version: " . $module_baseversion . '<br />');
  print("Version Major: " . $release->version_major . '<br />');
  print("Version Patch: " . $release->version_patch . '<br />');
  print("Security: " . $security_covered . '<br />');
  print("Date: " . $release->date . '<br />');
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

}

/***
 * Use the info we got from updates.d.o to note the status for each enabled modules, across all sites.
 * @return bool
 */
function _dt_record_site_modules_status() {
  global $job_id;
  //@todo also set the field for “unsupported release”

  //@todo if this module gets a free pass on this site (see drupal_site_module_okay for exceptions), ignore the issue.

  /*
   * # 2. Query for a distinct list of modules/versions and Drupal core in use across all websites.
# Retrieve update information for all modules and for core along with a timestamp representing the availabile date/time of the update.
# Write to drupal_module_update table.

$sql = "SELECT distinct module_machinename, module_baseversion, version_major, version_patch from drupal_site_module_log where job_id="
. $job_id . " and version_patch is not null order by module_machinename, module_baseversion, version_major, version_patch";
$sth = $log_db->prepare($sql);
$sth->execute
   or die "SQL Error: $DBI::errstr\n";

say("Checking module status for all modules in use.");
while (my @mod_row = $sth->fetchrow_array) {

	my $module_machinename = @mod_row[0];
	my $api_version = @mod_row[1];
	my $version_major = @mod_row[2];
	my $version_patch = @mod_row[3];

	# 3. Determine and record the update status for each enabled module and distinct version, using the table that has that info.
	# - do we have enough info- do we have update info up to this version (at a minimum) and how recently have we checked for updates?
	# - is there a pending update
	# - is there a pending security update.
	$sql = "SELECT distinct security_update_available, supported_major_versions, max(version_patch), unix_timestamp(start_time), unix_timestamp() "
	. " from drupal_module_release join job on drupal_module_release.job_id = job.job_id "
	. " where module_machinename='" . $module_machinename . "' and api_version='" . $api_version
	. "' and version_major=" . $version_major;

	my $updt = $log_db->prepare($sql);
	$updt->execute
	   or die "SQL Error: $DBI::errstr\n";

	my $outdated = 1;
	my $has_update = 0;
	my $has_secupdate = 0;

	if (my @version_row = $updt->fetchrow_array) {

		my $secupdate = @version_row[0];
		my $supported_major_versions = @version_row[1];
		my $max_patch = @version_row[2];
		my $job_starttime = @version_row[3];
		my $now_timestamp = @version_row[4];

		if(defined $job_starttime) {

			$outdated = 0; # At least we have info about this version and patch.

			# day = 86400 seconds
			# If the info we have is > 2 days old.
			if($job_starttime + 172800 < $now_timestamp) {
				# Call it stale.
				$outdated = 2;
			}
		}

		if($max_patch > $version_patch) {
			$has_update = 1;

			if($secupdate = 1) {
				$has_secupdate = 1;
			}

			# @todo Is the current version in the supported versions array?
		}


	} # If we got update information.

 	# At a minimum we update checked and stale/outdated fields.
	$sql = "UPDATE drupal_site_module_log SET checked=NOW(), modules_status_outdated=" . $outdated
		. ", update_available=" . $has_update . ", security_update_available=" . $has_secupdate
		. " where job_id=" . $job_id . " and module_machinename ='" . $module_machinename . "' and module_baseversion='"
	. $api_version . "' and version_major=" . $version_major . " and version_patch=" . $version_patch;

	my $upst = $log_db->prepare($sql);
	$upst->execute
	   or die "SQL Error: $DBI::errstr\n";

}

   */

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


  if ($info['http_code'] == 200) {

  } else {
    print("Could not retrieve update info from " . $curl_uri);
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