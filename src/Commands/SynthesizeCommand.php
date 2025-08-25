<?php

namespace Afaya\EdgeTTS\Commands;

use Afaya\EdgeTTS\Service\EdgeTTS;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @AsCommand(name="edge-tts:synthesize")
 */
class SynthesizeCommand extends Command
{
    protected static $defaultName = 'edge-tts:synthesize';
    protected static $defaultDescription = 'Edge TTS: synthesize text to audio';

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
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Input file name', null)
            ->addOption('ssml', 's', InputOption::VALUE_OPTIONAL, 'SSML content', false)
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
        $file = $input->getOption('file');
        $ssml = $input->getOption('ssml');

        if (empty($text) && !empty($file)) {
            $output->writeln("Error: Text or file is required");
            return Command::FAILURE;
        }

        if ($text && $file) {
            $output->writeln("Error: Cannot use both text and file options");
            return Command::FAILURE;
        }

        $content = $text;

        if ($file) {
            $content = file_get_contents($file);

            if (empty($content)) {
                $output->writeln("Error: File is empty");
                return Command::FAILURE;
            }
        }

        $tts = new EdgeTTS();
        $tts->synthesize($content, $voice, [
            'rate' => $rate,
            'volume' => $volume,
            'pitch' => $pitch,
            'inputType' => $ssml ? 'ssml' : 'auto'
        ]);
        $tts->toFile("{$output_file}");
        $output->writeln("Audio file generated: {$output_file}.mp3");

        return Command::SUCCESS;
    }
}
