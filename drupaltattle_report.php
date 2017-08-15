<?php
/**
 * Generate reports using data from the Drupal tattle db.
 *
 * @author Quotient, Inc. for The Smithsonian
 * @version 1.0
 * @property date 8/14/17
 */


//---------------------------------------------//
//-----------       Get Ready       -----------//
//---------------------------------------------//

//--------- Database Connection Info ----------//

// User must have read access on the drupal_log database.
$dbserver = '127.0.0.1';     // Do not use 'localhost' - use 127.0.0.1 or other IP.
$dbport = '33067';
$dbname = 'drupal_log';
$dbuser = 'user_all';
$dbpass = 'littlesnitch';

//---------     A few global vars    ----------//

global $db;           // The logging database.
global $job_id;       // Generated ID for this job.
global $drupal_sites; // Associative array of Drupal websites.
global $command_line;

$vars = array();

//-- Get params from command line or querystring --//
$command_line = true;
if(isset($argv)) {
  // command line
  foreach($argv as $arg) {
    $arg_array = explode('=', $arg);
    $nm = $arg_array[0];
    if($nm !== 'drupaltattle_report.php') {
      $val = array_key_exists(1, $arg_array) ? $arg_array[1] : '';
      $vars[$nm] = $val;
    }
  }
}
else {
  // look for vars in the querystring
  $g = $_GET;
  $vars = $g;
  $command_line = false;
}

// Can we connect to the logging database?
$db = _dt_connect($dbserver, $dbport, $dbname, $dbuser, $dbpass);
if($db == NULL) {
  die('Could not connect to logging database. Check the settings and re-run this script.');
}


//---------------------------------------------//
//-------- Run Reports Based on Params --------//
//---------------------------------------------//

if(array_key_exists('site', $vars)) {
  _dtr_generate_report_site($vars['site']);
}
elseif(array_key_exists('all', $vars)) {
  _dtr_generate_report_all();
}

//@todo - figure out how to synthesize this info with other server error reports
// James wanted a single column with a value representing the state - write to which report/file?
//@todo - we can run historical reports too!

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

function _dtr_generate_report_site($site_id = NULL) {
  global $db;
  global $command_line;
  $_job_id = NULL;

  $site_data = array();

  // status: 0=ok, 4=drupal core issue, 8=module security update or unsupported
  // site - core status - modules status

  // Get the most recent completed job.
  $jobs = $db->query("SELECT job_id from job WHERE job_type = 'log_drupalmodules_inuse' AND complete_time != '0000-00-00 00:00:00' order by job_id desc limit 0,1")->fetchAll(PDO::FETCH_ASSOC);
  if(count($jobs) > 0) {
    $_job_id = $jobs[0]['job_id'];
  }

  $sites = $db->query("SELECT ds_id, ds_name from drupal_site order by ds_name")->fetchAll(PDO::FETCH_ASSOC);
  foreach($sites as $site) {
    $site_data[$site['ds_id']] = array('ds_id' => $site['ds_id'], 'ds_name' => $site['ds_name']);
  }

  // Security updates
  $sql = "select drupal_site.ds_id, drupal_site.ds_name, ok.okid, dsml.module_machinename, dsml.module_version, dsml.security_update_available, dsml.unsupported,
      dsml.update_available
      from drupal_site_module_log dsml join drupal_site on dsml.ds_id = drupal_site.ds_id
      left join drupal_site_module_okay ok on dsml.ds_id = ok.ds_id 
      and dsml.module_machinename = ok.module_machinename 
      and dsml.module_baseversion = ok.api_version 
      and dsml.version_major = ok.version_major 
      and dsml.version_patch = ok.version_patch
      where job_id=" . $_job_id . "
      group by drupal_site.ds_name, dsml.module_machinename ";
  if(NULL !== $site_id && isset($site_id) && is_numeric($site_id)) {
    $sql .= ' having ds_id=' . $site_id;
  }
  $sql .= " order by drupal_site.ds_name, dsml.module_machinename, dsml.module_baseversion, dsml.module_version, dsml.version_major";
  $site_data = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  if($command_line) {
    $mask = "|%5.5s |%-20.20s |%-40.40s |%-60.60s |%-80.80s |\n";
    printf($mask, 'Site', 'Module', 'Version', 'Module Sec. Updates', 'Module Version Unsupported', 'Module Updates');
    foreach($site_data as $data) {
      $module_sec_update = isset($data['okid']) ? 0 : $data['security_update_available'];
      printf($mask, $data['ds_name'], $data['module_machinename'], $data['module_version'], $module_sec_update, $data['unsupported'], $data['update_available']);
    }
  }
  else {
    $out = '<table><thead><tr><th>Site</th><th>Module</th><th>Version</th><th>Module Sec. Updates</th><th>Module Version Unsupported</th><th>Module Updates</th></tr></thead>';
    foreach($site_data as $data) {
      $module_sec_update = isset($data['okid']) ? 0 : $data['security_update_available'];
      $out .= '<tr><td>' . $data['ds_name'] . '</td><td>' . $data['module_machinename'] . '</td><td>' . $data['module_version'] . '</td><td>' . $module_sec_update . '</td><td>' . $data['unsupported'] . '</td><td>' . $data['update_available'] . '</td></tr>';
    }
    $out .= '</table>';
  }
  print($out);

}

