<?php

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

class GetChuckNorrisJokeStep extends Step {
    public function execute(Task $task): StepResult {
        try {
            $this->getLogger()->debug("Fetching that chuck joke.....");
            $json = file_get_contents('https://api.chucknorris.io/jokes/random');
            $joke = json_decode($json);
            $task->getPayload(static::class)->joke = $joke->value ?? '(oops)';
            sleep(rand(0, 4));
            $this->getLogger()->debug("Fetched that chuck joke!");
            if (!empty($joke)) {
                return new StepResult(StepStatus::SUCCEEDED);
            }
            throw new Error('Cannot read Chuck Norris Joke', 500);
        } catch (Throwable $t) {
            return StepResult::failed(new ErrorInfo($t->getCode(), $t->getMessage()));
        }
    }
}
