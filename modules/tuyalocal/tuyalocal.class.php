<?php
/**
 * Tuya Local
 *
 * Локальное управление Tuya-устройствами по LAN (без облака) через tinytuya.
 * Изначально для Inkbird IIC-800 WiFi (категория Tuya `wk` — регулятор температуры),
 * но карта DP расширяема по категориям. Общение с устройством — python-хелпер
 * tuya_helper.py (tinytuya), вызывается из PHP.
 *
 * @package project
 */

class tuyalocal extends module
{
    var $PY     = '/opt/tuyaenv/bin/python';
    var $HELPER = '';

    function __construct()
    {
        $this->name  = "tuyalocal";
        $this->title = "Tuya Local";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->HELPER = DIR_MODULES . $this->name . '/tuya_helper.py';
        $this->checkInstalled();
        $this->getConfig();
    }

    function getConfig()
    {
        parent::getConfig();
        if (!isset($this->config['API_POLL']) || !$this->config['API_POLL']) $this->config['API_POLL'] = 30;
    }

    // -------------------------------------------------------------------------
    // Карта DP по категории Tuya -> объекты MajorDoMo
    // system => dp, type(bool|int|enum|str), scale, set, obj(thermostat|relay:Суффикс), prop, title, [enum]
    // -------------------------------------------------------------------------
    function dpMap($category)
    {
        if ($category == 'wk') { // регулятор температуры (IIC-800)
            return array(
                'current_temp' => array('dp'=>24,'type'=>'int', 'scale'=>10,'set'=>0,'obj'=>'thermostat',    'prop'=>'value',             'title'=>'Текущая t°'),
                'target_temp'  => array('dp'=>16,'type'=>'int', 'scale'=>1, 'set'=>1,'obj'=>'thermostat',    'prop'=>'currentTargetValue','title'=>'Уставка','min'=>5,'max'=>90),
                'power'        => array('dp'=>1, 'type'=>'bool','scale'=>1, 'set'=>1,'obj'=>'relay:Питание', 'prop'=>'status',            'title'=>'Питание'),
                'mode'         => array('dp'=>2, 'type'=>'enum','scale'=>1, 'set'=>1,'obj'=>'thermostat',    'prop'=>'mode',              'title'=>'Режим','enum'=>array('auto','manual')),
                'upper_temp'   => array('dp'=>19,'type'=>'int', 'scale'=>1, 'set'=>1,'obj'=>'thermostat',    'prop'=>'upper_temp',        'title'=>'Верхний предел','min'=>30,'max'=>90),
                'correction'   => array('dp'=>27,'type'=>'int', 'scale'=>1, 'set'=>1,'obj'=>'thermostat',    'prop'=>'correction',        'title'=>'Калибровка','min'=>-9,'max'=>9),
                'sensor'       => array('dp'=>43,'type'=>'enum','scale'=>1, 'set'=>1,'obj'=>'thermostat',    'prop'=>'sensor',            'title'=>'Датчик','enum'=>array('in','out')),
                'frost'        => array('dp'=>10,'type'=>'bool','scale'=>1, 'set'=>1,'obj'=>'relay:Антизаморозка','prop'=>'status',       'title'=>'Антизаморозка'),
                'work'         => array('dp'=>36,'type'=>'str', 'scale'=>1, 'set'=>0,'obj'=>'thermostat',    'prop'=>'work_state',        'title'=>'Выход'),
            );
        }
        return array();
    }

    // -------------------------------------------------------------------------
    // Вызов python-хелпера (tinytuya)
    // -------------------------------------------------------------------------
    function helperExec($args)
    {
        $cmd = escapeshellarg($this->PY) . ' ' . escapeshellarg($this->HELPER);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
        $out = shell_exec($cmd . ' 2>/dev/null');
        $res = json_decode(trim((string)$out), true);
        return is_array($res) ? $res : null;
    }

    function devStatus($d)
    {
        return $this->helperExec(array('status', $d['IP'], $d['DEV_ID'], $d['LOCAL_KEY'], $d['VERSION']));
    }

    function devSet($d, $dp, $value, $type)
    {
        return $this->helperExec(array('set', $d['IP'], $d['DEV_ID'], $d['LOCAL_KEY'], $d['VERSION'], $dp, $value, $type));
    }

    // -------------------------------------------------------------------------
    // Опрос
    // -------------------------------------------------------------------------
    function refreshDevices()
    {
        foreach (SQLSelect("SELECT * FROM tuyadevices") as $d) {
            $st = $this->devStatus($d);
            if (!$st || !isset($st['dps']) || !is_array($st['dps'])) {
                $this->log("No status from " . $d['NAME'] . " (" . $d['IP'] . ")");
                continue;
            }
            $this->processDeviceData($d, $st['dps']);
        }
        $unlinked = SQLSelect("SELECT DISTINCT DEVICE_ID FROM tuyacommands WHERE LINKED_OBJECT=''");
        foreach ($unlinked as $row) $this->autoCreateDevices($row['DEVICE_ID']);
    }

