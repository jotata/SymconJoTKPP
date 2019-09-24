<?php
/***************************************************************************************************
 * @Package:		 libs                                                                     *
 * @File:			 JoT_ModBus.php                                                                    *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 24.09.2019 19:48:00                                                           *
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
        $ident = "?";
        if (is_array($this->CurrentAction)){
            $action = $this->CurrentAction['Action']." ";
            $error = utf8_decode($ErrMsg);
            $function = $this->CurrentAction['Data']['Function'];
            $address = $this->CurrentAction['Data']['Address'];
            $quantity = $this->CurrentAction['Data']['Quantity'];
            $data = $this->CurrentAction['Data']['Data'];
            $ErrMsg = "ModBus-Message: $error (Function: $function, Address: $address, Quantity: $quantity, Data: $data)";
        } 
        $this->SendDebug($action."Ident: $ident ERROR", $ErrMsg, 0);
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
                /*switch ($quantity) {
                    case 1://16 Bit
                        $Value = unpack("n", $Value)[1];//vorzeichenloser Short-Typ (immer 16 Bit, Byte-Folge Big Endian)
                        break;
                    case 2://32 Bit
                        $Value = unpack("N", $Value)[1];//vorzeichenloser Long-Typ (immer 32 Bit, Byte-Folge Big Endian)
                        break;
                    case 4://64 Bit
                        $Value = unpack("J", $Value)[1];//vorzeichenloser Long-Long-Typ (immer 64 bit, Byte-Folge Big Endian)
                        break;
                    default:
                        return null;
                }*/
                $Value = intval(bin2hex($Value), 16);
                break;
            case self::VT_UnsignedInteger:
                /*switch ($quantity) {
                    case 1://16 Bit
                        $Value = unpack("s", $Value)[1];//vorzeichenbehafteter Short-Typ (immer 16 Bit, Byte-Folge maschinenabhängig)
                        break;
                    case 2://32 Bit
                        $Value = unpack("l", $Value)[1];//vorzeichenbehafteter Long-Typ (immer 32 Bit, Byte-Folge maschinenabhängig)
                        break;
                    case 4://64 Bit
                        $Value = unpack("q", $Value)[1];//vorzeichenbehafteter Long-Long-Typ (immer 64 bit, maschinenabhängig)
                        break;
                    default:
                        return null;
                }*/
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


