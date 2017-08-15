# drupaltattle
PHP script to tattle on Drupal websites
[Quotient, Inc.](http://www.quotient-inc.com)

# Environment Setup

1. Use the MySQL script to generate the logging database tables.
2. Create a MySQL user account.
3. Give the MySQL user r/w on all logging database tables, and r on all Drupal databases that you need to read.
4. Run drupaltattle.php either by command line or in the browser. This will populate the relational db (no actual relations but tables with related keys).
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

# Troubleshooting
The script generates a new "job", just for tying together the data in various tables. 

If you run into issues, first see what gets spit out on the command line. If that isn't useful, read the entries in job_error table in the logging database, using the job_id from your job.

Typical issues:

## Database user permissions

## File i/o issues
To get the Drupal core version, the script actually has to read the file [siteroot]/includes/bootstrap.inc for each Drupal 7 website. Make sure the scripts executes in a way that it has permission to read this file.

# Todo
Right now if no update info is found, modules are being marked as "unsupported". Maybe we need yet another column, since "unsupported" falls into the same bucket as security updates?
