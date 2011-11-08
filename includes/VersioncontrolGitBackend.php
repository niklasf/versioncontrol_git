<?php

class VersioncontrolGitBackend extends VersioncontrolBackend {

  public $type = 'git';

  public $classesEntities = array(
      'repo' => 'VersioncontrolGitRepository',
      'account' => 'VersioncontrolGitAccount',
      'operation' => 'VersioncontrolGitOperation',
      'item' => 'VersioncontrolGitItem',
      'event' => 'VersioncontrolGitEvent',
    );

  public $classesControllers = array(
    'repo' => 'VersioncontrolGitRepositoryController',
    'operation' => 'VersioncontrolGitOperationController',
    'item' => 'VersioncontrolGitItemController',
    'event' => 'VersioncontrolGitEventController',
  );

  public $defaultViews = array(
    'project_global_commit_view' => 'vc_git_project_global_commits',
    'project_user_commit_view' => 'vc_git_project_user_commits',
    'project_commit_view' => 'vc_git_project_commit_view',
    'individual_commit_view' => 'vc_git_individual_commit',
  );

  public function __construct() {
    parent::__construct();
    $this->name = 'Git';
    $this->description = t('Git is a fast, scalable, distributed revision control system with an unusually rich command set that provides both high-level operations and full access to internals.');
    $this->capabilities = array(
        // Use the commit hash for to identify the commit instead of an individual
        // revision for each file.
        VERSIONCONTROL_CAPABILITY_ATOMIC_COMMITS
    );
  }

  /**
   * Overwrite to get short sha-1's
   */
  public function formatRevisionIdentifier($revision, $format = 'full') {
    switch ($format) {
      case 'short':
        // Let's return only the first 7 characters of the revision identifier,
        // like git log --abbrev-commit does by default.
        return substr($revision, 0, 7);
      case 'full':
      default:
        return $revision;
    }
  }

  /**
   * Parse incoming data from a post-receive hook and turn it into a
   * VersioncontrolGitEvent object.
   *
   * @return VersioncontrolGitEvent
   *
   * @see VersioncontrolBackend::generateCodeArrivalEvent()
   */
  public function generateCodeArrivalEvent($data) {
    // Unpack all the post-receive data.
    $all_refdata = explode("\n", $data['data']);
    $refs = array();
    foreach ($all_refdata as $refdata) {
      if (empty($refdata)) {
        continue; // last element is often empty, skip it
      }
      list($start, $end, $refpath) = explode(' ', $refdata);
      // TODO need to accommodate other ref namespaces, such as notes
      list(, $type, $ref) = explode('/', $refpath);
      $refs[] = array(
        'reftype' => $type == 'tags' ? VERSIONCONTROL_LABEL_TAG : VERSIONCONTROL_LABEL_BRANCH,
        'refname' => $ref,
        'label_id' => 0, // init label_id to 0; it'll be updated later.
        'old_sha1' => $start,
        'new_sha1' => $end,
      );
    }

    // Slap together an object that VersioncontrolGitEvent::build() will like.
    $obj = new stdClass();
    $obj->uid = empty($data['uid']) ? 0 : $data['uid'];
    $obj->timestamp = empty($data['timestamp']) ? time() : $data['timestamp'];
    $obj->repository = $data['repository'];
    $obj->refs = $refs;

    return $this->buildEntity('event', $obj);
  }
}
