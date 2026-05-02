<?php

declare(strict_types=1);

use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FetchUserProfileStep.php';

final class PersistUserProfileStep extends Step {
    public function __construct(
        private ExampleUserRepository $repository,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(logger: $logger);
    }

    public function execute(Task $task): StepResult {
        try {
            // This step reads the payload fragment written by FetchUserProfileStep.
            $profile = (array) ($task->getPayload(FetchUserProfileStep::class)->profile ?? []);
            $this->repository->save($profile);

            return StepResult::succeeded(message: 'Profile persisted to repository.');
        } catch (Throwable $throwable) {
            return StepResult::failed(
                new ErrorInfo((int) $throwable->getCode(), $throwable->getMessage()),
                message: $throwable->getMessage(),
            );
        }
    }
}
