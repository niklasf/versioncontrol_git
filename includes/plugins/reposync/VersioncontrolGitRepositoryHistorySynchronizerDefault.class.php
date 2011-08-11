<?php

class VersioncontrolGitRepositoryHistorySynchronizerDefault implements VersioncontrolRepositoryHistorySynchronizerInterface {

  protected $repository;

  protected $templateDir;

  protected $built;

  public function setRepository(VersioncontrolRepository $repository) {
    // Additional parameter check to the appropriate Git subclass of that
    // required by the interface itself.
    if (!$repository instanceof VersioncontrolGitRepository) {
      $msg = 'The repository "@name" with repo_id "@repo_id" passed to ' . __METHOD__ . ' was not a VersioncontrolGitRepository instance.' ;
      $vars = array(
        '@name' => $repository->name,
        '@repo_id' => empty($repository->repo_id) ? '[NEW]' : $repository->repo_id,
      );
      watchdog($msg, $vars, WATCHDOG_ERROR);
      throw new Exception(strtr($msg, $vars), E_ERROR);
    }
    $this->repository = $repository;
  }

  protected function fetchBranchesInDatabase() {
    $branches = array();
    $branches_db = $this->repository->loadBranches(array(), array(), array('may cache' => FALSE));

    foreach ($branches_db as $key => $branch) {
      $branches[$branch->name] = $branch;
    }

    return $branches;
  }

  protected function fetchCommitsInDatabase($label_id = 0) {
    $conditions = array();
    
    if (!empty($branch_name)) {
      $conditions['branches'] = $label_id;
    }
    
    $commits = $this->repository->loadCommits(array(), $conditions, array('may cache' => FALSE));

    foreach ($commits as &$commit) {
      $commit = $commit->revision;
    }

    return $commits;
  }

  /**
   * Actually update the repository by fetching commits and other stuff
   * directly from the repository, invoking the git executable.
   * @param VersioncontrolGitRepository $repository
   * @return
   *   TRUE if the logs were updated, or FALSE if fetching and updating the logs
   *   failed for whatever reason.
   *
   * FIXME while largely ported to the new entities system, this is still not 100%
   * done
   */
  public function syncFull() {
    $this->verify();
    $this->prepare();

    $this->repository->updateLock();
    $this->repository->update();

    /**
     * Branches
     */
    // Fetch branches from the repo and load them from the db.
    $branches_repo = $this->repository->fetchBranches();
    $branches_db = $this->fetchBranchesInDatabase();

    // Determine whether we've got branch changes to make.

    // Insert new branches in the repository. Later all commits in these new
    // branches will be updated.
    // Unfortunately we can't say anything about the branch author at this time.
    // The post-update hook could do this, though.
    // We also can't insert a VCOperation which adds the branch, because
    // we don't know anything about the branch. This is all stuff a hook could
    // figure out.
    foreach(array_diff_key($branches_repo, $branches_db) as $branch) {
      $branch->insert();
    }

    // reload branches, after they was inserted
    $branches = $this->fetchBranchesInDatabase();

    // Deleted branches are removed, commits in them are not!
    foreach(array_diff_key($branches_db, $branches_repo) as $branch) {
      $branch->delete();
    }

    unset($branches_repo);
    unset($branches_db);

    /**
     * Commits
     */
    // Fetch commits from the repo and load them from the db.

    // Insert new commits in the database.
    foreach (array_diff($this->repository->fetchCommits(), $this->fetchCommitsInDatabase()) as $hash) {
      $this->parseCommit($hash, $branches);
    }

    /**
     * Tags
     */
    // Insert new tags in the database.
    $tags_in_repo = $this->repository->fetchTags();
    $tags_in_db = $this->repository->loadTags();
    $tags_in_db_by_name = array();
    foreach ($tags_in_db as $tag) {
      $tags_in_db_by_name[$tag->name] = $tag;
    }
    unset($tags_in_db);

    // Check for new tags.
    $tags_new = array_diff_key($tags_in_repo, $tags_in_db_by_name);
    $tags_deleted = array_diff_key($tags_in_db_by_name, $tags_in_repo);
    if (!empty($tags_new)) {
      $this->processTags($tags_new);
    }
    // Delete removed tags
    foreach($tags_deleted as $tag) {
      $tag->delete();
    }

    $this->finalize();

    return TRUE;
  }

