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
      foreach ($row as $key => $value) {
        $queried_entities[$row->elid]->$key = $value;
      }
    }
  }
}