    function processDeviceData($d, $dps)
    {
        $map = $this->dpMap($d['CATEGORY']);
        $commands = array();
        foreach ($map as $system => $m) {
            $dp = (string)$m['dp'];
            if (!array_key_exists($dp, $dps)) continue;
            $raw = $dps[$dp];
            $val = $raw;
            if ($m['type'] == 'bool') $val = $raw ? 1 : 0;
            elseif ($m['type'] == 'int' && ($m['scale'] ?? 1) > 1) $val = round($raw / $m['scale'], 1);
            $commands[] = array('SYSTEM'=>$system, 'DP'=>$m['dp'], 'TITLE'=>$d['NAME'].' '.$m['title'], 'VALUE'=>$val);
        }
        $this->processCommandsArray($d['ID'], $commands);
    }

    function processCommandsArray($device_id, $commands)
    {
        foreach ($commands as $c) {
            if (!$c['SYSTEM']) continue;
            $rec = SQLSelectOne("SELECT * FROM tuyacommands WHERE SYSTEM='" . DBSafe($c['SYSTEM']) . "' AND DEVICE_ID=" . (int)$device_id);
            $changed = (!isset($rec['ID']) || $rec['VALUE'] != (string)$c['VALUE']);
            foreach ($c as $k => $v) $rec[$k] = $v;
            $rec['DEVICE_ID'] = $device_id;
            $rec['VALUE'] = (string)$c['VALUE'];
            if ($changed) $rec['UPDATED'] = date('Y-m-d H:i:s');
            if (!$rec['ID']) $rec['ID'] = SQLInsert('tuyacommands', $rec);
            else SQLUpdate('tuyacommands', $rec);
            if ($rec['LINKED_OBJECT'] && $rec['LINKED_PROPERTY']) {
                setGlobal($rec['LINKED_OBJECT'] . '.' . $rec['LINKED_PROPERTY'], $rec['VALUE'], array($this->name => '0'));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Авто-создание объектов
    // -------------------------------------------------------------------------
    function autoCreateDevices($device_db_id)
    {
        require_once(DIR_MODULES . 'devices/devices.class.php');
        $devices_mod = new devices();
        $devices_mod->setDictionary();

        $did = (int)$device_db_id;
        $d = SQLSelectOne("SELECT * FROM tuyadevices WHERE ID=$did");
        if (!$d['ID']) return;
        $name = $d['NAME'];
        $map = $this->dpMap($d['CATEGORY']);

        $thermo = $this->createOrGetLinkedObject($devices_mod, 'thermostat', 'Inkbird ' . $name);
        $relays = array();

        foreach ($map as $system => $m) {
            $cmd = SQLSelectOne("SELECT * FROM tuyacommands WHERE DEVICE_ID=$did AND SYSTEM='" . DBSafe($system) . "'");
            if (!$cmd['ID'] || $cmd['LINKED_OBJECT']) continue;

            if ($m['obj'] === 'thermostat') {
                $obj = $thermo;
            } elseif (strpos($m['obj'], 'relay:') === 0) {
                $suffix = substr($m['obj'], 6);
                if (!isset($relays[$suffix])) $relays[$suffix] = $this->createOrGetLinkedObject($devices_mod, 'relay', 'Inkbird ' . $name . ' ' . $suffix);
                $obj = $relays[$suffix];
            } else continue;
            if (!$obj) continue;

            SQLExec("UPDATE tuyacommands SET LINKED_OBJECT='" . DBSafe($obj) . "', LINKED_PROPERTY='" . DBSafe($m['prop']) . "' WHERE ID=" . (int)$cmd['ID']);
            setGlobal($obj . '.' . $m['prop'], $cmd['VALUE'], array($this->name => '0'));
            if (!empty($m['set'])) addLinkedProperty($obj, $m['prop'], $this->name);
        }
        $pcmd = SQLSelectOne("SELECT VALUE FROM tuyacommands WHERE DEVICE_ID=$did AND SYSTEM='power'");
        if ($thermo && isset($pcmd['VALUE'])) setGlobal($thermo . '.relay_status', $pcmd['VALUE'], array($this->name => '0'));
    }

    function createOrGetLinkedObject($devices_mod, $type, $title)
    {
        $e = SQLSelectOne("SELECT LINKED_OBJECT FROM devices WHERE TITLE='" . DBSafe($title) . "' AND TYPE='" . DBSafe($type) . "' AND LINKED_OBJECT != ''");
        if ($e['LINKED_OBJECT']) return $e['LINKED_OBJECT'];
        $devices_mod->addDevice($type, array('TITLE' => $title));
        $n = SQLSelectOne("SELECT LINKED_OBJECT FROM devices WHERE TITLE='" . DBSafe($title) . "' AND TYPE='" . DBSafe($type) . "' AND LINKED_OBJECT != ''");
        return $n['LINKED_OBJECT'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Управление
    // -------------------------------------------------------------------------
    function propertySetHandle($object, $property, $value)
    {
        $this->getConfig();
        $rows = SQLSelect("SELECT * FROM tuyacommands WHERE LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND LINKED_PROPERTY LIKE '" . DBSafe($property) . "'");
        foreach ($rows as $cmd) $this->writeDeviceCommand($cmd['DEVICE_ID'], $cmd['SYSTEM'], $value);
    }

    function writeDeviceCommand($device_id, $system, $value)
    {
        $d = SQLSelectOne("SELECT * FROM tuyadevices WHERE ID=" . (int)$device_id);
        if (!$d['ID']) return;
        $map = $this->dpMap($d['CATEGORY']);
        if (!isset($map[$system]) || empty($map[$system]['set'])) return;
        $m = $map[$system];

        if ($m['type'] == 'bool') {
            $sv = strtolower((string)$value);
            $val = ($value && $sv !== '0' && $sv !== 'false' && $sv !== 'off') ? 'true' : 'false';
            $type = 'bool';
        } elseif ($m['type'] == 'int') {
            $t = (int)round((float)$value * ($m['scale'] ?? 1));
            if (isset($m['min']) && $t < $m['min']) $t = $m['min'];
            if (isset($m['max']) && $t > $m['max']) $t = $m['max'];
            $val = (string)$t; $type = 'int';
        } elseif ($m['type'] == 'enum') {
            if (!in_array((string)$value, $m['enum'], true)) { $this->log("enum $system: $value вне диапазона"); return; }
            $val = (string)$value; $type = 'str';
        } else {
            $val = (string)$value; $type = 'str';
        }
        $this->log("SET {$d['NAME']} dp{$m['dp']}($system)=$val");
        return $this->devSet($d, $m['dp'], $val, $type);
    }

    function processCycle()
    {
        $this->getConfig();
        $latest = (int)$this->cycle_time;
        if ((time() - $latest) > (int)$this->config['API_POLL']) {
            $this->cycle_time = time();
            $this->refreshDevices();
        }
    }

    function log($msg)
    {
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        DebMes($msg, 'tuyalocal');
    }

    // -------------------------------------------------------------------------
    // Админка / HTTP-эндпоинты
    // -------------------------------------------------------------------------
    function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->view_mode)) $p['view_mode'] = $this->view_mode;
        if (isset($this->tab)) $p['tab'] = $this->tab;
        return parent::saveParams($p);
    }

    function run()
    {
        $out = array();
        $this->admin($out);
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out)
    {
        global $session;
        // HTTP-управление любым свойством (enum/int) и для дашбордов:
        // /ajax/tuyalocal.html?op=set&id=<device_id>&system=<mode|sensor|upper_temp|...>&value=<v>
        if (gr('op') == 'set') {
            header('Content-Type: application/json; charset=utf-8');
            $id = (int)gr('id');
            $system = preg_replace('/[^a-z_]/', '', gr('system'));
            $value = gr('value');
            $ok = 0;
            $d = SQLSelectOne("SELECT ID,CATEGORY FROM tuyadevices WHERE ID=$id");
            if ($id && $system && $d['ID'] && isset($this->dpMap($d['CATEGORY'])[$system])) {
                $r = $this->writeDeviceCommand($id, $system, $value);
                $ok = ($r && isset($r['dps'])) ? 1 : 0;
            }
            echo json_encode(array('ok' => $ok, 'id' => $id, 'system' => $system, 'value' => $value), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (gr('op') == 'list') {
            header('Content-Type: application/json; charset=utf-8');
            $res = array();
            foreach (SQLSelect("SELECT * FROM tuyadevices ORDER BY NAME") as $d) {
                $links = array();
                foreach (SQLSelect("SELECT SYSTEM,LINKED_OBJECT,LINKED_PROPERTY FROM tuyacommands WHERE DEVICE_ID=" . (int)$d['ID'] . " AND LINKED_OBJECT!=''") as $c)
                    $links[$c['SYSTEM']] = array('object' => $c['LINKED_OBJECT'], 'property' => $c['LINKED_PROPERTY']);
                $res[] = array('id' => $d['ID'], 'name' => $d['NAME'], 'category' => $d['CATEGORY'], 'links' => $links);
            }
            echo json_encode(array('devices' => $res), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($this->view_mode == 'refresh') { $this->refreshDevices(); $this->redirect("?"); }
        if ($this->view_mode == 'update_settings') {
            $poll = (int)gr('api_poll', 'int');
            if ($poll >= 10) $this->config['API_POLL'] = $poll;
            $this->saveConfig();
            $this->redirect("?");
        }
        // Добавление/редактирование устройства Tuya (IP/Device ID/Local Key/версия)
        if ($this->view_mode == 'device_save') {
            $id = (int)gr('f_id');
            $rec = $id ? SQLSelectOne("SELECT * FROM tuyadevices WHERE ID=$id") : array();
            $rec['NAME']     = trim(gr('f_name'));
            $rec['CATEGORY'] = preg_replace('/[^a-z0-9_]/', '', strtolower(trim(gr('f_category'))));
            $rec['IP']       = preg_replace('/[^0-9.]/', '', gr('f_ip'));
            $rec['DEV_ID']   = preg_replace('/[^A-Za-z0-9]/', '', gr('f_devid'));
            $rec['VERSION']  = preg_replace('/[^0-9.]/', '', gr('f_version'));
            $lk = trim(gr('f_localkey'));
            if ($lk !== '') $rec['LOCAL_KEY'] = $lk; // при редактировании пустое поле = ключ не менять
            if ($rec['NAME'] != '' && $rec['IP'] != '' && $rec['DEV_ID'] != '' && !empty($rec['LOCAL_KEY'])) {
                if ($id) SQLUpdate('tuyadevices', $rec);
                else SQLInsert('tuyadevices', $rec);
            }
            $this->redirect("?");
        }
        if ($this->view_mode == 'device_delete') {
            $id = (int)gr('id');
            if ($id) {
                SQLExec("DELETE FROM tuyadevices WHERE ID=" . $id);
                SQLExec("DELETE FROM tuyacommands WHERE DEVICE_ID=" . $id);
            }
            $this->redirect("?");
        }
        $out['API_POLL'] = $this->config['API_POLL'];
        $out['DEVICES'] = SQLSelect("SELECT ID,NAME,CATEGORY,IP,DEV_ID,VERSION FROM tuyadevices ORDER BY NAME");
        $out['DEVICES_COUNT'] = count($out['DEVICES']);
        // префилл формы (режим редактирования при ?edit=ID)
        $ed = array();
        $eid = (int)gr('edit');
        if ($eid) $ed = SQLSelectOne("SELECT * FROM tuyadevices WHERE ID=" . $eid);
        $out['F_ID']       = isset($ed['ID']) ? (int)$ed['ID'] : 0;
        $out['EDITING']    = isset($ed['ID']) ? 1 : 0;
        $out['F_NAME']     = htmlspecialchars(isset($ed['NAME']) ? $ed['NAME'] : '');
        $out['F_CATEGORY'] = htmlspecialchars(isset($ed['CATEGORY']) ? $ed['CATEGORY'] : 'wk');
        $out['F_IP']       = htmlspecialchars(isset($ed['IP']) ? $ed['IP'] : '');
        $out['F_DEVID']    = htmlspecialchars(isset($ed['DEV_ID']) ? $ed['DEV_ID'] : '');
        $out['F_VERSION'] = (isset($ed['VERSION']) && $ed['VERSION']) ? $ed['VERSION'] : '3.3';
    }

    function usual(&$out) { $this->admin($out); }

    // -------------------------------------------------------------------------
    function install($data = '') { parent::install(); }

    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS tuyadevices');
        SQLExec('DROP TABLE IF EXISTS tuyacommands');
        parent::uninstall();
    }

    function dbInstall($data)
    {
        $data = <<<EOD
 tuyadevices: ID int(10) unsigned NOT NULL auto_increment
 tuyadevices: NAME varchar(100) NOT NULL DEFAULT ''
 tuyadevices: DEV_ID varchar(64) NOT NULL DEFAULT ''
 tuyadevices: LOCAL_KEY varchar(64) NOT NULL DEFAULT ''
 tuyadevices: IP varchar(40) NOT NULL DEFAULT ''
 tuyadevices: VERSION varchar(8) NOT NULL DEFAULT '3.3'
 tuyadevices: CATEGORY varchar(20) NOT NULL DEFAULT ''

 tuyacommands: ID int(10) unsigned NOT NULL auto_increment
 tuyacommands: DEVICE_ID int(10) NOT NULL DEFAULT '0'
 tuyacommands: DP int(10) NOT NULL DEFAULT '0'
 tuyacommands: SYSTEM varchar(40) NOT NULL DEFAULT ''
 tuyacommands: TITLE varchar(150) NOT NULL DEFAULT ''
 tuyacommands: VALUE varchar(255) NOT NULL DEFAULT ''
 tuyacommands: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 tuyacommands: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 tuyacommands: UPDATED datetime
EOD;
        parent::dbInstall($data);
    }
}