  /**
   * Parse the output of 'git log' and insert a commit based on its data.
   *
   * @param VersioncontrolRepository $repository
   * @param array $logs The output of 'git log' to parse
   * @param array $branch_label_list An associative list of branchname => VersioncontrolBranch
   */
  protected function parseCommit($hash, $branches) {
    $command = "show --numstat --summary --pretty=format:\"%H%n%P%n%an%n%ae%n%cn%n%ce%n%ct%n%B%nENDOFOUTPUTGITMESSAGEHERE\" " . escapeshellarg($hash);
    $logs = $this->execute($command);
      
    // Get commit hash (vcapi's "revision")
    $revision = trim(next($logs));

    // Get parent commit hash(es)
    $parents = explode(' ', trim(next($logs)));
    if (empty($parents[0])) {
      $parents = array();
    }
    $merge = !empty($parents[1]); // Multiple parents indicates a merge

    // Get author data
    $author_name = trim(next($logs));
    $author_email = trim(next($logs));
    // Get committer data
    $committer_name = trim(next($logs));
    $committer_email = trim(next($logs));

    // Get date as timestamp
    $date = trim(next($logs));

    // Get revision message.
    // TODO: revisit!
    $message = '';
    while (($line = trim(next($logs))) !== FALSE) {
      if ($line == 'ENDOFOUTPUTGITMESSAGEHERE') {
        if (substr($message, -2) === "\n\n") {
          $message = substr($message, 0, strlen($message) - 1);
        }
        break;
      }
      $message .= $line ."\n";
    }

    // This is either a (kind of) diffstat for each modified file or a list of
    // file actions like moved, created, deleted, mode changed.
    $line = next($logs);

    // build the data array to be used for the commit op
    $op_data = array(
      'type' => VERSIONCONTROL_OPERATION_COMMIT,
      'revision' => $revision,
      'author' => $author_email,
      'author_name' => $author_name,
      'committer' => $committer_email,
      'committer_name' => $committer_name,
      'parent_commit' => reset($parents),
      'merge' => $merge,
      'date' => $date,
      'message' => $message,
      'repository' => $this->repository,
    );

    $op = new VersioncontrolGitOperation($this->repository->getBackend());
    $op->build($op_data);
    $op->labels = $this->getBranchesOfCommit($revision, $branches);
    $op->insert(array('map users' => TRUE));

    $item_action = $merge ? VERSIONCONTROL_ACTION_MERGED : VERSIONCONTROL_ACTION_MODIFIED;
    // build the data array to be used as default values for the item revision
    $default_item_data = array(
      // pass backend in data array to avoid just another param to parse
      // item function
      'backend' => $this->repository->getBackend(),
      'repository' => $this->repository,
      'vc_op_id' => $op->vc_op_id,
      'type' => VERSIONCONTROL_ITEM_FILE,
      'revision' => $revision,
      'action' => $item_action,
    );

    // Parse in the raw data and create VersioncontrolGitItem objects.
    $op_items = $this->parseItems($logs, $line, $default_item_data, $parents);
    $op->itemRevisions = $op_items;
    $op->save(array('nested' => TRUE));
  }

  /**
   * Returns an array of all branches a given commit is in.
   * @param string $revision
   * @param array $branch_label_list
   * @return VersioncontrolBranch
   */
  protected function getBranchesOfCommit($revision, $branch_label_list) {
    $exec = 'branch --no-color --contains ' . escapeshellarg($revision);
    $logs = $this->execute($exec);
    $branches = array();

    while (($line = next($logs)) !== FALSE) {
      $line = trim($line);
      if($line[0] == '*') {
        $line = substr($line, 2);
      }
      if (!empty($branch_label_list[$line])) {
        $branches[] = $branch_label_list[$line];
      }
    }

    return $branches;
  }

