<?php
/***************************************************************************************************
 * @Package:		 libs                                                                          *
 * @File:			 JoT_Traits.php                                                             *
 * @Create Date:	 27.04.2019 11:51:35                                                           *
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch                                        *
 * @Last Modified:	 26.09.2019 10:32:03                                                           *
 * @Modified By:	 Jonathan Tanner                                                               *
 * @Copyright:		 Copyright(c) 2019 by JoT Tanner                                               *
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0                   *
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)                  *
 ***************************************************************************************************/


/**
 * Trait mit Hilfsfunktionen für Variablen-Profile.
 */
trait VariableProfile {
    /**
    * Liest die JSON-Profil-Definition aus $JsonFile (UTF-8) und erstellt/aktualisiert die entsprechenden Profile.
    * @param string $JsonFile mit Profil-Definitionen gemäss Rückgabewert von IPS_GetVariableProfile.
    * @param array $ReplaceMap mit Key -> Values welche in JSON ersetzt werden sollen.
    * @access protected
    */
    protected function ConfigProfiles(string $JsonFile, $ReplaceMap = []){
        $config = file_get_contents($JsonFile);
        foreach ($ReplaceMap as $search => $replace){
            $config = str_replace($search, $replace, $config);
        }
        $profiles = json_decode($config, true, 5);
        if (json_last_error() !== JSON_ERROR_NONE){
            echo("ConfigProfiles - Error in Profile-JSON (".json_last_error_msg()."). Please check \$ReplaceMap or File-Content of $JsonFile.");
            echo($config);
            exit;
        }
        foreach ($profiles as $profile){
            $this->MaintainProfile($profile);
        }
    }
    