//********************************************************************************************************************************** */
    /**
     * IPS-Instanz Funktion PREFIX_RequestRead.
     * Ließt alle Werte aus dem Gerät.
     * @param optional $force True, wenn auch nicht gepollte Values gelesen werden sollen.
     * @access public
     * @return bool True wenn Befehl erfolgreich ausgeführt wurde, sonst false.
     */
    public function _RequestRead(){
        $force = false;//$force = true wird über die Funktion RequestReadAll aktiviert oder String mit Ident über die Funktion RequestReadIdent
        if (func_num_args() == 1){ $force = func_get_arg(0); };//Intergation auf diese Art, da sonst in __generated.inc.php ein falscher Eintrag mit der PREFIX_Funktion erstellt wird
        
        $Gateway = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($Gateway == 0) {//kein Gateway gesetzt
            return false;
        }
        $IO = IPS_GetInstance($Gateway)['ConnectionID'];
        if ($IO == 0) {//kein I/O für Gateway gesetzt
            return false;
        }
        /* Wird Lock (für ModBus RTU) nicht direkt von Splitter übernommen?
        *  Ist Lock allenfalls bei vielen Values nötig, wenn die AbfrageZeit deutlich länger als der kleinste PollTimer ist?
        if (!$this->lock("ModBus.$IO")) {
            return false;
        }*/
        $Result = $this->ReadData($force);
        //IPS_Sleep(333);
        //$this->unlock("ModBus.$IO");
        return $Result;
    }

    /**
     * PHP Error-Handler - wird aufgerufen wenn ModBus einen Fehler zurück gibt
     * @param int $errNr PHP-ErrorNummer
     * @param string $errMsg PHP-Fehlermeldung
     * @access protected
     */
    protected function _ModuleErrorHandler(int $ErrLevel, string $ErrMsg){
        $action = "";
        $ident = "?";
        if (is_array($this->CurrentAction)){
            $action = $this->CurrentAction['Action']." ";
            $error = utf8_decode($ErrMsg);
            $ident = $this->CurrentAction['Ident'];
            $function = $this->CurrentAction['Data']['Function'];
            $address = $this->CurrentAction['Data']['Address'];
            $quantity = $this->CurrentAction['Data']['Quantity'];
            $data = $this->CurrentAction['Data']['Data'];
            $ErrMsg = "ModBus-Message: $error (Function: $function, Address: $address, Quantity: $quantity, Data: $data)";
        } 
        $this->SendDebug($action."Ident: $ident ERROR", $ErrMsg, 0);
    }

    /**
     * List die Statusvaiabeln via ModBus-Gateway ein
     * @param  bool|array $force wenn True, werden alle Statusvariabeln gelesen, wenn False, nur die mit aktivem Poll, wenn Array nur die mit übereinstimmendem Ident
     * @return bool gibt True bei Erfolg, False bei einem Fehler zurück
     * @access private
     */
    private function _ReadData($force){
        $Variables = json_decode($this->ReadPropertyString('ModuleVariables'), true);
        foreach ($Variables as $Variable) {
            if (@IPS_GetObjectIDByIdent($Variable['Ident'], $this->InstanceID) === false) {//Variable von User gelöscht oder durch ApplyChanges noch nicht angelegt
                continue;
            }
            if (is_bool($force) && !$force && !$Variable['Poll']) {//deaktivierte Variabeln überspringen (ausser bei $force = true)
                continue;
            }
            if (is_string($force)) {
                if (stripos("$force ", $Variable['Ident']." ") === false) {//Ident dieser Variable ist in $force nicht vorhanden
                    continue;
                }
                $force = str_ireplace($Variable['Ident']." ", "", "$force ");
            }
            //Daten für ModBus-Gateway vorbereiten
            $SendData['DataID'] = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
            $SendData['Function'] = $Variable['RFunction'];
            $SendData['Address'] = $Variable['RAddress'];
            $SendData['Quantity'] = $Variable['Quantity'];
            $SendData['Data'] = '';
            //Error-Handler setzen und Daten lesen
            set_error_handler([$this, 'ModuleErrorHandler']);
            $this->CurrentAction = ['Action' => "ReadData", 'Ident' => $Variable['Ident'], 'Data' => $SendData];
            $ReadData = $this->SendDataToParent(json_encode($SendData));
            restore_error_handler();
            $this->CurrentAction = false;
            if ($ReadData === false) {//Fehler beim Lesen des aktuellen Wertes - nächsten Wert versuchen
                continue;
            }
            //empfangene Daten verarbeiten
            $ReadValue = substr($ReadData, 2);
            $this->SendDebug("ReadData Ident: ".$Variable['Ident']." RAW", $ReadValue, 1);
            $Value = $this->ConvertValue($Variable, $ReadValue);
            if ($Value === null) {
                $logMsg = sprintf($this->Translate('Combination of DataType (%1$s) and Quantity (%2$s) not supportet. Please check settings for Ident \'%3$s\'.'), $Variable['VarType'], $Variable['Quantity'], $Variable['Ident']);
                $this->SendDebug("ReadData Ident: ".$Variable['Ident']." ERROR", $logMsg, 0);
                $this->LogMessage($logMsg, KL_ERROR);
                continue;
            }
            $this->SendDebug("ReadData Ident: ".$Variable['Ident']." FINAL", $Value, 0);
            $this->SetValue($Variable['Ident'], $Value);
        }
        if (is_string($force) && strlen(trim($force)) > 0) {//ungültiger Ident in $force
            echo $this->Translate("Unknown Ident(s)").":\r\n".str_replace(" ", "\r\n", $force);
            return false;
        }
        return true;
    }

    /**
     * Konvertiert die ModBus-Daten in die entsprechenden PHP-DatenTypen
     * @param array $Variable die Definition der Statusvariable
     * @param string $Value die ModBus-Daten
     * @return mixed Konvertierte Daten oder null, wenn Konvertierung nicht möglich ist
     * @access private
     */
    private function _ConvertValue(array $Variable, string $Value){
        //LittleEndian & WordSwap verarbeiten
        switch ($Variable['MBType']) {
            case self::MB_BigEndian://ABCD => ABCD
                break;
            case self::MB_BigEndian_WordSwap://ABCD => CDAB  
                $Value = $this->WordSwap($Value);
                break;
            case self::MB_LittleEndian://ABCD => BADC
                $Value = $this->LittleEndian($Value);
                break;
            case self::MB_LittleEndian_WordSwap://ABCD => DCBA
                $Value = $this->LittleEndian($Value);
                $Value = $this->WordSwap($Value);
                break;
        }
        $this->SendDebug("ConvertValue Ident: ".$Variable['Ident']." MBType: ".$Variable['MBType'], $Value, 1);

        //DatenTypen umwandeln
        switch ($Variable['VarType']) {
            case self::VT_Boolean:
                return ord($Value) == 0x01;
            case self::VT_SignedInteger:
                switch ($Variable['Quantity']) {
                    case 1://16 Bit
                        $result = unpack("n", $Value)[1];//vorzeichenloser Short-Typ (immer 16 Bit, Byte-Folge Big Endian)
                        break;
                    case 2://32 Bit
                        $result = unpack("N", $Value)[1];//vorzeichenloser Long-Typ (immer 32 Bit, Byte-Folge Big Endian)
                        break;
                    case 4://64 Bit
                        $result = unpack("J", $Value)[1];//vorzeichenloser Long-Long-Typ (immer 64 bit, Byte-Folge Big Endian)
                        break;
                    default:
                        return null;
                }
                return $this->CalcFactor($result, $Variable['Factor']);
            case self::VT_UnsignedInteger:
                switch ($Variable['Quantity']) {
                    case 1://16 Bit
                        $result = unpack("s", $Value)[1];//vorzeichenbehafteter Short-Typ (immer 16 Bit, Byte-Folge maschinenabhängig)
                        break;
                    case 2://32 Bit
                        $result = unpack("l", $Value)[1];//vorzeichenbehafteter Long-Typ (immer 32 Bit, Byte-Folge maschinenabhängig)
                        break;
                    case 4://64 Bit
                        $result = unpack("q", $Value)[1];//vorzeichenbehafteter Long-Long-Typ (immer 64 bit, maschinenabhängig)
                        break;
                    default:
                        return null;
                }
                return $this->CalcFactor($result, $Variable['Factor']);
            case self::VT_Float:
                if ($Variable['Quantity'] < 2){//Quantity ist zu klein für Float - dat kann nicht sein ;-)
                    return null;
                }
                $result = unpack("G", $Value)[1]; //Gleitkommazahl (maschinenabhängige Größe, Byte-Folge Big Endian)
                return $this->CalcFactor($result, $Variable['Factor']);
            case self::VT_String:
                return trim($Value);
        }
        return null;
    }
    
}
