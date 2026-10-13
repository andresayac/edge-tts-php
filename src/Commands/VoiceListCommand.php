<?php

namespace Afaya\EdgeTTS\Commands;

use Afaya\EdgeTTS\Service\EdgeTTS;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VoiceListCommand extends Command
{
    private ?EdgeTTS $tts;

    public function __construct(?EdgeTTS $tts = null)
    {
        $this->tts = $tts;
        parent::__construct('edge-tts:voice-list');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['voice-list'])
            ->setDescription('Get the list of available voices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $voices = ($this->tts ?? new EdgeTTS())->getVoices();
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('Available voices:');
        foreach ($voices as $voice) {
            $output->writeln(" - {$voice['ShortName']}");
        }
        return Command::SUCCESS;
    }
}
