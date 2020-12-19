<?php

declare(strict_types=1);
/**
 * @Package:         tests
 * @File:            JoTKPP_Test.php
 * @Create Date:     28.11.2020 17:41:30
 * @Author:          Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:   19.12.2020 21:27:46
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
    }
}