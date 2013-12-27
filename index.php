<?php

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Settings;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Timer;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Url;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Utility\Title;
use Symfony\Component\ClassLoader\ApcClassLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Exception\RuntimeException as DependencyInjectionRuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Language\Language;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\UserSession;

require_once __DIR__ . '/vendor/autoload.php';

$drupal_path = "/vendor/hechoendrupal/drupal/core";
require_once __DIR__ . $drupal_path . '/includes/bootstrap.inc';

try {

  drupal_environment_initialize();
  // Initialize the configuration, including variables from settings.php.
  drupal_settings_initialize();

  // Make sure we are using the test database prefix in child Drupal sites.
  _drupal_initialize_db_test_prefix();

  // Activate the class loader.
  drupal_classloader();

  // Start a page timer:
  Timer::start('page');

  // Detect string handling method.
  Unicode::check();

  // Load the procedural configuration system helper functions.
  require_once __DIR__ . $drupal_path . '/includes/config.inc';

  // Set the Drupal custom error handler. (requires \Drupal::config())
  set_error_handler('_drupal_error_handler');
  set_exception_handler('_drupal_exception_handler');

  // Redirect the user to the installation script if Drupal has not been
  // installed yet (i.e., if no $databases array has been defined in the
  // settings.php file) and we are not already installing.
  if (empty($GLOBALS['databases']) && !drupal_installation_attempted() && !drupal_is_cli()) {
    include_once __DIR__ . $drupal_path . '/includes/install.inc';
    install_goto('install.php');
  }

  // Exit if we should be in a test environment but aren't.
  if ($test_only && !drupal_valid_test_ua()) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
    exit;
  }

  $kernel = new DrupalKernel('prod', drupal_classloader(), !$test_only);

  // @todo Remove this once everything in the bootstrap has been
  //   converted to services in the DIC.
  $kernel->boot();

  // Create a request object from the HttpFoundation.
  $request = Request::createFromGlobals();
  \Drupal::getContainer()->set('request', $request);

  drupal_bootstrap(DRUPAL_BOOTSTRAP_CODE);

  $response = $kernel->handle($request)->prepare($request)->send();

  $kernel->terminate($request, $response);

}
catch (Exception $e) {
  $message = 'If you have just changed code (for example deployed a new module or moved an existing one) read <a href="http://drupal.org/documentation/rebuild">http://drupal.org/documentation/rebuild</a>';
  if (settings()->get('rebuild_access', FALSE)) {
    $rebuild_path = $GLOBALS['base_url'] . '/rebuild.php';
    $message .= " or run the <a href=\"$rebuild_path\">rebuild script</a>";
  }
  print $message;
  throw $e;
}
