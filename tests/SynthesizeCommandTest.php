<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Commands\SynthesizeCommand;
use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SynthesizeCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/edge-tts-cli-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '.mp3');
        @unlink($this->base . '.txt');
    }

    public function testSynthesizesTheTextToAFile(): void
    {
        $tts = new FakeEdgeTTS();
        $tester = new CommandTester(new SynthesizeCommand($tts));

        $code = $tester->execute(['--text' => 'Hello World', '--voice' => 'en-US-AriaNeural', '--rate' => '+10%', '--output' => $this->base]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString("Audio file generated: {$this->base}.mp3", $tester->getDisplay());
        $this->assertSame(FakeEdgeTTS::recordedAudio(), file_get_contents($this->base . '.mp3'));
        $this->assertStringContainsString('rate="+10%"', $tts->messages()[1]);
    }

    public function testReadsTheTextFromAFile(): void
    {
        file_put_contents($this->base . '.txt', 'From a file');
        $tts = new FakeEdgeTTS();
        $tester = new CommandTester(new SynthesizeCommand($tts));

        $code = $tester->execute(['--file' => $this->base . '.txt', '--output' => $this->base]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('From a file', $tts->messages()[1]);
    }

    public function testReadsTheTextFromStdin(): void
    {
        $tts = new FakeEdgeTTS();
        $tester = new CommandTester(new SynthesizeCommand($tts));
        $tester->setInputs(['From stdin']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--file' => '-', '--output' => $this->base]));
        $this->assertStringContainsString('From stdin', $tts->messages()[1]);
    }

    public function testWritesTheFormatAndTheSubtitles(): void
    {
        $tts = new FakeEdgeTTS();
        $tester = new CommandTester(new SynthesizeCommand($tts));

        $code = $tester->execute([
            '--text' => 'hi',
            '--output' => $this->base . '.webm',
            '--format' => 'webm-24khz-16bit-mono-opus',
            '--subtitles' => $this->base . '.vtt',
        ]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('"outputFormat":"webm-24khz-16bit-mono-opus"', $tts->messages()[0]);
        $this->assertFileExists($this->base . '.webm');
        $this->assertStringStartsWith("WEBVTT\n", (string) file_get_contents($this->base . '.vtt'));
        $this->assertStringContainsString("Subtitles generated: {$this->base}.vtt", $tester->getDisplay());
        unlink($this->base . '.webm');
        unlink($this->base . '.vtt');
    }

    public function testRequiresTextOrFile(): void
    {
        $tester = new CommandTester(new SynthesizeCommand(new FakeEdgeTTS()));
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Text (--text) or file (--file) is required', $tester->getDisplay());
    }

    public function testRefusesTextAndFileTogether(): void
    {
        $tester = new CommandTester(new SynthesizeCommand(new FakeEdgeTTS()));
        $this->assertSame(Command::FAILURE, $tester->execute(['--text' => 'a', '--file' => 'b']));
        $this->assertStringContainsString('Cannot use both', $tester->getDisplay());
    }

    public function testReportsErrorsOfTheService(): void
    {
        $tester = new CommandTester(new SynthesizeCommand(new FakeEdgeTTS()));
        $this->assertSame(Command::FAILURE, $tester->execute(['--text' => 'a', '--pitch' => 'loud']));
        $this->assertStringContainsString('Invalid pitch', $tester->getDisplay());
    }

    public function testHasTheNamesOfTheNodeCommand(): void
    {
        $command = new SynthesizeCommand();
        $this->assertSame('edge-tts:synthesize', $command->getName());
        $this->assertContains('synthesize', $command->getAliases());
    }
}
