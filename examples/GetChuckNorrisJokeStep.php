<?php

use ByLexus\DurableTask\Enum\StepStatus;
use ByLexus\DurableTask\Result\ErrorInfo;
use ByLexus\DurableTask\Result\StepResult;
use ByLexus\DurableTask\Step;
use ByLexus\DurableTask\Task;

class GetChuckNorrisJokeStep extends Step {
    public function execute(Task $task): StepResult {
        try {
            $json = file_get_contents('https://api.chucknorris.io/jokes/random');
            $joke = json_decode($json);
            $task->getPayload(static::class)->joke = $joke->value ?? '(oops)';
            sleep(rand(2, 8));
            if (!empty($joke)) {
                return new StepResult(StepStatus::SUCCEEDED);
            }
            throw new Error('Cannot read Chuck Norris Joke', 500);
        } catch (Throwable $t) {
            return StepResult::failed(new ErrorInfo($t->getCode(), $t->getMessage()));
        }
    }
}
