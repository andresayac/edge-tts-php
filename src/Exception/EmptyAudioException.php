<?php

namespace Afaya\EdgeTTS\Exception;

/**
 * The service answered without audio. It does that when the voice cannot
 * read the text: voices are locale specific, so an English voice cannot
 * read Chinese or Cyrillic text. It does the same for a text of only
 * punctuation.
 */
class EmptyAudioException extends EdgeTTSException
{
    public function __construct()
    {
        parent::__construct(
            'The service returned no audio. Voices are locale specific, so a voice cannot read text '
            . 'written in another script. Check that the voice matches the language of the text '
            . 'and that the text has words to read.'
        );
    }
}
