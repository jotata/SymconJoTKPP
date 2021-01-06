<?php

declare(strict_types=1);
/**
 * @Package:         JoTKPP
 * @File:            module.php
 * @Create Date:     09.07.2020 16:54:15
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   06.01.2021 16:42:42
 * @Modified By:     Jonathan Tanner
 * @Copyright:       Copyright(c) 2020 by JoT Tanner
 * @License:         Creative Commons Attribution Non Commercial Share Alike 4.0
 *                   (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */
require_once __DIR__ . '/../libs/JoT_Traits.php';  //Bibliothek mit allgemeinen Definitionen & Traits
require_once __DIR__ . '/../libs/JoT_ModBus.php';  //Bibliothek für ModBus-Integration

/**
 * JoTKPP ist die Unterklasse für die Integration eines Kostal Wechselrichters PLENTICORE plus.
 * Erweitert die Klasse JoTModBus, welche die ModBus- sowie die Modul-Funktionen zur Verfügung stellt.
 */
class JoTKPP extends JoTModBus {
    use VariableProfile;
    use Translation;
    use RequestAction;
    protected const PREFIX = 'JoTKPP';
    protected const MODULEID = '{E64278F5-1942-5343-E226-8673886E2D05}';
    protected const STATUS_Error_WrongDevice = 416;

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
        $this->RegisterPropertyString('PollIdents', ''); //wird seit V1.4RC2 nicht mehr benötigt, für Migration zu aber noch notwendig
        $this->RegisterPropertyString('ModuleVariables', ''); //wird seit V1.4 nicht mehr benötigt, für Migration zu 'PollIdents' aber noch notwendig
        $this->RegisterPropertyInteger('PollTime', 0);
        $this->RegisterPropertyInteger('CheckFWTime', 0);
        $this->RegisterPropertyBoolean('WriteMode', false);
        $this->RegisterPropertyString('SPList', '');
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

        //Migration Propertys < V1.4
        $oldMV = $this->ReadPropertyString('ModuleVariables');
        if ($oldMV !== '') {//Alte Property 'ModuleVariables' muss zu neuem Attribut 'PollIdents' konvertiert werden
            $oldMV = array_column(json_decode($oldMV, true), 'Poll', 'Ident');
            $pollIdents = $this->ReadAttributeString('PollIdents');
            foreach ($oldMV as $ident => $poll) {
                if ($poll) {
                    $pollIdents = "$pollIdents $ident";
                }
            }
            $this->WriteAttributeString('PollIdents', $pollIdents); //konvertierte Werte für neues Attribut (Anwendung / Speicherung erfolgt mittels ApplyChanges())
            IPS_SetProperty($this->InstanceID, 'ModuleVariables', ''); //alte Property "ausser Betrieb nehmen"
            IPS_ApplyChanges($this->InstanceID);
            return;
        }//Ende Migration
        //Migration Propertys < V1.4RC2
        $oldMV = $this->ReadPropertyString('PollIdents');
        if ($oldMV !== '') {//Alte Property 'PollIdents' muss zu neuem Attribut 'PollIdents' konvertiert werden
            $this->WriteAttributeString('PollIdents', $oldMV); //konvertierte Werte in neuem Attribut speichern
            IPS_SetProperty($this->InstanceID, 'PollIdents', ''); //alte Property "ausser Betrieb nehmen"
            IPS_ApplyChanges($this->InstanceID);
            return;
        }//Ende Migration

