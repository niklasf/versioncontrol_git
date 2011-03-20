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

  public function fullSync() {
    $this->verify();
    $this->prepare();

    // FIXME this is temporary; the entirety of the logic called in and below
    // this function needs to be moved into this class.
    _versioncontrol_git_log_update_repository($repository);
  }

  public function initialSync() {
    // TODO halstead's optimized fast-export-based parser goes here.
  }

  public function dumbSync($data) {

  }

  public function verifyData() {
    return TRUE;
  }

  protected function prepare() {

  }

  protected function verify() {
    if (!$this->repository->isValidGitRepo()) {
      drupal_set_message(t('The repository %name at <code>@root</code> is not a valid Git bare repository.', array('%name' => $repository->name, '@root' => $repository->root)), 'error');
      return FALSE;
    }
  }
}

