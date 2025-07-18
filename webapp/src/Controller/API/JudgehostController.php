<?php declare(strict_types=1);

namespace App\Controller\API;

use App\DataTransferObject\JudgehostFile;
use App\Doctrine\DBAL\Types\JudgeTaskType;
use App\Entity\Contest;
use App\Entity\DebugPackage;
use App\Entity\Executable;
use App\Entity\ExecutableFile;
use App\Entity\InternalError;
use App\Entity\Judgehost;
use App\Entity\JudgeTask;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\JudgingRunOutput;
use App\Entity\QueueTask;
use App\Entity\Rejudging;
use App\Entity\Submission;
use App\Entity\SubmissionFile;
use App\Entity\TestcaseContent;
use App\Entity\Version;
use App\Service\BalloonService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\RejudgingService;
use App\Service\ScoreboardService;
use App\Service\SubmissionService;
use App\Utils\Utils;
use BadMethodCallException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Rest\Route('/judgehosts')]
#[OA\Tag(name: 'Judgehosts')]
#[OA\Response(ref: '#/components/responses/InvalidResponse', response: 400)]
#[OA\Response(ref: '#/components/responses/Unauthenticated', response: 401)]
#[OA\Response(ref: '#/components/responses/Unauthorized', response: 403)]
class JudgehostController extends AbstractFOSRestController
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly EventLogService $eventLogService,
        protected readonly ScoreboardService $scoreboardService,
        protected readonly SubmissionService $submissionService,
        protected readonly BalloonService $balloonService,
        protected readonly RejudgingService $rejudgingService,
        protected readonly LoggerInterface $logger
    ) {}

    /**
     * Get judgehosts.
     *
     * @return Judgehost[]
     */
    #[IsGranted(new Expression("is_granted('ROLE_JURY') or is_granted('ROLE_JUDGEHOST')"))]
    #[Rest\Get('')]
    #[OA\Response(
        response: 200,
        description: 'The judgehosts',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Judgehost::class))
        )
    )]
    #[OA\Parameter(
        name: 'hostname',
        description: 'Only show the judgehost with the given hostname',
        in: 'query',
        schema: new OA\Schema(type: 'string')
    )]
    public function getJudgehostsAction(
        #[MapQueryParameter]
        ?string $hostname = null
    ): array {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j');

        if ($hostname) {
            $queryBuilder
                ->andWhere('j.hostname = :hostname')
                ->setParameter('hostname', $hostname);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Add a new judgehost to the list of judgehosts.
     * Also restarts (and returns) unfinished judgings.
     *
     * @return array<array{jobid: int, submitid: int}>
     * @throws NonUniqueResultException
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Post('')]
    #[OA\Response(
        response: 200,
        description: 'The returned unfinished judgings',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'jobid', type: 'integer'),
                    new OA\Property(property: 'submitid', type: 'integer'),
                ],
                type: 'object'
            )
        )
    )]
    public function createJudgehostAction(Request $request): array
    {
        if (!$request->request->has('hostname')) {
            throw new BadRequestHttpException('Argument \'hostname\' is mandatory');
        }

        $hostname = $request->request->get('hostname');

        /** @var Judgehost|null $judgehost */
        $judgehost = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->andWhere('j.hostname = :hostname')
            ->setParameter('hostname', $hostname)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($judgehost) {
            $judgehost->setHidden(false);
        } else {
            $judgehost = new Judgehost();
            $judgehost->setHostname($hostname);
            $judgehost->setEnabled((bool)$this->config->get('judgehost_activated_by_default'));
            $this->em->persist($judgehost);
        }
        $this->em->flush();

        // If there are any unfinished judgings in the queue in my name, they will not be finished.
        // Give them back.
        /** @var Judging[] $judgings */
        $judgings = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->leftJoin('j.rejudging', 'r')
            ->innerJoin('j.runs', 'jr')
            ->innerJoin('jr.judgetask', 'jt')
            ->innerJoin('jt.judgehost', 'jh')
            ->select('j')
            ->distinct()
            ->andWhere('jh.hostname = :hostname')
            ->andWhere('j.judgingid = jt.jobid')
            ->andWhere('jr.runresult IS NULL')
            ->andWhere('j.valid = 1 OR r.valid = 1')
            ->andWhere('j.result != :compiler_error')
            ->setParameter('hostname', $hostname)
            ->setParameter('compiler_error', 'compiler-error')
            ->getQuery()
            ->getResult();

        foreach ($judgings as $judging) {
            $this->giveBackJudging($judging->getJudgingid(), $judgehost);
        }

        return array_map(fn(Judging $judging) => [
            'jobid' => $judging->getJudgingid(),
            'submitid' => $judging->getSubmission()->getSubmitid(),
        ], $judgings);
    }

    /**
     * Update the configuration of the given judgehost.
     *
     * @return Judgehost[]
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Put('/{hostname}')]
    #[OA\Response(
        response: 200,
        description: 'The modified judgehost',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Judgehost::class))
        )
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(
                        property: 'enabled',
                        description: 'The new enabled state of the judgehost',
                        type: 'boolean'),
                ]
            )
        )
    )]
    public function updateJudgeHostAction(
        Request $request,
        #[OA\PathParameter(description: 'The hostname of the judgehost to update')]
        string $hostname
    ): array {
        if (!$request->request->has('enabled')) {
            throw new BadRequestHttpException('Argument \'enabled\' is mandatory');
        }

        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if ($judgehost) {
            $judgehost->setEnabled($request->request->getBoolean('enabled'));
            $this->em->flush();
            return [$judgehost];
        }
        throw new NotFoundHttpException(sprintf('Judgehost with hostname \'%s\' not found', $hostname));
    }

    /**
     * Update the given judging for the given judgehost.
     * @throws NonUniqueResultException
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Put('/update-judging/{hostname}/{judgetaskid<\d+>}')]
    #[OA\Response(
        response: 200,
        description: 'When the judging has been updated'
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(
                        property: 'compile_success',
                        description: 'Whether compilation was successful',
                        type: 'boolean'
                    ),
                    new OA\Property(
                        property: 'output_compile',
                        description: 'The compile output',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'entry_point',
                        description: 'The determined entrypoint',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'compile_metadata',
                        description: 'The (base64-encoded) metadata of the compilation.',
                        type: 'string'
                    ),
                ]
            )
        )
    )]
    public function updateJudgingAction(
        Request $request,
        #[OA\PathParameter(description: 'The hostname of the judgehost that wants to update the judging')]
        string $hostname,
        #[OA\PathParameter(description: 'The ID of the judgetask to update', schema: new OA\Schema(type: 'integer'))]
        int $judgetaskid
    ): void {
        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if (!$judgehost) {
            throw new BadRequestHttpException(
                'Register yourself first. You (' . $hostname . ') are not known to us yet.'
            );
        }

        $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(['judgetaskid' => $judgetaskid]);
        $query = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->join('j.submission', 's')
            ->join('s.contest', 'c')
            ->join('s.team', 't')
            ->join('s.problem', 'p')
            ->select('j, s, c, t, p')
            ->andWhere('j.judgingid = :judgingid')
            ->setParameter('judgingid', $judgingRun->getJudgingId())
            ->setMaxResults(1)
            ->getQuery();

        /** @var Judging|null $judging */
        $judging = $query->getOneOrNullResult();
        if (!$judging) {
            throw new BadRequestHttpException("We don't know this judging with judgetaskid ID $judgetaskid.");
        }

        if ($request->request->has('output_compile')) {
            // Note: we use ->get here instead of ->has since entry_point can be the empty string and then we do not
            // want to update the submission or send out an update event
            if ($request->request->get('entry_point')) {
                $this->em->wrapInTransaction(function () use ($query, $request, &$judging) {
                    $submission = $judging->getSubmission();
                    if ($submission->getEntryPoint() === $request->request->get('entry_point')) {
                        return;
                    }
                    $submission->setEntryPoint($request->request->get('entry_point'));
                    $this->em->flush();
                    $submissionId = $submission->getSubmitid();
                    $contestId    = $submission->getContest()->getCid();
                    $this->eventLogService->log('submission', $submissionId,
                                                EventLogService::ACTION_UPDATE, $contestId);

                    // As EventLogService::log() will clear the entity manager, so the judging has
                    // now become detached. We will have to reload it.
                    /** @var Judging $judging */
                    $judging = $query->getOneOrNullResult();
                });
            }

            // Reload judgehost just in case it got cleared above.
            /** @var Judgehost $judgehost */
            $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);

            $output_compile = base64_decode($request->request->get('output_compile'));
            if ($request->request->getBoolean('compile_success')) {
                if ($judging->getOutputCompile() === null) {
                    $judging
                        ->setOutputCompile($output_compile)
                        ->setCompileMetadata(base64_decode($request->request->get('compile_metadata')));
                    $this->em->flush();

                    if ($judging->getValid()) {
                        $this->eventLogService->log('judging', $judging->getJudgingid(),
                            EventLogService::ACTION_CREATE, $judging->getContest()->getCid());
                    }
                } elseif ($judging->getResult() === Judging::RESULT_COMPILER_ERROR) {
                    // The new result contradicts a former one, that's not good.
                    // Since the other judgehosts were not successful, but we were, assume that the other judgehosts
                    // are broken and disable it.
                    $disableHostnames = [];
                    /** @var JudgingRun $run */
                    foreach ($judging->getRuns() as $run) {
                        if ($run->getJudgeTask() &&
                            $run->getJudgeTask()->getJudgehost() &&
                            $run->getJudgeTask()->getJudgehost()->getHostname() !== $judgehost->getHostname()) {
                            $hostname = $run->getJudgeTask()->getJudgehost()->getHostname();
                            $disableHostnames[$hostname] = $hostname;
                        }
                    }

                    foreach ($disableHostnames as $hostname) {
                        $disabled = [
                            'kind' => 'judgehost',
                            'hostname' => $hostname,
                        ];
                        $error = new InternalError();
                        $error
                            ->setJudging($judging)
                            ->setContest($judging->getContest())
                            ->setDescription('Compilation results are different for j' . $judging->getJudgingid())
                            ->setJudgehostlog(base64_encode('New compilation output: ' . $output_compile))
                            ->setTime(Utils::now())
                            ->setDisabled($disabled);
                        $this->em->persist($error);
                    }
                }
            } else {
                $compileMetadata = $request->request->get('compile_metadata');
                $this->em->wrapInTransaction(function () use (
                    $judgehost,
                    $judging,
                    $query,
                    $output_compile,
                    $compileMetadata
                ) {
                    if ($judging->getOutputCompile() === null) {
                        $judging
                            ->setOutputCompile($output_compile)
                            ->setResult(Judging::RESULT_COMPILER_ERROR)
                            ->setEndtime(Utils::now());

                        if ($compileMetadata !== null) {
                            $judging->setCompileMetadata(base64_decode($compileMetadata));
                        }
                        $this->em->flush();

                        if ($judging->getValid()) {
                            $this->eventLogService->log('judging', $judging->getJudgingid(),
                                EventLogService::ACTION_CREATE, $judging->getContest()->getCid());
                        }

                        // As EventLogService::log() will clear the entity manager, so the judging has
                        // now become detached. We will have to reload it.
                        /** @var Judging $judging */
                        $judging = $query->getOneOrNullResult();

                        // Invalidate judgetasks.
                        $this->em->getConnection()->executeStatement(
                            'UPDATE judgetask SET valid=0'
                            . ' WHERE jobid=:jobid',
                            [
                                'jobid' => $judging->getJudgingid(),
                            ]
                        );
                        $this->em->flush();
                    } elseif ($judging->getResult() !== Judging::RESULT_COMPILER_ERROR) {
                        // The new result contradicts a former one, that's not good.
                        // Since at least one other judgehost was successful, but we were not, assume that the
                        // current judgehost is broken and disable it.
                        $disabled = [
                            'kind' => 'judgehost',
                            'hostname' => $judgehost->getHostname(),
                        ];
                        $error = new InternalError();
                        $error
                            ->setJudging($judging)
                            ->setContest($judging->getContest())
                            ->setDescription('Compilation results are different for j' . $judging->getJudgingid())
                            ->setJudgehostlog(base64_encode('New compilation output: ' . $output_compile))
                            ->setTime(Utils::now())
                            ->setDisabled($disabled);
                        $this->em->persist($error);
                    }

                    $judgingId = $judging->getJudgingid();
                    $contestId = $judging->getSubmission()->getContest()->getCid();
                    $this->dj->auditlog('judging', $judgingId, 'judged',
                                        'compiler-error', $judgehost->getHostname(), $contestId);

                    $this->maybeUpdateActiveJudging($judging);
                    $this->em->flush();
                    if (!$this->config->get('verification_required') &&
                        $judging->getValid()) {
                        $this->eventLogService->log('judging', $judgingId,
                                                    EventLogService::ACTION_UPDATE, $contestId);
                    }

                    $submission = $judging->getSubmission();
                    $contest    = $submission->getContest();
                    $team       = $submission->getTeam();
                    $problem    = $submission->getProblem();
                    $this->scoreboardService->calculateScoreRow($contest, $team, $problem);

                    $message = sprintf("submission %d, judging %d: compiler-error",
                                       $submission->getSubmitid(), $judging->getJudgingid());
                    $this->dj->alert('reject', $message);
                });
            }
        } else {
            throw new BadRequestHttpException('Inconsistent data, no compilation data provided.');
        }

        $judgehost->setPolltime(Utils::now());
        $this->em->flush();
    }

    /**
     * Add back debug info.
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Post('/add-debug-info/{hostname}/{judgeTaskId<\d+>}')]
    #[OA\Response(response: 200, description: 'When the debug info has been added')]
    public function addDebugInfo(
        Request $request,
        #[OA\PathParameter(description: 'The hostname of the judgehost that wants to add the debug info')]
        string $hostname,
        #[OA\PathParameter(description: 'The ID of the judgetask to add', schema: new OA\Schema(type: 'integer'))]
        int $judgeTaskId
    ): void {
        $judgeTask = $this->em->getRepository(JudgeTask::class)->find($judgeTaskId);
        if ($judgeTask === null) {
            throw new BadRequestHttpException(
                'Inconsistent data, no judgetask known with judgetaskid = ' . $judgeTaskId . '.');
        }

        if ($judgeTask->getRunScriptId() === null) {
            $full_debug = false;
            $required = [
                'output_run',
            ];
        } else {
            $full_debug = true;
            $required = [
                'full_debug',
            ];
        }

        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(
                    sprintf("Argument '%s' is mandatory", $argument));
            }
        }

        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if (!$judgehost) {
            throw new BadRequestHttpException("Who are you and why are you sending us any data?");
        }

        if ($full_debug) {
            $judging = $this->em->getRepository(Judging::class)->find($judgeTask->getJobId());
            if ($judging === null) {
                throw new BadRequestHttpException(
                    'Inconsistent data, no judging known with judgingid = ' . $judgeTask->getJobId() . '.');
            }
            if ($tempFilename = tempnam($this->dj->getDomjudgeTmpDir(), "full-debug-")) {
                $debug_package = base64_decode($request->request->get('full_debug'));
                file_put_contents($tempFilename, $debug_package);
            }
            // FIXME: error checking
            $debug_package = new DebugPackage();
            $debug_package
                ->setJudgehost($judgehost)
                ->setJudging($judging)
                ->setFilename($tempFilename);
            $this->em->persist($debug_package);
        } else {
            $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(
                [
                    'judging' => $judgeTask->getJobId(),
                    'testcase' => $judgeTask->getTestcaseId(),
                ]
            );
            if ($judgingRun === null) {
                throw new BadRequestHttpException(
                    'Inconsistent data, no judging run known with jid = ' . $judgeTask->getJobId() . '.');
            }

            $outputRun = base64_decode($request->request->get('output_run'));

            /** @var JudgingRunOutput $judgingRunOutput */
            $judgingRunOutput = $judgingRun->getOutput();
            $judgingRunOutput->setOutputRun($outputRun);
        }
        $this->em->flush();
    }

    /**
     * Add one JudgingRun. When relevant, finalize the judging.
     * @throws DBALException
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Post('/add-judging-run/{hostname}/{judgeTaskId<\d+>}')]
    #[OA\Response(response: 200, description: 'When the judging run has been added')]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['runresult', 'runtime', 'output_run', 'output_diff', 'output_error', 'output_system'],
                properties: [
                    new OA\Property(
                        property: 'runresult',
                        description: 'The result of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'runtime',
                        description: 'The runtime of the run',
                        type: 'number',
                        format: 'float'
                    ),
                    new OA\Property(
                        property: 'output_run',
                        description: 'The (base64-encoded) output of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'output_diff',
                        description: 'The (base64-encoded) output diff of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'output_error',
                        description: 'The (base64-encoded) error output of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'output_system',
                        description: 'The (base64-encoded) system output of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'team_message',
                        description: 'The (base64-encoded) message to the team of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'metadata',
                        description: 'The (base64-encoded) metadata of the run',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'compare_metadata',
                        description: 'The (base64-encoded) metadata of the validator',
                        type: 'string'
                    ),
                ]
            )
        )
    )]
    public function addJudgingRunAction(
        Request $request,
        #[OA\PathParameter(description: 'The hostname of the judgehost that wants to add the judging run')]
        string $hostname,
        #[OA\PathParameter(description: 'The ID of the judgetask to add', schema: new OA\Schema(type: 'integer'))]
        int $judgeTaskId
    ): int {
        $required = [
            'runresult',
            'runtime',
            'output_run',
            'output_diff',
            'output_error',
            'output_system'
        ];

        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(
                    sprintf("Argument '%s' is mandatory", $argument));
            }
        }

        $runResult    = $request->request->get('runresult');
        $runTime      = $request->request->get('runtime');
        $outputRun    = $request->request->get('output_run');
        $outputDiff   = $request->request->get('output_diff');
        $outputError  = $request->request->get('output_error');
        $outputSystem = $request->request->get('output_system');
        $teamMessage  = $request->request->get('team_message');
        $metadata     = $request->request->get('metadata');
        $testcasedir  = $request->request->get('testcasedir');
        $compareMeta = $request->request->get('compare_metadata');

        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if (!$judgehost) {
            throw new BadRequestHttpException("Who are you and why are you sending us any data?");
        }

        $hasFinalResult = $this->addSingleJudgingRun($judgeTaskId, $hostname, $runResult, $runTime,
            $outputSystem, $outputError, $outputDiff, $outputRun, $teamMessage, $metadata, $testcasedir, $compareMeta);
        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        $judgehost->setPolltime(Utils::now());
        $this->em->flush();

        return (int)$hasFinalResult;
    }

    /**
     * Internal error reporting (back from judgehost).
     *
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    #[IsGranted('ROLE_JUDGEHOST')]
    #[Rest\Post('/internal-error')]
    #[OA\Response(
        response: 200,
        description: 'The ID of the created internal error',
        content: new OA\JsonContent(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(
                required: ['description', 'judgehostlog', 'disabled'],
                properties: [
                    new OA\Property(
                        property: 'description',
                        description: 'The description of the internal error',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'judgehostlog',
                        description: 'The log of the judgehost',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'disabled',
                        description: 'The object to disable in JSON format',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'judgetaskid',
                        description: 'The ID of the judgeTask that was being worked on',
                        type: 'integer'
                    ),
                ]
            )
        )
    )]
    public function internalErrorAction(Request $request): ?int
    {
        $required = ['description', 'judgehostlog', 'disabled'];
        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(sprintf("Argument '%s' is mandatory", $argument));
            }
        }
        $description  = $request->request->get('description');
        $judgehostlog = $request->request->get('judgehostlog');
        $disabled     = $request->request->get('disabled');

        // The judgetaskid is allowed to be NULL.
        $judgeTaskId = $request->request->get('judgetaskid');
        $judging = null;
        $judgingId = null;
        $cid = null;
        $judgingRun = null;
        if ($judgeTaskId) {
            /** @var JudgeTask $judgeTask */
            $judgeTask = $this->em->getRepository(JudgeTask::class)->findOneBy(['judgetaskid' => $judgeTaskId]);
            if ($judgeTask->getType() == JudgeTaskType::JUDGING_RUN) {
                $judgingId = $judgeTask->getJobId();
                /** @var Judging $judging */
                $judging = $this->em->getRepository(Judging::class)->findOneBy(['judgingid' => $judgingId]);
                $cid = $judging->getContest()->getCid();
            }
            $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(['judgetaskid' => $judgeTaskId]);
        }

        $disabled = Utils::jsonDecode($disabled);

        /** @var Contest|null $contest */
        $contest = null;
        if ($cid) {
            $contest = $this->em->getRepository(Contest::class)->find($cid);
        }

        $field_name = null;
        $disabled_id = null;
        if (in_array($disabled['kind'], ['compile_script', 'compare_script', 'run_script'])) {
            $field_name = $disabled['kind'] . '_id';
            $disabled_id = $disabled[$field_name];

            // Since these are the immutable executables, we need to map it to the mutable one first to make linking and
            // re-enabling possible.
            $executable = $this->em->getRepository(Executable::class)
                ->findOneBy(['immutableExecutable' => $disabled[$field_name]]);
            if (!$executable) {
                // Race condition where the user changed the executable (hopefully for the better). Ignore.
                return null;
            }
            $disabled['execid'] = $executable->getExecid();
            unset($disabled[$field_name]);
            $disabled['kind'] = 'executable';
        }

        // Group together duplicate internal errors.
        // Note that it may be good to be able to ignore fields here, e.g. judgingid with compile errors.
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(InternalError::class, 'e')
            ->select('e')
            ->andWhere('e.description = :description')
            ->andWhere('e.disabled = :disabled')
            ->andWhere('e.status = :status')
            ->setParameter('description', $description)
            ->setParameter('disabled', Utils::jsonEncode($disabled))
            ->setParameter('status', 'open')
            ->setMaxResults(1);

        /** @var InternalError|null $error */
        $error = $queryBuilder->getQuery()->getOneOrNullResult();

        if ($error) {
            // FIXME: in some cases it makes sense to extend the known information, e.g. the judgehostlog.
            return $error->getErrorid();
        }

        $error = new InternalError();
        $error
            ->setJudging($judging)
            ->setContest($contest)
            ->setDescription($description)
            ->setJudgehostlog($judgehostlog)
            ->setTime(Utils::now())
            ->setDisabled($disabled);
        if ($judgingRun) {
            $error->setJudgingRun($judgingRun);
        }
        $this->em->persist($error);
        // Even if there are no remaining judge tasks for this judging open (which is covered by the transaction below),
        // we need to mark this judging as internal error.
        $judging?->setInternalError($error);
        $this->em->flush();

        if ($field_name !== null) {
            // Disable any outstanding judgetasks with the same script that have not been claimed yet.
            $this->em->wrapInTransaction(function (EntityManager $em) use ($field_name, $disabled_id, $error) {
                $judgingids = $em->getConnection()->executeQuery(
                    'SELECT DISTINCT jobid'
                    . ' FROM judgetask'
                    . ' WHERE ' . $field_name . ' = :id'
                    . ' AND judgehostid IS NULL'
                    . ' AND valid = 1',
                    [
                        'id' => $disabled_id,
                    ]
                )->fetchFirstColumn();
                $judgings = $em->getRepository(Judging::class)->findBy(['judgingid' => $judgingids]);
                foreach ($judgings as $judging) {
                    /** @var Judging $judging */
                    $judging->setInternalError($error);
                }
                $em->flush();
                $em->getConnection()->executeStatement(
                    'UPDATE judgetask SET valid=0'
                    . ' WHERE ' . $field_name . ' = :id'
                    . ' AND judgehostid IS NULL',
                    [
                        'id' => $disabled_id,
                    ]
                );
            });
        }

        $this->dj->setInternalError($disabled, $contest, false);

        if (in_array($disabled['kind'], ['problem', 'language', 'judgehost', 'executable', 'testcase'])) {
            // Give back judging if we have to.
            if ($disabled['kind'] == 'judgehost') {
                $hostname = $request->request->get('hostname');
                $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
            } else {
                $judgehost = null;
            }
            if (!empty($judgingId)) {
                $this->giveBackJudging((int)$judgingId, $judgehost);
            }
        }

        return $error->getErrorid();
    }

    /**
     * Give back the unjudged runs from the judging with the given judging ID
     * @param Judgehost|null $judgehost If set, only partially returns judgetasks instead of full judging.
     */
    protected function giveBackJudging(int $judgingId, ?Judgehost $judgehost): void
    {
        $judging = $this->em->getRepository(Judging::class)->find($judgingId);
        if ($judging) {
            $this->em->wrapInTransaction(function () use ($judging, $judgehost) {
                /** @var JudgingRun $run */
                foreach ($judging->getRuns() as $run) {
                    if ($judgehost === null) {
                        // This is coming from internal errors, reset the whole judging.
                        $run->getJudgetask()
                            ->setValid(false);
                        continue;
                    }

                    // We do not have to touch any finished runs
                    if ($run->getRunresult() !== null) {
                        continue;
                    }

                    // For the other runs, we need to reset the judge task if it belongs to the current judgehost.
                    if ($run->getJudgetask()->getJudgehost() && $run->getJudgetask()->getJudgehost()->getHostname() === $judgehost->getHostname()) {
                        $run->getJudgetask()
                            ->setJudgehost(null)
                            ->setStarttime(null);
                    }
                }

                $this->em->flush();
            });

            if ($judgehost === null) {
                // Invalidate old judging and create a new one - but without judgetasks yet since this was triggered by
                // an internal error.
                $judging->setValid(false);
                $newJudging = new Judging();
                $newJudging
                    ->setContest($judging->getContest())
                    ->setValid(true)
                    ->setSubmission($judging->getSubmission())
                    ->setOriginalJudging($judging);
                $this->em->persist($newJudging);
                $this->em->flush();
            }

            $this->dj->auditlog('judging', $judgingId, 'given back'
                . ($judgehost === null ? '' : ' for judgehost ' . $judgehost->getHostname()), null,
                $judgehost?->getHostname(), $judging->getContest()->getCid());
        }
    }

    /**
     * Add a single judging to a given judging run.
     *
     * @throws DBALException
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws ORMException
     *
     * Returns true if the judging needs more work.
     */
    private function addSingleJudgingRun(
        int $judgeTaskId,
        string  $hostname,
        string  $runResult,
        string  $runTime,
        string  $outputSystem,
        string  $outputError,
        string  $outputDiff,
        string  $outputRun,
        ?string $teamMessage,
        string  $metadata,
        ?string $testcasedir,
        ?string $compareMeta,
    ): bool {
        $resultsRemap = $this->config->get('results_remap');
        $resultsPrio  = $this->config->get('results_prio');

        if (array_key_exists($runResult, $resultsRemap)) {
            $this->logger->info('JudgeTask %d remapping result %s -> %s',
                                [ $judgeTaskId, $runResult, $resultsRemap[$runResult] ]);
            $runResult = $resultsRemap[$runResult];
        }

        $this->em->wrapInTransaction(function () use (
            $judgeTaskId,
            $runTime,
            $runResult,
            $outputSystem,
            $outputError,
            $outputDiff,
            $outputRun,
            $teamMessage,
            $metadata,
            $testcasedir,
            $compareMeta
        ) {
            $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(
                ['judgetaskid' => $judgeTaskId]);
            if ($judgingRun === null) {
                throw new BadRequestHttpException(
                    'Inconsistent data, no judging run known with judgetaskid = ' . $judgeTaskId . '.');
            }
            $judgingRunOutput = new JudgingRunOutput();
            $judgingRun->setOutput($judgingRunOutput);
            $judgingRun
                ->setRunresult($runResult)
                ->setRuntime((float)$runTime)
                ->setEndtime(Utils::now())
                ->setTestcasedir($testcasedir);
            $judgingRunOutput
                ->setOutputRun(base64_decode($outputRun))
                ->setOutputDiff(base64_decode($outputDiff))
                ->setOutputError(base64_decode($outputError))
                ->setOutputSystem(base64_decode($outputSystem))
                ->setMetadata(base64_decode($metadata));

            if ($compareMeta) {
                $judgingRunOutput->setValidatorMetadata(base64_decode($compareMeta));
            }

            if ($teamMessage) {
                $judgingRunOutput->setTeamMessage(base64_decode($teamMessage));
            }

            $judging = $judgingRun->getJudging();
            $this->maybeUpdateActiveJudging($judging);
            $this->em->flush();

            if ($judging->getValid()) {
                $this->eventLogService->log('judging_run', $judgingRun->getRunid(),
                                            EventLogService::ACTION_CREATE, $judging->getContest()->getCid());
            }
        });

        // Reload the judging, as EventLogService::log will clear the entity manager.
        // For the judging, also load in the submission and some of its relations.
        /** @var JudgingRun $judgingRun */
        $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(['judgetaskid' => $judgeTaskId]);
        $judging = $judgingRun->getJudging();

        // Result of this judging_run has been stored. now check whether
        // we're done or if more testcases need to be judged.

        /** @var JudgingRun[] $runs */
        $runs = $this->em->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->leftJoin(JudgingRun::class, 'jr', Join::WITH, 'jt.testcase_id = jr.testcase AND jr.judging = :judgingid')
            ->select('jr.runresult')
            ->andWhere('jt.jobid = :judgingid')
            ->andWhere('jr.judging = :judgingid')
            ->andWhere('jt.testcase_id = jr.testcase')
            ->orderBy('jt.judgetaskid')
            ->setParameter('judgingid', $judging->getJudgingid())
            ->getQuery()
            ->getArrayResult();
        $runresults = array_column($runs, 'runresult');

        $oldResult = $judging->getResult();

        $lazyEval = DOMJudgeService::EVAL_LAZY;
        if (($result = SubmissionService::getFinalResult($runresults, $resultsPrio)) !== null) {
            // Lookup global lazy evaluation of results setting and possible problem specific override.
            $lazyEval    = $this->config->get('lazy_eval_results');
            $problemLazy = $judging->getSubmission()->getContestProblem()->getLazyEvalResults();
            if ($problemLazy !== DOMJudgeService::EVAL_DEFAULT) {
                $lazyEval = $problemLazy;
            }

            $judging->setResult($result);

            $hasNullResults = false;
            foreach ($runresults as $runresult) {
                if ($runresult === null) {
                    $hasNullResults = true;
                    break;
                }
            }
            $sendJudgingEvent = false;
            if (!$hasNullResults || $lazyEval !== DOMJudgeService::EVAL_FULL) {
                // NOTE: setting endtime here determines in testcases_GET
                // whether a next testcase will be handed out.
                // We want to set the endtime and max runtime only once (once the verdict is known),
                // so that the API doesn't update these values once they are set.
                // We also don't want to send judging events after the verdict is known.
                if (!$judging->getEndtime()) {
                    $sendJudgingEvent = true;
                    $judging->setEndtime(Utils::now());

                    // Also calculate the max run time and set it
                    $maxRunTime = $this->em->createQueryBuilder()
                        ->from(Judging::class, 'j')
                        ->select('MAX(jr.runtime) AS maxruntime')
                        ->leftJoin('j.runs', 'jr')
                        ->andWhere('j.judgingid = :judgingid')
                        ->andWhere('jr.runtime IS NOT NULL')
                        ->setParameter('judgingid', $judging->getJudgingid())
                        ->getQuery()
                        ->getSingleScalarResult();
                    $judging->setMaxRuntimeForVerdict($maxRunTime);
                }
                $this->maybeUpdateActiveJudging($judging);
            }
            $this->em->flush();

            // Only update if the current result is different from what we had before.
            // This should only happen when the old result was NULL.
            if ($oldResult !== $result) {
                if ($oldResult === 'aborted') {
                    // This judging was cancelled while we worked on it,
                    // probably as part of a cancelled rejudging.
                    // Throw away our work, and return that we're done.
                    return false;
                }
                if ($oldResult !== null) {
                    throw new BadMethodCallException('internal bug: the evaluated result changed during judging');
                }

                if ($lazyEval !== DOMJudgeService::EVAL_FULL) {
                    // We don't want to continue on this problem, even if there's spare resources.
                    $this->em->getConnection()->executeStatement(
                        'UPDATE judgetask SET valid=0, priority=:priority'
                        . ' WHERE jobid=:jobid',
                        [
                            'priority' => JudgeTask::PRIORITY_LOW,
                            'jobid' => $judgingRun->getJudgingid(),
                        ]
                    );
                } else {
                    // Decrease priority of remaining unassigned judging runs.
                    $this->em->getConnection()->executeStatement(
                        'UPDATE judgetask SET priority=:priority'
                        . ' WHERE jobid=:jobid'
                        . ' AND judgehostid IS NULL',
                        [
                            'priority' => JudgeTask::PRIORITY_LOW,
                            'jobid' => $judgingRun->getJudgingid(),
                        ]
                    );
                }

                $submission = $judging->getSubmission();
                $contest    = $submission->getContest();
                $team       = $submission->getTeam();
                $problem    = $submission->getProblem();
                $this->scoreboardService->calculateScoreRow($contest, $team, $problem);

                // We call alert here before possible validation. Note that
                // this means that these alert messages should be treated as
                // confidential information.
                $msg = sprintf("submission %s, judging %s: %s",
                               $submission->getSubmitid(), $judging->getJudgingid(), $result);
                $this->dj->alert($result === 'correct' ? 'accept' : 'reject', $msg);

                // Potentially send a balloon, i.e. if no verification required (case of verification required is
                // handled in jury/SubmissionController::verifyAction).
                if (!$this->config->get('verification_required') && $judging->getValid()) {
                    $this->balloonService->updateBalloons($contest, $submission, $judging);
                }

                $this->dj->auditlog('judging', $judging->getJudgingid(), 'judged', $result, $hostname);
            }

            // Send an event for an endtime (and max runtime update).
            if ($sendJudgingEvent && $judging->getValid()) {
                $this->eventLogService->log('judging', $judging->getJudgingid(),
                    EventLogService::ACTION_UPDATE, $judging->getContest()->getCid());
            }
        }

        return $judging->getResult() === null || $judging->getJudgeCompletely() || $lazyEval === DOMJudgeService::EVAL_FULL;
    }

    private function maybeUpdateActiveJudging(Judging $judging): void
    {
        if ($judging->getRejudging() !== null) {
            $rejudging = $judging->getRejudging();
            if ($rejudging->getAutoApply()) {
                $judging->getSubmission()->setRejudging(null);
                foreach ($judging->getSubmission()->getJudgings() as $j) {
                    $j->setValid(false);
                }
                $judging->setValid(true);

                // Check whether we are completely done with this rejudging.
                if ($rejudging->getEndtime() === null && $this->rejudgingService->calculateTodo($rejudging)['todo'] == 0) {
                    $rejudging->setEndtime(Utils::now());
                    $rejudging->setFinishUser(null);
                    $this->em->flush();
                }
            }

            if ($rejudging->getRepeat() > 1 && $rejudging->getEndtime() === null
                    && $this->rejudgingService->calculateTodo($rejudging)['todo'] == 0) {
                $numberOfRepetitions = $this->em->createQueryBuilder()
                    ->from(Rejudging::class, 'r')
                    ->select('COUNT(r.rejudgingid) AS cnt')
                    ->andWhere('r.repeatedRejudging = :repeat_rejudgingid')
                    ->setParameter('repeat_rejudgingid', $rejudging->getRepeatedRejudging()->getRejudgingid())
                    ->getQuery()
                    ->getSingleScalarResult();
                // Only "cancel" the rejudging if it's not the last.
                if ($numberOfRepetitions < $rejudging->getRepeat()) {
                    $rejudgingid = $rejudging->getRejudgingid();
                    $numUpdated = $this->em->getConnection()->executeStatement(
                        'UPDATE rejudging
                        SET endtime = :endtime, valid = 0
                        WHERE rejudgingid = :rejudgingid
                          AND endtime IS NULL',
                        [
                            'endtime' => Utils::now(),
                            'rejudgingid' => $rejudgingid,
                        ]
                    );
                    $this->em->flush();
                    if ($numUpdated == 0) {
                        // Due to parallel judging some other judgehost did the same calculation just now and beat us
                        // to it.
                        return;
                    }

                    // Reset association before creating the new rejudging.
                    $this->em->getConnection()->executeStatement(
                        'UPDATE submission
                            SET rejudgingid = NULL
                            WHERE rejudgingid = :rejudgingid',
                        ['rejudgingid' => $rejudgingid]);
                    $this->em->flush();

                    $skipped = [];
                    /** @var Judging[] $judgings */
                    $judgings = $this->em->createQueryBuilder()
                        ->from(Judging::class, 'j')
                        ->leftJoin('j.submission', 's')
                        ->leftJoin('s.rejudging', 'r')
                        ->leftJoin('s.team', 't')
                        ->select('j', 's', 'r', 't')
                        ->andWhere('j.rejudging = :rejudgingid')
                        ->setParameter('rejudgingid', $rejudgingid)
                        ->getQuery()
                        ->setHint(Query::HINT_REFRESH, true)
                        ->getResult();
                    // TODO: Pick up priority from previous judgings?
                    $this->rejudgingService->createRejudging($rejudging->getReason(), JudgeTask::PRIORITY_DEFAULT, $judgings,
                        false, $rejudging->getRepeat(), 0, $rejudging->getRepeatedRejudging(), $skipped);
                }
            }
        }
    }

    /**
     * Get files for a given type and id.
     * @throws NonUniqueResultException
     * @return JudgehostFile[]
     */
    #[IsGranted(new Expression("is_granted('ROLE_JURY') or is_granted('ROLE_JUDGEHOST')"))]
    #[Rest\Get('/get_files/{type}/{id<\d+>}')]
    #[OA\Response(
        response: 200,
        description: 'The files for the submission, testcase or script.',
        content: new OA\JsonContent(ref: new Model(type: JudgehostFile::class))
    )]
    #[OA\Parameter(ref: '#/components/parameters/id')]
    public function getFilesAction(
        #[OA\PathParameter(description: 'The type to get files for')]
        string $type,
        string $id
    ): array {
        return match ($type) {
            'source' => $this->getSourceFiles($id),
            'testcase' => $this->getTestcaseFiles($id),
            'compare', 'compile', 'debug', 'run' => $this->getExecutableFiles($id),
            default => throw new BadRequestHttpException('Unknown type requested.'),
        };
    }

    /**
     * Get version commands for a given compile script.
     *
     * @return array{compiler_version_command?: string, runner_version_command?: string}
     */
    #[IsGranted(new Expression("is_granted('ROLE_JURY') or is_granted('ROLE_JUDGEHOST')"))]
    #[Rest\Get('/get_version_commands/{judgetaskid<\d+>}')]
    #[OA\Response(
        response: 200,
        description: 'Returns optionally compiler and runner version commands.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'compiler_version_command', type: 'string', nullable: true),
                new OA\Property(property: 'runner_version_command', type: 'string', nullable: true),
            ],
            type: 'object'
        )
    )]
    public function getVersionCommands(string $judgetaskid): array
    {
        $judgeTask = $this->em->getRepository(JudgeTask::class)
            ->findOneBy(['judgetaskid' => $judgetaskid]);
        if (!$judgeTask) {
            throw new BadRequestHttpException('Unknown judge task with id ' . $judgetaskid);
        }

        $submission = $this->em->getRepository(Submission::class)
            ->findOneBy(['submitid' => $judgeTask->getSubmitid()]);
        if (!$submission) {
            throw new HttpException(500, 'Unknown submission with submitid ' . $judgeTask->getSubmitid());
        }

        $language = $submission->getLanguage();

        $ret = [];
        if (!empty($language->getCompilerVersionCommand())) {
            $ret['compiler_version_command'] = $language->getCompilerVersionCommand();
        }
        if (!empty($language->getRunnerVersionCommand())) {
            $ret['runner_version_command'] = $language->getRunnerVersionCommand();
        }
        return $ret;
    }

    /**
     * @return array{}
     */
    #[IsGranted(new Expression("is_granted('ROLE_JURY') or is_granted('ROLE_JUDGEHOST')"))]
    #[Rest\Put('/check_versions/{judgetaskid}')]
    #[OA\Response(
        response: 200,
        description: 'Updates internal versions, does not check them yet.',
    )]
    #[OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['hostname', 'compiler', 'runner'],
                    properties: [
                        new OA\Property(
                            property: 'problem',
                            description: 'Checking versions for the given hostname.',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'compiler',
                            description: 'Reported compiler version.',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'runner',
                            description: 'Reported runner version.',
                            type: 'array',
                            items: new OA\Items(type: 'string', format: 'binary')
                        ),
                    ]
                )
            ),
        ]
    )]
    public function checkVersions(Request $request, string $judgetaskid): array
    {
        $judgeTask = $this->em->getRepository(JudgeTask::class)
            ->findOneBy(['judgetaskid' => $judgetaskid]);
        if (!$judgeTask) {
            throw new BadRequestHttpException('Unknown judge task with id ' . $judgetaskid);
        }

        $submission = $this->em->getRepository(Submission::class)
            ->findOneBy(['submitid' => $judgeTask->getSubmitid()]);
        if (!$submission) {
            throw new BadRequestHttpException('Unknown submission with submitid ' . $judgeTask->getSubmitid());
        }

        $language = $submission->getLanguage();

        $hostname = $request->request->get('hostname');
        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if (!$judgehost) {
            throw new BadRequestHttpException(
                'Register yourself first. You (' . $hostname . ') are not known to us yet.');
        }

        $reportedVersions = [];
        if ($request->request->has('compiler')) {
            $reportedVersions['compiler'] = base64_decode($request->request->get('compiler'));
        }
        if ($request->request->has('runner')) {
            $reportedVersions['runner'] = base64_decode($request->request->get('runner'));
        }
        $this->em->wrapInTransaction(function () use (
            $judgehost,
            $reportedVersions,
            $language,
            $judgeTask
        ) {
            $activeVersion = $this->em->getRepository(Version::class)
                ->findOneBy(['language' => $language, 'judgehost' => $judgehost, 'active' => true]);

            $isNewVersion = false;
            if (!$activeVersion) {
                $isNewVersion = true;
            } else {
                $reportedCompilerVersion = $reportedVersions['compiler'] ?? null;
                if ($activeVersion->getCompilerVersion() !== $reportedCompilerVersion) {
                    $isNewVersion = true;
                }
                $reportedRunnerVersion = $reportedVersions['runner'] ?? null;
                if ($activeVersion->getRunnerVersion() !== $reportedRunnerVersion) {
                    $isNewVersion = true;
                }
            }
            if ($isNewVersion) {
                if ($activeVersion) {
                    $activeVersion->setActive(false);
                    $this->em->flush();
                }
                $activeVersion = new Version();
                $activeVersion
                    ->setLanguage($language)
                    ->setJudgehost($judgehost)
                    ->setActive(true)
                ;
                if (isset($reportedVersions['compiler'])) {
                    $activeVersion
                        ->setCompilerVersion($reportedVersions['compiler'])
                        ->setCompilerVersionCommand($language->getCompilerVersionCommand());
                }
                if (isset($reportedVersions['runner'])) {
                    $activeVersion
                        ->setRunnerVersion($reportedVersions['runner'])
                        ->setRunnerVersionCommand($language->getRunnerVersionCommand());
                }
                $activeVersion->setLastChangedTime(Utils::now());
                $this->em->persist($activeVersion);
                $this->em->flush();
            }

            $judgeTask->setVersion($activeVersion);

            // TODO: Optionally check version here against canonical version.
        });
        return [];
    }

    /**
     * @return JudgehostFile[]
     */
    private function getSourceFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(SubmissionFile::class, 'f')
            ->select('f')
            ->andWhere('f.submission = :submitid')
            ->setParameter('submitid', $id)
            ->orderBy('f.ranknumber');

        /** @var SubmissionFile[] $files */
        $files = $queryBuilder->getQuery()->getResult();

        if (empty($files)) {
            throw new NotFoundHttpException(sprintf('Source code for submission with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach ($files as $file) {
            $result[]   = new JudgehostFile(
                filename: $file->getFilename(),
                content: base64_encode($file->getSourcecode()),
            );
        }
        return $result;
    }

    /**
     * @return JudgehostFile[]
     */
    private function getExecutableFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ExecutableFile::class, 'f')
            ->select('f')
            ->andWhere('f.immutableExecutable = :immutable_execid')
            ->setParameter('immutable_execid', $id)
            ->orderBy('f.rank');

        /** @var ExecutableFile[] $files */
        $files = $queryBuilder->getQuery()->getResult();

        if (empty($files)) {
            throw new NotFoundHttpException(sprintf('Files for immutable executable with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach ($files as $file) {
            $result[] = new JudgehostFile(
                filename: $file->getFilename(),
                content: base64_encode($file->getFileContent()),
                isExecutable: $file->isExecutable(),
            );
        }
        return $result;
    }

    /**
     * @return JudgehostFile[]
     */
    private function getTestcaseFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TestcaseContent::class, 'f')
            ->select('f.input, f.output')
            ->andWhere('f.testcase = :testcaseid')
            ->setParameter('testcaseid', $id);

        /** @var string[] $inout */
        $inout = $queryBuilder->getQuery()->getOneOrNullResult();

        if (empty($inout)) {
            throw new NotFoundHttpException(sprintf('Files for testcase_content with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach (['input', 'output'] as $k) {
            $result[]   = new JudgehostFile(
                filename: $k,
                content: base64_encode($inout[$k]),
            );
        }
        return $result;
    }

    /**
     * Fetch work tasks.
     *
     * @return JudgeTask[]
     */
    #[IsGranted(new Expression("is_granted('ROLE_JUDGEHOST')"))]
    #[Rest\Post('/fetch-work')]
    #[OA\Response(
        response: 200,
        description: 'Returns the workarray.',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: JudgeTask::class))
        )
    )]
    #[OA\Parameter(
        name: 'hostname',
        description: 'Hostname of the judgehost requesting work.',
        in: 'query',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'max_batchsize',
        description: 'Maximum number of tasks to return.',
        in: 'query',
        schema: new OA\Schema(type: 'integer')
    )]
    public function getJudgeTasksAction(Request $request): array
    {
        if (!$request->request->has('hostname')) {
            throw new BadRequestHttpException('Argument \'hostname\' is mandatory');
        }
        $hostname = $request->request->get('hostname');

        $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
        if (!$judgehost) {
            throw new BadRequestHttpException(
                'Register yourself first. You (' . $hostname . ') are not known to us yet.');
        }

        // Update last seen of judgehost
        $judgehost->setPolltime(Utils::now());
        $this->em->flush();

        // If this judgehost is not active, there's nothing to do.
        if (!$judgehost->getEnabled()) {
            return [];
        }

        if ($request->request->has('max_batchsize')) {
            $max_batchsize = $request->request->get('max_batchsize');
        } else {
            // Heuristically determine how to pick a good batch size.
            $numQueueTasks = $this->em->createQueryBuilder()
                ->from(QueueTask::class, 'qt')
                ->select('COUNT(qt.queuetaskid)')
                ->getQuery()
                ->getSingleScalarResult();
            $numActiveJudgehosts = $this->em->createQueryBuilder()
                ->from(Judgehost::class, 'jh')
                ->select('COUNT(jh.hostname)')
                ->andWhere('jh.enabled = 1')
                ->andWhere('jh.polltime > :recently')
                ->setParameter('recently', Utils::now() - 120)
                ->getQuery()
                ->getSingleScalarResult();
            if ($numQueueTasks >= $numActiveJudgehosts) {
                $max_batchsize = 25;
            } elseif ($numQueueTasks == 1) {
                $max_batchsize = 5;
            } else {
                $max_batchsize = (int)(5 + 20*($numQueueTasks / $numActiveJudgehosts));
            }
        }

        // First try to get any debug info tasks that are assigned to this host.
        /** @var JudgeTask[] $judgetasks */
        $judgetasks = $this->em
            ->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->select('jt')
            ->andWhere('jt.judgehost = :judgehost')
            ->andWhere('jt.starttime IS NULL')
            ->andWhere('jt.valid = 1')
            ->andWhere('jt.type = :type')
            ->setParameter('judgehost', $judgehost)
            ->setParameter('type', JudgeTaskType::DEBUG_INFO)
            ->addOrderBy('jt.priority')
            ->addOrderBy('jt.judgetaskid')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        if (!empty($judgetasks)) {
            return $this->serializeJudgeTasks($judgetasks, $judgehost);
        }

        /* Our main objective is to work on high priority work first while keeping the additional overhead of splitting
         * work across judgehosts (e.g. additional compilation) low.
         *
         * We follow the following high-level strategy here to assign work:
         * 1) If there's an unfinished job (e.g. a judging) to which we already contributed, and then continue handing
         *    out JudgeTasks for this job.
         * 2) Work on something new:
         *    a) If there's a completely unstarted job, hand out tasks from that job.
         *    b) Otherwise, contribute to an already started job even if we didn't contribute yet.

         * Note that there could potentially be races in the selection of work, but adding synchronization mechanisms is
         * more costly than starting a possible only second most important work item.
         */

        // This is case 1) from above: continue what we have started.
        $lastJobId = $this->em->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            // Note: we are joining on queue tasks here since if there is no more queue task, there is also no more
            // work to be done. If we would not do this join, the getJudgetasks would try to delete the queue task,
            // which is both slow and results in spamming the auditlog
            ->innerJoin(QueueTask::class, 'qt', Join::WITH, 'qt.judging = jt.jobid')
            ->select('jt.jobid')
            ->andWhere('jt.judgehost = :judgehost')
            ->andWhere('jt.type = :type')
            ->setParameter('judgehost', $judgehost)
            ->setParameter('type', JudgeTaskType::JUDGING_RUN)
            ->orderBy('jt.starttime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        $judgetasks = $this->getJudgetasks($lastJobId, $max_batchsize, $judgehost);
        if ($judgetasks !== null) {
            return $judgetasks;
        }

        // This is case 2.a) from above: start something new.
        // This runs transactional to prevent a queue task being picked up twice.
        $judgetasks = null;
        $jobid = $this->em->createQueryBuilder()
            ->from(QueueTask::class, 'qt')
            ->innerJoin('qt.judging', 'j')
            ->select('j.judgingid')
            ->andWhere('qt.startTime IS NULL')
            ->addOrderBy('qt.priority')
            ->addOrderBy('qt.teamPriority')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
        if ($jobid !== null) {
            // Mark it as being worked on.
            $result = $this->em->createQueryBuilder()
                ->update(QueueTask::class, 'qt')
                ->set('qt.startTime', Utils::now())
                ->andWhere('qt.judging = :jobid')
                ->andWhere('qt.startTime IS NULL')
                ->setParameter('jobid', $jobid)
                ->getQuery()
                ->execute();

            if ($result == 0) {
                // Another judgehost beat us to it.
                $judgetasks = [['type' => 'try_again']];
            } else {
                $judgetasks = $this->getJudgetasks($jobid, $max_batchsize, $judgehost);
                if (empty($judgetasks)) {
                    // Somehow we got ourselves in a situation that there was a queue task without remaining judge tasks.
                    // This should not happen, but if it does, we need to clean up. Each of the fetch-work calls will clean
                    // up one queue task. We need to signal to the judgehost that there might be more work to do.
                    $judgetasks = [['type' => 'try_again']];
                }
            }
            return $judgetasks;
        }

        if ($this->config->get('enable_parallel_judging')) {
            // This is case 2.b) from above: contribute to a job someone else has started,
            // but we have not contributed yet.
            $jobid = $this->em->createQueryBuilder()
                ->from(QueueTask::class, 'qt')
                ->innerJoin('qt.judging', 'j')
                ->select('j.judgingid')
                ->addOrderBy('qt.priority')
                ->addOrderBy('qt.teamPriority')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
            $judgetasks = $this->getJudgetasks($jobid, $max_batchsize, $judgehost);
            if ($judgetasks !== null) {
                return $judgetasks;
            }
        }

        // TODO: Dedup with the code from above.
        // If there's no judging work to do, let's check if we need to prefetch things.
        /** @var JudgeTask[] $judgetasks */
        $judgetasks = $this->em
            ->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->select('jt')
            ->andWhere('jt.judgehost = :judgehost')
            ->andWhere('jt.starttime IS NULL')
            ->andWhere('jt.valid = 1')
            ->andWhere('jt.type = :type')
            ->setParameter('judgehost', $judgehost)
            ->setParameter('type', JudgeTaskType::PREFETCH)
            ->addOrderBy('jt.priority')
            ->addOrderBy('jt.judgetaskid')
            // TODO: is 50 a good value here?
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
        if (!empty($judgetasks)) {
            return $this->serializeJudgeTasks($judgetasks, $judgehost);
        }

        return [];
    }

    /**
     * @param JudgeTask[] $judgeTasks
     * @return JudgeTask[]
     * @throws Exception
     */
    private function serializeJudgeTasks(array $judgeTasks, Judgehost $judgehost): array
    {
        if (empty($judgeTasks)) {
            return [];
        }

        // Filter by submit_id.
        $submit_id = $judgeTasks[0]->getSubmission()?->getSubmitid();
        $judgetaskids = [];
        foreach ($judgeTasks as $judgeTask) {
            if ($judgeTask->getSubmission()?->getSubmitid() == $submit_id) {
                $judgetaskids[] = $judgeTask->getJudgetaskid();
            }
        }

        $now = Utils::now();
        $numUpdated = $this->em->getConnection()->executeStatement(
            'UPDATE judgetask SET judgehostid = :judgehostid, starttime = :starttime WHERE starttime IS NULL AND valid = 1 AND judgetaskid IN (:ids)',
            [
                'judgehostid' => $judgehost->getJudgehostid(),
                'starttime' => $now,
                'ids' => $judgetaskids,
            ],
            [
                'ids' => ArrayParameterType::INTEGER,
            ]
        );

        if ($numUpdated == 0) {
            // Bad luck, some other judgehost beat us to it.
            return [];
        }

        // We got at least one, let's update the starttime of the corresponding judging if haven't done so in the past.
        $starttime_set = $this->em->getConnection()->executeStatement(
            'UPDATE judging SET starttime = :starttime WHERE judgingid = :jobid AND starttime IS NULL',
            [
                'starttime' => $now,
                'jobid' => $judgeTasks[0]->getJobId(),
            ]
        );

        if ($starttime_set && $judgeTasks[0]->getType() == JudgeTaskType::JUDGING_RUN) {
            /** @var Submission $submission */
            $submission = $this->em->getRepository(Submission::class)->findOneBy(['submitid' => $submit_id]);
            $teamid = $submission->getTeam()->getTeamid();

            $this->em->getConnection()->executeStatement(
                'UPDATE team SET judging_last_started = :starttime WHERE teamid = :teamid',
                [
                    'starttime' => $now,
                    'teamid' => $teamid,
                ]
            );
        }

        if ($numUpdated == sizeof($judgeTasks)) {
            // We got everything, let's ship it!
            return $judgeTasks;
        }

        // A bit unlucky, we only got partially the assigned work, so query what was assigned to us.
        $queryBuilder = $this->em->createQueryBuilder();
        $partialJudgeTaskIds = array_column(
            $queryBuilder
                ->from(JudgeTask::class, 'jt')
                ->select('jt.judgetaskid')
                ->andWhere('jt.judgehost = :judgehost')
                ->setParameter('judgehost', $judgehost)
                ->andWhere($queryBuilder->expr()->In('jt.judgetaskid', $judgetaskids))
                ->getQuery()
                ->getArrayResult(),
            'judgetaskid');

        $partialJudgeTasks = [];
        foreach ($judgeTasks as $judgeTask) {
            if (in_array($judgeTask->getJudgetaskid(), $partialJudgeTaskIds)) {
                $partialJudgeTasks[] = $judgeTask;
            }
        }
        return $partialJudgeTasks;
    }

    /**
     * @return JudgeTask[]|null
     */
    private function getJudgetasks(string|int|null $jobId, int $max_batchsize, Judgehost $judgehost): ?array
    {
        if ($jobId === null) {
            return null;
        }
        $queryBuilder = $this->em->createQueryBuilder();
        /** @var JudgeTask[] $judgetasks */
        $judgetasks = $queryBuilder
            ->from(JudgeTask::class, 'jt')
            ->select('jt')
            ->andWhere('jt.judgehost IS NULL')
            ->andWhere('jt.valid = 1')
            ->andWhere('jt.jobid = :jobid')
            ->andWhere('jt.type = :type')
            ->addOrderBy('jt.priority')
            ->addOrderBy('jt.judgetaskid')
            ->setParameter('type', JudgeTaskType::JUDGING_RUN)
            ->setParameter('jobid', $jobId)
            ->setMaxResults($max_batchsize)
            ->getQuery()
            ->getResult();
        if (empty($judgetasks)) {
            // TODO: There is currently a race condition when a jury member requests the remaining test cases to be
            // judged in the time between allocating the final batch and the next judgehost checking in and deleting
            // the queuetask here.
            $this->em->createQueryBuilder()
                ->from(QueueTask::class, 'qt')
                ->andWhere('qt.judging = :jobid')
                ->setParameter('jobid', $jobId)
                ->delete()
                ->getQuery()
                ->execute();
            $this->em->flush();
            $this->dj->auditlog('queuetask', $jobId, 'deleted');
        } else {
            return $this->serializeJudgeTasks($judgetasks, $judgehost);
        }
        return null;
    }
}
