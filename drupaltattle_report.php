<?php
/**
 * Drupal Tattle Report
 * Description: Generates reports using data from the Drupal tattle db.
 * Quotient, Inc. for The Smithsonian
 * Version: 1.0
 * Date: 8/14/17
 */

/*
 * 1) General report across sites:
sitename
drupal core

modules status
# modules installed (also list enabled)

# of modules with updates

# of modules with sec updates
   # modules not supported (no longer) - count as a security update
   # custom modules which have not been cleared - “static code analysis”

write the bit code (whiteboard shot)

2) Detailed report per site - accept ds_id and optional job_id (default to latest if no job_id)

SELECT website, timestamp, COUNT(security_update_available) FROM drupal_site_module_log WHERE job_id IN
(SELECT MAX(job_id) FROM job WHERE job_type=‘log_drupalmodules_inuse’)
and security_update_available = 1 GROUP BY website, timestamp.


Write:
- synopsis, functionality
- installation instructions

Ask for another pair of eyes on the scripts- ask Gor or Chintan for code review.

3) all details from a particular job - accept job_id

 */