  /**
   * Takes parts of the output of git log and returns all affected OperationItems for a commit.
   * @param array $logs
   * @param string $line
   * @param string $revision
   * @param array $parents The parent commit(s)
   * @param bool $merge
   * @return array All items affected by a commit.
   */
  protected function parseItems(&$logs, &$line, $data, $parents) {
    $op_items = array();

    // Parse the diffstat for the changed files.
    do {
      if (!preg_match('/^(\S+)' . "\t" . '(\S+)' . "\t" . '(.+)$/', $line, $matches)) {
        break;
      }
      $path = '/'. $matches[3];
      $op_items[$path] = new VersioncontrolGitItem($data['backend']);
      $data['path'] = $path;
      $op_items[$path]->build($data);
      unset($data['path']);

      if (is_numeric($matches[1]) && is_numeric($matches[2])) {
        $op_items[$path]->line_changes_added = $matches[1];
        $op_items[$path]->line_changes_removed = $matches[2];
      }

      // extract blob
      $command = 'ls-tree -r ' . escapeshellarg($data['revision']) . ' ' . escapeshellarg($matches[3]);
      $lstree_lines = $this->execute($command);
      $blob_hash = $this->parseItemBlob($lstree_lines);
      $op_items[$path]->blob_hash = $blob_hash;
    } while (($line = next($logs)) !== FALSE);

    // Parse file actions.
    do {
      if (!preg_match('/^ (\S+) (\S+) (\S+) (.+)$/', $line, $matches)) {
        break;
      }
      // We also can get 'mode' here if someone changes the file permissions.
      if ($matches[1] == 'create') {
        $op_items['/'. $matches[4]]->action = VERSIONCONTROL_ACTION_ADDED;
        // extract blob
        $command = 'ls-tree -r ' . escapeshellarg($data['revision']) . ' ' . escapeshellarg($matches[4]);
        $lstree_lines = $this->execute($command);
        $blob_hash = $this->parseItemBlob($lstree_lines);
        $op_items['/'. $matches[4]]->blob_hash = $blob_hash;
      }
      else if ($matches[1] == 'delete') {
        $op_items['/'. $matches[4]]->action = VERSIONCONTROL_ACTION_DELETED;
        $op_items['/'. $matches[4]]->type = VERSIONCONTROL_ITEM_FILE_DELETED;
      }
    } while (($line = next($logs)) !== FALSE);

    // Fill in the source_items for non-added items
    foreach ($op_items as $path => &$item) {
      if ($item->action != VERSIONCONTROL_ACTION_ADDED) {
        $this->fillSourceItem($item, $parents, $data);
      }
    }
    return $op_items;
  }

 /**
   * Parse ls-tree with one commit hash and one item.
   */
  protected function parseItemBlob($lines) {
    $line = next($lines);
    // output: <mode> SP <type> SP <object> TAB <file>
    $info = explode("\t", $line);
    $info = array_shift($info);
    $info = explode(' ', $info);
    $blob_hash = array_pop($info);
    return $blob_hash;
  }

  /**
   * A function to fill in the source_item for a specific VersioncontrolItem.
   *
   * Now VCS API assumes there is only one source item, so merges can not be
   * tracked propertly there, and we are neither tracking on git backend for
   * now.
   * For merges we are choosing the first parent git-log  show.
   *
   * @param VersioncontrolItem &$item
   * @param array $parents The parent commit(s)
   * @return none
   */
  protected function fillSourceItem(&$item, $parents, $inc_data) {
    $data = array(
      'type' => VERSIONCONTROL_ITEM_FILE,
      'repository' => $inc_data['repository'],
      'path' => $item->path,
    );

    $path_stripped = substr($item->path, 1);
    // using -5 to let detect merges until 4 parents, merging more than 4 parents in one operation is insane!
    // use also --first-parent to retrieve only one parent for the current support of VCS API
    $cmd = 'log --first-parent --follow --pretty=format:"%H" -5 ' . escapeshellarg($item->revision) . ' -- ' . escapeshellarg($path_stripped);
    $prev_revisions = $this->execute($cmd);

    next($prev_revisions); // grab our hash out
    if (($parent_hash = next($prev_revisions)) !== FALSE) { // get the first parent hash
      $data['revision'] = trim($parent_hash);
      // just fill an object from scratch
      $source_item = new VersioncontrolGitItem($item->getBackend());
      $source_item->build($data);
      $item->setSourceItem($source_item);
    }
    //TODO unify the way to fail
  }

