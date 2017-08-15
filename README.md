# drupaltattle
PHP script to tattle on Drupal websites
[Quotient, Inc.](http://www.quotient-inc.com)

# Environment Setup

1. Use the MySQL script to generate the logging database tables.
2. Create a MySQL user account.
3. Give the MySQL user r/w on all logging database tables, and r on all Drupal databases that you need to read.
4. Run drupaltattle.php either by command line or in the browser. This will populate the relational db (no actual relationships but tables with related keys).
5. Run drupaltattle_report.php by command line or in the browser. Parameters are "all", "site" and "site=n" where n is the drupal site id from the table drupal_site.

# Executing the scripts from the command line
```php -f drupaltattle.php```
```php -f drupaltattle_report.php all```
```php -f drupaltattle_report.php site```
```php -f drupaltattle_report.php site=1```

# Executing the scripts from the browser
```http://localhost/drupaltattle.php```
```http://localhost/drupaltattle_report.php?all```
```http://localhost/drupaltattle_report.php?site```
```http://localhost/drupaltattle_report.php?site=1```

# Bundled Modules
Some modules are bundled with others, such as various Views sub-modules.
You can add an entry in the table drupal_modules_bundled so Drupal will know where to look.

# Clearing the security flag for a module on a site
You can manually "clear" the security flag for a specific website and module by creating a row in drupal_site_module_okay.

The "clear" is in effect for that site and that version only. Each subsequent time the tattle job runs, if the current module version for the site matches a record in the okay table, the status for that module on that site will be ok.

This means (right now) that if you run the tattle report and create an okay record for a module/site combo, re-running the report will give the same status as the previous run. You'll have to run the tattle job again to get the cleared status, and run the report after that.

# Troubleshooting
The script generates a new "job", just for tying together the data in various tables. 

If you run into issues, first see what gets spit out on the command line. If that isn't useful, read the entries in job_error table in the logging database, using the job_id from your job.

## Typical issues:

### Database user permissions
If you're getting access denied from MySQL, check your permissions. The script attempts to verbosely log issues, but a connection error will be a fatal error and should crash the script.

### File i/o issues
To get the Drupal core version, the script actually has to read the file [siteroot]/includes/bootstrap.inc for each Drupal 7 website. Make sure the scripts executes in a way that it has permission to read this file.

# Todo
Right now if no update info is found, modules are being marked as "unsupported". Maybe we need yet another column, since "unsupported" falls into the same bucket as security updates?

Drupal 8 support- some minor changes to check "if 8 then look in this place" for the site module data, and for the Drupal version number. The module status checker and other parts will be un-changed.
