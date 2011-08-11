<?php

class VersioncontrolGitBranchChange extends VersioncontrolGitRefChange {

  public function __construct($data) {
    parent::__construct($data);
  }

  public function getLabel() {
    if (!empty($this->label_id)) {
      $branches = $this->repository->loadBranches(array($this->label_id));
      return reset($branches);
    }
  }

  public function syncLabel() {
    $branches = $this->repository->loadBranches(array(), array('name' => $this->refname));
    if (!empty($branches)) {
      $branch = reset($branches);
      $this->label_id = $branch->label_id;
    }
  }

  /**
   * Return the list of commits introduced on this branch by this push event.
   *
   * Note that this will be empty if the the branch was initially created by
   * this event.
   *
   * @return array
   *   An array of VersioncontrolGitOperation objects.
   */
  public function getIncludedCommits($options = array()) {
    return $this->repository->loadCommits(array(), array('revision' => $this->commits), $options);
  }
}