  /**
   * Does all processing to insert the tags in $tags_new in the database.
   * @param VersioncontrolGitRepository $repository
   * @param array $tags_new All new tags.
   * @return none
   */
  function processTags($tags_new) {
    if (empty($tags_new)) {
      return;
    }

    // get a list of all tag names with the corresponding commit.
    $tag_commit_list = $this->getTagCommitList($tags_new);
    $format = '%(objecttype)%0a%(objectname)%0a%(refname)%0a%(taggername) %(taggeremail)%0a%(taggerdate)%0a%(contents)ENDOFGITTAGOUTPUTMESAGEHERE';
    foreach($tag_commit_list as $tag_name => $tag_commit) {
      $exec = "for-each-ref --format=\"$format\" refs/tags/" . escapeshellarg($tag_name);
      $logs_tag_msg = $this->execute($exec);

      $tag_ops = $this->repository->loadCommits(array(), array('revision' => $tag_commit));
      $tagged_commit_op = reset($tag_ops);
      // Get the specific tag data for annotated vs not annotated tags.
      if ($logs_tag_msg[1] == 'commit') {
        // simple tag
        // [2] is tagged commit [3] tagname [4] and [5] empty [6] commit log message
        // We get the tagger, the tag_date and the tag_message from the tagged commit.
        $tagger = $tagged_commit_op->author;
        $tag_date = $tagged_commit_op->date + 1;
        $message = $tagged_commit_op->message;
      }
      else if($logs_tag_msg[1] == 'tag') {
        // annotated tag
        // [2] is the tagged commit [3] tag name
        $tagger = $logs_tag_msg[4];
        $tag_date = strtotime($logs_tag_msg[5]);
        // Get the tag message
        $message = '';
        $i = 0;
        while (true) {
          $line = $logs_tag_msg[$i + 6];
          if($logs_tag_msg[$i + 7] == 'ENDOFGITTAGOUTPUTMESAGEHERE') {
            $message .= $line;
            break;
          }
          $message .= $line ."\n";
          $i++;
        }
      }
      else {
        watchdog('versioncontrol_git', 'Serious problem in tag parsing, please check that you\'re using a supported version of git!');
      }

      $tag_data = array(
        'name' => $tag_name,
        'repository' => $this->repository,
        'repo_id' => $this->repository->repo_id,
      );

      $tag = $this->repository->getBackend()->buildEntity('tag', $tag_data + array('action' => VERSIONCONTROL_ACTION_ADDED));
      $tag->insert();
    }
  }

  /**
   * Returns a list of tag names with the tagged commits.
   * Handles annotated tags.
   * @param array $tags An array of tag names
   * @return array A list of all tags with the respective tagged commit.
   */
  function getTagCommitList($tags) {
    if(empty($tags)) {
      return array();
    }
    $tag_string = $this->getTagString($tags);
    $exec = "show-ref -d $tag_string";
    $tag_commit_list_raw = $this->execute($exec);
    $tag_commit_list = array();
    $tags_annotated = array();
    foreach($tag_commit_list_raw as $tag_commit_line) {
      if($tag_commit_line == '') {
        continue;
      }
      $tag_commit = substr($tag_commit_line, 0, 40);
      // annotated tag mark
      // 9c70f55549d3f4e70aaaf30c0697f704d02e9249 refs/tags/tag^{}
      if (substr($tag_commit_line, -3, 3) == '^{}') {
        $tag_name = substr($tag_commit_line, 51, -3);
        $tags_annotated[$tag_name] = $tag_commit;
      }
      // Simple tags
      // 9c70f55549d3f4e70aaaf30c0697f704d02e9249 refs/tags/tag
      else {
        $tag_name = substr($tag_commit_line, 51);
      }
      $tag_commit_list[$tag_name] = $tag_commit;
    }
    // Because annotated tags show up twice in the output of git show-ref, once
    // with a 'tag' object and once with a commit-id we will go through them and
    // adjust the array so we just keep the commits.
    foreach($tags_annotated as $tag_name => $tag_commit) {
      $tag_commit_list[$tag_name] = $tag_commit;
    }
    return $tag_commit_list;
  }

  /**
   * Returns a string with fully qualified tag names from an array of tag names.
   * @param array $tags
   * @return string
   */
  function getTagString($tags) {
    $tag_string = '';
    // $tag_string is a list of fully qualified tag names
    foreach ($tags as $tag) {
      $tag_string .= escapeshellarg("refs/tags/{$tag->name}") . ' ';
    }
    return $tag_string;
  }

  protected function execute($command) {
    $output = array();
    $git = _versioncontrol_git_get_binary_path();

    if ($git) {
      exec(escapeshellcmd("$git $command"), $output);

      array_unshift($output, ''); // FIXME doing it this way is just wrong.
      reset($output); // Reset the array pointer, so that we can use next().
    }

    return $output;
  }

  public function syncInitial() {
    // TODO halstead's optimized fast-export-based parser goes here. But for now, just use the same old crap

    return $this->syncFull();
  }
  
