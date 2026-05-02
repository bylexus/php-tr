<?php

declare(strict_types=1);

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/PersistUserProfileStep.php';

#[CleanupAfter(new DateInterval('PT2H'))]
final class ImportUserProfileTask extends Task {
    public function __construct(
        private ExampleImportPolicy $policy,
        private ExampleUserApi $api,
        private ExampleUserRepository $repository,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(logger: $logger);
    }

    public function forUserId(int $userId): self {
        // Producers prepare the durable payload before the task is inserted into the queue table.
        $this->getPayload()->userId = $userId;

        return $this;
    }

    public function nextStep(?Step $actStep = null): ?Step {
        if ($actStep === null) {
            // The first step is created during enqueue, so constructor dependencies must already be available.
            return new FetchUserProfileStep($this->api, $this->getLogger());
        }

        if ($actStep instanceof FetchUserProfileStep) {
            // Tasks decide the workflow graph based on the current payload and the completed step type.
            $profile = (array) ($this->getPayload(FetchUserProfileStep::class)->profile ?? []);

            if (!$this->policy->shouldPersist($profile)) {
                $this->getLogger()?->info('Import policy skipped profile persistence.', [
                    'userId' => $this->getPayload()->userId ?? null,
                ]);

                // Returning null here means the workflow succeeds without scheduling another step.
                return null;
            }

            return new PersistUserProfileStep($this->repository, $this->getLogger());
        }

        return null;
    }
}
