<?php

class VersioncontrolGitRepository extends VersioncontrolRepository {

  /**
   * The branch name of the default (HEAD) branch or empty if this information
   * is not available.
   */
  public $default_branch = 'master';

  protected function backendDelete($options) {
    db_delete('versioncontrol_git_repositories')
      ->condition('repo_id', $this->repo_id)
      ->execute();
  }

  protected function backendUpdate($options) {
    db_update('versioncontrol_git_repositories')
      ->condition('repo_id', $this->repo_id)
      ->fields(array('default_branch' => $this->default_branch))
      ->execute();
  }

  protected function backendInsert($options) {
    db_insert('versioncontrol_git_repositories')
      ->fields(array(
        'repo_id' => $this->repo_id,
        'default_branch' => $this->default_branch,
      ))
      ->execute();
  }

  /**
   * Get the default (HEAD) branch of the repository.
   *
   * @return
   *   The name of the default branch or empty if there is none.
   */
  public function getDefaultBranch() {
    return $this->default_branch;
  }

  /**
   * Changes the default branch. Note that this is an async operation.
   *
   * @param $branch_name
   *   The name of the branch that should be checked out by default, when the
   *   repository is closed.
   *
   * @throws Exception
   *   If the branch doesn't exist.
   */
  public function setDefaultBranch($branch_name) {
    // Ensure the branch exists.
    if (!$this->loadBranches(NULL, array('name' => $branch_name))) {
      throw new Exception(t('The branch %branch_name doesn\'t exist.', array('%branch_name' => $branch_name)));
    }

    // The job to be queued.
    $job = array(
      'operation' => array(
        'setDefaultBranch' => array($branch_name),
        'save' => array(),
      ),
      'repository' => $this,
    );

    // Queue the write operation on the database an the repository.
    drupal_queue_include();
    $queue = DrupalQueue::get('versioncontrol_repomgr');
    if (!$queue->createItem($job)) {
      watchdog('versioncontrol_git', t('Failed to enqueue a default branch change for the Git repository at %root.', array('%root' => $this->root)));
      throw new Exception(t('An error occured while attempting to enqueue switching the default branch.'), 'error');
    }
  }

  /**
   * State flag indicating whether or not the GIT_DIR variable has been pushed
   * into the environment.
   *
   * Used to prevent multiple unnecessary calls to putenv(). Will be obsoleted
   * by the introduction of a cligit library.
   *
   * @var bool
   */
  public $envSet = FALSE;

  /**
   * Ensure environment variables are set for interaction with the repository on
   * disk.
   *
   * Hopefully temporary, until we can get a proper cligit library written.
   */
  public function setEnv() {
    if (!$this->envSet) {
      $root = escapeshellcmd($this->root);
      putenv("GIT_DIR=$root");
      $this->envSet = TRUE;
    }
  }

  public function purgeData($bypass = TRUE) {
    if (empty($bypass)) {
      foreach ($this->loadBranches() as $branch) {
        $branch->delete();
      }
      foreach ($this->loadTags() as $tag) {
        $tag->delete();
      }
      foreach ($this->loadCommits() as $commit) {
        $commit->delete();
      }
    }
    else {
      $label_ids = db_select('versioncontrol_labels', 'vl')
        ->fields('vl', array('label_id'))
        ->condition('vl.repo_id', $this->repo_id)
        ->execute()->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($label_ids)) {
        db_delete('versioncontrol_operation_labels')
          ->condition('label_id', $label_ids)
          ->execute();
      }

      $op_ids = db_select('versioncontrol_operations', 'vco')
        ->fields('vco', array('vc_op_id'))
        ->condition('vco.repo_id', $this->repo_id)
        ->execute()->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($op_ids)) {
        db_delete('versioncontrol_git_operations')
          ->condition('vc_op_id', $op_ids)
          ->execute();
      }

      $ir_ids = db_select('versioncontrol_item_revisions', 'vir')
        ->fields('vir', array('item_revision_id'))
        ->condition('vir.repo_id', $this->repo_id)
        ->execute()->fetchAll(PDO::FETCH_COLUMN);

      if (!empty($ir_ids)) {
        db_delete('versioncontrol_git_item_revisions')
          ->condition('item_revision_id', $ir_ids)
          ->execute();
      }

      db_delete('versioncontrol_operations')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      db_delete('versioncontrol_labels')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      db_delete('versioncontrol_item_revisions')
        ->condition('repo_id', $this->repo_id)
        ->execute();

      module_invoke_all('versioncontrol_repository_bypass_purge', $this);
    }
  }

  public function fetchLogs() {
    // Set a hefty timeout, in case it ends up being a long fetch
    if (!ini_get('safe_mode')) {
      set_time_limit(3600);
    }
    require_once drupal_get_path('module', 'versioncontrol_git') .'/versioncontrol_git.log.inc';
    return _versioncontrol_git_log_update_repository($this);
  }

  /**
   * Invoke git to fetch a list of local branches in the repository, including
   * the SHA1 of the current branch tip and the branch name.
   */
  public function fetchBranches() {
    $branches = array();

    $data = array(
      'repo_id' => $this->repo_id,
      'label_id' => NULL,
    );
    $logs = $this->exec('show-ref --heads');
    while (($branchdata = next($logs)) !== FALSE) {
      list($data['tip'], $data['name']) = explode(' ', trim($branchdata));
      $data['name'] = substr($data['name'], 11);
      $branches[$data['name']] = new VersioncontrolBranch($this->backend);
      $branches[$data['name']]->build($data);
    }

    return $branches;
  }

  /**
   * Invoke git to fetch a list of local tags in the repository, including
   * the SHA1 of the commit to which the tag is attached.
   */
  public function fetchTags() {
    $tags = array();
    $data = array(
      'repo_id' => $this->repo_id,
      'label_id' => NULL,
    );

    $logs = $this->exec('show-ref --tags');
    while (($tagdata = next($logs)) !== FALSE) {
      list($data['tip'], $data['name']) = explode(' ', trim($tagdata));
      $data['name'] = substr($data['name'], 10);
      $tags[$data['name']] = new VersioncontrolTag();
      $tags[$data['name']]->build($data);
    }

    return $tags;
  }

  public function fetchCommits($branch_name = NULL) {
    $logs = $this->exec('rev-list --reverse ' . (empty($branch_name) ? '--all' : $branch_name));
    $commits = array();
    while (($line = next($logs)) !== FALSE) {
      $commits[] = trim($line);
    }
    return $commits;
  }

  /**
   * Execute a Git command using the root context and the command to be
   * executed.
   *
   * @param string $command
   *   Command to execute.
   * @return mixed
   *  Logged output from the command; an array of either strings or file
   *  pointers.
   */
  public function exec($command) {
    if (!$this->envSet) {
      $this->setEnv();
    }
    $logs = array();
    $git_bin = _versioncontrol_git_get_binary_path();
    exec(escapeshellcmd("$git_bin $command"), $logs);
    array_unshift($logs, '');
    reset($logs); // Reset the array pointer, so that we can use next().
    return $logs;
  }

  /**
   * Verify if the repository root points to a valid Git repository.
   *
   * @return boolean
   *   TRUE if the repository path is a bare Git Repository.
   */
  public function isValidGitRepo() {
    // do not use exec() method to get the shell return code
    if (!$this->envSet) {
      $this->setEnv();
    }
    $logs = array();
    $git_bin = _versioncontrol_git_get_binary_path();
    exec(escapeshellcmd("$git_bin ls-files"), $logs, $shell_return);
    if ($shell_return != 0) {
      return FALSE;
    }
    return TRUE;
  }

}
