<?php

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use PHPMailer\PHPMailer\PHPMailer;

class SendMailStep extends Step {
    public function __construct(protected PHPMailer $mailer) {
    }

    public function execute(Task $task): StepResult {
        try {
            $payload = $task->getPayload(static::class);
            $this->mailer->From = $payload->from ?? 'nobody@nobody.com';
            $this->mailer->addAddress($payload->to ?? '');
            $this->mailer->Subject = $payload->subject ?? '';
            $this->mailer->Body = $payload->body ?? '-';
            $this->mailer->send();
            return new StepResult(StepStatus::SUCCEEDED);
        } catch (Throwable $t) {
            return StepResult::failed(new ErrorInfo($t->getCode(), $t->getMessage()));
        }
    }
}
