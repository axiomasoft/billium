<?php

declare(strict_types=1);
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Tests\Support\RatingScenario;
use Billium\Rating\Tests\Support\RunnerSpy;
use Illuminate\Database\DatabaseManager;

require dirname(__DIR__, 6).'/vendor/autoload.php';
$app = WorkerApplication::create();
RatingScenario::prepare($app);
$bytes = base64_decode($argv[1], strict: true);
if ($bytes === false) {
    throw new LogicException('Invalid frozen input encoding');
}
$inputs = FrozenInputsCodec::decode($bytes);
$prepared = new PreparedRating(inputs: $inputs, draft: $app->make(RatingComputation::class)->compute($inputs));
if ($argv[2] === 'after_commit_before_reply') {
    $spy = new RunnerSpy($app->make(OperationRunner::class));
    $spy->loseReply = true;
    $app->instance(OperationRunner::class, $spy);
}
$pid = $app->make(DatabaseManager::class)->connection('pgsql')->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new LogicException('PostgreSQL did not return a backend PID');
}
fwrite(STDOUT, 'pid:'.$pid."\n");
fflush(STDOUT);
$app->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'crash'));
