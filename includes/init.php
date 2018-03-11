<?php

use \Core\SnBootstrap;

// Защита от двойного инита
if(defined('INIT')) {
  return;
}

define('INIT', true);

// Замеряем начальные параметры
define('SN_TIME_MICRO', microtime(true));
define('SN_MEM_START', memory_get_usage());
define('SN_ROOT_PHYSICAL', str_replace('\\', '/', realpath(dirname(__DIR__))) . '/');
define('SN_ROOT_PHYSICAL_STR_LEN', strlen(SN_ROOT_PHYSICAL));

version_compare(PHP_VERSION, '5.5') < 0 ? die('FATAL ERROR: SuperNova REQUIRE PHP version >= 5.5') : false;

//define('DEBUG_UBE', true);
//define('DEBUG_FLYING_FLEETS', true);
//define('SN_DEBUG_LOG', true);
//define('SN_DEBUG_PDUMP_CALLER', true);

!defined('INSIDE') ? define('INSIDE', true) : false;
!defined('INSTALL') ? define('INSTALL', false) : false;
!defined('IN_PHPBB') ? define('IN_PHPBB', true) : false;

header('Content-type: text/html; charset=utf-8');
ob_start();
ini_set('error_reporting', E_ALL ^ E_NOTICE);

// Installing autoloader
require_once SN_ROOT_PHYSICAL . 'classes/Core/Autoloader.php';
\Core\Autoloader::register('classes/');
\Core\Autoloader::register('classes/UBE/');


require_once SN_ROOT_PHYSICAL . 'includes/constants.php';

// Бенчмарк
SnBootstrap::install_benchmark();
// Loading functions - can't be inserted into function
require_once SN_ROOT_PHYSICAL . 'includes/db.php';
require_once(SN_ROOT_PHYSICAL . 'includes/general.php');
require_once(SN_ROOT_PHYSICAL . 'includes/template.php');
sn_sys_load_php_files(SN_ROOT_PHYSICAL . 'includes/functions/', PHP_EX);

classSupernova::loadFileSettings();
classSupernova::init_global_objects();

// AFTER init global objects 'cause vars.php uses some from config
require_once(SN_ROOT_PHYSICAL . 'includes/vars.php');

// Some time required to start from cold cache
set_time_limit(60);

// Отладка
// define('BE_DEBUG', true); // Отладка боевого движка
SnBootstrap::init_debug_state();

SnBootstrap::performUpdate(classSupernova::$config);

// init constants from db
// Moved from SnBootstrap for phpStorm autocomplete
// Shoud be removed someday - there should NOT be constants that depends on configuration!
define('SN_COOKIE', (classSupernova::$config->COOKIE_NAME ? classSupernova::$config->COOKIE_NAME : 'SuperNova') . (defined('SN_GOOGLE') ? '_G' : ''));
define('SN_COOKIE_I', SN_COOKIE . AUTH_COOKIE_IMPERSONATE_SUFFIX);
define('SN_COOKIE_D', SN_COOKIE . '_D');
define('SN_COOKIE_T', SN_COOKIE . '_T'); // Time measure cookie
define('SN_COOKIE_F', SN_COOKIE . '_F'); // Font size cookie
define('SN_COOKIE_U', SN_COOKIE . '_U'); // Current user cookie aka user ID
define('SN_COOKIE_U_I', SN_COOKIE_U . AUTH_COOKIE_IMPERSONATE_SUFFIX); // Current impersonator user cookie aka impersonator user ID
define('TEMPLATE_NAME', classSupernova::$config->game_default_template ? classSupernova::$config->game_default_template : 'OpenGame');
define('TEMPLATE_PATH', 'design/templates/' . TEMPLATE_NAME);
define('TEMPLATE_DIR', SN_ROOT_PHYSICAL . TEMPLATE_PATH);
define('DEFAULT_SKINPATH', classSupernova::$config->game_default_skin ? classSupernova::$config->game_default_skin : 'skins/EpicBlue/');
define('DEFAULT_SKIN_NAME', substr(DEFAULT_SKINPATH, 6, -1));
define('DEFAULT_LANG', classSupernova::$config->game_default_language ? classSupernova::$config->game_default_language : 'ru');
define('FMT_DATE', classSupernova::$config->int_format_date ? classSupernova::$config->int_format_date : 'd.m.Y');
define('FMT_TIME', classSupernova::$config->int_format_time ? classSupernova::$config->int_format_time : 'H:i:s');
define('FMT_DATE_TIME', FMT_DATE . ' ' . FMT_TIME);


