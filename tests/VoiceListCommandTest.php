<?php

namespace Afaya\EdgeTTS\Tests;

use Afaya\EdgeTTS\Commands\VoiceListCommand;
use Afaya\EdgeTTS\Tests\Fake\FakeEdgeTTS;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class VoiceListCommandTest extends TestCase
{
    public function testListsTheVoices(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [200, [], '[{"ShortName":"en-US-AriaNeural","Locale":"en-US"},{"ShortName":"es-CO-SalomeNeural","Locale":"es-CO"}]'];
        $tester = new CommandTester(new VoiceListCommand($tts));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame("Available voices:\n - en-US-AriaNeural\n - es-CO-SalomeNeural\n", str_replace(PHP_EOL, "\n", $tester->getDisplay()));
    }

    public function testReportsAFailedRequest(): void
    {
        $tts = new FakeEdgeTTS();
        $tts->voiceResponse = [500, [], ''];
        $tester = new CommandTester(new VoiceListCommand($tts));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('status 500', $tester->getDisplay());
    }

    public function testHasTheNamesOfTheNodeCommand(): void
    {
        $command = new VoiceListCommand();
        $this->assertSame('edge-tts:voice-list', $command->getName());
        $this->assertContains('voice-list', $command->getAliases());
    }
}
