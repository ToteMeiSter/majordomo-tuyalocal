<?php
chdir(dirname(__FILE__) . '/../');
include_once("./config.php"); include_once("./lib/loader.php"); include_once("./lib/threads.php");
set_time_limit(0);
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();
include_once(DIR_MODULES . 'tuyalocal/tuyalocal.class.php');
$m = new tuyalocal(); $m->getConfig();
if (!$m->config['API_POLL']) { echo "Polling period not set\n"; exit; }
$tmp = SQLSelectOne("SELECT ID FROM tuyadevices LIMIT 1");
if (!$tmp['ID']) { echo "No devices found\n"; exit; }
echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;
$latest = 0; $checkEvery = 5;
while (1) {
    setGlobal((str_replace('.php','',basename(__FILE__))) . 'Run', time(), 1);
    if ((time()-$latest) > $checkEvery) { $latest = time(); $m->processCycle(); }
    if (file_exists('./reboot') || IsSet($_GET['onetime'])) { $db->Disconnect(); exit; }
    if (gg('cycle_tuyalocalControl') == 'restart') { setGlobal('cycle_tuyalocalControl',''); $db->Disconnect(); exit; }
    sleep(1);
}
