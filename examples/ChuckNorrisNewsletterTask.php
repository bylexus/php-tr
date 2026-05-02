<?php

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

require_once(__DIR__ . '/GetChuckNorrisJokeStep.php');
require_once(__DIR__ . '/SendMailStep.php');

#[CleanupAfter(new DateInterval('PT1H'))]
class ChuckNorrisNewsletterTask extends Task {
    public function __construct(protected PHPMailer $mailer, ?LoggerInterface $logger = null) {
        parent::__construct(logger: $logger);
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if (!$actStep) {
            return new GetChuckNorrisJokeStep();
        }
        if ($actStep instanceof GetChuckNorrisJokeStep) {
            $joke = $this->getPayload(GetChuckNorrisJokeStep::class)->joke ?? '';
            $this->setSubject('Your daily Chuck Norris Joke');
            $this->setBody($joke);

            return new SendMailStep($this->mailer);
        }

        return null;
    }

    public function setTo(string $to): self {
        // Prepare payload for SendMailStep
        $this->getPayload(SendMailStep::class)->to = $to;
        return $this;
    }

    public function setFrom(string $from): self {
        // Prepare payload for SendMailStep
        $this->getPayload(SendMailStep::class)->from = $from;
        return $this;
    }

    public function setSubject(string $subject): self {
        // Prepare payload for SendMailStep
        $this->getPayload(SendMailStep::class)->subject = $subject;
        return $this;
    }

    public function setBody(string $body): self {
        // Prepare payload for SendMailStep
        $this->getPayload(SendMailStep::class)->body = $body;
        return $this;
    }
}
