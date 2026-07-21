<?php

namespace Draw\Contracts\Messenger;

use Draw\Contracts\Messenger\Exception\MessageNotFoundException;
use Symfony\Component\Messenger\Envelope;

interface EnvelopeFinderInterface
{
    /**
     * @throws MessageNotFoundException
     */
    public function findById(string $messageId): Envelope;

    /**
     * Return all envelopes that match all the tags.
     *
     * @param string[] $tags
     *
     * @return Envelope[]
     */
    public function findByTags(array $tags): array;
}
