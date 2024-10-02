<?php

namespace App\CLI;


use App\Service\EdgeTTS;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


#[AsCommand(name: 'app:voice-list')]
class VoiceListCommand extends Command
{
    protected static $defaultName = 'app:voice-list';

    protected function configure(): void
    {
        $this
            ->setDescription('Get the list of available voices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webSocketService = new EdgeTTS();
        $voices = $webSocketService->getVoices();

        $output->writeln("Lista de voces disponibles:");
        foreach ($voices as $voice) {
            $output->writeln(" - {$voice}");
        }

        return Command::SUCCESS;
    }
}
