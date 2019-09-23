<?php
/***************************************************************************************************
 * @Package:		 KostalPlenticorePlus                                                          *
 * @File:			 module.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 30.05.2019 17:20:06                                                           *
 * @Modified By:	 Jonathan Tanner                                                               *
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner                                               *
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0                   *
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)                  *
 ***************************************************************************************************/


require_once(__DIR__ . "/../libs/JoTModBusModule.php");  //Klasse mit Allgemeinen ModBus-Funktionen

/**
 * KostalPlenticorePlus ist die Klasse für die Wechselrichter Plenticore Plus der Firma KOSTAL Solar Electric GmbH.
 * Erweitert die Klasse JoTModBus.
 */
class KostalPlenticorePlus extends JoTModBus {
    protected const PREFIX = "JoTKPP";

    public static $ModuleVariables = [
        //[Name/IDENT, VariableType, Profile, Factor, Address, Function, Quantity, ModBus Type, Keep],
        //System-Status
        ['Inverter state', self::VT_UnsignedInteger, 'Status.KostalWR', null, 0x38, 3, 2, self::MB_LittleEndian, true],
        ['Energy Manager state', self::VT_Float, 'Status.KostalEM', null, 0x68, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['PSSB fuse state', self::VT_Float, 'Status.KostalPSSBFuse', null, 0xCA, 3, 2, self::MB_BigEndian_WordSwap, true],
        //Eigenverbrauch
        ['Home own consumption from grid', self::VT_Float, '~Power', 0.001, 0x6C, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Home own consumption from battery', self::VT_Float, '~Power', 0.001, 0x6A, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Home own consumption from PV', self::VT_Float, '~Power', 0.001, 0x74, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total home consumption Grid', self::VT_Float, '~Electricity', 0.001, 0x70, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total home consumption Battery', self::VT_Float, '~Electricity', 0.001, 0x6E, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total home consumption PV', self::VT_Float, '~Electricity', 0.001, 0x72, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total home consumption', self::VT_Float, '~Electricity', 0.001, 0x76, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total home consumption rate', self::VT_Float, 'Percent', 1, 0x7C, 3, 2, self::MB_BigEndian_WordSwap, true],
        //AC-Seite
        ['Power limit from EVU', self::VT_Float, 'Percent', 1, 0x7A, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Grid frequency', self::VT_Float, '~Hertz', 1, 0x98, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Current Phase 1', self::VT_Float, '~Ampere', 1, 0x9A, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Active power Phase 1', self::VT_Float, '~Power', 0.001, 0x9C, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage Phase 1', self::VT_Float, '~Volt', 1, 0x9E, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Current Phase 2', self::VT_Float, '~Ampere', 1, 0xA0, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Active power Phase 2', self::VT_Float, '~Power', 0.001, 0xA2, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage Phase 2', self::VT_Float, '~Volt', 1, 0xA4, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Current Phase 3', self::VT_Float, '~Ampere', 1, 0xA6, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Active power Phase 3', self::VT_Float, '~Power', 0.001, 0xA8, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage Phase 3', self::VT_Float, '~Volt', 1, 0xAA, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total AC active power', self::VT_Float, '~Power', 0.001, 0xAC, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total AC reactive power', self::VT_Float, 'Power.VA', 0.001, 0xAE, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total AC apparent power', self::VT_Float, 'Power.VaR', 0.001, 0xB2, 3, 2, self::MB_BigEndian_WordSwap, true],
        //Batterie
        ['Actual battery current', self::VT_Float, '~Ampere', 1, 0xC8, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Actual battery power', self::VT_SignedInteger, '~Power', 0.001, 0x246, 3, 1, self::MB_BigEndian_WordSwap, true],
        ['Actual state of charge', self::VT_Float, 'Battery.Charge', 1, 0xD2, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Battery temperature', self::VT_Float, '~Temperature', 1, 0xD6, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Battery voltage', self::VT_Float, '~Volt', 1, 0xD8, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Number of battery cycles', self::VT_Float, null, 1, 0xC2, 3, 2, self::MB_BigEndian_WordSwap, true],
        //Photovoltaik
        ['Current DC1', self::VT_Float, '~Ampere', 1, 0x102, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Power DC1', self::VT_Float, '~Power', 0.001, 0x104, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage DC1', self::VT_Float, '~Volt', 1, 0x10A, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Current DC2', self::VT_Float, '~Ampere', 1, 0x10C, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Power DC2', self::VT_Float, '~Power', 0.001, 0x10E, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage DC2', self::VT_Float, '~Volt', 1, 0x114, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Current DC3', self::VT_Float, '~Ampere', 1, 0x116, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Power DC3', self::VT_Float, '~Power', 0.001, 0x118, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Voltage DC3', self::VT_Float, '~Volt', 1, 0x11E, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total DC power', self::VT_Float, '~Power', 0.001, 0x64, 3, 2, self::MB_BigEndian_WordSwap, true],
        //Ertrag
        ['Daily yield', self::VT_Float, '~Electricity', 0.001, 0x142, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Monthly yield', self::VT_Float, '~Electricity', 0.001, 0x146, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Yearly yield', self::VT_Float, '~Electricity', 0.001, 0x144, 3, 2, self::MB_BigEndian_WordSwap, true],
        ['Total yield', self::VT_Float, '~Electricity', 0.001, 0x140, 3, 2, self::MB_BigEndian_WordSwap, true],
        //Zusatzinfos
        ['Inverter article number', self::VT_String, null, null, 0x06, 3, 8, self::MB_BigEndian, true],
        ['Inverter serial number', self::VT_String, null, null, 0x0E, 3, 8, self::MB_BigEndian, true],
        ['Inverter network name ', self::VT_String, null, null, 0x180, 3, 32, self::MB_BigEndian, true],
        ['Number of AC phases', self::VT_UnsignedInteger, null, null, 0x20, 3, 1, self::MB_LittleEndian, true],
        ['Number of PV strings', self::VT_UnsignedInteger, null, null, 0x22, 3, 1, self::MB_LittleEndian, true],
        ['Hardware-Version', self::VT_UnsignedInteger, null, null, 0x24, 3, 1, self::MB_LittleEndian, true],
        ['Software-Version MC', self::VT_String, null, null, 0x26, 3, 8, self::MB_BigEndian, true],
        ['Software-Version IOC', self::VT_String, null, null, 0x2E, 3, 8, self::MB_BigEndian, true],
        ['Worktime', self::VT_Float, null, 1, 0x90, 3, 2, self::MB_BigEndian_WordSwap, true], //Profil/Umrechnung definieren (Wert in Sekunden)
        ['Battery charge current', self::VT_Float, '~Ampere', 1, 0xBE, 3, 2, self::MB_BigEndian_WordSwap, true],
    ];
    public static $HiddenVariables = [
        //[Name/IDENT, VariableType, Profile, Factor, Address, Function, Quantity, Keep],
        ['Inverter article number', self::VT_String, null, null, 0x06, 3, 8, self::MB_BigEndian, true],
        ['Inverter serial number', self::VT_String, null, null, 0x0E, 3, 8, self::MB_BigEndian, true],
        ['Inverter network name ', self::VT_String, null, null, 0x180, 3, 32, self::MB_BigEndian, true],
        ['Number of AC phases', self::VT_UnsignedInteger, null, null, 0x20, 3, 1, self::MB_LittleEndian, true],
        ['Number of PV strings', self::VT_UnsignedInteger, null, null, 0x22, 3, 1, self::MB_LittleEndian, true],
        ['Hardware-Version', self::VT_UnsignedInteger, null, null, 0x24, 3, 1, self::MB_LittleEndian, true],
        ['Software-Version MC', self::VT_String, null, null, 0x26, 3, 8, self::MB_BigEndian, true],
        ['Software-Version IOC', self::VT_String, null, null, 0x2E, 3, 8, self::MB_BigEndian, true],
        ['Worktime', self::VT_Float, null, 1, 0x90, 3, 2, self::MB_BigEndian_WordSwap, true], //Profil/Umrechnung definieren (Wert in Sekunden)
        ['Battery charge current', self::VT_Float, '~Ampere', 1, 0xBE, 3, 2, self::MB_BigEndian_WordSwap, true],
    ];
    public static $ModuleProfiles = [
        //[Name, VarType, Icon, Prefix, Suffix, MinValue, MaxValue, StepSize, Digits, Associations[[Value, Name, Icon, Color]]]
        ['Status.KostalWR', self::VT_Integer, 'Graph', '', '', 0, 0, 0, 0, [
            [0, 'Off', '', -1],
            [1, 'Initialization', '', 0x0000ff],
            [2, 'ISO Meassuring', '', 0x0000ff],
            [3, 'Grid Check', '', 0x0000ff],
            [4, 'StartUp', '', 0x0000ff],
            [6, 'Feed In', '', 0x00ff00],
            [7, 'Throttled', '', 0x00ff00],
            [8, 'External Switch Off', '', -1],
            [9, 'Update', '', -1],
            [10, 'Standby', '', -1],
            [11, 'Grid Syncronisation', '', 0x0000ff],
            [12, 'Grid PreCheck', '', 0x0000ff],
            [13, 'Grid Switch Off', '', -1],
            [14, 'Overheating', 'Error', 0xff0000],
            [15, 'Shutdown', '', -1],
            [16, 'Improper DC Voltage', 'Warning', 0xff9800],
            [17, 'ESB', '', -1],
            [18, 'Unknown', 'Warning', 0xff9800]
        ]],
        ['Status.KostalEM', self::VT_Float, 'Graph', '', '', 0, 0, 0, 0, [
            [0, 'Idle', 'Ok', -1],
            [2, 'Emergency Battery Charge', 'EnergyProduction', -1],
            [8, 'Winter Mode Step 1', 'Sleep', -1],
            [10, 'Winter Mode Step 2', 'Sleep', -1]
        ]],
        ['Status.KostalPSSBFuse', self::VT_Float, 'Graph', '', '', 0, 0, 0, 0, [
            [0, 'Fuse Fail', 'Warning', 0xff0000],
            [1, 'Fuse Ok', 'Ok', -1],
            [0xFF, 'Unknown', 'Warning', 0xff9800]
        ]],
        ['Power.VA', self::VT_Float, 'Electricity', '', ' VA', 0, 0, 0, 0],
        ['Power.VaR', self::VT_Float, 'Electricity', '', ' VaR', 0, 0, 0, 0],
        ['Percent', self::VT_Float, '', '', ' %', 0, 100, 0, 1],
        ['Battery.Charge', self::VT_Float, 'Battery', '', '', 0, 100, 0, 1, [
            [0, 'Empty (%s%%)', '', 0xff0000],
            [1, 'Low (%s%%)', '', 0xff9800],
            [10, '%s%%', '', 0x00ff00],
            [99, 'Full (%s%%)', '', 0x00ff00]
        ]],
    ];

    /**
     * Interne Funktion des SDK.
     * Initialisiert Modul-spezifische Funktionen.
     *
     * @access public
     */
    public function Create(){
        parent::Create();
        
        $FirmwareUpdateURL = "https://www.kostal-solar-electric.com/download/download#PLENTICORE%20plus/PLENTICORE%20plus%207.0/Schweiz/Update/";
        $this->RegisterPropertyString('FirmwareUpdateURL', $FirmwareUpdateURL);

    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function ApplyChanges(){
        parent::ApplyChanges();
        
        $eventIDENT = "CheckFirmwareUpdate";
        if (@IPS_GetObjectIDByIdent($eventIDENT , $this->InstanceID) == false){//Event für Firmware-Check einrichten
            $eventID = IPS_CreateEvent(EVENTTYPE_CYCLIC);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, $eventIDENT);
            IPS_SetName($eventID, $this->Translate("Check for Firmware Update"));
            IPS_SetEventCyclic($eventID, EVENTCYCLICDATETYPE_DAY, 1, 0, 0, 0, 0);//täglich
            IPS_SetEventCyclicTimeFrom($eventID, 17, 0, 0);//um 17:00
            IPS_SetEventCyclicDateFrom($eventID, intval(date("d")), intval(date("m")), intval(date("Y")));//ab Heute
            IPS_SetEventScript($eventID, static::PREFIX . '_CheckFirmwareUpdate($_IPS["TARGET"]);');
            IPS_SetEventActive ($eventID, true);
        };
    }

    /**
     * IPS-Instanz Funktion PREFIX_CheckFirmwareUpdate.
     * Kontrolliert die aktuelle Firmware-Version Online.
     *
     * @access public
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    public function CheckFirmwareUpdate(){
        //$FirmwareUpdateURL = $this->ReadPropertyString('FirmwareUpdateURL');
        $FirmwareUpdateURL = "https://www.kostal-solar-electric.com/download/download#PLENTICORE%20plus/PLENTICORE%20plus%207.0/Schweiz/Update/";
        
        $c = curl_init($FirmwareUpdateURL);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        $html = curl_exec($c);
        if ($html === false){
            die(curl_error($c));
        }
        curl_close($c);
        
        echo "HTML: $html";
        return true;
    }

}
