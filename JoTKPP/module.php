<?php
/***************************************************************************************************
 * @Package:		 JoTKPP                                                          *
 * @File:			 module.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 27.10.2019 18:58:09                                                           *
 * @Modified By:	 Jonathan Tanner                                                               *
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner                                               *
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0                   *
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)                  *
 ***************************************************************************************************/


require_once(__DIR__ . "/../libs/JoT_Traits.php");  //Bibliothek mit allgemeinen Definitionen & Traits
require_once(__DIR__ . "/../libs/JoT_ModBus.php");  //Bibliothek für ModBus-Intgration

/**
 * JoTKPP ist die Unterklasse für die Integration eines Kostal Wechselrichters PLENTICORE plus.
 * Erweitert die Klasse JoTModBus, welche die ModBus- sowie die Modul-Funktionen zur Verfügung stellt.
 */
class JoTKPP extends JoTModBus {
    protected const PREFIX = "JoTKPP";
    protected const STATUS_Error_WrongDevice = 416;
    
    use VariableProfile;
    use Translation;
    use RequestAction;

    /**
     * Interne Funktion des SDK.
     * Initialisiert Properties, Attributes und Timer.
     * @access public
     */
    public function Create(){
        parent::Create();
        $this->ConfigProfiles(__DIR__."/ProfileConfig.json", ['$VT_Float' => self::VT_Float, '$VT_Integer' => self::VT_Integer]);
        $this->RegisterPropertyString("ModuleVariables", json_encode([]));
        $this->RegisterPropertyInteger("PollTime", 0);
        $this->RegisterPropertyInteger("CheckFWTime", 0);
        $this->RegisterTimer("RequestRead", 0, static::PREFIX . '_RequestRead($_IPS["TARGET"]);');
        $this->RegisterTimer("CheckFW", 0, static::PREFIX . '_CheckFirmwareUpdate($_IPS["TARGET"]);');
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);//Gateway wurde geändert
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn die Konfigurations-Änderungen gespeichet werden.
     * @access public
     */
    public function ApplyChanges(){
        parent::ApplyChanges();
        $moduleVariables = json_decode($this->ReadPropertyString("ModuleVariables"), 1);
        $mbConfig = $this->GetModBusConfig();
        $groups = array_values(array_unique(array_column($mbConfig, "Group")));

        //Bestehende Instanz-Variablen pflegen...
        foreach ($moduleVariables as $var){
            $ident = $var['Ident'];
            if ($var['Poll'] == false || !key_exists($ident, $mbConfig)){//wenn nicht gepollt oder in ModBusConfig nicht mehr vorhanden
                $this->UnregisterVariable($ident);
            } else if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false){//wenn Instanz-Variable nicht vorhanden
                $varType = $this->GetIPSVarType($mbConfig[$ident]['VarType'], $mbConfig[$ident]['Factor']);
                $profile = $this->CheckProfileName($mbConfig[$ident]['Profile']);
                $pos = array_search($mbConfig[$ident]['Group'], $groups)*20;//20er Schritte, damit User innerhalb der Gruppen-Position auch sortieren kann
                $this->MaintainVariable($ident, $mbConfig[$ident]['Name'], $varType, $profile, $pos, true);
            }
        }
        
        //Timer für Polling (de)aktivieren
        if ($this->ReadPropertyInteger('PollTime') > 0) {
            $this->SetTimerInterval('RequestRead', $this->ReadPropertyInteger('PollTime')*1000);
        } else {
            $this->SetTimerInterval('RequestRead', 0);
        }

