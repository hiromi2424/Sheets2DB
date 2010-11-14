# Sheets2DB #

## Summary ##

This is a web application to import designing database tables from Google Document(Spread Sheets) into local real database.
Now this supports MySQL only.
Now Special syntax is needed on SpreadSheets.(it is welid, it may be able to configure on the future)

Now this is alpha version so this is unsatisfactory.

## Requirements ##

- CakePHP >= 1.3
- ZendFramework(GData libraries is required)
- PHP >= 5.2


## Setup ##

As like standard cakephp application:

- Change permission of /app/tmp to 777(recursively)
- Copy from /app/config/database.php.default to /app/config/database.php.
- Modify $default property as like your database in above database.php.
- Copy CakePHP core in to root(/cake). (official site or git repository(http://github.com/cakephp/cakephp))

Install ZendFramework:

- You don't need to install it if its library direcotory is available as include_path.
- Copy your ZendFramework downloaded and extracted(ZendFramework-1.xx.xx) /vendors or /app/vendors directory. Rename the folder as "zend_framework".

## Usage ##

- Access your installed web site.
- Enter Google authentication.
- Go to top and enter your sheet name.
