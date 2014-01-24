<?php

namespace Drupal\HechoEnDrupal\Installer;

use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\StringTranslation\Translator\FileTranslation;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Installer {

	public function __construct($settings = []) {

    global $install_state;
    // Initialize the installation state with the settings that were passed in,
    // as well as a boolean indicating whether or not this is an interactive
    // installation.
    $interactive = empty($settings);
    $install_state = $settings + array('interactive' => $interactive) + install_state_defaults();

    try {
      // Begin the page request. This adds information about the current state of
      // the Drupal installation to the passed-in array.
      //install_begin_request($install_state);
      $this->beginRequest();
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

  public function beginRequest(){
    global $install_state;

    // A request object from the HTTPFoundation to tell us about the request.
    $request = Request::createFromGlobals();

    // Create a minimal container so that t() and $request will work. This
    // container will be overriden but it's needed for the very early installation
    // process when database tasks run.
    $container = new ContainerBuilder();
    $container->set('request', $request);
    \Drupal::setContainer($container);

    // Add any installation parameters passed in via the URL.
    if ($install_state['interactive']) {
      $install_state['parameters'] += $request->query->all();
    }

    // Validate certain core settings that are used throughout the installation.
    if (!empty($install_state['parameters']['profile'])) {
      $install_state['parameters']['profile'] = preg_replace('/[^a-zA-Z_0-9]/', '', $install_state['parameters']['profile']);
    }
    if (!empty($install_state['parameters']['langcode'])) {
      $install_state['parameters']['langcode'] = preg_replace('/[^a-zA-Z_0-9\-]/', '', $install_state['parameters']['langcode']);
    }

    // Allow command line scripts to override server variables used by Drupal.
    //require_once __DIR__ . '/bootstrap.inc';

    if (!$install_state['interactive']) {
      drupal_override_server_variables($install_state['server']);
    }

    // Initialize conf_path().
    // This primes the site path to be used during installation. By not requiring
    // settings.php, a bare site folder can be prepared in the /sites directory,
    // which will be used for installing Drupal.
    //conf_path(FALSE);

    drupal_bootstrap(DRUPAL_BOOTSTRAP_CONFIGURATION);

    // If the hash salt leaks, it becomes possible to forge a valid testing user
    // agent, install a new copy of Drupal, and take over the original site. To
    // avoid this yet allow for automated testing of the installer, make sure
    // there is also a special test-specific settings.php overriding conf_path().
    // _drupal_load_test_overrides() sets the simpletest_conf_path in-memory
    // setting in this case.
    if ($install_state['interactive'] && drupal_valid_test_ua() && !settings()->get('simpletest_conf_path')) {
      header($request->server->get('SERVER_PROTOCOL') . ' 403 Forbidden');
      exit;
    }

    // If we have a language selected and it is not yet saved in the system
    // (eg. pre-database data screens we are unable to persistently store
    // the default language), we should set language_default so the proper
    // language is used to display installer pages as early as possible.
    // The language list is stored in configuration and cannot be saved either
    // until later in the process. Language negotiation bootstrapping needs
    // the new default language to be in the list though, so inject it in.
    if (!empty($install_state['parameters']['langcode']) && language_default()->id != $install_state['parameters']['langcode']) {
      $GLOBALS['conf']['language_default'] = array('id' => $install_state['parameters']['langcode']);

      $languages = &drupal_static('language_list');
      $languages[$install_state['parameters']['langcode']] = new Language($GLOBALS['conf']['language_default']);
    }

    require_once __DIR__ . '/../modules/system/system.install';
    require_once __DIR__ . '/common.inc';
    require_once __DIR__ . '/file.inc';
    require_once __DIR__ . '/install.inc';
    require_once __DIR__ . '/schema.inc';
    require_once __DIR__ . '/../../' . settings()->get('path_inc', 'core/includes/path.inc');

    // Load module basics (needed for hook invokes).
    include_once __DIR__ . '/module.inc';
    include_once __DIR__ . '/session.inc';
    require_once __DIR__ . '/entity.inc';

    // Determine whether the configuration system is ready to operate.
    $install_state['config_verified'] = install_verify_config_directory(CONFIG_ACTIVE_DIRECTORY) && install_verify_config_directory(CONFIG_STAGING_DIRECTORY);

    // Register the translation services.
    install_register_translation_service($container);
    \Drupal::setContainer($container);

    // Check existing settings.php.
    $install_state['database_verified'] = install_verify_database_settings();
    $install_state['settings_verified'] = $install_state['config_verified'] && $install_state['database_verified'];

    // If it is not, replace the configuration storage with the InstallStorage
    // implementation, for the following reasons:
    // - The first call into drupal_container() will try to set up the regular
    //   runtime configuration storage, using the CachedStorage by default. It
    //   calls config_get_config_directory() to retrieve the config directory to
    //   use, but that throws an exception, since $config_directories is not
    //   defined since there is no settings.php yet. If there is a prepared
    //   settings.php already, then the returned directory still cannot be used,
    //   because it does not necessarily exist. The installer ensures that it
    //   exists and is writeable in a later step.
    // - The installer outputs maintenance theme pages and performs many other
    //   operations, which try to load configuration. Since there is no active
    //   configuration yet, and because the configuration system does not have a
    //   notion of default values at runtime, data is missing in many places. The
    //   lack of data does not trigger errors, but results in a broken user
    //   interface (e.g., missing page title, etc).
    // - The actual configuration data to read during installation is essentially
    //   the default configuration provided by the installation profile and
    //   modules (most notably System module). The InstallStorage therefore reads
    //   from the default configuration directories of extensions.
    // This override is reverted as soon as the config directory and the
    // database has been set up successfully.
    // @see drupal_install_config_directories()
    // @see install_settings_form_submit()
    if ($install_state['settings_verified']) {
      $kernel = new DrupalKernel('install', drupal_classloader(), FALSE);
      $kernel->boot();
      $container = $kernel->getContainer();
      // Add the file translation service to the container.
      $container->set('string_translator.file_translation', install_file_translation_service());
      $container->get('string_translation')->addTranslator($container->get('string_translator.file_translation'));
    }
    else {
      // @todo Move into a proper Drupal\Core\DependencyInjection\InstallContainerBuilder.
      $container = new ContainerBuilder();

      $container->register('event_dispatcher', 'Symfony\Component\EventDispatcher\EventDispatcher');

      $container->register('config.storage', 'Drupal\Core\Config\InstallStorage');
      $container->register('config.context.factory', 'Drupal\Core\Config\Context\ConfigContextFactory')->addArgument(new Reference('event_dispatcher'))->addArgument(new Reference('uuid'));

      $container->register('config.context', 'Drupal\Core\Config\Context\ContextInterface')->setFactoryService(new Reference('config.context.factory'))->setFactoryMethod('get');

      $container->register('config.storage.schema', 'Drupal\Core\Config\Schema\SchemaStorage');

      $container->register('config.typed', 'Drupal\Core\Config\TypedConfigManager')->addArgument(new Reference('config.storage'))->addArgument(new Reference('config.storage.schema'));

      $container->register('config.factory', 'Drupal\Core\Config\ConfigFactory')->addArgument(new Reference('config.storage'))->addArgument(new Reference('config.context'))->addArgument(new Reference('config.typed'));

      // Register the 'language_manager' service.
      $container->register('language_manager', 'Drupal\Core\Language\LanguageManager')->addArgument(NULL);

      // Register the translation services.
      install_register_translation_service($container);

      foreach (array(
        'bootstrap',
        'config',
        'cache',
        'menu',
        'page',
        'path',
      ) as $bin) {
        $container->register("cache.$bin", 'Drupal\Core\Cache\MemoryBackend')->addArgument($bin);
      }

      // The install process cannot use the database lock backend since the database
      // is not fully up, so we use a null backend implementation during the
      // installation process. This will also speed up the installation process.
      // The site being installed will use the real lock backend when doing AJAX
      // requests but, except for a WSOD, there is no chance for a a lock to stall
      // (as opposed to the cache backend) so we can afford having a null
      // implementation here.
      $container->register('lock', 'Drupal\Core\Lock\NullLockBackend');

      $container->register('theme.registry', 'Drupal\Core\Theme\Registry')->addArgument(new Reference('cache.cache'))->addArgument(new Reference('lock'))->addArgument(new Reference('module_handler'))->addTag('needs_destruction');

      // Register a module handler for managing enabled modules.
      $container->register('module_handler', 'Drupal\Core\Extension\ModuleHandler');

      // Register the Guzzle HTTP client for fetching translation files from a
      // remote translation server such as localization.drupal.org.
      $container->register('http_default_client', 'Guzzle\Http\Client')->addArgument(NULL)->addArgument(array(
        'curl.CURLOPT_TIMEOUT' => 30.0,
        'curl.CURLOPT_MAXREDIRS' => 3,
      ))->addMethodCall('setUserAgent', array('Drupal (+http://drupal.org/)'));

      $container->register('settings', 'Drupal\Component\Utility\Settings')->setFactoryClass('Drupal\Component\Utility\Settings')->setFactoryMethod('getSingleton');

      $container->register('keyvalue', 'Drupal\Core\KeyValueStore\KeyValueMemoryFactory');
      $container->register('keyvalue.expirable', 'Drupal\Core\KeyValueStore\KeyValueNullExpirableFactory');

      $container->register('state', 'Drupal\Core\KeyValueStore\State')->addArgument(new Reference('keyvalue'));

      // Register Twig template engine for use during install.
      CoreServiceProvider::registerTwig($container);

      $container->register('url_generator', 'Drupal\Core\Routing\NullGenerator');

      $container->register('form_builder', 'Drupal\Core\Form\FormBuilder')->addArgument(new Reference('module_handler'))->addArgument(new Reference('keyvalue.expirable'))->addArgument(new Reference('event_dispatcher'))->addArgument(new Reference('url_generator'))->addArgument(new Reference('string_translation'))->addArgument(new Reference('csrf_token', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))->addArgument(new Reference('http_kernel', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))->addMethodCall('setRequest', array(new Reference('request')));

      // Register UUID.
      CoreServiceProvider::registerUuid($container);

      // Register the CSS and JavaScript asset collection renderers.
      $container->register('asset.css.collection_renderer', 'Drupal\Core\Asset\CssCollectionRenderer')->addArgument(new Reference('state'));
      $container->register('asset.js.collection_renderer', 'Drupal\Core\Asset\JsCollectionRenderer')->addArgument(new Reference('state'));

      // Register the info parser.
      $container->register('info_parser', 'Drupal\Core\Extension\InfoParser');

      $container->register('theme_handler', 'Drupal\Core\Extension\ThemeHandler')->addArgument(new Reference('config.factory'))->addArgument(new Reference('module_handler'))->addArgument(new Reference('cache.cache'))->addArgument(new Reference('info_parser'));
    }

    // Set the request in the kernel to the new created Request above
    // so it is available to the rest of the installation process.
    $container->set('request', $request);

    \Drupal::setContainer($container);

    // Set up $language, so t() caller functions will still work.
    drupal_language_initialize();
    // Add in installation language if present.
    if (isset($install_state['parameters']['langcode'])) {
      \Drupal::translation()->setDefaultLangcode($install_state['parameters']['langcode']);
    }

    require_once __DIR__ . '/ajax.inc';

    $module_handler = \Drupal::moduleHandler();
    if (!$module_handler->moduleExists('system')) {
      // Override the module list with a minimal set of modules.
      $module_handler->setModuleList(array('system' => 'core/modules/system/system.module'));
    }
    $module_handler->load('system');

    require_once __DIR__ . '/cache.inc';

    // Prepare for themed output. We need to run this at the beginning of the
    // page request to avoid a different theme accidentally getting set. (We also
    // need to run it even in the case of command-line installations, to prevent
    // any code in the installer that happens to initialize the theme system from
    // accessing the database before it is set up yet.)
    drupal_maintenance_theme();

    if ($install_state['database_verified']) {
      // Initialize the database system. Note that the connection
      // won't be initialized until it is actually requested.
      require_once __DIR__ . '/database.inc';

      // Verify the last completed task in the database, if there is one.
      $task = install_verify_completed_task();
    }
    else {
      $task = NULL;

      // Do not install over a configured settings.php.
      if (!empty($GLOBALS['databases'])) {
        throw new Exception(install_already_done_error());
      }
    }

    // Ensure that the active configuration directory is empty before installation
    // starts.
    if ($install_state['config_verified'] && empty($task)) {
      $config = glob(config_get_config_directory(CONFIG_ACTIVE_DIRECTORY) . '/*.' . FileStorage::getFileExtension());
      if (!empty($config)) {
        $task = NULL;
        throw new Exception(install_already_done_error());
      }
    }

    // Modify the installation state as appropriate.
    $install_state['completed_task'] = $task;
    $install_state['database_tables_exist'] = !empty($task);

    // Add the list of available profiles to the installation state.
    $install_state['profiles'] += drupal_system_listing('/^' . DRUPAL_PHP_FUNCTION_PATTERN . '\.profile$/', 'profiles');

  }
}