  protected function getCommitInterval($start, $end) {
    if ($start == GIT_NULL_REV) {
      $range = $end;
    }
    elseif ($end == GIT_NULL_REV) {
      $range = "$start..";
    }
    else {
      $range = "$start..$end";
    }
    
    $command = "log --format=format:%H $range";
    $logs = $this->execute($command);
    
    $commits = array();
    while (($line = next($logs)) !== FALSE) {
      $commits[] = trim($line);
    }
    return $commits;
  }

  public function syncEvent(VersioncontrolEvent $event) {
    // Additional parameter check to the appropriate Git subclass of that
    // required by the interface itself.
    if (!$event instanceof VersioncontrolGitEvent) {
      $msg = 'An incompatible VersioncontrolEvent object (of type @class) was provided to a Git repo synchronizer for event-driven repository sync.';
      $vars = array(
        '@class' => get_class($event),
      );
      watchdog($msg, $vars, WATCHDOG_ERROR);
      throw new Exception(strtr($msg, $vars), E_ERROR);
    }
    
    $branches = $this->repository->fetchBranches();
    $tags = $this->repository->fetchTags();

    foreach ($event as $ref) {
      // 1. Process labels

      $ref->syncLabel();
      $label_db = $ref->getLabel();
      
      switch ($ref->reftype) {
        case VERSIONCONTROL_OPERATION_BRANCH:
          $label_repo = $branches[$ref->refname];
          
          break;
        case VERSIONCONTROL_OPERATION_TAG:
          $label_repo = $tags[$ref->refname];
          
          break;
      }
      
      if ($ref->eventCreatedMe() && ($label = $label_repo)) {
        $label->insert();
      }
      elseif ($ref->eventDeletedMe() && ($label = $label_db)) {
        $label->delete();
      }
      elseif ($label = $label_db) {
        $label->update();
      }
      else {
        // This shouldn't happen, but it never hurts to double-check.
        continue;
      }
      
      unset($label_db);
      unset($label_repo);
      
      // 2. Process commits for branches
      
      if ($label->type == VERSIONCONTROL_OPERATION_BRANCH) {
      
        // 2.1. Add all commits that aren't currently in the branch
        $commits = $this->getCommitInterval($ref->old_sha1, $ref->new_sha1);
        $commits_db = $this->fetchCommitsInDatabase($label->label_id);
        
        // Get a list of all branches.
        $branches_db = $this->fetchBranchesInDatabase();
        
        foreach(array_diff($commits, $commits_db) as $revision) {
          $commit = $this->repository->loadCommits(array(), array('revision' => $revision));
          
          if (empty($commit)) {
            // Insert completly new commit object into database. 
            $this->parseCommit($revision, $branches_db);
          }
          else {
            // Link existing commit object to branch.
            $commit->labels[] = $label;
            $commit->update();
          }
        }
        
        // 2.2. Remove all connections with commits that aren't in the branch
        $commits_branch = $this->repository->fetchCommits($label->name);
        
        foreach(array_diff($commits_db, $commits_branch) as $revision) {
          $commit = reset($this->repository->loadCommits(array(), array('revision' => $revision)));
          
          if (count($commit->labels) > 1) {
            // There are other labels that contain this commit, just only remove the connection to the current label.
            foreach ($commit->labels as $key => $commit_label) {
              if ($commit_label->label_id == $label->label_id) {
                unset($commit->labels[$key]);
              }
            }
            
            $commit->update();
          } 
          else {
            // It's save to completly delete the commit from the database.
            $commit->delete();
          }
        }
        
        $ref->commits = $commits;
      }
    }
    
    $event->update();
  }

  public function verifyData() {
    return TRUE;
  }

  protected function prepare() {
    putenv("GIT_DIR=". escapeshellcmd($this->repository->root));
    if (!empty($this->repository->locked)) {
      drupal_set_message(t('This repository is locked, there is already a fetch in progress. If this is not the case, press the clear lock button.'), 'error');

      return FALSE;
    }
  }

  protected function finalize() {
    // Update repository updated field. Displayed on administration interface for documentation purposes.
    $this->repository->updated = time();
    $this->repository->updateLock(0);
    $this->repository->update();
  }

  protected function verify() {
    if (!$this->repository->isValidGitRepo()) {
      drupal_set_message(t('The repository %name at <code>@root</code> is not a valid Git bare repository.', array('%name' => $repository->name, '@root' => $repository->root)), 'error');
      return FALSE;
    }
  }
}

