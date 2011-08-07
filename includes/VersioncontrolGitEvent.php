<?php
/**
 * Entity class representing a push event that occurred in a tracked
 * Git repository.
 *
 * Is Traversable - if you want to get at all the underlying ref changes
 * in this event, just foreach() the object.
 */
class VersioncontrolGitEvent extends VersioncontrolEvent implements IteratorAggregate {

  /**
   * An array of the refs that were updated by this event, each
   * represented as a VersioncontrolGitRefChange object.
   *
   * @var array
   */
  public $refs = array();

  /**
   * Populate the Git event object with all its data.
   *
   * @param $args
   *   An array of data provided from a post-receive hook.
   */
  public function build($args = array()) {
    // Don't build twice.
    if ($this->built === TRUE) {
      return;
    }

    foreach ($args as $prop => $value) {
      $this->$prop = $value;
    }

    $refs = array();
    foreach ($this->refs as $ref) {
      if ($ref->reftype == VERSIONCONTROL_LABEL_BRANCH) {
        $refs[$ref->refname] = new VersioncontrolGitBranchChange($ref);
      }
      else {
        // TODO need to accommodate other ref namespaces, such as notes
        $refs[$ref->refname] = new VersioncontrolGitTagChange($ref);
      }
    }
    
    $this->refs = $refs;

    $this->built = TRUE;
  }

  public function getLabelChanges() {
    return $this->refs;
  }

  protected function backendInsert($options = array()) {
    $this->fillExtendedTable();
  }

  protected function backendUpdate($options = array()) {
    $this->cleanExtendedTable();
    $this->fillExtendedTable();
    // Flush the extended table, then rewrite the whole thing in one fell swoop.
    drupal_write_record('versioncontrol_git_event_data', $this, 'elid');
  }

  protected function backendDelete($options = array()) {
    $this->cleanExtendedTable();
  }

  protected function cleanExtendedTable() {
    db_delete('versioncontrol_git_event_data')
    ->condition('elid', $this->elid)
    ->execute();
  }

  protected function fillExtendedTable() {
    $fields = array('elid', 'refname', 'label_id', 'reftype', 'old_obj', 'new_obj', 'commits');
    $query = db_insert('versioncontrol_git_event_data')->fields($fields);

    foreach ($this->refs as $ref) {
      $query->values($ref->dumpProps());
    }

    $query->execute();
  }

  public function getIterator() {
    // TODO ensure the array is passed by reference. Since the contents are
    // objects, they're automagically taken care of, but still.
    return new ArrayIterator($this->refs);
  }

}
