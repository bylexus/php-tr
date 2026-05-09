<?php

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

require_once(__DIR__ . '/GetDailyCatStep.php');
require_once(__DIR__ . '/SendMailStep.php');
require_once(__DIR__ . '/ResizeFileStep.php');

#[CleanupAfter(successful: new DateInterval('PT4H'), unsuccessful: new DateInterval('PT1H'))]
class DailyCatTask extends Task {
    public function __construct(protected PHPMailer $mailer, ?LoggerInterface $logger = null) {
        parent::__construct(logger: $logger);
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if (!$actStep) {
            return new GetDailyCatStep();
        }
        if ($actStep instanceof GetDailyCatStep) {
            $s = new ResizeFileStep(logger: $this->getLogger());
            $s->setWidth($this, 1000);
            $s->setFile($this, $actStep->catFile($this));
            return $s;
        }

        // store the result for later re-use:
        if ($actStep instanceof ResizeFileStep) {
            $this->setPayload('catfile', $actStep->file($this));
        }
        // send 1 mail at a time - addressing a lot of recipients with own mails:
        if ($actStep instanceof ResizeFileStep || $actStep instanceof SendMailStep) {
            if (!empty($this->getPayload()->recipients)) {
                $s = new SendMailStep($this->mailer);
                $s->setTo($this, array_shift($this->getPayload()->recipients));
                $s->setSubject($this, 'Your daily Cat!');
                $s->setBody($this, 'Please find attached your daily cat.');
                $s->setAttachments($this, [$this->getPayload('catfile')]);
                return $s;
            }
        }

        return null;
    }

    public function setTo(array $to): self {
        // Prepare payload for SendMailStep
        $this->getPayload()->recipients = $to ?? [];
        return $this;
    }

    public function setFrom(string $from): self {
        // Prepare payload for SendMailStep
        $this->getPayload(SendMailStep::class)->from = $from;
        return $this;
    }
}