    /**
    * Erstellt/Aktualisiert ein Variablen-Profil.
    * Erstellt den Profil-Namen immer mit Modul-Prefix.
    * Kann einfach zum Klonen eines vorhandenen Variablen-Profils verwendet werden.
    * @param mixed $Profile Array gemäss Rückgabewert von IPS_GetVariableProfile.
    * @access protected
    */
    protected function MaintainProfile(array $Profile){
        $Name = $this->CheckProfileName($Profile['ProfileName']);
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $Profile['ProfileType']);
        } else {
            $exProfile = IPS_GetVariableProfile($Name);
            if ($exProfile['ProfileType'] != $Profile['ProfileType']) {
                echo("MaintainProfile - Variable profile type does not match for profile $Name");
                exit;
            }
        }
        if (key_exists("Icon", $Profile)){
            IPS_SetVariableProfileIcon($Name, $Profile['Icon']);
        }
        if (key_exists("Prefix", $Profile) && key_exists("Suffix", $Profile)){
            IPS_SetVariableProfileText($Name, $Profile['Prefix'], $Profile['Suffix']);
        }
        if (key_exists("MinValue", $Profile) && key_exists("MaxValue", $Profile) && key_exists("StepSize", $Profile)){
            IPS_SetVariableProfileValues($Name, $Profile['MinValue'], $Profile['MaxValue'], $Profile['StepSize']);
        }
        if ($Profile['ProfileType'] == VARIABLETYPE_FLOAT && key_exists("Digits", $Profile)) {
            IPS_SetVariableProfileDigits($Name, $Profile['Digits']);
        }
        if (key_exists("Associations", $Profile)){
            foreach ($Profile['Associations'] as $Assoc){
                IPS_SetVariableProfileAssociation($Name, $Assoc['Value'], $Assoc['Name'], $Assoc['Icon'], $Assoc['Color']);
            }
        }
    }

    /**
     * Überprüft ob der Profil-Name neu ist. Falls ja, wird sichergestellt, dass er den Modul-Prefix enthält.
     * Ist das Profil bereits vorhanden, wird der Name nicht verändert.
     * Dies erlaubt es, im Modul die Profile ohne Prefix anzugeben und trotzdem bei neuen Profilen den Prefix voranzustellen.
     * Damit wird sichergestellt, dass die Best Practice für Module eingehalten wird (https://gist.github.com/paresy/236bfbfcb26e6936eaae919b3cfdfc4f).
     * @param string $Name der zu prüfende Profil-Name.
     * @return string den Profil-Namen mit Modul-Prefix (falls nötig).
     * @access protected
     */
    protected function CheckProfileName(string $Name){
        if ($Name !== "" && !IPS_VariableProfileExists($Name)){ 
            $Prefix = "JoT";
            if (!is_null(self::PREFIX)){ 
                $Prefix = self::PREFIX;
            }
            if (substr($Name, strlen("$Prefix.")) !== "$Prefix."){//Modul-Prefix zu Namen hinzufügen
                $this->LogMessage("Variable profile name '$Name' did not include module prefix. Changed name to '$Prefix.$Name'.", KL_NOTIFY);
                $Name = "$Prefix.$Name";
            }
        }
        return $Name;
    }

    /**
     * Überprüft ob der Profil-Name für den entsprechenden DatenTyp (falls angegeben) existiert.
     * @param string $Name der zu prüfende Profil-Name.
     * @param int optional $DataType des Profils.
     * @return bool true wenn vorhanden, sonst false.
     * @access protected
     */
    protected function VariableProfileExists(string $Name, int $DataType = -1){
        if (IPS_VariableProfileExists($Name)){ 
            if ($DataType >= 0){
                if ($DataType >= 10){//Selbstdefinierte Datentypen sollten immer das 10-fache der System-VariablenTypen sein.
                    $DataType = intval($DataType / 10);
                }
                $profiles = IPS_GetVariableProfileListByType($DataType);
                if (!in_array(strtolower($Name), array_map('strtolower', $profiles))){
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Lädt alle Profile vom Typ $DataType und aktualisiert im Konfigurations-Formular das Select $SelectName
     * mit den entsprechenden Optionen für diese Profile.
     * Dies erlaubt ein dynamisches Select-Profile Feld in einem Konfigurations-Formular.
     * @param string $DataType der anzuzeigenden Profile.
     * @param string $SelectName ist der NAme des Select-Elementes im Konfigurations-Formular.
     * @access public
     */
    public function UpdateSelectProfile(int $DataType, string $SelectName){
        if ($DataType >= 0){
            if ($DataType >= 10){//Selbstdefinierte Datentypen sollten immer das 10-fache der System-VariablenTypen sein.
                $DataType = intval($DataType / 10);
            }
            $options[] = ['caption' => "", 'value' => ""];
            $profiles = IPS_GetVariableProfileListByType($DataType);
            foreach ($profiles as $profile) {
                $options[] = ['caption' => $profile, 'value' => $profile];
            }
            $this->UpdateFormField($SelectName, "options", json_encode($options));
            $this->UpdateFormField($SelectName, "value", "");
            $this->UpdateFormField($SelectName, "enabled", true);
        } else {
            $this->UpdateFormField($SelectName, "enabled", false);
        }
    }
}

/**
 * Funktionen zum Ent-/Sperren bei paralellen Zugriffen.
 */
trait Semaphore {
    /**
     * Versucht eine Sperre zu setzen und wiederholt dies bei Misserfolg bis zu 100 mal.
     * @param string $Ident Ein String der die Sperre bezeichnet.
     * @return boolean True bei Erfolg, False bei Misserfolg.
     */
    private function Lock($Ident){
        for ($i = 0; $i < 100; $i++) {
            if (IPS_SemaphoreEnter($Ident, 1)) {
                return true;
            } else {
                IPS_Sleep(mt_rand(1, 5));
            }
        }
        return false;
    }

    /**
     * Löscht eine Sperre.
     * @param string $ident Ein String der den Lock bezeichnet.
     */
    private function Unlock(string $Ident){
        IPS_SemaphoreLeave($Ident);
    }
}