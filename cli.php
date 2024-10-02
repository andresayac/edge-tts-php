<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\CLI\SynthesizeCommand;
use App\CLI\VoiceListCommand;

$application = new Application();

$application->addCommands([
    new SynthesizeCommand(),
    new VoiceListCommand()
]);

$application->run();
