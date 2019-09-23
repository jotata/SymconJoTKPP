<?php
/***************************************************************************************************
 * @Package:		 KostalPlenticorePlus                                                          *
 * @File:			 module.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 23.09.2019 18:39:59                                                           *
 * @Modified By:	 Jonathan Tanner                                                               *
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner                                               *
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0                   *
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)                  *
 ***************************************************************************************************/


require_once(__DIR__ . "/../libs/JoT_Traits.php");  //Bibliothek mit allgemeinen Definitionen & Traits
require_once(__DIR__ . "/../libs/JoT_ModBus.php");  //Bibliothek für ModBus-Intgration

/**
 * JoTKostalPlenticorePlus ist die Unterklasse für die Integration eines Kostal Wechselrichters Plenticore Plus.
 * Erweitert die Klasse JoTModBus, welche die ModBus- sowie die Modul-Funktionen zur Verfügung stellt.
 */
class JoTKostalPlenticorePlus extends JoTModBus {
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
        $this->RegisterPropertyString('ModuleVariables', json_encode([]));
        $this->RegisterPropertyInteger('PollTime', 0);
        $this->RegisterTimer('UpdateTimer', 0, static::PREFIX . '_RequestRead($_IPS["TARGET"]);');
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn die Konfigurations-Änderungen gespeichet werden.
     * @access public
     */
    public function ApplyChanges(){
        parent::ApplyChanges();
        $moduleVariables = json_decode($this->ReadPropertyString('ModuleVariables'), 1);
        $mbConfig = $this->GetModBusConfig();

        //Bestehende Instanz-Variablen pflegen...
        foreach ($moduleVariables as $var){
            if ($var['Poll'] == false || !key_exists($var['Ident'], $mbConfig)){//wenn nicht gepollt oder in ModBusConfig nicht mehr vorhanden
                $this->UnregisterVariable($var['Ident']);
            } else if (@IPS_GetObjectIDByIdent($var['Ident'], $this->InstanceID) === false){//wenn Instanz-Variable nicht vorhanden
                $varType = $this->GetIPSVarType($mbConfig[$var['Ident']]['VarType'], $mbConfig[$var['Ident']]['Factor']);
                $profile = $this->CheckProfileName($mbConfig[$var['Ident']]['Profile']);
                $this->MaintainVariable($var['Ident'], $mbConfig[$var['Ident']]['Name'], $varType, $profile, 0, true);
            }
        }
        
        //Timer für Polling (de)aktivieren
        if ($this->ReadPropertyInteger('PollTime') > 0) {
            $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('PollTime')*1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }
    }

    /**
     * Interne Funktion des SDK.
     * Stellt Informationen für das Konfigurations-Formular zusammen
     * @return string JSON-Codiertes Formular
     * @access public
     */
    public function GetConfigurationForm(){
        //Values für Liste vorbereiten (vorhandene Variabeln)
        $modBusConfig = $this->GetModBusConfig();
        $values = [];
        $variable = [];
        foreach ($modBusConfig as $ident => $config){
            $variable['Ident'] = $ident;
            $variable['Group'] = $config['Group'];
            $variable['Name'] = $config['Name'];
            $variable['cName'] = "";
            $variable['Profile'] = $config['Profile'];
            $variable['cProfile'] = "";
            $variable['Pos'] = count($values) + 1;
            if(($id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID)) !== false){//Falls Variable bereits existiert, deren Werte übernehmen
                $obj = IPS_GetObject($id);
                $var = IPS_GetVariable($id);
                if ($obj['ObjectName'] != $config['Name']){
                    $variable['cName'] = $obj['ObjectName'];
                }
                $variable['Pos'] = $obj['ObjectPosition'];
                $variable['cProfile'] = $var['VariableCustomProfile'];
            }
            $values[] = $variable;
        }
        //Formular vorbereiten
        $form = file_get_contents(__DIR__ . "/form.json");
        $form = str_replace('$ModuleVariablesValues$', json_encode($values), $form);//Values für 'ModuleVariables' setzen
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
            $config = str_replace('$MB_LittleEndian$', self::MB_LittleEndian, $config);
            $this->SetBuffer("ModBusConfig", $config);
        } 
        //JSON in Array umwandeln
        $aConfig = json_decode($config, true, 4);
        if (json_last_error() !== JSON_ERROR_NONE){
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
     * @return array mit den angeforderten Werten
     */
    public function RequestRead(){
        $force = false;//$force = true wird über die Funktion RequestReadAll aktiviert oder String mit Ident über die Funktion RequestReadIdent
        if (func_num_args() == 1){//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit der PREFIX_Funktion erstellt wird
            $force = func_get_arg(0);
        };

        $mbConfig = $this->GetModBusConfig();
        $moduleVariables = json_decode($this->ReadPropertyString('ModuleVariables'), 1);
        $values = [];
        foreach ($moduleVariables as $var){
            $ident = $var['Ident'];
            //Wenn ModBus-Konfiguration exisitert UND ENTWEDER entsprechende Variable auf Poll ODER $force true ODER aktuelle Variable in Liste der angeforderten Idents
            if (key_exists($ident, $mbConfig) && (($var['Poll'] == true && $force === false) || $force === true || (is_string($force) && strpos($force, $ident) !== false))){
                $config = $mbConfig[$ident];
                $value = $this->ReadModBus($config['Function'], $config['Address'], $config['Quantity'], $config['Factor'], $config['MBType'], $config['VarType']);
                if (($id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID)) !== false){//Instanz-Variablen sind nur für Werte mit aktivem Polling vorhanden
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
     * @return array mit allen Werten
     */
    public function RequestReadAll(){
        return $this->RequestRead(true);
    }

    /**
     * IPS-Instanz Funktion PREFIX_RequestReadIdent.
     * Ruft PREFIX_RequestRead($Ident) auf
     * @param string $Ident - eine mit Leezeichen getrennte Liste aller zu lesenden Idents
     * @access public
     * @return array mit den angeforderten Werten
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
     * @param string $Group - eine mit Leezeichen getrennte Liste aller zu lesenden Gruppen
     * @access public
     * @return array mit den angeforderten Werten
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
}
