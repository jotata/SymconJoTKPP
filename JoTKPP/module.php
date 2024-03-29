<?php

declare(strict_types=1);
/**
 * @Package:         JoTKPP
 * @File:            module.php
 * @Create Date:     09.07.2020 16:54:15
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   07.07.2023 12:56:57
 * @Modified By:     Jonathan Tanner
 * @Copyright:       Copyright(c) 2020 by JoT Tanner
 * @License:         Creative Commons Attribution Non Commercial Share Alike 4.0
 *                   (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */
require_once __DIR__ . '/../libs/JoT_Traits.php'; //Bibliothek mit allgemeinen Definitionen & Traits
require_once __DIR__ . '/../libs/JoT_ModBus.php'; //Bibliothek für ModBus-Integration

/**
 * JoTKPP ist die Unterklasse für die Integration eines Kostal Wechselrichters PLENTICORE plus.
 * Erweitert die Klasse JoTModBus, welche die ModBus- sowie die Modul-Funktionen zur Verfügung stellt.
 */
class JoTKPP extends JoTModBus {
    use VariableProfile;
    use Translation;
    use RequestAction;
    use ModuleInfo;
    protected const PREFIX = 'JoTKPP';
    protected const MODULEID = '{E64278F5-1942-5343-E226-8673886E2D05}';
    protected const STATUS_Error_WrongDevice = 416;
    protected const LED_Off = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUAQMAAAC3R49OAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAADUExURcPDw9YpAkQAAAAJcEhZcwAAFiQAABYkAZsVxhQAAAANSURBVBjTY6AqYGAAAABQAAGwhtz8AAAAAElFTkSuQmCC';
    protected const LED_Read = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAAFiUAABYlAUlSJPAAAAA3SURBVDhPpcexDQAwCMAw/n+a7p6IKnnxzH7wiU984hOf+MQnPvGJT3ziE5/4xCc+8YlP/N3OA6M/joCROxOnAAAAAElFTkSuQmCC';
    protected const LED_Write = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAAFiUAABYlAUlSJPAAAAAiSURBVDhPY/zPQD5ggtJkgVHNJIJRzSSCUc0kgiGpmYEBACKcASfOmBk0AAAAAElFTkSuQmCC';

    /**
     * Interne Funktion des SDK.
     * Initialisiert Properties, Attributes und Timer.
     * @access public
     */
    public function Create() {
        parent::Create();
        $this->ConfigProfiles(__DIR__ . '/ProfileConfig.json', ['$VT_Float' => self::VT_Float, '$VT_Integer' => self::VT_Integer]);
        $this->RegisterAttributeString('PollIdents', '');
        $this->RegisterAttributeInteger('MBType', self::MB_LittleEndian_ByteSwap);
        $this->RegisterPropertyInteger('PollTime', 0);
        $this->RegisterPropertyInteger('CheckFWTime', 0);
        $this->RegisterTimer('RequestRead', 0, static::PREFIX . '_RequestRead($_IPS["TARGET"]);');
        $this->RegisterTimer('CheckFW', 0, static::PREFIX . '_CheckFirmwareUpdate($_IPS["TARGET"]);');
        $this->RegisterMessage($this->InstanceID, IM_CONNECT); //Instanz verfügbar
        $this->SetBuffer('RequestReadType', 'Group');
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn die Konfigurations-Änderungen gespeichet werden.
     * @access public
     */
    public function ApplyChanges() {
        parent::ApplyChanges();

        //Variablen initialisieren
        $fwVersion = @floatval(json_decode($this->GetBuffer('DeviceInfo'), true)['FWVersion']); //Falls noch nicht initialisiert wird 0 zurückgegeben
        $mbConfig = $this->GetModBusConfig();
        if (array_search('TempPollIdents', $this->GetBufferList()) === false) { //Buffer 'TempPollIdents' ist nicht initialisiert (GetConfigurationForm() wurde nie aufgerufen)
            $pollIdents = explode(' ', $this->ReadAttributeString('PollIdents'));
        } else {
            $pollIdents = explode(' ', $this->GetBuffer('TempPollIdents'));
        }
        if ($pollIdents[0] === '' || $pollIdents[0] === 'NONE') {
            unset($pollIdents[0]);
        }
        $groups = array_values(array_unique(array_column($mbConfig, 'Group')));
        $vars = [];

        //Instanz-Variablen vorbereiten (Reihenfolge beachten, damit nicht mehr vorhandene Konfigurationen entfernt werden)...
        //1. Poll-Variablen
        foreach ($pollIdents as $ident) {
            $vars[$ident] = true;
        }
        //2. Bestehende Instanz-Variablen
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $cId) {
            if (IPS_VariableExists($cId)) { //Child ist Variable
                $ident = IPS_GetObject($cId)['ObjectIdent'];
                if ($ident !== '') {//Nur Instanz-Variablen verarbeiten
                    $vars[$ident] = true;
                    //Wenn in PollIdents ODER ModBusConfig nicht mehr vorhanden - löschen
                    //Wenn in aktueller FW-Version nicht vorhanden - löschen
                    //Wenn bei einem Restart von IPS ev. die GW-Instanz noch nicht verfügabr ist, ist $fwVersion noch 0 und Verfügbarkeit kann/darf nicht überprüft werden, sonst wird Variable immer gelöscht.
                    if (array_search($ident, $pollIdents) === false || ($fwVersion > 0 && $this->IsIdentAvailable($ident) === false)) {
                        $vars[$ident] = false;
                    }
                }
            }
        }
        //Instanz-Variablen erstellen / löschen / aktualisieren
        foreach ($vars as $ident => $keep) {
            if (substr($ident, 0, 1) === '_') { //Instanz-Variablen, welche nicht aus ModBusConfig kommen beginnen mit '_'
                continue; //Diese werden anders gepflegt
            }
            $name = '';
            $varType = 0;
            $profile = '';
            $position = 0;
            if ($keep) { //Folgende Werte werden durch MaintainVariable() nur bei neuen Variablen angewendet
                $name = $mbConfig[$ident]['Name'];
                $varType = $this->GetIPSVarType($mbConfig[$ident]['VarType'], $mbConfig[$ident]['Factor']);
                $profile = $this->CheckProfileName($mbConfig[$ident]['Profile']);
                $position = array_search($mbConfig[$ident]['Group'], $groups) * 20 + 20; //*20, damit User innerhalb der Gruppen-Position auch sortieren kann - +20, damit Events zuoberst sind
            }
            $this->MaintainVariable($ident, $name, $varType, $profile, $position, $keep);
            if ($keep) { //Darf nicht aufgerufen werden, wenn Instanz-Variable gelöscht wurde, da $ident in $mbConfig ev. nicht mehr existiert
                $this->MaintainAction($ident, array_key_exists('WFunction', $mbConfig[$ident])); //Gültigkeit der WFunction wird bereits mit ModulTests überprüft
            }
        }

        //Poll-Idents definitiv speichern
        $this->WriteAttributeString('PollIdents', implode(' ', $pollIdents));

        //Timer für Polling (de)aktivieren
        if ($this->ReadPropertyInteger('PollTime') > 0 && count($pollIdents) > 0) {
            $this->SetTimerInterval('RequestRead', $this->ReadPropertyInteger('PollTime') * 1000);
        } else {
            $this->SetTimerInterval('RequestRead', 0);
        }

        //Timer für FW-Updates (de)aktivieren
        if ($this->ReadPropertyInteger('CheckFWTime') > 0) {
            $this->SetTimerInterval('CheckFW', $this->ReadPropertyInteger('CheckFWTime') * 60 * 60 * 1000);
            $this->CheckFirmwareUpdate();
        } else {
            $this->SetTimerInterval('CheckFW', 0);
            $this->UnregisterVariable('_CurrentFWOnline');
        }
    }

