<?php

namespace Drupal\HechoEnDrupal\Installer;

use Symfony\Component\HttpFoundation\Request;

function install_drupal($settings = array()) {

  global $install_state;
  // Initialize the installation state with the settings that were passed in,
  // as well as a boolean indicating whether or not this is an interactive
  // installation.
  $interactive = empty($settings);
  $install_state = $settings + array('interactive' => $interactive) + install_state_defaults();
  try {
    // Begin the page request. This adds information about the current state of
    // the Drupal installation to the passed-in array.
    install_begin_request($install_state);
    // Based on the installation state, run the remaining tasks for this page
    // request, and collect any output.
    $output = install_run_tasks($install_state);
  }
  catch (Exception $e) {
    // When an installation error occurs, either send the error to the web
    // browser or pass on the exception so the calling script can use it.
    if ($install_state['interactive']) {
      install_display_output($e->getMessage(), $install_state);
    }
    else {
      throw $e;
    }
  }
  // After execution, all tasks might be complete, in which case
  // $install_state['installation_finished'] is TRUE. In case the last task
  // has been processed, remove the global $install_state, so other code can
  // reliably check whether it is running during the installer.
  // @see drupal_installation_attempted()
  $state = $install_state;
  if (!empty($install_state['installation_finished'])) {
    unset($GLOBALS['install_state']);
  }

  // All available tasks for this page request are now complete. Interactive
  // installations can send output to the browser or redirect the user to the
  // next page.
  if ($state['interactive']) {
    if ($state['parameters_changed']) {
      // Redirect to the correct page if the URL parameters have changed.
      install_goto(install_redirect_url($state));
    }
    elseif (isset($output)) {
      // Display a page only if some output is available. Otherwise it is
      // possible that we are printing a JSON page and theme output should
      // not be shown.
      install_display_output($output, $state);
    }
  }
}
