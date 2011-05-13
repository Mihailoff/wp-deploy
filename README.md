wp-deploy
=========
This script deploys a WordPress site from localhost to an online site via FTP, applying configuration changes so that the site still works at the new location.

I use it mainly to transfer my WordPress site from my development server to a test/production server.

By Chris Khoo (chris.khoo@gmail.com)

Installation & Usage
--------------------
1. Copy file to web folder.

2. Update settings in the wp-deploy.php file.

3. Run.

Features
--------
TODO - below is not exactly right.

* Allows automatic backup & deployment of your WordPress site based on the hostname it is run from
* The backup function:
  * creates the SQL file & ZIP file,
  * uploads it via FTP to the deployment server,
  * runs the script online and
  * runs the deployment script (by redirection).
* The deployment function:
  * unzips the ZIP file,
  * runs the SQL script,
  * updates the wp-config.php automatically to the correct database server, name, username & password,
  * updates all image links & hyperlinks to the new server as well and
  * plays a sound to let you know that the backup & deployment has completed.