    /**
     * Interne Funktion des SDK.
     * Stellt Informationen für das Konfigurations-Formular zusammen
     * @return string JSON-Codiertes Formular
     * @access public
     */
    public function GetConfigurationForm() {
        $values = [];
        $device = $this->GetDeviceInfo();
        $fwVersion = @floatval($device['FWVersion']); //= 0.00 falls nicht definiert
        $pollIdents = $this->ReadAttributeString('PollIdents');
        $this->SetBuffer('TempPollIdents', $pollIdents);
        $mbConfig = $this->GetModBusConfig();
        $variable = [];
        $eid = 1;
        //Values für IdentList vorbereiten
        foreach ($mbConfig as $ident => $config) {
            if (array_key_exists($config['Group'], $values) === false) {//Gruppe exstiert im Tree noch nicht
                $values[$config['Group']] = ['Group' => $config['Group'], 'Ident' => '', 'id' => $eid++, 'parent' => 0, 'rowColor' => '#DFDFDF', 'expanded' => false, 'editable' => false, 'deletable' => false];
            }
            $variable['parent'] = $values[$config['Group']]['id'];
            $variable['id'] = $eid++;
            $variable['Group'] = $config['Group'];
            $variable['Ident'] = $ident;
            $variable['Name'] = $config['Name'];
            if ($config['Address'] === 0) { //Berechnete Werte
                $variable['Name'] = '*' . $config['Name'];
            }
            $variable['cName'] = '';
            $variable['Profile'] = $this->CheckProfileName($config['Profile']); //Damit wird der PREFIX immer davor hinzugefügt
            $variable['cProfile'] = '';
            $variable['Access'] = '';
            if (array_key_exists('RFunction', $config) || $config['Address'] === 0) {
                $variable['Access'] .= 'R';
            }
            if (array_key_exists('WFunction', $config)) {
                $variable['Access'] .= 'W';
            }
            $variable['FWVersion'] = floatval($config['FWVersion']);
            $variable['Poll'] = false;
            if (strpos(" $pollIdents ", " $ident ") !== false) {//Aktivierte Idents markieren und Gruppe öffnen
                $variable['Poll'] = true;
                $values[$config['Group']]['expanded'] = true;
            }
            if (($id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID)) !== false) {//Falls Variable bereits existiert, deren Werte übernehmen
                $obj = IPS_GetObject($id);
                $var = IPS_GetVariable($id);
                if ($obj['ObjectName'] != $config['Name']) {
                    $variable['cName'] = $obj['ObjectName'];
                }
                $variable['Pos'] = $obj['ObjectPosition'];
                $variable['cProfile'] = $var['VariableCustomProfile'];
            }

            //Nur Variablen aktivieren, welche mit der aktuellen FW abrufbar sind
            $variable['deletable'] = false;
            $variable['editable'] = true;
            if ($fwVersion < $variable['FWVersion']) {
                $variable['editable'] = false;
                $variable['Poll'] = false;
            }

            $values[$ident] = $variable;
        }