        //Variablen initialisieren
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
                    if (array_search($ident, $pollIdents) === false || array_key_exists($ident, $mbConfig) === false) {//Wenn in PollIdents ODER ModBusConfig nicht mehr vorhanden - löschen
                        $vars[$ident] = false;
                    }
                }
            }
        }
        //Instanz-Variablen erstellen / löschen / aktualisieren
        foreach ($vars as $ident => $keep) {
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
        } else {
            $this->SetTimerInterval('CheckFW', 0);
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
        $fwVersion = 0;
        if (array_key_exists('FWVersion', $device) && $device['FWVersion'] != '') {
            $fwVersion = floatval($device['FWVersion']);
        }
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
        $form = str_replace('$DeviceString', $device['String'], $form);
        $form = str_replace('"$DeviceInfoVisible"', $this->ConvertToBoolStr($device['Error'], true), $form); //Visible für 'DeviceInfo' setzen
        $diValues = [];
        foreach ($device as $ident => $value) { //DeviceInfo aufbereiten
            if ($ident != 'String' && $ident != 'Error') {
                $diValues[] = ['Name' => $ident, 'Value' => $value];
            }
        }
        $form = str_replace('"$DeviceInfoValues"', json_encode($diValues), $form); //Values für 'DeviceInfos' setzen
        $form = str_replace('$ColumnNameCaption', $this->Translate('Name') . ' (* = ' . $this->Translate('calculated value') . ')', $form); //Caption für Spalte Name in 'IdentList' setzen
        $form = str_replace('"$IdentListValues"', json_encode(array_values($values)), $form); //Values für 'IdentList' setzen
        $form = str_replace('"$SPListVisible"', $this->ConvertToBoolStr(strpos("  $pollIdents", ' SP')), $form); //Visible für 'SPList' setzen (strpos mit 2 Leerzeichen, damit SP auch als erster Ident erkannt wird)
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
        $this->UpdateFormField('DeviceInfo', 'visible', false);
        $device = json_decode($this->GetBuffer('DeviceInfo'), true); //Aktuell bekannte Geräte-Parameter aus Cache holen

        //Prüfen ob es sich um einen Kostal Wechselrichter handelt
        $read = $this->Translate('Reading device information...');
        $this->UpdateFormField('Device', 'caption', $read . '(Manufacturer)');
        $mfc = $this->RequestReadIdent('Manufacturer');
        if ($mfc !== 'KOSTAL') {
            $device['String'] = $this->Translate('Device information could not be read. Gateway settings correct?');
            $device['Error'] = true;
            $this->UpdateFormField('Device', 'caption', $device['String']);
            $this->LogMessage($device['String'], KL_ERROR);
            if ($this->GetStatus() == self::STATUS_Ok_InstanceActive) {//ModBus-Verbindung OK, aber falsche Antwort
                $this->SetStatus(self::STATUS_Error_WrongDevice);
            }
            return $device;
        }

        //SerienNr lesen
        $this->UpdateFormField('Device', 'caption', $read . '(SerialNr)');
        $serialNr = $this->RequestReadIdent('SerialNr');
        if (is_null($device) || $device['SerialNr'] !== $serialNr) {//Neue SerienNr/Gerät - Werte neu einlesen
            $device = ['Manufacturer' => $mfc, 'Error' => false];
        }
        $device['SerialNr'] = $serialNr;

        //Folgende Werte könnten ändern, daher immer auslesen
        $this->UpdateFormField('Device', 'caption', $read . '(NetworkName)');
        $device['NetworkName'] = $this->RequestReadIdent('NetworkName');
        $this->UpdateFormField('Device', 'caption', $read . '(SoftwareVersionMC)');
        $device['FWVersion'] = $this->RequestReadIdent('SoftwareVersionMC');

        //unbekannte Werte vom Gerät auslesen
        $idents = ['ProductName', 'PowerClass', 'BTReadyFlag', 'SensorType', 'BTType', 'BTCrossCapacity', 'BTManufacturer', 'NumberPVStrings', 'HardwareVersion'];
        foreach ($idents as $ident) {
            if (!array_key_exists($ident, $device) || is_null($device[$ident])) {
                if ($this->IsIdentAvailable($ident, $device['FWVersion'])) {
                    $this->UpdateFormField('Device', 'caption', $read . "($ident)");
                    $device[$ident] = $this->RequestReadIdent($ident);
                }
            }
        }

        $device['String'] = $device['Manufacturer'] . ' ' . $device['ProductName'] . ' ' . $device['PowerClass'] . " ($serialNr) - " . $device['NetworkName'];
        $this->UpdateFormField('Device', 'caption', $device['String']);
        $this->UpdateFormField('DeviceInfo', 'visible', true);

        $this->SetBuffer('DeviceInfo', json_encode($device)); //Aktuell bekannte Geräte-Parameter im Cache zwischenspeichern
        return $device;
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn eine registrierte Nachricht verfügbar ist.
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $MessageID, $Data) {
        if ($MessageID == IM_CONNECT) {//Instanz verfügbar
            $this->SendDebug('Instance ready', '', 0);
            $this->RegisterMessage($this->InstanceID, FM_CONNECT); //Gateway wurde geändert
            $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESETTINGS); //Gateway-Einstellungen wurden geändert
            $this->SetModBusType();
        } elseif ($MessageID == FM_CONNECT) {//Gateway wurde geändert
            $this->SendDebug('Gateway changed', 'Gateway #' . IPS_GetInstance($this->InstanceID)['ConnectionID'], 0);
            $this->RegisterOnceTimer('GetDeviceInfo', 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
            foreach ($this->GetMessageList() as $id => $msgs) {//Nachrichten von alten GWs deaktivieren
                $this->UnregisterMessage($id, IM_CHANGESETTINGS);
            }
            $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESETTINGS);
        } elseif ($MessageID == IM_CHANGESETTINGS) {//Einstellungen im Gateway wurde geändert
            $this->SendDebug('Gateway settings changed', 'Gateway #' . IPS_GetInstance($this->InstanceID)['ConnectionID'], 0);
            $this->SetModBusType();
            $this->RegisterOnceTimer('GetDeviceInfo', 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
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
        $mbConfig = $this->GetModBusConfig();
        $idents = $this->ReadAttributeString('PollIdents');
        if (func_num_args() == 1) {//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit PREFIX_Function erstellt wird
            $idents = func_get_arg(0); //true wird über die Funktion RequestReadAll / String mit Idents wird über die Funktion RequestReadIdent/Group aktiviert
        }
        if ($idents === true) { //Aufruf von RequestReadAll
            $idents = array_keys($mbConfig);
        } elseif (strlen($idents) > 0) {
            $idents = explode(' ', trim($idents));
        } else { //keine Idents angegeben
            return null;
        }

        //ModBus-Abfrage durchführen
        $mbType = $this->ReadAttributeInteger('MBType');
        $values = [];
        foreach ($idents as $ident) {
            if (array_key_exists($ident, $mbConfig) === false) { //Unbekannter Ident
                $unknown[] = $ident;
                continue; 
            }
            $config = $mbConfig[$ident];
            $vID = @$this->GetIDForIdent($ident);
            if ($config['Address'] === 0) { //Wert berechnen
                $this->SendDebug('RequestRead', "Ident: $ident gets calculated...", 0);
                $value = $this->CalculateValue($ident);
            } elseif (array_key_exists('RFunction', $config)) { //Wert via Cache / ModBus auslesen
                $value = $this->GetFromCache($ident);
                if (is_null($value)) { //Im Cache nicht vorhanden oder abgelaufen
                    $this->SendDebug('RequestRead', "Ident: $ident on Address: " . $config['Address'], 0);
                    if ($config['VarType'] === self::VT_String) { //Strings werden vom WR immer als BigEndian zurück gegeben, egal welcher Modus aktiviert ist (Bug in FW?)
                        $value = $this->ReadModBus($config['RFunction'], $config['Address'], $config['Quantity'], $config['Factor'], self::MB_BigEndian, $config['VarType']);
                    } else { //andere Datentypen
                        $factor = $config['Factor'];
                        if (array_key_exists('ScaleIdent', $config) && $config['ScaleIdent'] !== '') { //Skalierungs-Faktor mit Factor kombinieren
                            $factor = $config['Factor'] * pow(10, $this->RequestReadIdent($config['ScaleIdent']));
                        }
                        $value = $this->ReadModBus($config['RFunction'], $config['Address'], $config['Quantity'], $factor, $mbType, $config['VarType']);
                    }
                    $this->SetToCache($ident, $value);
                } else { //Wert aus Cache gelesen
                    $vID = false; //Wert nicht in Instanz-Variable zurückschreiben
                    $this->SendDebug('RequestRead', "Ident: $ident from Cache: $value", 0);
                }
            } else { //Kein Read-Access
                if (is_string(@func_get_arg(0))) { // Warnung nur wenn Ident explizit angefordert wurde
                    $noaccess[] = $ident;
                }
                continue;
            }
             
            //Zur Analyse von Forum-Beitrag https://www.symcon.de/forum/threads/41720-Modul-JoTKPP-Solar-Wechselrichter-Kostal-PLENTICORE-plus-PIKO-IQ?p=445149#post445149
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                $msg = 'ModBus-Result is wrong. Please file a bug in forum (https://www.symcon.de/forum/threads/41720-Modul-JoTKPP-Solar-Wechselrichter-Kostal-PLENTICORE-plus-PIKO-IQ) with following information:';
                $msg .= ' Version: ' . IPS_GetLibrary('{89441F1C-532D-3F34-FF79-07A3B38FDD86}')['Version'] . " | Ident: $ident | Value: $value | isNAN: " . $this->ConvertToBoolStr(is_nan($value)) . ' | isINF: ' . $this->ConvertToBoolStr(is_infinite($value));
                $this->ThrowMessage($msg);
                $vID = false;
            } //Ende Analyse

            if ($vID !== false && is_null($value) === false) { //Instanz-Variablen sind nur für Werte mit aktivem Polling vorhanden
                 $this->SetValue($ident, $value);
            }
            $values[$ident] = $value;
        }
        if (isset($unknown) > 0){
            $this->ThrowMessage('Unknown Ident(s): %s', implode(', ', $unknown));
        }
        if (isset($noaccess) > 0){
            $this->ThrowMessage('No Read-Access for Ident(s): %s', implode(', ', $noaccess));
        }
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
        $ident = 'CurrentFWOnline';
        $this->MaintainVariable($ident, $this->Translate('Current FW-Version online'), VARIABLETYPE_STRING, '', 999, true);
        if (preg_match('/^Location: (.+)$/im', $headers, $matches)) {
            $fwFile = basename(trim($matches[1]));
            if ($this->GetValue($ident) !== $fwFile) {
                $this->SetValue($ident, $fwFile);
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
        if (IPS_GetProperty(IPS_GetInstance($this->InstanceID)['ConnectionID'], 'SwapWords') === false) {
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

        //PV-Überschuss
        if (substr($Ident, 0, 2) === 'SP') {
            //Werte einlesen...
            $val = 0;
            if ($Ident === 'SPFeedin') {
                $val = $this->RequestReadIdent('ACActivePowerTot') * 1000; //Vergleich erfolgt in W
            } elseif ($Ident === 'SPReduction') {
                $val = $this->RequestReadIdent('PowerClass ACActivePowerTot InverterState');
                if ($val['InverterState'] === 7) { //=Throttled - Wechselrichter drosselt Leistung
                    //Berechnung ist theoretisch, da unbekannt ist, wie viel Energie die PV-Seite im Moment liefern könnte.
                    //Daher wird die max. möglich Leistung des WR herangezogen.
                    //Falls PV-Seite kleiner dimensioniert oder nicht genügend Sonnen-Einstrahlung vorhanden ist, kann ev. nicht so viel mehr verbraucht werden
                    $val = ($val['PowerClass'] * 1000) - ($val['ACActivePowerTot'] * 1000); //max. mögliche WR-Leistung - aktuell produzierte WR-Leistung in W
                } else { //Wechselrichter kann alle Energie einspeisen oder befindet sich in einem anderen Zustand
                    $val = 0;
                }
            } elseif ($Ident === 'SPCharge') {
                $val = $this->RequestReadIdent('BTPower') * -1 * 1000; //Umdrehen und in W umrechnen, da Vergleich immer mit positivem Wert in W gemacht wird und Charging negativ in kW wäre
            }
            //mit Konfiguration vergleichen
            $config = json_decode($this->ReadPropertyString('SPList'), true);
            $buf = json_decode($this->GetBuffer($Ident), true);
            $temp = [];
            foreach ($config as $conf) { //Alle States ermitteln
                if ($conf['Ident'] === $Ident && $conf['Active'] === true) {
                    $offValue = $conf['OnValue'] - abs($conf['OffDiff']);
                    $index = $conf['OnValue'] . ":$offValue:" . $conf['OnCount'] . ':' . $conf['OffCount'] . ':' . $conf['Value']; //Eindeutigen Index generieren
                    $temp[$index] = ['State' => 0, 'OnCount' => 0, 'OffCount' => 0];
                    if (is_array($buf) && array_key_exists($index, $buf)) { //Durch diese Zuweisung werden geänderte / deaktivierte Konfigurationen im Buffer automatisch bereinigt
                        $temp[$index] = ['State' => $buf[$index]['State'], 'OnCount' => $buf[$index]['OnCount'], 'OffCount' => $buf[$index]['OffCount']];
                    }
                    if ($val >= $conf['OnValue']) {
                        $temp[$index]['OffCount'] = 0;
                        if (++$temp[$index]['OnCount'] >= $conf['OnCount']) {
                            $temp[$index]['State'] = $conf['Value'];
                        }
                    } elseif ($val < $conf['OnValue'] && $val > $offValue) {
                        $temp[$index]['OnCount'] = 0;
                        $temp[$index]['OffCount'] = 0;
                    } elseif ($val <= $offValue) {
                        $temp[$index]['OnCount'] = 0;
                        if (++$temp[$index]['OffCount'] >= $conf['OffCount']) {
                            $temp[$index]['State'] = 0;
                        }
                    }
                }
            }
            $this->SetBuffer($Ident, json_encode($temp));
            krsort($temp, SORT_STRING); //Sortierung analog OnValue DESC basierend auf generiertem Index
            $this->SendDebug('CalculateValue', "Ident: $Ident Buffer: " . json_encode($temp), 0);
            //und $value auf ersten aktiven State setzen
            if (count($temp) > 0) { //Falls keine Konfiguration aktiv ist, wird $value auch nicht gesetzt
                $value = 0;
                foreach ($temp as $tmp) {
                    if ($tmp['State'] > 0) { //$value erhält den ersten aktiven State
                        $value = $tmp['State'];
                        break;
                    }
                }
            }
        }

        if (is_null($value)) {
            //Tritt insbesondere dann auf, wenn keine Konfigurationen in der SPList vorhanden sind.
            //Kann aber auch sein, wenn in der ModBusConfig eine Berechnung definiert, aber hier keine Formel dazu vorhanden ist.
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
        $this->UpdateFormField('SPList', 'visible', boolval(strpos(" $pollIdents", ' SP'))); //Liste 'SPList' ein/ausblenden abhängig von gepollten SP-Idents
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
                '$VT_Boolean'                        => self::VT_Boolean
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
                    $aConfig[$c['Ident']]['Factor'] = 0;
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
     * @param $Ident der zu lesende ModBus-Parameter
     * @param optional $FWVersion die geprüft werden soll
     * @access private
     * @return boolean
     */
    private function IsIdentAvailable(string $Ident, string $FWVersion = '') {
        if ($FWVersion == '') {
            $FWVersion = json_decode($this->GetBuffer('DeviceInfo'), true);
            if (!array_key_exists('FWVersion', $FWVersion)) {
                $FWVersion = '??';
            } else {
                $FWVersion = $FWVersion['FWVersion'];
            }
        }
        $mbConfig = $this->GetModBusConfig();
        if (array_key_exists($Ident, $mbConfig)) {//Konfiguration ist vorhanden
            if (floatval($FWVersion) >= floatval($mbConfig[$Ident]['FWVersion'])) {//FW-Version passt
                return true;
            }
        }
        return false;
    }
}