<?php
namespace GutenbergA11yEnforcer;

class Enforcer {
    public function validateBlock($block) {
        if ($block['blockName'] === 'core/image' && empty($block['attrs']['alt'])) {
            return false;
        }
        return true;
    }
}
