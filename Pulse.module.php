<?php namespace ProcessWire;

/**
 * Pulse
 *
 * Main module entry point used by ProcessWire and the modules directory.
 * Runtime/admin work is delegated to ProcessPulse and TextformatterPulse.
 */
class Pulse extends WireData implements Module {

    public static function getModuleInfo() {
        return [
            'title' => 'Pulse',
            'version' => '1.0.3',
            'summary' => 'Polls and quizzes embedded via shortcodes, with live results.',
            'author' => 'Maxim Semenov',
            'href' => 'https://smnv.org',
            'icon' => 'bar-chart',
            'autoload' => false,
            'singular' => true,
            'requires' => ['PHP>=8.2', 'ProcessWire>=3.0.0'],
            'installs' => ['ProcessPulse', 'TextformatterPulse'],
        ];
    }
}