/**
 * @var classCache $sn_cache
 * @var classConfig $config
 * @var debug $debug
 */
global $sn_cache, $config, $auth, $debug, $lang;


global $sn_page_name;
$sn_page_name = INITIAL_PAGE;
global $template_result;
$template_result = array('.' => array('result' => array()));

classSupernova::$lang = $lang = new classLocale(classSupernova::$config->server_locale_log_usage);


global $sn_data, $sn_mvc;
global $sn_module, $sn_module_list;

// Подключаем все модули
// По нормальным делам тут надо подключать манифесты
// И читать конфиги - вдруг модуль отключен?
// Конфиг - часть манифеста?
$sn_module = array();
$sn_module_list = array();

classSupernova::$auth = new core_auth();

sn_sys_load_php_files(SN_ROOT_PHYSICAL . 'modules/', PHP_EX, true);
// Здесь - потому что core_auth модуль лежит в другом каталоге и его нужно инициализировать отдельно

// Подключаем дефолтную страницу
// По нормальным делам её надо подключать в порядке загрузки обработчиков
// Сейчас мы делаем это здесь только для того, что бы содержание дефолтной страницы оказалось вверху. Что не факт, что нужно всегда
// Но нужно, пока у нас есть не MVC-страницы
$sn_page_data = $sn_mvc['pages'][$sn_page_name];
$sn_page_name_file = 'includes/pages/' . $sn_page_data['filename'] . DOT_PHP_EX;
if($sn_page_name) {
  // Merging page options to global option pull
  if(is_array($sn_page_data['options'])) {
    classSupernova::$options = array_merge(classSupernova::$options, $sn_page_data['options']);
  }

  if(isset($sn_page_data) && file_exists($sn_page_name_file)) {
    require_once($sn_page_name_file);
  }
}

if((defined('IN_AJAX') && IN_AJAX === true) || (defined('IN_ADMIN') && IN_ADMIN === true)) {
  classSupernova::$options['fleet_update_skip'] = true;
}

// load_order:
//  100000 - default load order
//  999999 - core_ship_constructor
//  2000000000 - that requires that all possible modules loaded already
//  2000100000 - game_skirmish

// Генерируем список требуемых модулей
$load_order = array();
$sn_req = array();

foreach($sn_module as $loaded_module_name => $module_data) {
  $load_order[$loaded_module_name] = isset($module_data->manifest['load_order']) && !empty($module_data->manifest['load_order']) ? $module_data->manifest['load_order'] : 100000;
  if(isset($module_data->manifest['require']) && !empty($module_data->manifest['require'])) {
    foreach($module_data->manifest['require'] as $require_name) {
      $sn_req[$loaded_module_name][$require_name] = 0;
    }
  }
}

// Создаем последовательность инициализации модулей
// По нормальным делам надо сначала читать их конфиги - вдруг какой-то модуль отключен?
do {
  $prev_order = $load_order;

  foreach($sn_req as $loaded_module_name => &$req_data) {
    $level = 1;
    foreach($req_data as $req_name => &$req_level) {
      if($load_order[$req_name] == -1 || !isset($load_order[$req_name])) {
        $level = $req_level = -1;
        break;
      } else {
        $level += $load_order[$req_name];
      }
      $req_level = $load_order[$req_name];
    }
    if($level > $load_order[$loaded_module_name] || $level == -1) {
      $load_order[$loaded_module_name] = $level;
    }
  }
}
while($prev_order != $load_order);

asort($load_order);

