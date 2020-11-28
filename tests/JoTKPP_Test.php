<?php
declare(strict_types=1);
/**
 * @Package:		 tests
 * @File:			 JoTKPP_Test.php
 * @Create Date:	 28.11.2020 17:41:30
 * @Author:			 Jonathan Tanner - admin@tanner-info.ch
 * @Last Modified:	 28.11.2020 21:33:54
 * @Modified By:	 Jonathan Tanner
 * @Copyright:		 Copyright(c) 2020 by JoT Tanner
 * @License:		 Creative Commons Attribution Non Commercial Share Alike 4.0
 * 					 (http://creativecommons.org/licenses/by-nc-sa/4.0/legalcode)
 */

use PHPUnit\Framework\TestCase;

//IP-Symcon "Simulator" laden
include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';

class JoTKPP_Test extends TestCase {
    //Manual zu PHPUnit: https://phpunit.readthedocs.io/en/9.3/writing-tests-for-phpunit.html
    
    private $moduleID = '{E64278F5-1942-5343-E226-8673886E2D05}';

    //wird vor jedem Test ausgeführt
    public function setup(): void {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        parent::setup();
    }

    //jeder Test begint mit 'test' + Was soll getestet werden
    public function testBeispiel () {
        $var1 = 1;
        $var2 = 4;
        $var3 = 5;

        $sum = $var1 + $var2 + $var3;
        $this->assertEquals(10, $sum);//erfolgreicher Test
        //$this->assertEquals(12, $sum);//fehlerhafter Test
    }
}