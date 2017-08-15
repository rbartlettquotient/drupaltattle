# drupaltattle
PHP script to tattle on Drupal websites
[Quotient, Inc.](http://www.quotient-inc.com)

# Environment Setup

1. Use the MySQL script to generate the logging database tables.
2. Create a MySQL user account.
3. Give the MySQL user r/w on all logging database tables, and r on all Drupal databases that you need to read.

# Executing the script from the command line
```php -f drupaltattle.php```

# Troubleshooting
The script generates a new "job", just for tying together the data in various tables. 

If you run into issues, first see what gets spit out on the command line. If that isn't useful, read the entries in job_error table in the logging database, using the job_id from your job.

Typical issues:

## Database user permissions

## File i/o issues
To get the Drupal core version, the script actually has to read the file [siteroot]/includes/bootstrap.inc for each Drupal 7 website. Make sure the scripts executes in a way that it has permission to read this file.

