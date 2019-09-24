<?php
/***************************************************************************************************
 * @Package:		 libs                                                                     *
 * @File:			 JoT_ModBus.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 24.09.2019 20:09:59                                                           *
 * @Modified By:	 Jonathan Tanner                                                               *
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner                                               *
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0                   *
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)                  *
 ***************************************************************************************************/


require_once(__DIR__ . "/../libs/JoT_Traits.php");  //Bibliothek mit allgemeinen Definitionen & Traits

/**
 * JoTModBus ist die Basisklasse für erweiterte ModBus-Funktionalität.
 * Erweitert die Klasse IPSModule.
 */
class JoTModBus extends IPSModule {
    protected const VT_Boolean = VARIABLETYPE_BOOLEAN;
    protected const VT_Integer = VARIABLETYPE_INTEGER;
    protected const VT_UnsignedInteger = 10;
    protected const VT_SignedInteger = VARIABLETYPE_INTEGER;
    protected const VT_Float = VARIABLETYPE_FLOAT;
    protected const VT_String = VARIABLETYPE_STRING;
    protected const MB_BigEndian = 0;//ABCD=>ABCD
    protected const MB_BigEndian_WordSwap = 1;//ABCD=>CDAB
    protected const MB_LittleEndian = 2;//ABCD=>BADC
    protected const MB_LittleEndian_WordSwap = 3;//ABCD=>DCBA
    protected const FC_Read_Coil = 1;
    protected const FC_Read_DiscreteInput = 2;
    protected const FC_Read_HoldingRegisters = 3;
    protected const FC_Read_InputRegisters = 4;
    protected const FC_Write_SingleCoil = 5;
    protected const FC_Write_SingleHoldingRegister = 6;
    protected const FC_Write_MultipleCoils = 15;
    protected const FC_Write_MultipleHoldingRegisters = 16;
    private const PREFIX = "JoTMB";
    private $CurrentAction = false;//Für Error-Handling bei Lese-/Schreibaktionen
    
    use VariableProfile;
    //use Semaphore;

    /**
     * Interne Funktion des SDK.
     * Initialisiert Connection.
     * @access public
     */
    public function Create(){
        parent::create();
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");//ModBus Gateway
    }

    /**
     * Interne Funktion des SDK.
     * Wird ausgeführt wenn die Konfigurations-Änderungen gespeichet werden.
     * @access public
     */
    public function ApplyChanges(){
        parent::ApplyChanges();
    }
    
    /**
     * Liest ModBus-Daten vom Gerät aus
     * @param int $Function - ModBus-Function (siehe const FC_Read_xyz)
     * @param int $Address - Start-Register
     * @param int $Quantity - Anzahl der zu lesenden Register
     * @param int/float $Factor - Multiplikationsfaktor für gelesenen Wert
     * @param int $MBType - Art der Daten-Übertragung (siehe const MB_xyz)
     * @param int $VarType - Daten-type der abgefragten Werte (siehe const VT_xyz)
     * @return mixed der angeforderte Wert oder NULL im Fehlerfall
     */
    protected function ReadModBus(int $Function, int $Address, int $Quantity, $Factor, int $MBType, int $VarType){
        if ($Function > self::FC_Read_InputRegisters){
            echo "Wrong Function ($Function) for Read. Please use WriteModBus.";
            return null;
        }

        if ($this->CheckConnection() === true){
            //Daten für ModBus-Gateway vorbereiten
            $sendData = [];
            $sendData['DataID'] = "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}";
            $sendData['Function'] = $Function;
            $sendData['Address'] = $Address;
            $sendData['Quantity'] = $Quantity;
            $sendData['Data'] = "";

            //Error-Handler setzen und Daten lesen
            set_error_handler([$this, 'ModBusErrorHandler']);
            $this->CurrentAction = ['Action' => "ReadModBus", 'Data' => $sendData];
            $readData = $this->SendDataToParent(json_encode($sendData));
            restore_error_handler();
            $this->CurrentAction = false;

            //Daten auswerten
            if ($readData !== false) {//kein Fehler - empfangene Daten verarbeiten
                $readValue = substr($readData, 2);//Geräte-Adresse & Funktion aus der Antwort entfernen
                $this->SendDebug("ReadModBus FC $Function Addr $Address x $Quantity RAW", $readValue, 1);
                $value = $this->SwapValue($MBType, $readValue);
                $value = $this->ConvertMBtoPHP($VarType, $value);
                $value = $this->CalcFactor($value, $Factor);
                return $value;
            }
        }
        return null;
    }

    /**
     * Prüft ob alle notwendigen Verbindungs-Instanzen konfiguriert sind
     * @return boolen true, wenn alles i.O.
     */
    private function CheckConnection(){
        $gateway = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($gateway == 0) {//kein Gateway gesetzt
            return false;
        }
        $io = IPS_GetInstance($gateway)['ConnectionID'];
        if ($io == 0) {//kein I/O für Gateway gesetzt
            return false;
        }
        return true;
    }

