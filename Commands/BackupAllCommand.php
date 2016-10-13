<?php

namespace Terminus\Commands;

use Terminus\Collections\Sites;
use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
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
   * Backup all your available Pantheon sites simultaneously
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
   * Backup all your available Pantheon sites simultaneously
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
   * : How to handle pending filesystem changes in sftp connection mode (commit, ignore or skip).
   *   Default is commit.
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
   *
   * @param array $args       Array of plugin names
   * @param array $assoc_args Array of backup options
   *
   * @return null
   */
  public function index($args, $assoc_args) {
    // Validate the --element argument value.
    $valid_elements = array('all', 'code', 'database', 'files');
    $element = 'all';
    if (isset($assoc_args['element'])) {
      $element = $assoc_args['element'];
    }
    if (!in_array($element, $valid_elements)) {
      $message = 'Invalid --element argument value. Valid values are all, code, database or files.';
      $this->failure($message);
    }

    // Validate the --changes argument value.
    $valid_changes = array('commit', 'ignore', 'skip');
    $changes = 'commit';
    if (isset($assoc_args['changes'])) {
      $changes = $assoc_args['changes'];
    }
    if (!in_array($changes, $valid_changes)) {
      $message = 'Invalid --changes argument value.  Allowed values are commit, ignore or skip.';
      $this->failure($message);
    }

    $options = [
      'org_id'    => $this->input()->optional(
        [
          'choices' => $assoc_args,
          'default' => null,
          'key'     => 'org',
        ]
      ),
      'team_only' => isset($assoc_args['team']),
    ];
    $this->sites->fetch($options);

    if (isset($assoc_args['name'])) {
      $this->sites->filterByName($assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = $this->user->id;
      }
      $this->sites->filterByOwner($owner_uuid);
    }

    $sites = $this->sites->all();

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = 'all';
    if (isset($assoc_args['env'])) {
      $env = $assoc_args['env'];
    }
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
      $message = 'Invalid --env argument value. Allowed values are dev, test, live';
      $message .= ' or a valid multi-site environment.';
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
      } else {
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
   * Perform the backup of a specific site and environment.
   *
   * @param array $args The site environment arguments.
   *
   * @return null
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
    $mode = $env->get('connection_mode');
    if ($mode == 'sftp') {
      $valid_elements = array('all', 'code');
      if (in_array($element, $valid_elements)) {
        $diff = (array)$env->diffstat();
        if (!empty($diff)) {
          switch ($changes) {
            case 'commit':
              $message = 'Start automatic backup commit for {environ} environment of {name} site.';
              $this->log()->notice(
                $message,
                ['environ' => $environ, 'name' => $name,]
              );
              $message = 'Automatic backup commit of pending filesystem changes';
              $workflow = $env->commitChanges($message);
              $message = 'End automatic backup commit for {environ} environment of {name} site.';
              $this->log()->notice(
                $message,
                ['environ' => $environ, 'name' => $name,]
              );
                break;
            case 'ignore':
              $message = "Automatic backup commit ignored for {element} in {environ} environment";
              $message .= " of {name} site.  Note there are still pending filesystem changes that";
              $message .= " will not be included in the backup.";
              $this->log()->notice(
                $message,
                ['element' => $element, 'environ' => $environ, 'name' => $name,]
              );
                break;
            case 'skip':
              $message = 'Automatic backup commit skipped for {element} in {environ} environment';
              $message .= ' of {name} site. Note there are still pending filesystem changes and';
              $message .= ' the backup has been aborted.';
              $this->log()->notice(
                $message,
                ['element' => $element, 'environ' => $environ, 'name' => $name,]
              );
              $backup = false;
                break;
          }
        }
      }
    }
    if ($backup) {
      $message = 'Start backup for {element} in {environ} environment of {name} site.';
      $this->log()->notice(
        $message,
        ['element' => $element, 'environ' => $environ, 'name' => $name,]
      );
      $args = array(
        'element' => $element,
      );
      $workflow = $env->backups->create($args);
      $message = 'End backup for {element} in {environ} environment of {name} site.';
      $this->log()->notice(
        $message,
        ['element' => $element, 'environ' => $environ, 'name' => $name,]
      );
    }
  }

}
