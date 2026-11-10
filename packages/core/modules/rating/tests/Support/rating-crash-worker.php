<?php

declare(strict_types=1);

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Tests\Support\RatingScenario;
use Billium\Rating\Tests\Support\RunnerSpy;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$app = WorkerApplication::create();
RatingScenario::prepare($app);
$inputs = FrozenInputsCodec::decode(base64_decode($argv[1], strict: true));
$prepared = new PreparedRating(inputs: $inputs, draft: $app->make(RatingComputation::class)->compute($inputs));
if ($argv[2] === 'after_commit_before_reply') {
    $spy = new RunnerSpy($app->make(OperationRunner::class));
    $spy->loseReply = true;
    $app->instance(OperationRunner::class, $spy);
}
fwrite(STDOUT, 'pid:'.$app['db']->connection('pgsql')->scalar('SELECT pg_backend_pid()')."\n");
fflush(STDOUT);
$app->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'crash'));
