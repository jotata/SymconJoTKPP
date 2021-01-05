<?php

declare(strict_types=1);
/**
 * @Package:         tests
 * @File:            JoTKPP_Test.php
 * @Create Date:     28.11.2020 17:41:30
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   05.01.2021 20:02:22
 * @Modified By:     Jonathan Tanner
 * @Copyright:       Copyright(c) 2020 by JoT Tanner
 * @License:         Creative Commons Attribution Non Commercial Share Alike 4.0
 *                   (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */

use PHPUnit\Framework\TestCase;

//IP-Symcon "Simulator" laden
include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';

class JoTKPP_Test extends TestCase {
    //Manual zu PHPUnit: https://phpunit.readthedocs.io/en/9.3/writing-tests-for-phpunit.html

    private $moduleID = '{E64278F5-1942-5343-E226-8673886E2D05}';
    private $socketID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'; //Client Socket
    private $gatewayID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}'; //ModBus Gateway

    //wird vor jedem Test ausgeführt
    public function setup(): void {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');
        parent::setup();
    }

    //jeder Test begint mit 'test' + Was soll getestet werden
    public function testBeispiel() {
        $var1 = 1;
        $var2 = 4;
        $var3 = 5;
        $sum = $var1 + $var2 + $var3;
        $this->assertEquals(10, $sum); //erfolgreicher Test
        //$this->assertEquals(12, $sum); //fehlerhafter Test
    }

    //Testet das Format der ModBusConfig.json
    public function testModBusConfig() {
        $file = __DIR__ . '/../JoTKPP/ModBusConfig.json';
        $VarType = ['$VT_String', '$VT_UnsignedInteger', '$VT_SignedInteger', '$VT_Float', '$VT_Real', '$VT_Boolean'];
        $RFunction = ['$FC_Read_HoldingRegisters'];
        $WFunction = ['$FC_Write_SingleHoldingRegister', '$FC_Write_MultipleHoldingRegisters'];

        $json = file_get_contents($file);
        $config = json_decode($json, true, 4);
        $this->assertEquals(json_last_error(), JSON_ERROR_NONE, 'Error (' . json_last_error_msg() . ') in ' . $file); //Check JSON Syntax-Errors
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->assertGreaterThanOrEqual(1, count($config), "$file does not contain definitions.");
            foreach ($config as $c) {
                if (array_key_exists('Address', $c)) {
                    $a = 'ADDRESS: ' . $c['Address'] . ' - ';
                    $this->assertIsInt($c['Address'], $a . 'Wrong definition of \'Address\'.');
                    $this->assertIsString($c['Ident'], $a . 'Wrong definition of \'Ident\'.');
                    $this->assertIsString($c['Group'], $a . 'Wrong definition of \'Group\'.');
                    $this->assertIsString($c['Name'], $a . 'Wrong definition of \'Name\'.');
                    $this->assertContains($c['VarType'], $VarType, $a . 'Wrong definition of \'VarType\'. Allowed: ' . implode(', ', $VarType));
                    $this->assertIsFloat($c['FWVersion'], $a . 'Wrong definition of \'FWVersion\'.');
                    if (array_key_exists('Profile', $c)) { //optional
                        $this->assertIsString($c['Profile'], $a . 'Wrong definition of \'Profile\'.');
                    }
                    if (intval($c['Address']) > 0) { //Folgende Werte sind bei Berechnungen (0) nicht nötig
                        if (array_key_exists('ScaleIdent', $c)) { //optional
                            $this->assertIsString($c['ScaleIdent'], $a . 'Wrong definition of \'ScaleIdent\'.');
                        }
                        if (array_key_exists('Factor', $c)) { //optional
                            $this->assertContains(gettype($c['Factor']), ['integer', 'float', 'double'], $a . 'Wrong definition of \'Factor\'. Allowed types: Int, Float');
                        }
                        $this->assertIsInt($c['Quantity'], $a . 'Wrong definition of \'Quantity\'.');
                        $func = false;
                        if (array_key_exists('RFunction', $c)) { //optional
                            $this->assertContains($c['RFunction'], $RFunction, $a . 'Wrong definition of \'RFunction\'. Allowed: ' . implode(', ', $RFunction));
                            $func = true;
                        }
                        if (array_key_exists('WFunction', $c)) { //optional
                            $this->assertContains($c['WFunction'], $WFunction, $a . 'Wrong definition of \'RFunction\'. Allowed: ' . implode(', ', $WFunction));
                            $func = true;
                        }
                        $this->assertTrue($func, $a . 'At least \'RFunction\' OR \'WFunction\' must be defined. Can also be both of them.');
                    }
                } else {
                    $this->assertArrayHasKey('Address', $c, 'Definition does not contain \'Address\'.');
                }
            }
        }
    }

    //Testet ob die Instanz erstellt werden kann (auch wenn kein Gerät verfügbar ist)
    public function testCreateInstance() {
        //$soID = IPS_CreateInstance($this->socketID);
        //IPS_SetConfiguration($soID, json_encode(['Host' => '127.0.0.1', 'Open' => true, 'Port' => 1502]));
        //IPS_ApplyChanges($soID);
        //$gwID = IPS_CreateInstance($this->gatewayID);
        //IPS_SetConfiguration($gwID, json_encode(['DeviceID' => 71, 'GatewayMode' => 0, 'SwapWords' => 0]));
        //IPS_ApplyChanges($gwID);

        //Modul 'ModBus Gateway' {A5F663AB-C400-4FE5-B207-4D67CC030564} ist in "Symcon-Simulator" nicht vorhanden. Instanz kann nicht erstellt werden.
        //Anfrage im Forum https://www.symcon.de/forum/threads/45276-Modul-ModBus-Gateway-in-Symcon-Simulator-%28SymconStubs%29-nicht-vorhanden
        //$iID = IPS_CreateInstance($this->moduleID);
        $this->assertTrue(true);
    }
}