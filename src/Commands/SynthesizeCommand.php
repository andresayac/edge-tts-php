<?php

namespace Afaya\EdgeTTS\Commands;

use Afaya\EdgeTTS\Service\EdgeTTS;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SynthesizeCommand extends Command
{
    private ?EdgeTTS $tts;

    public function __construct(?EdgeTTS $tts = null)
    {
        $this->tts = $tts;
        parent::__construct('edge-tts:synthesize');
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['synthesize'])
            ->setDescription('Edge TTS: synthesize text to audio')
            ->addOption('text', 't', InputOption::VALUE_REQUIRED, 'Text to convert to audio')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Input file (text); --file=- reads from stdin')
            ->addOption('voice', null, InputOption::VALUE_REQUIRED, 'Voice to use for the audio synthesis', 'en-US-AriaNeural')
            ->addOption('rate', 'r', InputOption::VALUE_REQUIRED, 'Rate of speech', '0%')
            ->addOption('volume', 'l', InputOption::VALUE_REQUIRED, 'Volume of speech', '0%')
            ->addOption('pitch', 'p', InputOption::VALUE_REQUIRED, 'Pitch of speech', '0Hz')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file name; the extension is added when missing')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format, e.g. audio-24khz-96kbitrate-mono-mp3')
            ->addOption('subtitles', null, InputOption::VALUE_REQUIRED, 'Write subtitles (.srt, or .vtt for WebVTT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = (string) $input->getOption('text');
        $file = (string) $input->getOption('file');

        if ($text === '' && $file === '') {
            $output->writeln('<error>Error: Text (--text) or file (--file) is required</error>');
            return Command::FAILURE;
        }
        if ($text !== '' && $file !== '') {
            $output->writeln('<error>Error: Cannot use both text (--text) and file (--file) options</error>');
            return Command::FAILURE;
        }
        if ($file === '-') {
            $text = (string) stream_get_contents($this->stdin($input));
        } elseif ($file !== '') {
            $text = is_file($file) ? (string) file_get_contents($file) : '';
            if (trim($text) === '') {
                $output->writeln("<error>Error: File {$file} is missing or empty</error>");
                return Command::FAILURE;
            }
            $output->writeln("Reading from file: {$file}");
        }

        $tts = $this->tts ?? new EdgeTTS();
        $subtitles = (string) $input->getOption('subtitles');
        try {
            $tts->synthesize($text, (string) $input->getOption('voice'), [
                'rate' => $input->getOption('rate'),
                'volume' => $input->getOption('volume'),
                'pitch' => $input->getOption('pitch'),
                'outputFormat' => $input->getOption('format'),
            ]);
            $path = $tts->toFile((string) ($input->getOption('output') ?? 'output_' . time()));
            if ($subtitles !== '') {
                $content = preg_match('/\.vtt$/i', $subtitles) ? $tts->toVTT() : $tts->toSRT();
                if (@file_put_contents($subtitles, $content) === false) {
                    throw new \RuntimeException("Failed to write the subtitles to {$subtitles}");
                }
            }
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln("Audio file generated: {$path}");
        if ($subtitles !== '') {
            $output->writeln("Subtitles generated: {$subtitles}");
        }
        return Command::SUCCESS;
    }

    /**
     * @return resource
     */
    private function stdin(InputInterface $input)
    {
        if ($input instanceof StreamableInputInterface && $input->getStream() !== null) {
            return $input->getStream();
        }
        return fopen('php://stdin', 'rb');
    }
}