// Инициализируем модули
// По нормальным делам это должна быть загрузка модулей и лишь затем инициализация - что бы минимизировать размер процесса в памяти
foreach($load_order as $loaded_module_name => $load_order_order) {
  if($load_order_order >= 0) {
    $sn_module[$loaded_module_name]->check_status();
    if(!$sn_module[$loaded_module_name]->manifest['active']) {
      unset($sn_module[$loaded_module_name]);
      continue;
    }

    $sn_module[$loaded_module_name]->initialize();
    $sn_module_list[$sn_module[$loaded_module_name]->manifest['package']][$loaded_module_name] = &$sn_module[$loaded_module_name];
  } else {
    unset($sn_module[$loaded_module_name]);
  }
}

// Скрипач не нужон
unset($load_order);
unset($sn_req);

// А теперь проверяем - поддерживают ли у нас загруженный код такую страницу
// TODO - костыль, что бы работали старые модули. Убрать!
if(is_array($sn_data['pages'])) {
  $sn_mvc['pages'] = array_merge($sn_mvc['pages'], $sn_data['pages']);
}
if(!isset($sn_mvc['pages'][$sn_page_name])) {
  $sn_page_name = '';
}

$lang->lng_switch(sys_get_param_str('lang'));


if(classSupernova::$config->server_updater_check_auto && classSupernova::$config->server_updater_check_last + classSupernova::$config->server_updater_check_period <= SN_TIME_NOW) {
  \Tools\VersionCheckerDeprecated::performCheckVersion();
}

if(classSupernova::$config->user_birthday_gift && SN_TIME_NOW - classSupernova::$config->user_birthday_celebrate > PERIOD_DAY) {
  require_once(SN_ROOT_PHYSICAL . 'includes/includes/user_birthday_celebrate.php');
  sn_user_birthday_celebrate();
}

if(!classSupernova::$config->var_online_user_count || classSupernova::$config->var_online_user_time + 30 < SN_TIME_NOW) {
  classSupernova::$config->db_saveItem('var_online_user_count', db_user_count(true));
  classSupernova::$config->db_saveItem('var_online_user_time', SN_TIME_NOW);
  if(classSupernova::$config->server_log_online) {
    doquery("INSERT IGNORE INTO {{log_users_online}} SET online_count = " . classSupernova::$config->var_online_user_count . ";");
  }
}




global $user;
$result = classSupernova::$auth->login();

global $account_logged_in;
$account_logged_in = !empty(classSupernova::$auth->account) && $result[F_LOGIN_STATUS] == LOGIN_SUCCESS;

$user = !empty($result[F_USER]) ? $result[F_USER] : false;

unset($result[F_USER]);
$template_result += $result;
unset($result);
// В этой точке пользователь либо авторизирован - и есть его запись - либо пользователя гарантированно нет в базе

$template_result[F_ACCOUNT_IS_AUTHORIZED] = $sys_user_logged_in = !empty($user) && isset($user['id']) && $user['id'];

if(!empty($user['id'])) {
  classSupernova::$user_options->user_change($user['id']);
}

// Если сообщение пустое - заполняем его по коду
$template_result[F_LOGIN_MESSAGE] =
  !empty($template_result[F_LOGIN_MESSAGE])
    ? $template_result[F_LOGIN_MESSAGE]
    : ($template_result[F_LOGIN_STATUS] != LOGIN_UNDEFINED
        ? $lang['sys_login_messages'][$template_result[F_LOGIN_STATUS]]
        : false
      );

if($template_result[F_LOGIN_STATUS] == LOGIN_ERROR_USERNAME_RESTRICTED_CHARACTERS) {
  $prohibited_characters = array_map(function($value) {
    return "'" . htmlentities($value, ENT_QUOTES, 'UTF-8') . "'";
  }, str_split(LOGIN_REGISTER_CHARACTERS_PROHIBITED));
  $template_result[F_LOGIN_MESSAGE] .= implode(', ', $prohibited_characters);
}


if(defined('DEBUG_AUTH') && DEBUG_AUTH && !defined('IN_AJAX')) {
  pdump('Отключи отладку перед продакшном!');
}