function _dtr_generate_report_all() {

  global $db;
  global $command_line;
  $_job_id = NULL;

  $site_data = array();

  // status: 0=ok, 4=drupal core issue, 8=module security update or unsupported
  // site - core status - modules status

  // Get the most recent completed job.
  $jobs = $db->query("SELECT job_id from job WHERE job_type = 'log_drupalmodules_inuse' AND complete_time != '0000-00-00 00:00:00' order by job_id desc limit 0,1")->fetchAll(PDO::FETCH_ASSOC);
  if(count($jobs) > 0) {
    $_job_id = $jobs[0]['job_id'];
  }

  $sites = $db->query("SELECT ds_id, ds_name from drupal_site order by ds_name")->fetchAll(PDO::FETCH_ASSOC);
  foreach($sites as $site) {
    $site_data[$site['ds_id']] = array('ds_id' => $site['ds_id'], 'ds_name' => $site['ds_name']);
  }

  // Module security updates.
  //@todo check drupal_site_module_okay and deduct any that were recently cleared.
  $sql = "select drupal_site.ds_id, sum(security_update_available + unsupported) as sec_update_count,
      count(dsmlid) as modules_enabled, sum(update_available) as update_count
      from drupal_site_module_log join drupal_site on drupal_site_module_log.ds_id = drupal_site.ds_id
      where module_machinename not like 'drupal' and job_id=" . $_job_id . " 
      group by ds_id";
  $modules_log = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  foreach($modules_log as $module) {
    if(array_key_exists($module['ds_id'], $site_data)) {
      $site_data[$module['ds_id']]['module_totals'] = $module['modules_enabled'];
      $site_data[$module['ds_id']]['module_security_updates'] = $module['update_count'];
      $site_data[$module['ds_id']]['module_updates'] = $module['sec_update_count'];
    }
    else {
      //@todo!
    }
  }

  // Core security updates.
  $sql = "select drupal_site.ds_id, sum(security_update_available + unsupported) as sec_update_count 
      from drupal_site_module_log join drupal_site on drupal_site_module_log.ds_id = drupal_site.ds_id
      where module_machinename like 'drupal' and job_id=" . $_job_id . " 
      group by ds_id 
      order by module_machinename, module_baseversion";
  $modules_log = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  foreach($modules_log as $module) {
    if(array_key_exists($module['ds_id'], $site_data)) {
      $site_data[$module['ds_id']]['core_security_updates'] = $module['sec_update_count'];
    }
    else {
      //@todo!
    }
  }

  if($command_line) {
    $mask = "|%5.5s |%-20.20s |%-40.40s |%-60.60s |%-80.80s |\n";
    printf($mask, 'Site', 'Num Modules', 'Drupal Core Sec. Update', 'Module Sec. Updates', 'Module Updates');
    foreach($site_data as $ds_id => $data) {
      printf($mask, $data['ds_name'], $data['module_totals'], $data['core_security_updates'], $data['module_security_updates'], $data['module_updates']);
    }
  }
  else {
    $out = '<table><thead><tr><th>Site</th><th>Num Modules</th><th>Drupal Core Sec. Update</th><th>Module Sec. Updates</th><th>Module Updates</th></tr></thead>';
    foreach($site_data as $ds_id => $data) {
      $out .= '<tr><td>' . $data['ds_name'] . '</td><td>' . $data['module_totals'] . '</td><td>' . $data['core_security_updates'] . '</td><td>' . $data['module_security_updates'] . '</td><td>' . $data['module_updates'] . '</td></tr>';
    }
    $out .= '</table>';
  }
  print($out);

}

