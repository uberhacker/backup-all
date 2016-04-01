<?php

namespace Terminus\Commands;

use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Collections\Sites;
use Terminus\Models\Organization;
use Terminus\Models\Site;
use Terminus\Models\Upstreams;
use Terminus\Models\User;
use Terminus\Models\Workflow;
use Terminus\Session;
use Terminus\Utils;

/**
 * Actions on multiple sites
 *
 * @command sites
 */
class BackupAllCommand extends TerminusCommand {
  public $sites;

  /**
   * Backup all your available Pantheon sites simultaneously.
   *
   * @param array $options Options to construct the command object
   * @return BackupAllCommand
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
  }

  /**
   * Backup all sites user has access to
   * Note: because of the size of this call, it is cached
   *   and also is the basis for loading individual sites by name
   *
   * [--env=<env>]
   * : Filter sites by environment.  Use 'all' or exclude to get all.
   *
   * [--element=<element>]
   * : Filter sites by element (code, database or files).  Use 'all' or exclude to get all.
   *
   * [--changes=<change>]
   * : How to handle pending filesystem changes in sftp connection mode (commit, ignore or skip).  Default is commit.
   *
   * [--team]
   * : Filter for sites you are a team member of
   *
   * [--owner]
   * : Filter for sites a specific user owns. Use "me" for your own user.
   *
   * [--org=<id>]
   * : Filter sites you can access via the organization. Use 'all' to get all.
   *
   * [--name=<regex>]
   * : Filter sites you can access via name
   *
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew
   *
   * @subcommand backup-all
   * @alias ba
   */
  public function index($args, $assoc_args) {
    // Always fetch a fresh list of sites.
    if (!isset($assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }
    $sites = $this->sites->all();

    // Validate the --element argument value.
    $valid_elements = array('all', 'code', 'database', 'files');
    $element = isset($assoc_args['element']) ? $assoc_args['element'] : 'all';
    if (!in_array($element, $valid_elements)) {
      $message = 'Invalid --element argument value. Allowed values are all, code, database or files.';
      $this->failure($message);
    }

    // Validate the --changes argument value.
    $valid_changes = array('commit', 'ignore', 'skip');
    $changes = isset($assoc_args['changes']) ? $assoc_args['changes'] : 'commit';
    if (!in_array($changes, $valid_changes)) {
      $message = 'Invalid --changes argument value.  Allowed values are commit, ignore or skip.';
      $this->failure($message);
    }

    if (isset($assoc_args['team'])) {
      $sites = $this->filterByTeamMembership($sites);
    }
    if (isset($assoc_args['org'])) {
      $org_id = $this->input()->orgId(
        [
          'allow_none' => true,
          'args'       => $assoc_args,
          'default'    => 'all',
        ]
      );
      $sites = $this->filterByOrganizationalMembership($sites, $org_id);
    }

    if (isset($assoc_args['name'])) {
      $sites = $this->filterByName($sites, $assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = Session::getData()->user_uuid;
      }
      $sites = $this->filterByOwner($sites, $owner_uuid);
    }

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = isset($assoc_args['env']) ? $assoc_args['env'] : 'all';
    $valid_env = ($env == 'all');
    if (!$valid_env) {
      foreach ($sites as $site) {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $e = $environment->get('id');
          if ($e == $env) {
            $valid_env = true;
            break;
          }
        }
        if ($valid_env) {
          break;
        }
      }
    }
    if (!$valid_env) {
      $message = 'Invalid --env argument value. Allowed values are dev, test, live or a valid multi-site environment.';
      $this->failure($message);
    }

    // Loop through each site and backup.
    foreach ($sites as $site) {
      $name = $site->get('name');
      // Loop through each environment and backup, if necessary.
      if ($env == 'all') {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $args = array(
            'name'    => $name,
            'env'     => $environment->get('id'),
            'element' => $element,
            'changes' => $changes,
          );
          $this->backup($args);
        }
      }
      else {
        $args = array(
          'name'    => $name,
          'env'     => $env,
          'element' => $element,
          'changes' => $changes,
        );
        $this->backup($args);
      }
    }
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites An array of sites to filter by
   * @param string $regex Non-delimited PHP regex to filter site names by
   * @return Site[]
   */
  private function filterByName($sites, $regex = '(.*)') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($regex) {
        preg_match("~$regex~", $site->get('name'), $matches);
        $is_match = !empty($matches);
        return $is_match;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites      An array of sites to filter by
   * @param string $owner_uuid UUID of the owning user to filter by
   * @return Site[]
   */
  private function filterByOwner($sites, $owner_uuid) {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($owner_uuid) {
        $is_owner = ($site->get('owner') == $owner_uuid);
        return $is_owner;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites  An array of sites to filter by
   * @param string $org_id ID of the organization to filter for
   * @return Site[]
   */
  private function filterByOrganizationalMembership($sites, $org_id = 'all') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($org_id) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ((($org_id == 'all') && ($membership['type'] == 'organization'))
            || ($membership['id'] === $org_id)
          ) {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is a team member
   *
   * @param Site[] $sites An array of sites to filter by
   * @return Site[]
   */
  private function filterByTeamMembership($sites) {
    $filtered_sites = array_filter(
      $sites,
      function($site) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ($membership['name'] == 'Team') {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Perform the backup of a specific site and environment.
   *
   * @param array $args
   *   The site environment arguments.
   */
  private function backup($args) {
    $name = $args['name'];
    $environ = $args['env'];
    $element = $args['element'];
    $changes = $args['changes'];
    $assoc_args = array(
      'site' => $name,
      'env'  => $environ,
    );
    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args])
    );
    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );
    $backup = true;
    $mode = $env->info('connection_mode');
    if ($mode == 'sftp') {
      $valid_elements = array('all', 'code');
      if (in_array($element, $valid_elements)) {
        $diff = (array)$env->diffstat();
        if (!empty($diff)) {
          switch ($changes) {
            case 'commit':
              $this->log()->notice("Start automatic backup commit for $environ environment of $name site.");
              $env->commit();
              $this->log()->notice("End automatic backup commit for $environ environment of $name site.");
              break;
            case 'ignore':
              $this->log()->notice("Automatic backup commit ignored for $element in $environ environment of $name site. Note there are still pending filesystem changes that will not be included in the backup.");
              break;
            case 'skip':
              $this->log()->notice("Automatic backup commit skipped for $element in $environ environment of $name site. Note there are still pending filesystem changes and the backup has been aborted.");
              $backup = false;
              break;
          }
        }
      }
    }
    if ($backup) {
      $this->log()->notice("Start backup for $element in $environ environment of $name site.");
      $args = array(
        'element' => $element,
      );
      $workflow = $env->backups->create($args);
      $this->log()->notice("End backup for $element in $environ environment of $name site.");
    }
  }
}