// Это уже переключаемся на пользовательский язык с откатом до языка в параметрах запроса
$lang->lng_switch(sys_get_param_str('lang'));

classSupernova::$config->db_loadItem('game_disable') == GAME_DISABLE_INSTALL
  ? define('INSTALL_MODE', GAME_DISABLE_INSTALL)
  : false;

StatUpdateLauncher::unlock();

if($template_result[F_GAME_DISABLE] = classSupernova::$config->game_disable) {
  $template_result[F_GAME_DISABLE_REASON] = HelperString::nl2br(
    classSupernova::$config->game_disable == GAME_DISABLE_REASON
      ? classSupernova::$config->game_disable_reason
      : $lang['sys_game_disable_reason'][classSupernova::$config->game_disable]
  );
  if(defined('IN_API')) {
    return;
  }

  if(
    ($user['authlevel'] < 1 || !(defined('IN_ADMIN') && IN_ADMIN))
    &&
    !(defined('INSTALL_MODE') && defined('LOGIN_LOGOUT'))
  ) {
    messageBox($template_result[F_GAME_DISABLE_REASON], classSupernova::$config->game_name, '', 5, false);
    ob_end_flush();
    die();
  }
}

// TODO ban
// TODO $skip_ban_check
if($template_result[F_BANNED_STATUS] && !$skip_ban_check) {
  if(defined('IN_API')) {
    return;
  }

  $bantime = date(FMT_DATE_TIME, $template_result[F_BANNED_STATUS]);
  // TODO: Add ban reason. Add vacation time. Add message window
  messageBox("{$lang['sys_banned_msg']} {$bantime}", $lang['ban_title']);
  die("{$lang['sys_banned_msg']} {$bantime}");
}

// TODO !!! Просто $allow_anonymous используется в платежных модулях !!!
$allow_anonymous = $allow_anonymous || (isset($sn_page_data['allow_anonymous']) && $sn_page_data['allow_anonymous']);


if($sys_user_logged_in && INITIAL_PAGE == 'login') {
  sys_redirect(SN_ROOT_VIRTUAL . 'overview.php');
} elseif($account_logged_in && !$sys_user_logged_in) { // empty(core_auth::$user['id'])
} elseif(!$allow_anonymous && !$sys_user_logged_in) {
  sys_redirect(SN_ROOT_VIRTUAL . 'login.php');
}

$user_time_diff = playerTimeDiff::user_time_diff_get();
global $time_diff;
define('SN_CLIENT_TIME_DIFF', $time_diff = $user_time_diff[PLAYER_OPTION_TIME_DIFF] + $user_time_diff[PLAYER_OPTION_TIME_DIFF_UTC_OFFSET]);
define('SN_CLIENT_TIME_LOCAL', SN_TIME_NOW + SN_CLIENT_TIME_DIFF);
define('SN_CLIENT_TIME_DIFF_GMT', $user_time_diff[PLAYER_OPTION_TIME_DIFF]); // Разница в GMT-времени между клиентом и сервером. Реальная разница в ходе часов

// ...to controller
!empty($user) && sys_get_param_id('only_hide_news') ? die(nws_mark_read($user)) : false;
!empty($user) && sys_get_param_id('survey_vote') ? die(survey_vote($user)) : false;

!empty($sn_mvc['i18n']['']) ? lng_load_i18n($sn_mvc['i18n']['']) : false;
$sn_page_name && !empty($sn_mvc['i18n'][$sn_page_name]) ? lng_load_i18n($sn_mvc['i18n'][$sn_page_name]) : false;

execute_hooks($sn_mvc['model'][''], $template, 'model', '');

// classSupernova::$config->fleet_update_last
classSupernova::$gc->watchdog->checkConfigTimeDiff(
  'fleet_update_last',
  classSupernova::$config->fleet_update_interval,
  // Promise
  function () {classSupernova::$gc->fleetDispatcher->dispatch();},
  WATCHDOG_TIME_SQL,
  false
);

StatUpdateLauncher::scheduler_process();
