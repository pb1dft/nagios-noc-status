# Installation

This document describes a basic installation of Nagios NOC Status behind Apache or Nginx using the existing Nagios htpasswd file for authentication.

## 1. Prerequisites

The server should have:

- Nagios Core installed and running
- Apache or Nginx
- PHP
- PHP-FPM when using Nginx
- Git
- A Nagios htpasswd file
- The Nagios status file
- The Nagios external command file

Typical Nagios paths are:

```text
/var/spool/nagios/status.dat
/var/spool/nagios/cmd/nagios.cmd
/etc/nagios/htpasswd.users
```

These paths vary by distribution and Nagios installation.

## 2. Clone the repository

Example:

```bash
cd /var/www
git clone git@github.com:pb1dft/nagios-noc-status.git
cd nagios-noc-status
```

Alternatively, deploy the repository to another directory and use that directory as the web root.

## 3. Configure Nagios paths

The application should use a central configuration file rather than requiring Nagios paths to be edited in individual scripts.

Recommended configuration:

```text
config/config.php
```

Example:

```php
<?php
declare(strict_types=1);
/*
 * Nagios status.dat file.
 */
define(
    'NAGIOS_STATUS_FILE',
    '/var/spool/nagios/status.dat'
);


/*
 * Nagios external command pipe.
 */
define(
    'NAGIOS_COMMAND_FILE',
    '/var/spool/nagios/cmd/nagios.cmd'
);
?>

```

Use the actual paths from your Nagios installation.


## 4. Nagios permissions

The web server/PHP process must be able to:

1. Read the Nagios status file.
2. Write commands to the Nagios external command file.

Do not make these files world-writable just to make the dashboard work.

Use the existing Nagios/web-server group and the minimum permissions appropriate for your operating system.

After changing permissions, verify access as the user running PHP.

For example:

```bash
sudo -u www-data test -r /var/spool/nagios/status.dat
sudo -u www-data test -w /var/spool/nagios/cmd/nagios.cmd
```

The web-server user may differ on your system.

## 5. Configure Basic Authentication

The dashboard is intended to use the existing Nagios htpasswd file.

Typical location:

```text
/etc/nagios/htpasswd.users
```

Do not copy the password file into the web root.

### Apache

Copy the example configuration:

```bash
sudo cp config/apache/nagios-noc-status.conf.example     /etc/apache2/sites-available/nagios-noc-status.conf
```

Edit it:

```bash
sudo editor /etc/apache2/sites-available/nagios-noc-status.conf
```

Set the correct:

- `ServerName`
- `DocumentRoot`
- `AuthUserFile`

Enable required modules and the site:

```bash
sudo a2enmod auth_basic authn_file
sudo a2ensite nagios-noc-status.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Nginx

Copy the example:

```bash
sudo cp config/nginx/nagios-noc-status.conf.example     /etc/nginx/sites-available/nagios-noc-status
```

Create the enabled-site link:

```bash
sudo ln -s /etc/nginx/sites-available/nagios-noc-status     /etc/nginx/sites-enabled/nagios-noc-status
```

Edit the configuration and set:

- `server_name`
- `root`
- htpasswd file path
- PHP-FPM socket

Test and reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

The Nginx PHP configuration should pass the authenticated username to PHP:

```nginx
fastcgi_param REMOTE_USER $remote_user;
```

The acknowledgement API uses `REMOTE_USER` as the Nagios command author.

## 6. HTTPS

Use HTTPS in production.

Basic Authentication credentials must not be sent over an unencrypted HTTP connection.

A typical production flow is:

```text
Browser
   |
   | HTTPS
   v
Apache / Nginx
   |
   | Basic Authentication
   v
Nagios NOC Status
   |
   +---- status.dat
   |
   +---- nagios.cmd
```

HTTP can be useful temporarily during initial testing, but production deployments should use TLS.

## 7. Test the dashboard

Open the configured hostname in a browser.

You should receive a Basic Authentication prompt.

Log in using a user from the Nagios htpasswd file.

Verify that:

- The dashboard loads.
- Nagios hosts/services are displayed.
- Active problems are shown correctly.
- The acknowledgement dialog opens.
- The authenticated username is used as the acknowledgement author.

## 8. Test acknowledgement

Before testing against important production monitoring, verify that the Nagios external command interface is working.

Submit an acknowledgement for a test problem.

The dashboard supports:

- Non-persistent acknowledgement comments — default.
- Persistent acknowledgement comments — selected explicitly in the acknowledgement dialog.

Check Nagios after submission to confirm that the acknowledgement was accepted.

## 9. Troubleshooting

### `Permission denied` when reading status.dat

Check:

```bash
ls -l /var/spool/nagios/status.dat
```

Then test access as the web-server user:

```bash
sudo -u www-data test -r /var/spool/nagios/status.dat
```

Adjust the user/group permissions appropriately.

### `Permission denied` writing nagios.cmd

Check:

```bash
ls -l /var/spool/nagios/cmd/nagios.cmd
```

Then:

```bash
sudo -u www-data test -w /var/spool/nagios/cmd/nagios.cmd
```

Do not solve this by making the command file world-writable.

### Basic Authentication does not work

Check the htpasswd path in the web-server configuration.

For Apache:

```bash
sudo apache2ctl configtest
```

For Nginx:

```bash
sudo nginx -t
```

Also check the web-server error log.

### PHP does not see the authenticated username

The acknowledgement API expects:

```php
$_SERVER['REMOTE_USER']
```

For Nginx + PHP-FPM, ensure the PHP location contains:

```nginx
fastcgi_param REMOTE_USER $remote_user;
```

Apache normally provides `REMOTE_USER` when Basic Authentication is configured.

## 10. Production checklist

Before exposing the dashboard:

- [ ] HTTPS enabled
- [ ] Correct Nagios status file configured
- [ ] Correct Nagios command file configured
- [ ] Nagios password file outside the web root
- [ ] Minimum required filesystem permissions applied
- [ ] Basic Authentication enabled
- [ ] PHP errors not displayed to users
- [ ] No passwords/API keys/private keys committed to Git
- [ ] Test host acknowledgement
- [ ] Test service acknowledgement
- [ ] Test non-persistent acknowledgement
- [ ] Test persistent acknowledgement
- [ ] Verify web-server and PHP logs
