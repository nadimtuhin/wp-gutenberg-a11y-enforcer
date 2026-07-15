<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../includes/enforcer.php';

class EnforcerTest extends TestCase {
    public function testBlockValidationPassesWithAlt() {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $block = [
            'blockName' => 'core/image',
            'attrs' => ['alt' => 'description']
        ];
        $this->assertTrue($enforcer->validateBlock($block));
    }

    public function testBlockValidationFailsWithoutAlt() {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $block = [
            'blockName' => 'core/image',
            'attrs' => []
        ];
        $this->assertFalse($enforcer->validateBlock($block));
    }
}
