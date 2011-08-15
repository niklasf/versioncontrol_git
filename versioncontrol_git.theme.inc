<?php
/**
 * @file
 * Git backend for Version Control API - Provides Git commit information and
 * account management as a pluggable backend.
 *
 * Copyright 2008 by Jimmy Berry ("boombatower", http://drupal.org/user/214218)
 * Copyright 2009 by Cornelius Riemenschneider ("CorniI", http://drupal.org/user/136353)
 */
 
 /**
  * Theme function for VersioncontrolGitEvent.
  *
  * @param VersioncontrolGitEvent $event
  *   The VersioncontrolGitEvent object to generate a text string from.
  *
  * @return string
  */
 function theme_versioncontrol_git_event($event) {
  $refs = array();
  
  $repo = $event->getRepository();
  
  foreach ($event as $event_ref) {
    switch ($event_ref->reftype) {
      case VERSIONCONTROL_OPERATION_BRANCH:
        if ($event_ref->eventDeletedMe()) {
          $refs[] = t('Branch !branch was deleted.', array('!branch' => $event_ref->refname));
        }
        else {
          $ref = array();
          
          $commits = $event_ref->getIncludedCommits();
          
          if ($event_ref->eventCreatedMe()) {
            if (empty($commits)) {
              $ref['data'] = t('Branch !branch was created.', array('!branch' => $event_ref->refname));
            }
            else {
              $ref['data'] = t('Branch !branch was created, it contains the following commits:', array('!branch' => $event_ref->refname));
            }
          }
          else {
            if (empty($commits)) {
              $ref['data'] = t('Branch !branch was updated.', array('!branch' => $event_ref->refname));
            }
            else {
              $ref['data'] = t('Branch !branch was updated with the following commits:', array('!branch' => $event_ref->refname));
            }
          }
          
          if (!empty($commits)) {
            $ref['children'] = array();
            
            foreach ($commits as $commit) {
              $ref['children'][] = theme('versioncontrol_git_operation', $commit);
            }
          }
          
          $refs[] = $ref;
        }
        
        break;
      case VERSIONCONTROL_OPERATION_TAG:
        if ($event_ref->eventCreatedMe()) {
          $refs[] = t('Tag !tag was created and points to !commit.', array('!tag' => $event_ref->refname, '!commit' => theme('versioncontrol_git_operation', $event_ref->getNewCommit())));
        }
        elseif ($event_ref->eventDeletedMe()) {
          $refs[] = t('Tag !tag was deleted.', array('!tag' => $event_ref->refname));
        }
        
        break;
    }
  }
  
  return theme('item_list', $refs);
}

/**
 * Theme function for VersioncontrolGitOperation.
 *
 * @param VersioncontrolGitOperation $operation
 *   The VersioncontrolGitOperation object to generate a text string from.
 *
 * @return string
 */
function theme_versioncontrol_git_operation($operation) {
  $revision = $operation->getBackend()->formatRevisionIdentifier($operation->revision, 'short');
  $revision_link = $operation->getRepository()->getUrlHandler()->getCommitViewUrl($revision);
  $revision = (!empty($revision_link) ? $revision_link : $revision);
  
  return t('!revision: !message', array('!revision' => $revision, '!message' => check_plain(truncate_utf8($operation->message, 100, FALSE, TRUE))));
}