    /**
     * PHP Error-Handler - wird aufgerufen wenn ModBus einen Fehler zurück gibt
     * @param int $errNr PHP-ErrorNummer
     * @param string $errMsg PHP-Fehlermeldung
     * @access private
     */
    public function ModBusErrorHandler(int $ErrLevel, string $ErrMsg){
        $action = "";
        if (is_array($this->CurrentAction)){
            $action = $this->CurrentAction['Action']." ";
            $error = utf8_decode($ErrMsg);
            $function = $this->CurrentAction['Data']['Function'];
            $address = $this->CurrentAction['Data']['Address'];
            $quantity = $this->CurrentAction['Data']['Quantity'];
            $data = $this->CurrentAction['Data']['Data'];
            $ErrMsg = "ModBus-Message: $error (Function: $function, Address: $address, Quantity: $quantity, Data: $data)";
        } 
        $this->SendDebug("$action ERROR", $ErrMsg, 0);
    }

    /**
     * Führt einen Swap gemäss $MBType für $Value durch
     * @param string $MBType ModBus Datenübertragungs-Typ
     * @param string $Value Wert für Swap
     * @return string umgekehrte Zeichenfolge von $value
     * @access protected
     */
    protected function SwapValue(int $MBType, string $Value){
        switch ($MBType) {
            case self::MB_BigEndian://ABCD => ABCD
                $swap = "ABCD => ABCD";
                break;
            case self::MB_BigEndian_WordSwap://ABCD => CDAB  
                $Value = $this->WordSwap($Value);
                $swap = "ABCD => CDAB";
                break;
            case self::MB_LittleEndian://ABCD => BADC
                $Value = $this->LittleEndian($Value);
                $swap = "ABCD => BADC";
                break;
            case self::MB_LittleEndian_WordSwap://ABCD => DCBA
                $Value = $this->LittleEndian($Value);
                $Value = $this->WordSwap($Value);
                $swap = "ABCD => DCBA";
                break;
        }
        $this->SendDebug("SwapValue MBType $MBType $swap", $Value, 1);
        return $Value;
    }

    /**
     * Vertauscht immer zwei Wörter (16 Bit-Blöcke) in $Value (=>WordSwap)
     * @param string $Value Original-Wert
     * @return string $Value mit vertauschten Wörtern
     * @access private
     */
    private function WordSwap(string $Value){
        $Words = str_split($Value, 2);//Ein Word besteht aus zwei Zeichen à 8 Bit = 16 Bit (oder 1 ModBus Register)
        if (count($Words) > 1){
            $Value = "";
            $x = 0;
            while (($x+1) < count($Words)){
                $Value = $Value . $Words[$x+1] . $Words[$x];
                $x = $x+2;
            }
        }
        return $Value;
    }

    /**
     * Vertauscht immer zwei Zeichen (8 Bit-Blöcke) in $Value (=>LittleEndian)
     * @param string $Value Original-Wert
     * @return string $Value mit vertauschten Zeichen
     * @access private
     */
    private function LittleEndian(string $Value){
        $Chars = str_split($Value, 1);
        if (count($Chars) > 1){
            $Value = "";
            $x = 0;
            while (($x+1) < count($Chars)){
                $Value = $Value . $Chars[$x+1] . $Chars[$x];
                $x = $x+2;
            }
        }
        return $Value;
    }

    /**
     * Konvertiert $Value in den entsprechenden PHP-DatenTyp
     * @param int $VarType der ModBus-Datentyp
     * @param string $Value die ModBus-Daten
     * @return mixed Konvertierte Daten oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function ConvertMBtoPHP(int $VarType, string $Value){
        $quantity = strlen($Value);
        switch ($VarType) {
            case self::VT_Boolean:
                $Value = ord($Value) == 0x01;
                break;
            case self::VT_SignedInteger:
                $Value = intval(bin2hex($Value), 16);
                break;
            case self::VT_UnsignedInteger:
                $Value = hexdec(bin2hex($Value));
                break;
            case self::VT_Float:
                if ($quantity < 2){//String ist zu kurz für Float
                    return null;
                }
                $Value = unpack("G", $Value)[1]; //Gleitkommazahl (maschinenabhängige Größe, Byte-Folge Big Endian)
                break;
            case self::VT_String:
                $Value = trim($Value);
                break;
            default:
                return null;
        }
        $this->SendDebug("ConvertMBtoPHP VarType $VarType", $Value, 0);
        return $Value;
    }

    /**
    * Multipliziert $Value mit $Factor, sofern $Value kein String oder $Factor nicht 0 ist
    * @param mixed $Value - Original-Wert
    * @param int|float $Factor - Multiplikationswert
    * @return mixed Ergebnis der Berechnung oder original $Value
    * @access private
    */
    private function CalcFactor($Value, $Factor){
        if (is_string($Value) || $Factor == 0){
            return $Value;
        }
        $nValue =  $Value * $Factor;
        $this->SendDebug("CalcFactor Factor $Factor", strval($nValue), 0);
        return $nValue;
    }

    /**
    * Ermittelt den korrekten Variablen-Typ für eine Instanz-Variable basierend auf ModBus-DatenTyp & -Faktor
    * @param int $VarType ist der ModBus-DatenTyp
    * @param $Factor ist der ModBus-Faktor
    * @return int IPS Variablen-Type
    * @access protected
    */
    protected function GetIPSVarType(int $VarType, $Factor){
        if ($VarType == self::VT_UnsignedInteger){//PHP kennt keine Unsigned Integer für Ziel-Variable, ModBus schon
            $VarType = VARIABLETYPE_INTEGER;
        }
        if ($VarType == self::VT_Integer && is_float($Factor)){//Wenn Faktor Float ist muss Ziel-Variable ebenfalls Float sein
            $VarType = VARIABLETYPE_FLOAT;
        }
        return $VarType;
    }
}
