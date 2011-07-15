<?php

class VersioncontrolGitEventController extends VersioncontrolEventController {

  /**
   * Extend the base query with the git backend's additional data in
   * {versioncontrol_git_operations}.
   *
   * @return SelectQuery
   */
  protected function attachExtendedEventData($elids, &$queried_entities) {
    $result = db_select('versioncontrol_git_event_data', 'vged')
      ->fields('vged')
      ->condition('vged.elid', $elids)
      ->execute();

    foreach ($result as $row) {
      // This is just an example - we need to decide how to attach the data to 
      // VersioncontrolGitEvent objects. We'll want to do at least some unpacking.
      $queried_entities[$row->elid]->extended_data = $row;
    }
  }
}
