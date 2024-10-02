<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';


use Symfony\Component\Console\Application;
use App\CLI\SynthesizeCommand;
use App\CLI\VoiceListCommand;

// Crear una nueva instancia de la aplicación de consola
$application = new Application('Edge TTS CLI', '1.0');

// Registrar los comandos
$application->addCommands([
    new SynthesizeCommand(),
    new VoiceListCommand(),
]);

// Manejar errores y excepciones
try {
    $application->run();
} catch (\Exception $e) {
    // Mostrar el mensaje de error y salir con un código de error
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
