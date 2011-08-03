<?php

class VersioncontrolGitTagChange extends VersioncontrolGitRefChange {
  public function __construct($data) {
    parent::__construct($data);
    $this->commits = array();
    // Just ensure we don't have anything for ff
    unset($this->ff);
  }

  public function getLabel() {
    if (!empty($this->label_id)) {
      $tags = $this->repository->loadTags(array($this->label_id));
      return reset($tags);
    }
  }
}
