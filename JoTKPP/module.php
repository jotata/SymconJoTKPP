<?php
/**
 * @Package:		 JoTKPP
 * @File:			 module.php
 * @Create Date:	 27.04.2019 11:51:35
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:	 27.11.2020 21:26:43
 * @Modified By:	 Jonathan Tanner
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */


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
        $this->RegisterPropertyString("PollIdents", "");
        $this->RegisterPropertyString("ModuleVariables", "");//wird seit V1.4 nicht mehr benötigt, aber IPS crasht beim Modul-Reload, wenn die Eigenschaft entfernt wird :-(
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

        //Migration Propertys < V1.4
        $oldMV = $this->ReadPropertyString("ModuleVariables");
        if ($oldMV !== ""){//Alte Property 'ModuleVariables' muss zu neuer Property 'PollIdents' konvertiert werden
            $oldMV = array_column(json_decode($oldMV, 1), "Poll", "Ident");
            $pollIdents = $this->ReadPropertyString("PollIdents");
            foreach ($oldMV as $ident => $poll){
                if ($poll){
                    $pollIdents = "$pollIdents $ident";
                }
            }
            IPS_SetProperty($this->InstanceID, "ModuleVariables", "");//alte Property "ausser Betrieb nehmen"
            IPS_SetProperty($this->InstanceID, "PollIdents", $pollIdents);//konvertierte Werte in neuer Property speichern
            IPS_ApplyChanges($this->InstanceID);
            return;
        }//Ende Migration

        $pollIdents = explode(" ", $this->ReadPropertyString("PollIdents"));
        $mbConfig = $this->GetModBusConfig();
        $groups = array_values(array_unique(array_column($mbConfig, "Group")));
        $vars = [];

        //Instanz-Variablen vorbereiten...
        foreach ($pollIdents as $ident) {
            $pos = array_search($mbConfig[$ident]['Group'], $groups)*20;//20er Schritte, damit User innerhalb der Gruppen-Position auch sortieren kann
            $vars[$ident] = ['Keep' => true, 'Position' => $pos];
        }
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $cId){
            if (IPS_VariableExists($cId)) {
                $ident = IPS_GetObject($cId)['ObjectIdent'];
                if ($ident !== ""){//Nur Instanz-Variablen verarbeiten
                    $pos = IPS_GetObject($cId)['ObjectPosition'];
                    $vars[$ident] = ['Keep' => true, 'Position' => $pos];
                    if (array_search($ident, $pollIdents) === false || array_key_exists($ident, $mbConfig) === false) {//Wenn in PollIdents ODER ModBusConfig nicht mehr vorhanden - löschen
                        $vars[$ident]['Keep'] = false;
                    }
                }
            }
        }
        //... und erstellen / löschen / aktualisieren
        foreach ($vars as $ident => $values){
            $varType = $this->GetIPSVarType($mbConfig[$ident]['VarType'], $mbConfig[$ident]['Factor']);
            $profile = $this->CheckProfileName($mbConfig[$ident]['Profile']);
            $this->MaintainVariable($ident, $mbConfig[$ident]['Name'], $varType, $profile, $values['Position'], $values['Keep']);
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
    public function GetConfigurationForm(){
        $values = [];
        $device = $this->GetDeviceInfo();
        $fwVersion = 0;
        if (array_key_exists('FWVersion', $device) && $device['FWVersion'] != '') {
            $fwVersion = floatval($device['FWVersion']);
        }
        $pollIdents = $this->ReadPropertyString("PollIdents");
        $mbConfig = $this->GetModBusConfig();
        $variable = [];
        $eid = 1;
        //Values für Liste vorbereiten (vorhandene Variabeln)
        foreach ($mbConfig as $ident => $config) {
            if (array_key_exists($config['Group'], $values) === false){//Gruppe exstiert im Tree noch nicht
                $values[$config['Group']] = ["Group" => $config['Group'], "Ident" => "", "id" => $eid++, "parent" => 0, "rowColor" => "#DFDFDF", "expanded" => false, "editable" => false, "deletable" => false];
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
        $form = file_get_contents(__DIR__ . "/form.json");
        $form = str_replace("\$DeviceString", $device['String'], $form);
        foreach ($device as $ident => $value) {
            $diValues[] = ["Name" => $ident, "Value" => $value];
        }
        $form = str_replace('"$DeviceInfoValues"', json_encode($diValues), $form); //Values für 'DeviceInfos' setzen
        $form = str_replace('"$IdentListValues"', json_encode(array_values($values)), $form); //Values für 'IdentList' setzen
        $form = str_replace('$EventCreated', $this->Translate("Event was created. Please check/change settings."), $form); //Übersetzungen einfügen
        return $form;
    }

    /**
    * Fügt einen Ident zu PollIdents hinzu oder entfernt ihn
    * @param string $Submit json_codiertes Array(boolean Poll, string Ident, string PollIdents)
    * @access private
    */
    private function FormEditIdent(string $Submit){
        $Submit = json_decode($Submit, 1);
        $poll = $Submit[0];
        $ident = $Submit[1];
        $pollIdents = $Submit[2];
        $pollIdents = trim(str_replace(" $ident ", " ", " $pollIdents "));//Ident aus der Liste löschen
        if ($poll){//Ident hinzufügen, wenn dieser aktiviert wurde
            $pollIdents = "$pollIdents $ident";
        }        
        $this->UpdateFormField("PollIdents", "value", trim($pollIdents));
    }

    /**
    * Fügt einen Ident zur Liste für CreateEvent hinzu
    * @param string $Submit json_codiertes Array(string Type, string ReadIdents)
    * @access private
    */
    private function FormAddIdent(string $Submit){
        $Submit = json_decode($Submit, 1);
        $lastType = $this->GetBuffer("lastType");
        $type = $Submit[0];
        $ident = $Submit[1];
        if ($lastType !== $type){
            $ident = @array_pop(explode(" ", $ident));//letzen (neuen) Wert nehmen
        } else {
            $ident = implode(" ", array_unique(explode(" ", $ident)));//doppelte Werte entfernen
        }
        $this->UpdateFormField("CreateEvent", "enabled", ($ident !== ""));
        $this->UpdateFormField("RequestRead", "caption", static::PREFIX."_RequestRead$type");
        $this->UpdateFormField("RequestRead", "value", $ident);
        $this->SetBuffer("lastType", $type);
    }

    /**
    * Erstellt einen Event mit Action RequestReadGroup/Ident($Idents)
    * @param string string $Submit json_codiertes Array(string EventName, string EventType, string Ident(s))
    * @access private
    */
    private function CreateEvent(string $Submit){
        $Submit = json_decode($Submit, 1);
        $eventName = $Submit[0];
        $eventType = $Submit[1];
        $idents = $Submit[2];
        $type = $this->GetBuffer("lastType");
        $action = static::PREFIX."_RequestRead$type(\$_IPS['TARGET'], '$idents');";
        $eId = IPS_CreateEvent($eventType);
        IPS_SetParent($eId, $this->InstanceID);
        IPS_SetName($eId, $eventName);
        IPS_SetEventScript($eId, $action);
        if ($eventType == EVENTTYPE_CYCLIC){
            IPS_SetEventCyclic($eId, 0, 0, 0, 0, 1, 30);//alle 30 Sekunden
        }
        IPS_SetEventActive($eId, false); 
        $this->UpdateFormField("OpenEvent", "caption", sprintf($this->Translate("Check Event (%s)"), $eId));
        $this->UpdateFormField("OpenEvent", "objectID", $eId);
        $this->UpdateFormField("OpenEvent", "visible", true);
        IPS_LogMessage(static::PREFIX, "INSTANCE: ".$this->InstanceID." ACTION: CreateEvent: #$eId - ".$this->Translate("Event was created. Please check/change settings."));
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
        $force = $this->ReadPropertyString("PollIdents");//$force = true wird über die Funktion RequestReadAll aktiviert oder String mit Ident über die Funktion RequestReadIdent/Group
        if (func_num_args() == 1){//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit der PREFIX_Funktion erstellt wird
            $force = func_get_arg(0);
        };
        $mbConfig = $this->GetModBusConfig();

        //Prüfe auf ungültige Idents
        if (is_string($force)){
            $unknown = array_diff(explode(" ", $force), array_keys($mbConfig));
            if (count($unknown) > 0){
                $msg = $this->Translate("Unknown Ident(s)").": ".implode(", ", $unknown);
                $this->SendDebug("RequestRead", $msg, 0);
                echo "INSTANCE: ".$this->InstanceID." ACTION: RequestRead: $msg\r\n";
            }
        }

        //ModBus-Abfrage durchführen
        $values = [];
        foreach ($mbConfig as $ident => $config){//Loop durch $mbConfig, damit Werte auch ausgelesen werden können, wenn Instanz noch nicht gespeichert ist. Dadurch werden auch nur gültige ModBus-Configs abgefragt.
            //Wenn $force true ODER aktuelle Variable in Liste der angeforderten/gepollten Idents (strpos mit Leerzeichen, da mehrere Idents ebenfalls mit Leerzeichen getrennt werden).
            if ($force === true || (is_string($force) && strpos(" $force ", " $ident ") !== false)){
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
        $Ident = trim($Ident);
        if ($Ident == ""){
            echo "INSTANCE: ".$this->InstanceID." ACTION: RequestReadIdent: ".$this->Translate("Ident(s) can not be empty!")."\r\n";
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
        if (trim($Group) == ""){
            echo "INSTANCE: ".$this->InstanceID." ACTION: RequestReadGroup: ".$this->Translate("Group(s) can not be empty!")."\r\n";
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
            echo "INSTANCE: ".$this->InstanceID." ACTION: RequestReadGroup: ".sprintf($this->Translate("No idents found for group(s) '%s'!"), $Group)."\r\n";
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