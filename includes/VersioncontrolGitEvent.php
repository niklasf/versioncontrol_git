<?php
/**
 * @file
 * Event class
 */

/**
 * Stuff that happened in a repository at a specific time
 */
class VersioncontrolGitEvent extends VersioncontrolEvent {

  /**
   * The name of the reference that was updated.
   *
   * @var string
   */
  public $refname;
  
  /**
   * The identifier of the label.
   *
   * @var int
   */
  public $label_id;
  
  /**
   * The type of the reference:
   *    2 == branch
   *    3 == tag
   *
   * @var int
   */
  public $reftype;
  
  /**
   * The object to which this reference pointed before the push.
   *
   * @var SHA1
   */
  public $old_obj;
  
  /**
   * The object to which this reference pointed after the push.
   *
   * @var SHA1
   */
  public $new_obj;
  
  /**
   * A list of all the commits contained in the push.
   *
   * @var serialized array
   */
  public $commits;
  
  public function backendInsert($options = array()) {
    drupal_write_record('versioncontrol_git_event_data', $this);
  }

  public function backendUpdate($options = array()) {
    drupal_write_record('versioncontrol_git_event_data', $this, 'elid');
  }

  public function backendDelete($options = array()) {
    db_delete('versioncontrol_git_event_data')
      ->condition('elid', $this->elid)
      ->execute();
  }
  
  /**
   * Load all commit objects associated with this event.
   */
  public function loadCommits() {
    $commits = array();
    $commits_raw = unserialize($this->commits);
    
    if (!empty($commits_raw)) {
      $condition = 
        array(
          'values' => $commits_raw,
          'operator' => 'IN',
        );
      
      $conditions = array('revision' => $condition);
      
      $commits = $this->getRepository()->loadCommits(NULL, $conditions, array('may cache' => FALSE));
    }
    
    return $commits;
  }

  /**
   * Load all branches associated with this event.
   */
  public function loadBranches() {
    return $this->_loadLabels(VERSIONCONTROL_LABEL_BRANCH);
  }

  /**
   * Load all tags associated with this event.
   */
  public function loadTags() {
    return $this->_loadLabels(VERSIONCONTROL_LABEL_TAG);
  }

  /**
   * Load all branches AND tags associated with this event.
   */
  public function loadLabels() {
    return $this->_loadLabels();
  }
  
  private function _loadLabels($type = NULL) {
    $commits = $this->loadCommits();
    
    $labels = array();
    
    foreach ($commits as $commit) {
      foreach ($commit->labels as $label) {
        if (is_null($type) || ($label->type == $type)) {
          $labels[$label->name] = $label;
        }
      }
    }
    
    return $labels;
  }
  
}
