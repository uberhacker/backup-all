# Backup All

Terminus plugin to backup all available Pantheon sites simultaneously.

## Installation:

Refer to the [Terminus Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins).

## Usage:
```
$ terminus sites backup-all [--env=<id>] [--element=<element>] [--changes=<change>]
```
The associative arguments are all optional and the same filtering rules as the `terminus sites list` command apply.

The **--env** argument value filters by environment and defaults to **all**.  Valid values also include **dev, test, live** or any valid multi-site environment.

The **--element** argument value filters by element and defaults to **all**.  Valid values also include **code, database or files**.

The **--changes** argument is only necessary when the environment is in sftp connection mode and decides how to handle pending filesystem changes.  Valid values include **commit, ignore or skip**.  The difference between **ignore** and **skip** is **ignore** will continue and make the backup anyway *(without pending filesystem changes)*, whereas **skip** will not.

## Examples:
```
$ terminus sites ba
```
This is an alias for the `terminus sites backup-all` command and will backup all elements in all environments of all available sites simultaneously
```
$ terminus sites ba --env=dev
```
Backup all elements in the dev environment only of all available sites
```
$ terminus sites ba --element=code
```
Backup the code only for all environments of all available sites
```
$ terminus sites ba --env=dev --element=code --changes=ignore
```
Backup the code only of the dev environment only for all available sites and perform the backup without committing pending filesystem changes

## Example crontab entry:
```
# Backup all Pantheon sites daily at 3 AM.
0 3 * * * $HOME/.composer/vendor/bin/terminus sites backup-all 2>&1 >> /var/log/pantheon-backup.log
```