        //Variabeln in $form ersetzen
        $form = file_get_contents(__DIR__ . '/form.json');
        $form = $this->AddModuleInfoAsElement($form);
        $form = str_replace('$DeviceString', $device['String'], $form);
        $form = str_replace('"$DeviceNoError"', $this->ConvertToBoolStr($device['Error'], true), $form); //Visible für 'DeviceInfo' setzen
        $diValues = [];
        foreach ($device as $ident => $value) { //DeviceInfo aufbereiten
            if ($ident != 'String' && $ident != 'Error') {
                $diValues[] = ['Name' => $ident, 'Value' => $value];
            }
        }
        $form = str_replace('"$DeviceInfoValues"', json_encode($diValues), $form); //Values für 'DeviceInfos' setzen
        $form = str_replace('$ColumnNameCaption', $this->Translate('Name') . ' (* = ' . $this->Translate('calculated value') . ')', $form); //Caption für Spalte Name in 'IdentList' setzen
        $form = str_replace('"$IdentListValues"', json_encode(array_values($values)), $form); //Values für 'IdentList' setzen
        $form = str_replace('$RequestReadCaption', static::PREFIX . '_RequestRead' . $this->GetBuffer('RequestReadType'), $form); //Caption für 'RequestRead' setzen
        $form = str_replace('$RequestReadValue', $this->GetBuffer('RequestReadValue'), $form); //Value für 'RequestRead' setzen
        $form = str_replace('$EventCreated', $this->Translate('Event was created. Please check/change settings.'), $form); //Übersetzungen einfügen
        return $form;
    }

    /**
     * Liest Informationen zur Geräte-Erkennung vom Gerät aus und aktualisiert diese im Formular
     * @access public
     * @return array mit Geräte-Informationen oder Fehlermeldung
     */
    public function GetDeviceInfo() {
        $this->UpdateFormField('DeviceInfoValues', 'visible', false);
        $this->UpdateFormField('ReadNow', 'enabled', false);
        $device = json_decode($this->GetBuffer('DeviceInfo'), true); //Aktuell bekannte Geräte-Parameter aus Cache holen

        //Prüfen ob es sich um einen Kostal Wechselrichter handelt
        $read = $this->Translate('Reading device information...');
        $this->UpdateFormField('DeviceInfo', 'caption', $read . '(Manufacturer)');
        $mfc = $this->RequestReadIdent('Manufacturer');
        if (is_null($mfc) || $mfc !== 'KOSTAL') {
            $device['String'] = $this->Translate('Device information could not be read. Gateway settings correct?');
            $device['Error'] = true;
            $this->UpdateFormField('DeviceInfo', 'caption', $device['String']);
            $this->LogMessage($device['String'] . " Manufacturer: '$mfc'", KL_ERROR);
            if ($this->GetStatus() == self::STATUS_Ok_InstanceActive) { //ModBus-Verbindung OK, aber falsche Antwort
                $this->SetStatus(self::STATUS_Error_WrongDevice);
            }
            return $device;
        }

        //SerienNr lesen
        $this->UpdateFormField('DeviceInfo', 'caption', $read . '(SerialNr)');
        $serialNr = $this->RequestReadIdent('SerialNr');
        if (is_null($device) || $device['SerialNr'] !== $serialNr) {//Neue SerienNr/Gerät - Werte neu einlesen
            $device = ['Manufacturer' => $mfc, 'Error' => false];
        }
        $device['SerialNr'] = $serialNr;

        //Folgende Werte könnten ändern, daher immer auslesen
        $this->UpdateFormField('DeviceInfo', 'caption', $read . '(SoftwareVersionMC)');
        $device['FWVersion'] = $this->RequestReadIdent('SoftwareVersionMC');
        $this->SetBuffer('DeviceInfo', json_encode($device)); //FW-Version sofort in Buffer schreiben, damit diese für die restlichen Werte zur Verfügung steht
        $this->UpdateFormField('DeviceInfo', 'caption', $read . '(NetworkName)');
        $device['NetworkName'] = $this->RequestReadIdent('NetworkName');

        //unbekannte Werte vom Gerät auslesen
        $idents = ['ProductName', 'PowerClass', 'BTReadyFlag', 'SensorType', 'BTType', 'BTGrossCapacity', 'BTWorkCapacity', 'BTManufacturer', 'NumberPVStrings', 'HardwareVersion'];
        foreach ($idents as $ident) {
            if (!array_key_exists($ident, $device) || is_null($device[$ident])) {
                $this->UpdateFormField('DeviceInfo', 'caption', $read . "($ident)");
                $device[$ident] = $this->RequestReadIdent($ident);
            }
        }

        $device['String'] = $device['Manufacturer'] . ' ' . $device['ProductName'] . ' ' . $device['PowerClass'] . " ($serialNr) - " . $device['NetworkName'];
        $this->UpdateFormField('DeviceInfo', 'caption', $device['String']);
        $this->UpdateFormField('DeviceInfoValues', 'visible', true);
        $this->UpdateFormField('ReadNow', 'enabled', true);

        $this->SetBuffer('DeviceInfo', json_encode($device)); //Aktuell bekannte Geräte-Parameter im Cache zwischenspeichern
        return $device;
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn eine registrierte Nachricht verfügbar ist.
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $MessageID, $Data) {
        $gwID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $ioID = intval(@IPS_GetInstance($gwID)['ConnectionID']); //=0 wenn $gwID nicht mehr vorhanden ist - wird via FM_CONNECT der Instanz korrigiert, sobald User wieder einen gültigen GW einstellt
        $this->SetModBusType();
        if ($MessageID === IM_CONNECT) { //Instanz verfügbar
            $this->SendDebug('Instance ready', '', 0);
            $this->GetDeviceInfo();
        } elseif ($MessageID === FM_CONNECT) { //Gateway / ClientSocket wurde geändert
            $this->SendDebug('Connection changed', "Gateway #$gwID - I/O #$ioID", 0);
            $this->RegisterOnceTimer('GetDeviceInfo', 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
        } elseif ($MessageID === IM_CHANGESETTINGS) { //Einstellungen im Gateway / ClientSocket wurden geändert
            $this->SendDebug('Connection settings changed', "Connection-Instance #$SenderID", 0);
            $this->RegisterOnceTimer('GetDeviceInfo', 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
        }
        if ($MessageID === IM_CONNECT || $MessageID === FM_CONNECT) { //Nachrichten (neu) registrieren
            foreach ($this->GetMessageList() as $id => $msgs) { //alte Nachrichten deaktivieren
                $this->UnregisterMessage($id, FM_CONNECT);
                $this->UnregisterMessage($id, IM_CHANGESETTINGS);
            }
            $this->RegisterMessage($this->InstanceID, FM_CONNECT); //Gateway wurde geändert
            $this->RegisterMessage($gwID, FM_CONNECT); //ClientSocket wurden geändert
            $this->RegisterMessage($gwID, IM_CHANGESETTINGS); //Gateway-Einstellungen wurden geändert
            $this->RegisterMessage($ioID, IM_CHANGESETTINGS); //ClientSocket-Einstellungen wurden geändert
        }
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestRead.
     * Ließt alle/gewünschte Werte aus dem Gerät.
     * @param bool|string optional $force wenn auch nicht gepollte Values gelesen werden sollen.
     * @access public
     * @return array mit den angeforderten Werten, NULL bei Fehler oder Wert wenn nur ein Wert.
     */
    public function RequestRead() {
        $ms = microtime(true);
        $mbConfig = $this->GetModBusConfig();
        $idents = '';
        if (func_num_args() == 1) {//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit PREFIX_Function erstellt wird
            $idents = func_get_arg(0); //true wird über die Funktion RequestReadAll / String mit Idents wird über die Funktion RequestReadIdent/Group aktiviert
        }
        if ($idents === true) { //Aufruf von RequestReadAll
            $idents = array_keys($mbConfig);
        } elseif (strlen(trim($idents)) > 0) {
            $idents = explode(' ', trim($idents));
        } else { //keine Idents angegeben
            $idents = $this->ReadAttributeString('PollIdents');
            if (strlen($idents) > 0) {
                $idents = explode(' ', $idents);
            } else { //Keine aktiven Idents konfiguriert
                $idents = [];
            }
        }

        //ModBus-Abfrage durchführen
        $values = [];
        foreach ($idents as $ident) {
            if ($this->IsIdentAvailable($ident) === false) { //Unbekannter Ident oder falsche FW-Version
                $unknown[] = $ident;
                continue;
            }
            $config = $mbConfig[$ident];
            $vID = @$this->GetIDForIdent($ident);
            if ($config['Address'] === 0) { //Wert berechnen
                $this->SendDebug('RequestRead', "Ident: $ident gets calculated...", 0);
                $value = $this->CalculateValue($ident);
            } elseif (array_key_exists('RFunction', $config)) { //Wert via Cache / ModBus auslesen
                $this->UpdateFormField('StatusLED', 'image', self::LED_Read);
                $value = $this->GetFromCache($ident);
                if (is_null($value)) { //Im Cache nicht vorhanden oder abgelaufen
                    $this->SendDebug('RequestRead', "Ident: $ident on Address: " . $config['Address'], 0);
                    $mbType = $this->ReadAttributeInteger('MBType');
                    if (array_key_exists('MBType', $config)) { //Einige Werte werden nicht gemäss der Standard-Einstellung des Gerätes zurückgegeben (Bugs in Geräte-FW). Dies wird hiermit korrigiert.
                        $mbType = $config['MBType'];
                    }
                    $factor = $config['Factor'];
                    if (array_key_exists('ScaleIdent', $config) && $config['ScaleIdent'] !== '') { //Skalierungs-Faktor mit Factor kombinieren
                        $factor = $config['Factor'] * pow(10, $this->RequestReadIdent($config['ScaleIdent']));
                    }
                    $value = $this->ReadModBus($config['RFunction'], $config['Address'], $config['Quantity'], $factor, $mbType, $config['VarType']);
                    if (is_null($value)) { //Fehler beim Lesen
                        continue;
                    }
                    $this->SetToCache($ident, $value);
                } else { //Wert aus Cache gelesen
                    $vID = false; //Wert nicht in Instanz-Variable zurückschreiben
                    $this->SendDebug('RequestRead', "Ident: $ident from Cache: $value", 0);
                }
            } else { //Kein Read-Access
                if (is_string(@func_get_arg(0)) && @func_get_arg(0) !== '') { //Warnung nur wenn Ident explizit angefordert wurde
                    $noaccess[] = $ident;
                }
                continue;
            }

            if (is_numeric($value) && $value == 0) { //negative 0-Werte als positive darstellen (https://community.symcon.de/t/modul-jotkpp-solar-wechselrichter-kostal-plenticore-plus-piko-iq/50857/161)
                $value = abs($value);
            }
            if ($vID !== false && is_null($value) === false) { //Instanz-Variablen sind nur für Werte mit aktivem Polling vorhanden
                $this->SetValue($ident, $value);
            }
            $values[$ident] = $value;
        }
        if (isset($unknown) > 0) {
            $this->ThrowMessage('Unknown Ident(s): %s', implode(', ', $unknown));
        }
        if (isset($noaccess) > 0) {
            $this->ThrowMessage('No Read-Access for Ident(s): %s', implode(', ', $noaccess));
        }
        $this->UpdateFormField('StatusLED', 'image', self::LED_Off);
        $this->SendDebug('RequestRead', sprintf('Picked out %u idents in %f seconds.', count($values), microtime(true) - $ms), 0);
        switch (count($values)) {
            case 0:
                return null;
            case 1:
                return array_pop($values);
            default:
                return $values;
        }
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestReadAll.
     * Ruft PREFIX_RequestRead($force = true) auf und liest somit alle Werte, egal ob Polling aktiv oder nicht.
     * Dadurch lassen sich Daten abfragen ohne diese in einer Instanz-Variable abzulegen.
     * @access public
     * @return array mit allen Werten.
     */
    public function RequestReadAll() {
        return $this->RequestRead(true);
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestReadIdent.
     * Ruft PREFIX_RequestRead($Ident) auf
     * @param string $Ident - eine mit Leerzeichen getrennte Liste aller zu lesenden Idents.
     * @access public
     * @return array mit den angeforderten Werten.
     */
    public function RequestReadIdent(string $Ident) {
        $Ident = trim($Ident);
        if ($Ident == '') {
            $this->ThrowMessage('Ident(s) can not be empty!');
            return null;
        }
        return $this->RequestRead($Ident);
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestReadGroup.
     * Ruft PREFIX_RequestRead($Ident) mit allen Idents von $Group auf
     * @param string $Group - eine mit Leerzeichen getrennte Liste aller zu lesenden Gruppen.
     * @access public
     * @return array mit den angeforderten Werten.
     */
    public function RequestReadGroup(string $Group) {
        if (trim($Group) == '') {
            $this->ThrowMessage('Group(s) can not be empty!');
            return null;
        }
        $idents = '';
        $mbConfig = $this->GetModBusConfig();
        foreach ($mbConfig as $ident => $config) {
            if (strpos($Group, $config['Group']) !== false && array_key_exists('RFunction', $config)) { //Nur Idents mit Read-Zugriff
                $idents = "$idents $ident";
            }
        }
        $idents = trim($idents);
        if ($idents == '') {
            $this->ThrowMessage('No idents found for group(s) \'%s\'!', $Group);
            return null;
        }
        return $this->RequestRead($idents);
    }

    /**
     * IPS-Instanz Funktion PREFIX_CheckFirmwareUpdate.
     * Kontrolliert die aktuelle Firmware-Version online.
     * @access public
     * @return string mit aktuellstem FW-File oder LEER bei Fehler.
     */
    public function CheckFirmwareUpdate() {
        /**
         * Aktuell wird die aktuellste FW-Datei von Kostal über $fwUpdateURL zur Verfügung gestellt.
         * Es gibt (noch?) keine API um diese irgendwie abzufragen (auch der WR kann dies nicht automatisch).
         * Daher prüfen wir aktuell einfach auf den Dateinamen der FW-Datei. Wenn sich dieser ändert, ist eine neue FW vorhanden.
         * Die aktuellste Datei wird in einer Variable zwischengespeichert. Der User kann diese auf Änderung überwachen und als Ereignis weiterverarbeiten.
         */
        $fwUpdateURL = 'https://www.kostal-solar-electric.com/software-update-hybrid';

        //Header von Download-Url lesen
        $curl = curl_init($fwUpdateURL);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        $headers = curl_exec($curl);
        curl_close($curl);

        //Aktuelle FW-Datei von Location aus Header herauslesen und in Instanz-Variable schreiben
        $this->MaintainVariable('_CurrentFWOnline', $this->Translate('Current FW-Version online'), VARIABLETYPE_STRING, '', 999, true);
        if ($headers !== false && preg_match('/^Location: (.+)$/im', $headers, $matches)) {
            $fwFile = basename(trim($matches[1]));
            if ($this->GetValue('_CurrentFWOnline') !== $fwFile) {
                $this->SetValue('_CurrentFWOnline', $fwFile);
                $this->LogMessage(sprintf($this->Translate('New FW-Version available online (%s)'), $fwFile), KL_NOTIFY);
            }
            return $fwFile;
        } else {
            $error = sprintf($this->Translate("Error reading FW-Version from '%s'!"), $fwUpdateURL);
            $this->LogMessage($error, KL_WARNING);
            return '';
        }
    }

    /**
     * Wird von IPS-Instanz Funktion PREFIX_RequestAction aufgerufen
     * und schreibt den Wert der Variable auf den Wechselrichter zurück
     * @param string $Ident der Variable
     * @param mixed $Value zu schreibender Wert
     * @return boolean true bei Erfolg oder false bei Fehler
     * @access private
     */
    private function RequestVariableAction(string $Ident, $Value) {
        $ms = microtime(true);
        $mbConfig = $this->GetModBusConfig();

        if ($this->IsIdentAvailable($Ident) === false) { //Ident existiert nicht oder falsche FW-Version
            $this->ThrowMessage('Unknown Ident(s): %s', $Ident);
            return false;
        }

        $config = $mbConfig[$Ident];
        if (array_key_exists('WFunction', $config) === false) { //Kein Schreib-Zugriff für Ident
            $this->ThrowMessage('No Write-Access for Ident(s): %s', $Ident);
            return false;
        }

        //Wert auf Gerät schreiben
        $this->UpdateFormField('StatusLED', 'image', self::LED_Write);
        $this->SendDebug('WriteValue', "Ident: $Ident on Address: " . $config['Address'] . ' Type: ' . gettype($Value) . " Value: $Value", 0);
        $mbType = $this->ReadAttributeInteger('MBType');
        if (array_key_exists('MBType', $config)) { //Einige Werte werden nicht gemäss der Standard-Einstellung des Gerätes zurückgegeben (Bugs in Geräte-FW). Dies wird hiermit korrigiert.
            $mbType = $config['MBType'];
        }
        $factor = $config['Factor'];
        if (array_key_exists('ScaleIdent', $config) && $config['ScaleIdent'] !== '') { //Skalierungs-Faktor mit Factor kombinieren
            $factor = $config['Factor'] * pow(10, $this->RequestReadIdent($config['ScaleIdent']));
        }
        $result = $this->WriteModBus($Value, $config['WFunction'], $config['Address'], $config['Quantity'], $factor, $mbType, $config['VarType']);

        if ($result === true) {
            $this->SendDebug('WriteValue', sprintf('Wrote ident \'%s\' in %f seconds.', $Ident, microtime(true) - $ms), 0);
            $vID = $this->GetIDForIdent($Ident);
            if ($vID !== false) { //für einen Ident exisitert nicht zwingend eine Status-Variable
                $this->SetValue($Ident, $Value);
            }
        }
        $this->UpdateFormField('StatusLED', 'image', self::LED_Off);
        return $result;
    }

    /**
     * Gibt Wert von $Ident aus dem Cache zurück, wenn vorhanden und noch gültig
     * @param string $Ident (optional) Wenn leer werden alle noch gültigen Idents zurückgegeben
     * @return mixed null, wenn $Ident nicht vorhanden oder abgelaufen, Wert aus Cache oder Array mit allen noch gültigen Werten und deren Timestamp
     * @access private
     */
    private function GetFromCache(string $Ident = '') {
        $buffer = json_decode($this->GetBuffer('Cache'), true);
        $ts = time();
        if (is_array($buffer) && array_key_exists($Ident, $buffer) && ($ts - $buffer[$Ident]['Timestamp'] <= 1)) { //$Ident max. seit 1 Sekunde im Cache vorhanden
            return $buffer[$Ident]['Value'];
        } elseif ($Ident !== '') { //$Ident im Cache nicht vorhanden oder abgelaufen
            return null;
        } elseif (is_array($buffer)) { //Alle noch gültigen Einträge aus dem Cache zurückgeben
            $cache = [];
            foreach ($buffer as $id => $data) {
                if ($ts - $data['Timestamp'] <= 1) { //Nur gültig wenn nicht älter als 1 Sekunde
                    $cache[$id] = $data;
                }
            }
            return $cache;
        }
        return [];
    }

    /**
     * Schreibt $Value für $Ident in den Cache und entfernt alle abgelaufenen Idents aus dem Cache
     * @param string $Ident
     * @param mixed $Value
     * @access private
     */
    private function SetToCache(string $Ident, $Value) {
        $cache = $this->GetFromCache(); //Holt alle noch gültigen Werte aus dem Cache
        $cache[$Ident] = ['Value' => $Value, 'Timestamp' => time()];
        $this->SetBuffer('Cache', json_encode($cache));
    }

    /**
     * Liest die Property 'SwapWords' aus dem ModBus-Gateway aus
     * und setzt damit den korrekten Wert für das Attribut 'MBType'.
     * @access private
     */
    private function SetModBusType() {
        $mbType = self::MB_LittleEndian_ByteSwap;
        $gwID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if (IPS_ObjectExists($gwID) && IPS_GetProperty($gwID, 'SwapWords') === false) {
            $mbType = self::MB_BigEndian;
        }
        $this->WriteAttributeInteger('MBType', $mbType);
    }

    /**
     * Berechnet den Wert von $Ident
     * @param string $Ident
     * @return mixed Berechneter Wert (wenn -1, dann keine Berechnung durchgeführt)
     * @access private
     */
    private function CalculateValue(string $Ident) {
        $value = null;

        //Summe Leistung Hausverbrauch
        if ($Ident === 'ConsFromAll') {
            $val = $this->RequestReadIdent('ConsFromAC ConsFromBT ConsFromPV');
            $value = $val['ConsFromAC'] + $val['ConsFromBT'] + $val['ConsFromPV'];
        }

        //Summe Leistung aller PV-Strings (ohne Batterie)
        if ($Ident === 'PVPowerStrTot') {
            $idents = 'PVPowerDC1 PVPowerDC2';
            if ($this->RequestReadIdent('BTReadyFlag') == 0) { //Falls keine Batterie angeschlossen ist, wird Eingang 3 auch für PV genutzt
                $idents = "$idents PVPowerDC3";
            }
            foreach ($this->RequestReadIdent($idents) as $val) {
                $value = $value + $val;
            }
        }

        //Status der Batterie
        if ($Ident === 'BTState') {
            $value = $this->RequestReadIdent('BTPower') <=> 0; //gibt -1 (Charging), 0 (Idle) oder 1 (Discharging) zurück
        }

        //Status Netz
        if ($Ident === 'GridState') {
            $value = $this->RequestReadIdent('PMActivePowerTot') <=> 0; //gibt -1 (FeedIn), 0 (Idle) oder 1 (Purchase) zurück
        }

        if (is_null($value)) {
            //Tritt auf, wenn in der ModBusConfig eine Berechnung definiert, aber hier keine Formel dazu vorhanden ist.
            $this->ThrowMessage('Calculation not possible for Ident \'%s\'!', $Ident);
        }
        $this->SendDebug('CalculateValue', "Ident: $Ident Result: $value", 0);
        return $value;
    }

    /**
     * Fügt einen Ident zu PollIdents hinzu oder entfernt ihn
     * @param string $Submit json_codiertes Array(boolean Poll, string Ident)
     * @access private
     */
    private function FormEditIdent(string $Submit) {
        $Submit = json_decode($Submit, true);
        $poll = $Submit[0];
        $ident = $Submit[1];
        $pollIdents = $this->GetBuffer('TempPollIdents');
        $pollIdents = trim(str_replace(' NONE ', ' ', " $pollIdents ")); //Wert für KEINE aus der Liste löschen
        $pollIdents = trim(str_replace(" $ident ", ' ', " $pollIdents ")); //Ident aus der Liste löschen
        if ($poll) {//Ident hinzufügen, wenn dieser aktiviert wurde
            $pollIdents = "$pollIdents $ident";
        } elseif (trim($pollIdents) == '') {//NONE einfügen, wenn gar nichts gewählt ist, damit ApplyChanges() das unterscheiden kann
            $pollIdents = 'NONE';
        }
        //$this->UpdateFormField('SPList', 'visible', boolval(strpos(" $pollIdents", ' SP'))); //Liste 'SPList' ein/ausblenden abhängig von gepollten SP-Idents
        $this->SetBuffer('TempPollIdents', trim($pollIdents));
    }

    /**
     * Fügt einen Ident zur Liste für CreateEvent hinzu
     * @param string $Submit json_codiertes Array(string Type, string Ident)
     * @access private
     */
    private function FormAddIdent(string $Submit) {
        $Submit = json_decode($Submit, true);
        $lastType = $this->GetBuffer('RequestReadType');
        $type = $Submit[0];
        $ident = $Submit[1];
        if ($ident !== '') {
            $ident = trim($this->GetBuffer('RequestReadValue') . ' ' . $ident);
        }
        if ($lastType !== $type) {
            $ident = @array_pop(explode(' ', $ident)); //letzen (neuen) Wert nehmen
        } else {
            $ident = implode(' ', array_unique(explode(' ', $ident))); //doppelte Werte entfernen
        }
        $this->UpdateFormField('CreateEvent', 'enabled', ($ident !== ''));
        $this->UpdateFormField('RequestRead', 'caption', static::PREFIX . "_RequestRead$type");
        $this->UpdateFormField('RequestRead', 'value', $ident);
        $this->SetBuffer('RequestReadType', $type);
        $this->SetBuffer('RequestReadValue', $ident);
    }

    /**
     * Erstellt einen Event mit Action RequestReadGroup/Ident($Idents)
     * @param string string $Submit json_codiertes Array(string EventName, string EventType, string Ident(s))
     * @access private
     */
    private function CreateEvent(string $Submit) {
        $Submit = json_decode($Submit, true);
        $eventName = $Submit[0];
        $eventType = $Submit[1];
        $idents = $Submit[2];
        $type = $this->GetBuffer('RequestReadType');
        $action = static::PREFIX . "_RequestRead$type(\$_IPS['TARGET'], '$idents');";
        $eId = IPS_CreateEvent($eventType);
        IPS_SetParent($eId, $this->InstanceID);
        IPS_SetName($eId, $eventName);
        IPS_SetEventScript($eId, $action);
        if ($eventType == EVENTTYPE_CYCLIC) {
            IPS_SetEventCyclic($eId, 0, 0, 0, 0, 1, 30); //alle 30 Sekunden
        }
        IPS_SetEventActive($eId, false);
        $this->UpdateFormField('OpenEvent', 'caption', sprintf($this->Translate('Check Event (%s)'), $eId));
        $this->UpdateFormField('OpenEvent', 'objectID', $eId);
        $this->UpdateFormField('OpenEvent', 'visible', true);
        IPS_LogMessage(static::PREFIX, 'INSTANCE: ' . $this->InstanceID . " ACTION: CreateEvent: #$eId - " . $this->Translate('Event was created. Please check/change settings.'));
    }

    /**
     * Liest die ModBus-Konfigurationsdaten entweder direkt aus dem JSON-File oder aus dem Cache und gibt diese als Array zurück
     * @return array mit allen Konfigurationsparametern
     * @access private
     */
    private function GetModBusConfig() {
        $config = $this->GetBuffer('ModBusConfig');
        if ($config == '') {//erstes Laden aus File & Ersetzen der ModBus-Konstanten
            $config = $this->GetJSONwithVariables(__DIR__ . '/ModBusConfig.json', [
                '$FC_Read_HoldingRegisters'          => self::FC_Read_HoldingRegisters,
                '$FC_Write_SingleHoldingRegister'    => self::FC_Write_SingleHoldingRegister,
                '$FC_Write_MultipleHoldingRegisters' => self::FC_Write_MultipleHoldingRegisters,
                '$VT_String'                         => self::VT_String,
                '$VT_UnsignedInteger'                => self::VT_UnsignedInteger,
                '$VT_Float'                          => self::VT_Float,
                '$VT_Real'                           => self::VT_Real,
                '$VT_SignedInteger'                  => self::VT_SignedInteger,
                '$VT_Boolean'                        => self::VT_Boolean,
                '$MB_BigEndian'                      => self::MB_BigEndian
            ]);
            $config = json_decode($config, true, 4);
            if (json_last_error() !== JSON_ERROR_NONE) {//Fehler darf nur beim Entwickler auftreten (nach Anpassung der JSON-Daten). Wird daher direkt als echo ohne Übersetzung ausgegeben.
                echo 'GetModBusConfig - Error in JSON (' . json_last_error_msg() . '). Please check ReplaceMap / Variables and File-Content of ' . __DIR__ . '/ModBusConfig.json and run PHPUnit-Test \'testModBusConfig\'';
                exit;
            }
            $aConfig = [];
            foreach ($config as $c) { //Idents und notwendige Parameter einlesen
                $aConfig[$c['Ident']] = $c;
                unset($aConfig['Ident']);
                if (!array_key_exists('Profile', $c)) {
                    $aConfig[$c['Ident']]['Profile'] = '';
                }
                if (!array_key_exists('Factor', $c)) {
                    $aConfig[$c['Ident']]['Factor'] = 1;
                }
                //Weitere Tests sind nicht nötig, da die ModBusConfig.json mittels PHPUnit-Tests kontrolliert wird und die Daten somit stimmen sollten.
            }
            $this->SetBuffer('ModBusConfig', json_encode($aConfig));
            //Bei Modul-Updates kann es sein, dass Einträge in der ModBusConfig umbenannt oder ganz gelöscht werden.
            //Die dadurch ungültig gewordenen Idents müssen aus der Property 'PollIdents' entfernt werden, damit es nicht zu Fehlern kommt.
            $pollIdents = $this->ReadAttributeString('PollIdents');
            $knownIdents = implode(' ', array_intersect(explode(' ', $pollIdents), array_keys($aConfig))); //am Ende bleiben nur die Idents aus $pollIdents übrig, welche auch in $config vorhaden sind
            $this->WriteAttributeString('PollIdents', $knownIdents); //Schreibe bereinigte Werte für 'PollIdents' zurück
            return $aConfig;
        }
        return json_decode($config, true, 4);
    }

    /**
     * Prüft ob $Ident in der ModBus-Config für $FWVersion vorhanden ist.
     * @param string $Ident der zu lesende ModBus-Parameter
     * @param string $FWVersion (optional) die geprüft werden soll
     * @access private
     * @return boolean
     */
    private function IsIdentAvailable(string $Ident, string $FWVersion = '') {
        if ($FWVersion === '') {
            $FWVersion = @floatval(json_decode($this->GetBuffer('DeviceInfo'), true)['FWVersion']); //Falls noch nicht definiert, wird 0 verwendet
        }
        $mbConfig = $this->GetModBusConfig();
        if (array_key_exists($Ident, $mbConfig)) { //Konfiguration ist vorhanden
            if (floatval($FWVersion) >= $mbConfig[$Ident]['FWVersion']) { //FW-Version passt
                return true;
            }
        }
        return false;
    }
}