         //Timer für FW-Updates (de)aktivieren
         if ($this->ReadPropertyInteger('CheckFWTime') > 0) {
            $this->SetTimerInterval('CheckFW', $this->ReadPropertyInteger('CheckFWTime')*60*60*1000);
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
    public function GetConfigurationFormOld(){
        $values = [];
        $device = $this->GetDeviceInfo();
        //ModBus-Parameter nur anzeigen wenn FW-Version bekannt ist
        if (key_exists("FWVersion", $device) && $device['FWVersion'] != "") {
            $fwVersion = floatval($device['FWVersion']);

            //Values für Liste vorbereiten (vorhandene Variabeln)
            $mbConfig = $this->GetModBusConfig();
            $variable = [];
            foreach ($mbConfig as $ident => $config) {
                $variable['Ident'] = $ident;
                $variable['Group'] = $config['Group'];
                $variable['Name'] = $config['Name'];
                $variable['cName'] = '';
                $variable['Profile'] = $this->CheckProfileName($config['Profile']); //Damit wird der PREFIX immer davor hinzugefügt
                $variable['cProfile'] = '';
                $variable['FWVersion'] = floatval($config['FWVersion']);
                $variable['Poll'] = false;
                if (array_key_exists('Poll', $config)) {
                    //Übernimmt Poll nur initial bei Erstellung der Instanz (als Vorschlag), danach wird Poll von ModuleVariables überschrieben
                    $variable['Poll'] = $config['Poll'];
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

            //Sortieren der Einträge - muss analog ModuleVariables sein (sonst entsteht bei neuen / geänderten Definitionen ein Durcheinander)
            $mvKeys = array_column(json_decode($this->ReadPropertyString('ModuleVariables'), 1), 'Ident');
            $sValues = [];
            foreach ($mvKeys as $ident) {
                if (array_key_exists($ident, $mbConfig) === false) {//Definition wurde aus ModBusConfig.json entfernt/umbenannt - Möglichkeit zur Löschung freischalten
                    $values[$ident]['Name'] = $this->Translate('ModBus-Definition for this entry does not exist anymore.');
                    $values[$ident]['rowColor'] = '#FFC0C0';
                    $values[$ident]['deletable'] = true;
                }
                $sValues[] = $values[$ident];
                unset($values[$ident]);
            }
            $values = array_merge($sValues, array_values($values)); //neue Definitionen am Ende einfügen
        }

        //Variabeln in $form ersetzen
        $form = file_get_contents(__DIR__ . "/form.json");
        $form = str_replace("\$DeviceString", $device['String'], $form);
        foreach ($device as $ident => $value) {
            $diValues[] = ["Name" => $ident, "Value" => $value];
        }
        $form = str_replace('"$DeviceInfoValues"', json_encode($diValues), $form); //Values für 'DeviceInfos' setzen
        $form = str_replace('"$ModuleVariablesValues"', json_encode($values), $form); //Values für 'ModuleVariables' setzen
        return $form;
    }
    public function GetConfigurationForm(){
        $values = [];
        $device = $this->GetDeviceInfo();
        //ModBus-Parameter nur anzeigen wenn FW-Version bekannt ist
        if (key_exists("FWVersion", $device) && $device['FWVersion'] != "") {
            $fwVersion = floatval($device['FWVersion']);

            //Values für Liste vorbereiten (vorhandene Variabeln)
            $mbConfig = $this->GetModBusConfig();
            $variable = [];
            $eid = 1;
            foreach ($mbConfig as $ident => $config) {
                if (array_key_exists($config['Group'], $values) === false){//Gruppe exstiert im Tree noch nicht
                    $values[$config['Group']] = ["Group" => $config['Group'], "Ident" => $config['Group'], "id" => $eid++, "parent" => 0, "rowColor" => "#DFDFDF", "editable" => false, "deletable" => false];
                }
                $variable['parent'] = $values[$config['Group']]['id'];
                $variable['id'] = $eid++;
                $variable['Group'] = $config['Group'];
                $variable['Ident'] = $ident;
                $variable['Name'] = $config['Name'];
                $variable['cName'] = '';
                $variable['Profile'] = $this->CheckProfileName($config['Profile']); //Damit wird der PREFIX immer davor hinzugefügt
                $variable['cProfile'] = '';
                $variable['FWVersion'] = floatval($config['FWVersion']);
                $variable['Poll'] = false;
                if (array_key_exists('Poll', $config)) {
                    //Übernimmt Poll nur initial bei Erstellung der Instanz (als Vorschlag), danach wird Poll von ModuleVariables überschrieben
                    $variable['Poll'] = $config['Poll'];
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

            //Sortieren der Einträge - muss analog ModuleVariables sein (sonst entsteht bei neuen / geänderten Definitionen ein Durcheinander)
            $mvKeys = array_column(json_decode($this->ReadPropertyString('ModuleVariables'), 1), 'Ident');
            $sValues = [];
            foreach ($mvKeys as $ident) {
                if (array_key_exists($ident, $values) === false) {//Definition wurde aus ModBusConfig.json entfernt/umbenannt
                    $values[$ident]['Name'] = sprintf($this->Translate("ModBus-Definition for '%s' does not exist anymore."), $ident);
                    $values[$ident]['rowColor'] = '#FFC0C0';
                    $values[$ident]['deletable'] = true;
                    $values[$ident]['id'] = $eid++;
                }
                $sValues[] = $values[$ident];
                unset($values[$ident]);
            }
            $values = array_merge($sValues, array_values($values)); //neue Definitionen am Ende einfügen
        }

        //Variabeln in $form ersetzen
        $form = file_get_contents(__DIR__ . "/form.json");
        $form = str_replace("\$DeviceString", $device['String'], $form);
        foreach ($device as $ident => $value) {
            $diValues[] = ["Name" => $ident, "Value" => $value];
        }
        $form = str_replace('"$DeviceInfoValues"', json_encode($diValues), $form); //Values für 'DeviceInfos' setzen
        $form = str_replace('"$ModuleVariablesValues"', json_encode($values), $form); //Values für 'ModuleVariables' setzen
        return $form;
    }

    /**
    * Liest die ModBus-Konfigurationsdaten entweder direkt aus dem JSON-File oder aus dem Cache und gibt diese als Array zurück
    * @return array mit allen Konfigurationsparametern
    * @access private
    */
    private function GetModBusConfig(){
        $config = $this->GetBuffer("ModBusConfig");
        if ($config == ""){//erstes Laden aus File & Ersetzen der ModBus-Konstanten
            $config = $this->GetJSONwithVariables(__DIR__."/ModBusConfig.json", [
                '$FC_Read_HoldingRegisters' => self::FC_Read_HoldingRegisters,
                '$VT_String' => self::VT_String,
                '$VT_UnsignedInteger' => self::VT_UnsignedInteger,
                '$VT_Float' => self::VT_Float,
                '$VT_SignedInteger' => self::VT_SignedInteger,
                '$MB_BigEndian_WordSwap' => self::MB_BigEndian_WordSwap,
                '$MB_BigEndian' => self::MB_BigEndian
            ]);
            $this->SetBuffer("ModBusConfig", $config);
        }
        //JSON in Array umwandeln
        $aConfig = json_decode($config, true, 4);
        if (json_last_error() !== JSON_ERROR_NONE){//Fehler darf nur beim Entwickler auftreten (nach Anpassung der JSON-Daten). Wird daher direkt als echo ohne Übersetzung ausgegeben.
            echo("GetModBusConfig - Error in JSON (".json_last_error_msg()."). Please check ReplaceMap / Variables and File-Content of ".__DIR__."/ModBusConfig.json");
            exit;
        }
        return $aConfig;
    }

    /**
    * Liest Informationen zur Geräte-Erkennung vom Gerät aus und aktualisiert diese im Formular
    * @access public
    * @return array mit Geräte-Informationen oder Fehlermeldung
    */
    public function GetDeviceInfo(){
        $this->UpdateFormField("DeviceInfo", "visible", false);
        $device = json_decode($this->GetBuffer("DeviceInfo"), 1);//Aktuell bekannte Geräte-Parameter aus Cache holen

        //Prüfen ob es sich um einen Kostal Wechselrichter handelt
        $read = $this->Translate("Reading device information...");
        $this->UpdateFormField("Device", "caption", $read . "(Manufacturer)");
        $mfc = $this->RequestReadIdent("Manufacturer");
        if ($mfc !== "KOSTAL"){
            $device['String'] = $this->Translate("Device information could not be read. Gateway settings correct?");
            $device['Error'] = true;
            $this->UpdateFormField("Device", "caption", $device['String']);
            $this->LogMessage($device['String'], KL_ERROR);
            if ($this->GetStatus() == self::STATUS_Ok_InstanceActive){//ModBus-Verbindung OK, aber falsche Antwort
                $this->SetStatus(self::STATUS_Error_WrongDevice);
            }
            return $device;
        }

        //SerienNr lesen
        $this->UpdateFormField("Device", "caption", $read . "(SerialNr)");
        $serialNr = $this->RequestReadIdent("SerialNr");
        if (is_null($device) || $device['SerialNr'] !== $serialNr){//Neue SerienNr/Gerät - Werte neu einlesen
            $device = ['Manufacturer' => $mfc, 'Error' => false];
        }
        $device['SerialNr'] = $serialNr;

        //Folgende Werte könnten ändern, daher immer auslesen
        $this->UpdateFormField("Device", "caption", $read . "(NetworkName)");
        $device['NetworkName'] = $this->RequestReadIdent("NetworkName");
        $this->UpdateFormField("Device", "caption", $read . "(SoftwareVersionMC)");
        $device['FWVersion'] = $this->RequestReadIdent("SoftwareVersionMC");

        //unbekannte Werte vom Gerät auslesen
        $idents = ["ProductName", "PowerClass", "BTReadyFlag", "SensorType", "BTType", "BTCrossCapacity", "BTManufacturer", "NumberPVStrings", "HardwareVersion"];
        foreach ($idents as $ident){
            if (!key_exists($ident, $device) || is_null($device[$ident])){
                if ($this->IsIdentAvailable($ident, $device['FWVersion'])) {
                    $this->UpdateFormField('Device', 'caption', $read . "($ident)");
                    $device[$ident] = $this->RequestReadIdent($ident);
                }
            }
        }
        
        $device['String'] = $device['Manufacturer']." ".$device['ProductName']." ".$device['PowerClass']." ($serialNr) - ".$device['NetworkName'];
        $this->UpdateFormField("Device", "caption", $device['String']);
        $this->UpdateFormField("DeviceInfo", "visible", true);

        $this->SetBuffer("DeviceInfo", json_encode($device));//Aktuell bekannte Geräte-Parameter im Cache zwischenspeichern
        return $device;
    }

    /**
     * Prüft ob $Ident in der ModBus-Config für $FWVersion vorhanden ist.
     * @param $Ident der zu lesende ModBus-Parameter
     * @param optional $FWVersion die geprüft werden soll
     * @access private
     * @return boolean
     */
    private function IsIdentAvailable(string $Ident, string $FWVersion = ""){
        if ($FWVersion == ""){
            $FWVersion = json_decode($this->GetBuffer("DeviceInfo"), 1);
            if (!key_exists("FWVersion", $FWVersion)){
                $FWVersion = "??";
            } else {
                $FWVersion = $FWVersion['FWVersion'];
            }
        }
        $mbConfig = $this->GetModBusConfig();
        if (key_exists($Ident, $mbConfig)){//Konfiguration ist vorhanden
            if (floatval($FWVersion) >= floatval($mbConfig[$Ident]['FWVersion'])){//FW-Version passt
                return true;
            }
        }
        return false;
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn eine registrierte Nachricht verfügbar ist.
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $MessageID, $Data) {
        if ($MessageID == FM_CONNECT){//Gateway wurde geändert
            $this->RegisterOnceTimer("GetDeviceInfo", 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
            foreach ($this->GetMessageList() as $id => $msgs){//Nachrichten von alten GWs deaktivieren
                $this->UnregisterMessage($id, IM_CHANGESETTINGS);
            }
            $this->RegisterMessage(IPS_GetInstance($this->InstanceID)['ConnectionID'], IM_CHANGESETTINGS);
        } else if ($MessageID == IM_CHANGESETTINGS){//Einstellungen im Gateway wurde geändert
            $this->RegisterOnceTimer("GetDeviceInfo", 'IPS_RequestAction($_IPS["TARGET"], "GetDeviceInfo", "");');
        }
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestRead.
     * Ließt alle/gewünschte Werte aus dem Gerät.
     * @param bool|string optional $force wenn auch nicht gepollte Values gelesen werden sollen.
     * @access public
     * @return array mit den angeforderten Werten, NULL bei Fehler oder Wert wenn nur ein Wert.
     */
    public function RequestRead(){
        $force = false;//$force = true wird über die Funktion RequestReadAll aktiviert oder String mit Ident über die Funktion RequestReadIdent/Group
        if (func_num_args() == 1){//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit der PREFIX_Funktion erstellt wird
            $force = func_get_arg(0);
        };

        $mbConfig = $this->GetModBusConfig();
        $moduleVariables = json_decode($this->ReadPropertyString("ModuleVariables"), 1);
        $values = [];
        $mvKeys = array_flip(array_column($moduleVariables, "Ident"));
        foreach ($mbConfig as $ident => $config){//Loop durch $mbConfig, damit Werte auch ausgelesen werden können, wenn Instanz noch nicht gespeichert ist.
            //Wenn ENTWEDER entsprechende Variable auf Poll ODER $force true ODER aktuelle Variable in Liste der angeforderten Idents (strpos mit Leerzeichen, da mehrere Idents ebenfalls mit Leerzeichen getrennt werden).
            if ((key_exists($ident, $mvKeys) && $moduleVariables[$mvKeys[$ident]]['Poll'] == true && $force === false) || $force === true || (is_string($force) && strpos(" $force ", " $ident ") !== false)){
                $this->SendDebug("RequestRead", "Ident: $ident on Address: ".$config['Address'], 0);
                $value = $this->ReadModBus($config['Function'], $config['Address'], $config['Quantity'], $config['Factor'], $config['MBType'], $config['VarType']);
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false){//Instanz-Variablen sind nur für Werte mit aktivem Polling vorhanden
                    $this->SetValue($ident, $value);
                }
                $values[$ident] = $value;
            }
        }
        switch (count($values)){
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
    public function RequestReadAll(){
        return $this->RequestRead(true);
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestReadIdent.
     * Ruft PREFIX_RequestRead($Ident) auf
     * @param string $Ident - eine mit Leerzeichen getrennte Liste aller zu lesenden Idents.
     * @access public
     * @return array mit den angeforderten Werten.
     */
    public function RequestReadIdent(string $Ident){
        if ($Ident == ""){
            echo $this->Translate("Ident(s) can not be empty!");
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
    public function RequestReadGroup(string $Group){
        if ($Group == ""){
            echo $this->Translate("Group(s) can not be empty!");
            return null;
        }
        $idents = "";
        $mbConfig = $this->GetModBusConfig();
        foreach ($mbConfig as $ident => $config){
            if (strpos($Group, $config['Group']) !== false){
                $idents = "$idents $ident";
            }
        }
        $idents = trim($idents);
        if ($idents == ""){
            echo sprintf($this->Translate("No idents found for group(s) '%s'!"), $Group);
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
    public function CheckFirmwareUpdate(){
        /**
         * Aktuell wird die aktuellste FW-Datei von Kostal über $fwUpdateURL zur Verfügung gestellt.
         * Es gibt (noch?) keine API um diese irgendwie abzufragen (auch der WR kann dies nicht automatisch).
         * Daher prüfen wir aktuell einfach auf den Dateinamen der FW-Datei. Wenn sich dieser ändert, ist eine neue FW vorhanden.
         * Die aktuellste Datei wird in einer Variable zwischengespeichert. Der User kann diese auf Änderung überwachen und als Ereignis weiterverarbeiten.
         */
        $fwUpdateURL = "https://www.kostal-solar-electric.com/software-update-hybrid";
        
        //Header von Download-Url lesen
        $curl = curl_init($fwUpdateURL);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        $headers = curl_exec($curl);
        curl_close($curl);
        
        //Aktuelle FW-Datei von Location aus Header herauslesen und in Instanz-Variable schreiben
        $ident = "CurrentFWOnline";
        $this->MaintainVariable($ident, $this->Translate("Current FW-Version online"), VARIABLETYPE_STRING, "", 999, true);
        if (preg_match('/^Location: (.+)$/im', $headers, $matches)){
            $fwFile = basename(trim($matches[1]));
            if ($this->GetValue($ident) !== $fwFile){
                $this->SetValue($ident, $fwFile);
                $this->LogMessage(sprintf($this->Translate("New FW-Version available online (%s)"), $fwFile), KL_NOTIFY);
            }
            return $fwFile;
        } else {
            $error = sprintf($this->Translate("Error reading FW-Version from '%s'!"), $fwUpdateURL);
            $this->LogMessage($error, KL_WARNING);
            return "";
        }
    }
}
