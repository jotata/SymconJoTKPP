<?php
/***************************************************************************************************
 * @Package:		 JoTKPP                                                          *
 * @File:			 module.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 29.09.2019 19:42:30                                                           *
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
    
    use VariableProfile;

    /**
     * Interne Funktion des SDK.
     * Initialisiert Properties, Attributes und Timer.
     * @access public
     */
    public function Create(){
        parent::Create();
        $this->ConfigProfiles(__DIR__."/ProfileConfig.json", ['$VT_Float$' => self::VT_Float, '$VT_Integer$' => self::VT_Integer]);
        $this->RegisterPropertyString("ModuleVariables", json_encode([]));
        $this->RegisterPropertyInteger("PollTime", 0);
        $this->RegisterTimer("UpdateTimer", 0, static::PREFIX . '_RequestRead($_IPS["TARGET"]);');
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
                $pos = array_search($mbConfig[$ident]['Group'], $groups);
                $this->MaintainVariable($ident, $mbConfig[$ident]['Name'], $varType, $profile, $pos, true);
            }
        }
        
        //Timer für Polling (de)aktivieren
        if ($this->ReadPropertyInteger('PollTime') > 0) {
            $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('PollTime')*1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

        //Event für CheckFirmwareUpdate erstellen
        $ident = "CheckFirmwareUpdate";
        if (@IPS_GetObjectIDByIdent($ident , $this->InstanceID) == false){//Event für Firmware-Check einmalig einrichten - es steht dem User danach frei, diesen beliebig anzupassen oder zu deaktivieren
            $eID = IPS_CreateEvent(EVENTTYPE_CYCLIC);
            IPS_SetParent($eID, $this->InstanceID);
            IPS_SetIdent($eID, $ident);
            IPS_SetName($eID, $this->Translate("Check for FW-update"));
            IPS_SetPosition($eID, 998);
            IPS_SetEventCyclic($eID, EVENTCYCLICDATETYPE_DAY, 1, 0, 0, 0, 0);//täglich
            IPS_SetEventCyclicTimeFrom($eID, 17, 0, 0);//um 17:00
            IPS_SetEventCyclicDateFrom($eID, intval(date("d")), intval(date("m")), intval(date("Y")));//ab Heute
            IPS_SetEventScript($eID, static::PREFIX . '_CheckFirmwareUpdate($_IPS["TARGET"]);');
            IPS_SetEventActive ($eID, true);
        };
    }

    /**
     * Interne Funktion des SDK.
     * Stellt Informationen für das Konfigurations-Formular zusammen
     * @return string JSON-Codiertes Formular
     * @access public
     */
    public function GetConfigurationForm(){
        //Values für Liste vorbereiten (vorhandene Variabeln)
        $mbConfig = $this->GetModBusConfig();
        $values = [];
        $variable = [];
        foreach ($mbConfig as $ident => $config){
            $variable['Ident'] = $ident;
            $variable['Group'] = $config['Group'];
            $variable['Name'] = $config['Name'];
            $variable['cName'] = "";
            $variable['Profile'] = $config['Profile'];
            $variable['cProfile'] = "";
            $variable['Poll'] = false;
            if (key_exists("Poll", $config)){
                //Übernimmt Poll nur initial bei Erstellung der Instanz (als Vorschlag), danach wird Poll von ModuleVariables überschrieben
                $variable['Poll'] = $config['Poll'];
            }
            if(($id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID)) !== false){//Falls Variable bereits existiert, deren Werte übernehmen
                $obj = IPS_GetObject($id);
                $var = IPS_GetVariable($id);
                if ($obj['ObjectName'] != $config['Name']){
                    $variable['cName'] = $obj['ObjectName'];
                }
                $variable['Pos'] = $obj['ObjectPosition'];
                $variable['cProfile'] = $var['VariableCustomProfile'];
            }
            $values[$ident] = $variable;
        }

        //Sortieren der Einträge - muss analog ModuleVariables sein (sonst entsteht bei neuen / geänderten Definitionen ein Durcheinander)
        $mvKeys = array_column(json_decode($this->ReadPropertyString("ModuleVariables"), 1), "Ident");
        $sValues = [];
        foreach ($mvKeys as $ident){
            if (key_exists($ident, $mbConfig) === false){//Definition wurde aus ModBusConfig.json entfernt/umbenannt - Sollte, falls einmal nötig, in eimem Update-Prozess aus ModuleVariables entfernt werden.
                $values[$ident]['Name'] = $this->Translate("ModBus-Definition for this entry does not exist anymore.");
                $values[$ident]['rowColor'] = "#FFC0C0";
            }
            $sValues[] = $values[$ident];
            unset($values[$ident]);
        }
        $values = array_merge($sValues, array_values($values));//neue Definitionen am Ende einfügen

        //Device-Info auslesen & anpassen
        $device = $this->RequestReadIdent("Manufacturer ProductName PowerClass SerialNr NetworkName");
        $device = $device['Manufacturer']." ".$device['ProductName']." ".$device['PowerClass']." (".$device['SerialNr'].") - ".$device['NetworkName'];
        if ($device == "   () - "){
            $device = $this->Translate("Device information could not be read. Gateway settings correct?");
        }
        //Formular vorbereiten
        $form = file_get_contents(__DIR__ . "/form.json");
        $form = str_replace('$Device$', $device, $form);//Wert für 'Device' setzen
        $form = str_replace('"$ModuleVariablesValues$"', json_encode($values), $form);//Values für 'ModuleVariables' neuindexiert setzen, damit neue Definitionen korrekt am Ende hinzugefügt werden
        //$form = str_replace('$PREFIX$', static::PREFIX, $form);//Prefix für Funktionen ersetzen
        //echo "Form: $form";
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
            $config = file_get_contents(__DIR__."/ModBusConfig.json");
            $config = str_replace('$FC_Read_HoldingRegisters$', self::FC_Read_HoldingRegisters, $config);
            $config = str_replace('$VT_String$', self::VT_String, $config);
            $config = str_replace('$VT_UnsignedInteger$', self::VT_UnsignedInteger, $config);
            $config = str_replace('$VT_Float$', self::VT_Float, $config);
            $config = str_replace('$VT_SignedInteger$', self::VT_SignedInteger, $config);
            $config = str_replace('$MB_BigEndian_WordSwap$', self::MB_BigEndian_WordSwap, $config);
            $config = str_replace('$MB_BigEndian$', self::MB_BigEndian, $config);
            $this->SetBuffer("ModBusConfig", $config);
        } 
        //JSON in Array umwandeln
        $aConfig = json_decode($config, true, 4);
        if (json_last_error() !== JSON_ERROR_NONE){//Fehler darf nur beim Entwicler auftreten (nach Anoassung der JSON-Daten). Wird daher direkt als echo ohne Übersetzung ausgegeben.
            echo("GetModBusConfig - Error in JSON (".json_last_error_msg()."). Please check Replacements and File-Content of ".__DIR__."/ModBusConfig.json");
            echo($config);
            exit;
        }
        return $aConfig;
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestRead.
     * Ließt alle/gewünschte Werte aus dem Gerät.
     * @param bool|string optional $force wenn auch nicht gepollte Values gelesen werden sollen.
     * @access public
     * @return array mit den angeforderten Werten.
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
            //Wenn ENTWEDER entsprechende Variable auf Poll ODER $force true ODER aktuelle Variable in Liste der angeforderten Idents
            if ((key_exists($ident, $mvKeys) && $moduleVariables[$mvKeys[$ident]]['Poll'] == true && $force === false) || $force === true || (is_string($force) && strpos($force, $ident) !== false)){
                $value = $this->ReadModBus($config['Function'], $config['Address'], $config['Quantity'], $config['Factor'], $config['MBType'], $config['VarType']);
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false){//Instanz-Variablen sind nur für Werte mit aktivem Polling vorhanden
                    $this->SetValue($ident, $value);
                }
                $values[$ident] = $value;
            }
        }
        return $values;
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
