<?php

namespace Afaya\EdgeTTS\CLI;

use Afaya\EdgeTTS\Service\EdgeTTS;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'edge-tts:synthesize')]
class SynthesizeCommand extends Command
{
    protected static $defaultName = 'app:synthesize';

    protected function configure(): void
    {
        $this
            ->setDescription('Edge TTS: synthesize text to audio')
            ->addOption('text', 'txt',  InputOption::VALUE_REQUIRED, 'Text to convert to audio')
            ->addOption('voice', 'vcl', InputOption::VALUE_OPTIONAL, 'Voice to use for the audio synthesis', 'en-US-AriaNeural')
            ->addOption('rate', 'r', InputOption::VALUE_OPTIONAL, 'Rate of speech', '0%')
            ->addOption('volume', 'lvl',  InputOption::VALUE_OPTIONAL, 'Volume of speech', '0%')
            ->addOption('pitch', 'pit', InputOption::VALUE_OPTIONAL, 'Pitch of speech', '0Hz')
            ->addOption('output', 'out',  InputOption::VALUE_OPTIONAL, 'Output file name', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $text = $input->getOption('text');
        $voice = $input->getOption('voice');
        $pitch = $input->getOption('pitch');
        $rate = $input->getOption('rate');
        $volume = $input->getOption('volume');
        $output_file = $input->getOption('output') ?? 'output_' . time();

        if (empty($text)) {
            $output->writeln("Text is required");
            return Command::FAILURE;
        }



        $webSocketService = new EdgeTTS();
        $webSocketService->synthesize($text, $output_file, $voice, $rate, $volume, $pitch);

        $output->writeln("Audio file generated: {$output_file}.wav");

        return Command::SUCCESS;
    }
}
