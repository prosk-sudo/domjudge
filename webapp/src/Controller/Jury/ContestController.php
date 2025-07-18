<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Controller\BaseController;
use App\Doctrine\DBAL\Types\JudgeTaskType;
use App\Entity\Clarification;
use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\Judgehost;
use App\Entity\JudgeTask;
use App\Entity\Judging;
use App\Entity\Language;
use App\Entity\Problem;
use App\Entity\RemovedInterval;
use App\Entity\Submission;
use App\Entity\Team;
use App\Entity\TeamCategory;
use App\Entity\Testcase;
use App\Form\Type\ContestType;
use App\Form\Type\FinalizeContestType;
use App\Form\Type\RemovedIntervalType;
use App\Service\AssetUpdateService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\ScoreboardService;
use App\Utils\Utils;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_JURY')]
#[Route(path: '/jury/contests')]
class ContestController extends BaseController
{
    use JudgeRemainingTrait;

    public function __construct(
        EntityManagerInterface $em,
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        KernelInterface $kernel,
        protected readonly EventLogService $eventLogService,
        protected readonly AssetUpdateService $assetUpdater,
    ) {
        parent::__construct($em, $eventLogService, $dj, $kernel);
    }

    /**
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    #[Route(path: '', name: 'jury_contests')]
    public function indexAction(Request $request): Response
    {
        $em = $this->em;

        /** @var Contest[] $contests */
        $contests = $em->createQueryBuilder()
            ->select('c')
            ->from(Contest::class, 'c')
            ->orderBy('c.starttime', 'DESC')
            ->groupBy('c.cid')
            ->getQuery()->getResult();

        $table_fields = [
            'cid'             => ['title' => 'CID', 'sort' => true],
            'externalid'      => ['title' => "external ID", 'sort' => true],
            'shortname'       => ['title' => 'shortname', 'sort' => true],
            'name'            => ['title' => 'name', 'sort' => true],
            'scoreboard_type' => ['title' => 'scoreboard type', 'sort' => true],
            'activatetime'    => ['title' => 'activate', 'sort' => true],
            'starttime'       => ['title' => 'start', 'sort' => true,
                                  'default_sort' => true, 'default_sort_order' => 'desc'],
            'endtime'         => ['title' => 'end', 'sort' => true],
        ];

        $currentContests = $this->dj->getCurrentContests();

        $timeFormat = (string)$this->config->get('time_format');

        if ($this->getParameter('removed_intervals')) {
            $table_fields['num_removed_intervals'] = ['title' => "# removed\nintervals", 'sort' => true];
            $removedIntervals                      = $em->createQueryBuilder()
                ->from(RemovedInterval::class, 'i')
                ->join('i.contest', 'c')
                ->select('COUNT(i.intervalid) AS num_removed_intervals', 'c.cid')
                ->groupBy('i.contest')
                ->getQuery()
                ->getResult();
            $removedIntervals = Utils::reindex($removedIntervals, static fn(array $data): int => $data['cid']);
        } else {
            $removedIntervals = [];
        }

        $problemData = $em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->select('COUNT(cp.problem) AS num_problems', 'c.cid')
            ->join('cp.contest', 'c')
            ->groupBy('cp.contest')
            ->getQuery()
            ->getResult();

        $problems = [];
        foreach ($problemData as $data) {
            $problems[$data['cid']] = $data['num_problems'];
        }

        $table_fields = array_merge($table_fields, [
            'process_balloons' => ['title' => 'process balloons?', 'sort' => true],
            'medals_enabled'   => ['title' => 'medals?', 'sort' => true],
            'public'           => ['title' => 'public?', 'sort' => true],
            'num_teams'        => ['title' => '# teams', 'sort' => true],
            'num_problems'     => ['title' => '# problems', 'sort' => true],
        ]);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $contests_table   = [];
        foreach ($contests as $contest) {
            $contestdata    = [];
            $contestactions = [];
            // Get whatever fields we can from the contest object itself
            foreach ($table_fields as $k => $v) {
                if ($k == 'scoreboard_type') {
                    $contestdata[$k] = ['value' => $contest->getScoreboardType()->value];
                } elseif ($propertyAccessor->isReadable($contest, $k)) {
                    $contestdata[$k] = ['value' => $propertyAccessor->getValue($contest, $k)];
                }
            }

            // Create action links
            if ($contest->getContestProblemsetType()) {
                $contestactions[] = [
                    'icon' => 'file-' . $contest->getContestProblemsetType(),
                    'title' => 'view contest problemset document',
                    'link' => $this->generateUrl('jury_contest_problemset', [
                        'cid' => $contest->getCid(),
                    ])
                ];
            } else {
                $contestactions[] = [];
            }
            if ($this->isGranted('ROLE_ADMIN')) {
                if ($contest->isLocked()) {
                    // The number of table columns and thus the number of actions need
                    // to match for all rows to not get DataTables errors.
                    // Since we add two actions for non-locked contests, we need to add
                    // two empty actions for locked contests.
                    $contestactions[] = [];
                    $contestactions[] = [];
                } else {
                    $contestactions[] = [
                        'icon' => 'edit',
                        'title' => 'edit this contest',
                        'link' => $this->generateUrl('jury_contest_edit', [
                            'contestId' => $contest->getCid(),
                        ])
                    ];
                    $contestactions[] = [
                        'icon' => 'trash-alt',
                        'title' => 'delete this contest',
                        'link' => $this->generateUrl('jury_contest_delete', [
                            'contestId' => $contest->getCid(),
                        ]),
                        'ajaxModal' => true,
                    ];
                }
            }

            $contestdata['process_balloons'] = [
                'toggle_partial' => 'contest_toggle.html.twig',
                'partial_arguments' => [
                    'type' => 'balloons',
                    'contest' => $contest,
                    'enabled' => $contest->getProcessBalloons(),
                ],
            ];
            $contestdata['medals_enabled'] = [
                'toggle_partial' => 'contest_toggle.html.twig',
                'partial_arguments' => [
                    'type' => 'medals',
                    'contest' => $contest,
                    'enabled' => $contest->getMedalsEnabled(),
                ],
            ];
            $contestdata['public'] = [
                'toggle_partial' => 'contest_toggle.html.twig',
                'partial_arguments' => [
                    'type' => 'public',
                    'contest' => $contest,
                    'enabled' => $contest->getPublic(),
                ],
            ];
            if ($contest->isOpenToAllTeams()) {
                $contestdata['num_teams'] = ['value' => 'all'];
            } else {
                $teamCount = $em
                    ->createQueryBuilder()
                    ->select('COUNT(DISTINCT t.teamid)')
                    ->from(Team::class, 't')
                    ->leftJoin('t.contests', 'c')
                    ->join('t.category', 'cat')
                    ->leftJoin('cat.contests', 'cc')
                    ->andWhere('c.cid = :cid OR cc.cid = :cid')
                    ->setParameter('cid', $contest->getCid())
                    ->getQuery()
                    ->getSingleScalarResult();
                $contestdata['num_teams'] = ['value' => $teamCount];
            }

            if ($this->getParameter('removed_intervals')) {
                $contestdata['num_removed_intervals'] = [
                    'value' => $removedIntervals[$contest->getCid()]['num_removed_intervals'] ?? 0
                ];
            }
            $contestdata['num_problems'] = ['value' => $problems[$contest->getCid()] ?? 0];

            $timeFields = [
                'activate',
                'start',
                'end',
            ];
            $startTime = null;
            foreach ($timeFields as $timeField) {
                $time = $contestdata[$timeField . 'time']['value'];
                if ($timeField === 'start') {
                    $startTime = $time;
                }
                $timeIcon = null;
                if (!$contest->getStarttimeEnabled() && $timeField != 'activate') {
                    $time      = null;
                }
                if ($time === null) {
                    $timeValue = '-';
                    $timeTitle = '-';
                } else {
                    $timeValue = Utils::printtime($time, $timeFormat);
                    $timeTitle = Utils::printtime($time, 'Y-m-d H:i:s (T)');
                    if ($timeField === 'activate' && $contest->getStarttimeEnabled()) {
                        if (Utils::difftime($contestdata['starttime']['value'], $time)>Utils::DAY_IN_SECONDS) {
                            $timeIcon  = 'calendar-minus';
                        }
                    } elseif ($timeField === 'end' && $contest->getStarttimeEnabled()) {
                        if (Utils::difftime($time, $startTime)>Utils::DAY_IN_SECONDS) {
                            $timeIcon  = 'calendar-plus';
                        }
                    }
                }
                $contestdata[$timeField . 'time']['value']     = $timeValue;
                $contestdata[$timeField . 'time']['sortvalue'] = $time;
                $contestdata[$timeField . 'time']['title']     = $timeTitle;
                if ($timeIcon !== null) {
                    $contestdata[$timeField . 'time']['icon']  = $timeIcon;
                }
            }

            $styles = [];
            if (!$contest->getEnabled()) {
                $styles[] = 'disabled';
            }
            if (in_array($contest->getCid(), array_keys($currentContests))) {
                $styles[] = 'highlight';
            }
            $contests_table[] = [
                'data' => $contestdata,
                'actions' => $contestactions,
                'link' => $this->generateUrl('jury_contest', ['contestId' => $contest->getCid()]),
                'cssclass' => implode(' ', $styles),
            ];
        }

        /** @var Contest $upcomingContest */
        $upcomingContest = $em->createQueryBuilder()
            ->from(Contest::class, 'c')
            ->select('c')
            ->andWhere('c.activatetime > :now')
            ->andWhere('c.enabled = 1')
            ->setParameter('now', Utils::now())
            ->orderBy('c.activatetime')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('jury/contests.html.twig', [
            'upcoming_contest' => $upcomingContest,
            'contests_table' => $contests_table,
            'table_fields' => $table_fields,
        ]);
    }

    #[Route(path: '/{contestId<\d+>}', name: 'jury_contest')]
    public function viewAction(Request $request, int $contestId): Response
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        $newRemovedInterval = new RemovedInterval();
        $newRemovedInterval->setContest($contest);
        $contest->addRemovedInterval($newRemovedInterval);
        $form = $this->createForm(RemovedIntervalType::class, $newRemovedInterval);
        $form->handleRequest($request);
        if ($this->isGranted('ROLE_ADMIN') && $form->isSubmitted() && $form->isValid()) {
            $this->em->persist($newRemovedInterval);
            $this->em->flush();

            $this->addFlash('scoreboard_refresh',
                'After adding a removed time interval, it is ' .
                'necessary to recalculate any cached scoreboards.');
            return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
        }

        /** @var RemovedInterval[] $removedIntervals */
        $removedIntervals = $this->em->createQueryBuilder()
            ->from(RemovedInterval::class, 'i')
            ->select('i')
            ->andWhere('i.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('i.starttime')
            ->getQuery()
            ->getResult();

        /** @var ContestProblem[] $problems */
        $problems = $this->em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->join('cp.problem', 'p')
            ->select('cp', 'p')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('cp.shortname')
            ->getQuery()
            ->getResult();

        $languages = $this->dj->getAllowedLanguagesForContest($contest);

        return $this->render('jury/contest.html.twig', [
            'contest' => $contest,
            'allowRemovedIntervals' => $this->getParameter('removed_intervals'),
            'removedIntervalForm' => $form,
            'removedIntervals' => $removedIntervals,
            'problems' => $problems,
            'languages' => $languages,
        ]);
    }

    #[Route(path: '/{contestId}/toggle/{type<submit|balloons|tiebreaker|medals|public>}', name: 'jury_contest_toggle')]
    public function toggleSubmitAction(
        RouterInterface $router,
        Request $request,
        string $contestId,
        string $type
    ): Response {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        $value = $request->request->getBoolean('value');

        switch ($type) {
            case 'submit':
                $contest->setAllowSubmit($value);
                $label = 'set allow submit';
                break;
            case 'balloons':
                $contest->setProcessBalloons($value);
                $label = 'set process balloons';
                break;
            case 'tiebreaker':
                $contest->setRuntimeAsScoreTiebreaker($value);
                $label = 'set runtime as tiebreaker';
                break;
            case 'medals':
                $contest->setMedalsEnabled($value);
                $label = 'set medal processing';
                break;
            case 'public':
                $contest->setPublic($value);
                $label = 'set publicly visible';
                break;
            default:
                throw new BadRequestHttpException('Unknown toggle type');
        }
        $this->em->flush();

        $this->dj->auditlog('contest', $contestId, $label, $value ? 'yes' : 'no');
        return $this->redirectToLocalReferrer(
            $router,
            $request,
            $this->generateUrl('jury_contest', ['contestId' => $contestId])
        );
    }

    #[Route(path: '/{contestId<\d+>}/remove-interval/{intervalId}', name: 'jury_contest_remove_interval', methods: ['POST'])]
    public function removeIntervalAction(int $contestId, int $intervalId): RedirectResponse
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        $removedInterval = $this->em->getRepository(RemovedInterval::class)->find($intervalId);
        if (!$removedInterval) {
            throw new NotFoundHttpException(
                sprintf('Removed interval with ID %s not found', $intervalId)
            );
        }

        if ($removedInterval->getContest()->getCid() !== $contest->getCid()) {
            throw new NotFoundHttpException('Removed interval is of wrong contest');
        }

        $contest->removeRemovedInterval($removedInterval);
        $this->em->remove($removedInterval);
        // Recalculate timing
        $contest->setStarttimeString($contest->getStarttimeString());
        $this->em->flush();

        $this->addFlash('scoreboard_refresh',
            'After removing a removed time interval, it is ' .
            'necessary to recalculate any cached scoreboards.');
        return $this->redirectToRoute('jury_contest', ['contestId' => $contest->getCid()]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/edit', name: 'jury_contest_edit')]
    public function editAction(Request $request, int $contestId): Response
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        if ($contest->isLocked()) {
            $this->addFlash('danger', 'You cannot edit a locked contest.');
            return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
        }

        $form = $this->createForm(ContestType::class, $contest);

        // If we are submitting problems, we need to do some reindexing:
        // Problems that already existed on the contest should use the same index as before.
        // Any problems not present yet should use a new index.
        // This makes sure that problems are updated and not deleted + inserted, which breaks if
        // you use the same shortname for the problem because of the unique index on (cid, shortname)
        // and the fact that Doctrine first does inserts and then deletes.
        if ($contestData = $request->request->all('contest')) {
            if ($problems = $contestData['problems'] ?? null) {
                $existingProblemIndices = [];
                foreach ($contest->getProblems() as $index => $problem) {
                    $existingProblemIndices[$problem->getProbId()] = $index;
                }
                $indexForNew = $contest->getProblems()->count();
                $newProblems = [];
                foreach ($problems as $problem) {
                    if (isset($existingProblemIndices[$problem['problem']])) {
                        $newProblems[$existingProblemIndices[$problem['problem']]] = $problem;
                    } else {
                        $newProblems[$indexForNew] = $problem;
                        $indexForNew++;
                    }
                }

                $contestData['problems'] = $newProblems;

                $request->request->set('contest', $contestData);
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $response = $this->checkTimezones($form);
            if ($response !== null) {
                return $response;
            }

            // We need to explicitly assign the contest on all problems, because
            // otherwise we can not save new problems on the contest.
            /** @var ContestProblem[] $problems */
            $problems = $contest->getProblems()->toArray();
            foreach ($problems as $problem) {
                $problem->setContest($contest);

                if ($problem->getAllowJudge()) {
                    $this->dj->unblockJudgeTasksForProblem($problem->getProbid());
                }
            }

            // Determine the removed teams, team categories and problems.
            // Note that we do not send out create / update events for
            // existing / new problems, teams and team categories. This happens
            // when someone connects to the event feed (or we have a
            // dependent event) anyway and adding the code here would
            // overcomplicate this function.
            // Note that getSnapshot() returns the data as retrieved from the
            // database.
            /**
             * @param Collection<T> $collection
             *
             * @return array<T>
             * @template T
             */
            $getDeletedEntities = function (Collection $collection, string $idMethod): array {
                if (!$collection instanceof PersistentCollection) {
                    return [];
                }
                $deletedEntities = [];
                foreach ($collection->getSnapshot() as $oldEntity) {
                    $oldId = call_user_func([$oldEntity, $idMethod]);
                    $found = false;
                    foreach ($collection->toArray() as $newEntity) {
                        $newId = call_user_func([$newEntity, $idMethod]);
                        if ($newId === $oldId) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $deletedEntities[] = $oldEntity;
                    }
                }

                return $deletedEntities;
            };

            /** @var Team[] $deletedTeams */
            $deletedTeams = $getDeletedEntities($contest->getTeams(), 'getTeamid');
            /** @var TeamCategory[] $deletedTeamCategories */
            $deletedTeamCategories = $getDeletedEntities($contest->getTeamCategories(), 'getCategoryid');
            /** @var ContestProblem[] $deletedProblems */
            $deletedProblems = $getDeletedEntities($contest->getProblems(), 'getProbid');

            $this->assetUpdater->updateAssets($contest);
            $this->saveEntity($contest, $contest->getCid(), false);

            // TODO: cascade deletes. Maybe use getDependentEntities()?
            foreach ($deletedTeams as $team) {
                $this->eventLogService->log('teams', $team->getTeamid(),
                    EventLogService::ACTION_DELETE, $contest->getCid(), null, null, false);
            }
            foreach ($deletedTeamCategories as $category) {
                $this->eventLogService->log('groups', $category->getCategoryid(),
                    EventLogService::ACTION_DELETE, $contest->getCid(), null, null, false);
            }
            foreach ($deletedProblems as $problem) {
                $this->eventLogService->log('problems', $problem->getProbid(),
                    EventLogService::ACTION_DELETE, $contest->getCid(), null, null, false);
            }
            return $this->redirectToRoute('jury_contest', ['contestId' => $contest->getcid()]);
        }

        $this->em->refresh($contest);

        return $this->render('jury/contest_edit.html.twig', [
            'contest' => $contest,
            'form' => $form,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/delete', name: 'jury_contest_delete')]
    public function deleteAction(Request $request, int $contestId): Response
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        if ($contest->isLocked()) {
            $this->addFlash('danger', 'You cannot delete a locked contest.');
            return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
        }

        return $this->deleteEntities($request, [$contest], $this->generateUrl('jury_contests'));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/problems/{probId<\d+>}/delete', name: 'jury_contest_problem_delete')]
    public function deleteProblemAction(Request $request, int $contestId, int $probId): Response
    {
        $contestProblem = $this->em->getRepository(ContestProblem::class)->find([
            'contest' => $contestId,
            'problem' => $probId
        ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(
                sprintf('Contest problem with contest ID %s and problem ID %s not found',
                        $contestId, $probId)
            );
        }

        if ($contestProblem->getContest()->isLocked()) {
            $this->addFlash('danger', 'You cannot delete a problem from a locked contest.');
            return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
        }

        return $this->deleteEntities($request, [$contestProblem], $this->generateUrl('jury_contest', ['contestId' => $contestId]));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/add', name: 'jury_contest_add')]
    public function addAction(Request $request): Response
    {
        $contest = new Contest();
        // Set default activate time
        $contest->setActivatetimeString(date('Y-m-d H:i:00 ') . date_default_timezone_get());

        $form = $this->createForm(ContestType::class, $contest);

        $form->handleRequest($request);

        if ($response = $this->processAddFormForExternalIdEntity(
            $form, $contest,
            fn () => $this->generateUrl('jury_contest', ['contestId' => $contest->getcid()]),
            function () use ($form, $contest) {
                $response = $this->checkTimezones($form);
                if ($response !== null) {
                    return $response;
                }

                $this->em->wrapInTransaction(function () use ($contest) {
                    // A little 'hack': we need to first persist and save the
                    // contest, before we can persist and save the problem,
                    // because we need a contest ID.
                    /** @var ContestProblem[] $problems */
                    $problems = $contest->getProblems()->toArray();
                    foreach ($contest->getProblems() as $problem) {
                        $contest->removeProblem($problem);
                    }
                    $this->em->persist($contest);
                    $this->em->flush();

                    // Now we can assign the problems to the contest and persist them.
                    foreach ($problems as $problem) {
                        $problem->setContest($contest);
                        $this->em->persist($problem);
                    }
                    $this->assetUpdater->updateAssets($contest);
                    $this->saveEntity($contest, null, true);
                    // Note that we do not send out create events for problems,
                    // teams and team categories for this contest. This happens
                    // when someone connects to the event feed (or we have a
                    // dependent event) anyway and adding the code here would
                    // overcomplicate this function.
                });

                return null;
            }
        )) {
            return $response;
        }

        return $this->render('jury/contest_add.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/{contestId<\d+>}/prefetch', name: 'jury_contest_prefetch')]
    public function prefetchAction(Request $request, int $contestId): Response
    {
        $contest  = $this->em->getRepository(Contest::class)->find($contestId);
        if ($contest === null) {
            throw new BadRequestHttpException("Contest with cid=$contestId not found.");
        }
        $judgehosts = $this->em->getRepository(Judgehost::class)->findBy([
            'enabled' => true,
            'hidden'  => false,
        ]);
        $cnt = 0;
        /** @var Judgehost $judgehost */
        foreach ($judgehosts as $judgehost) {
            /** @var ContestProblem $contestProblem */
            foreach ($contest->getProblems() as $contestProblem) {
                if (!$contestProblem->getAllowJudge() || !$contestProblem->getAllowSubmit()) {
                    continue;
                }
                /** @var Problem $problem */
                $problem = $contestProblem->getProblem();
                foreach ($problem->getTestcases() as $testcase) {
                    /** @var Testcase $testcase */
                    $judgeTask = new JudgeTask();
                    $judgeTask
                        ->setType(JudgeTaskType::PREFETCH)
                        ->setJudgehost($judgehost)
                        ->setPriority(JudgeTask::PRIORITY_DEFAULT)
                        ->setTestcaseId($testcase->getTestcaseid())
                        ->setTestcaseHash($testcase->getTestcaseHash());
                    $this->em->persist($judgeTask);
                    $cnt++;
                }
                // TODO: dedup here?
                $compareExec = $this->dj->getImmutableCompareExecutable($contestProblem);
                $runExec     = $this->dj->getImmutableRunExecutable($contestProblem);
                $runConfig = Utils::jsonEncode(
                    [
                        'hash' => $runExec->getHash(),
                        'combined_run_compare' => $problem->isInteractiveProblem(),
                    ]
                );
                $judgeTask = new JudgeTask();
                $judgeTask
                    ->setType(JudgeTaskType::PREFETCH)
                    ->setJudgehost($judgehost)
                    ->setPriority(JudgeTask::PRIORITY_DEFAULT)
                    ->setCompareScriptId($compareExec->getImmutableExecId())
                    ->setCompareConfig(Utils::jsonEncode(['hash' => $compareExec->getHash()]))
                    ->setRunScriptId($runExec->getImmutableExecId())
                    ->setRunConfig($runConfig);
                $this->em->persist($judgeTask);
                $cnt++;
            }
            $languages = $this->em->getRepository(Language::class)->findBy(
                [
                    'allowJudge' => true,
                    'allowSubmit' => true,
                ]
            );
            foreach ($languages as $language) {
                /** @var Language $language */
                $compileExec = $language->getCompileExecutable()->getImmutableExecutable();
                $judgeTask = new JudgeTask();
                $judgeTask
                    ->setType(JudgeTaskType::PREFETCH)
                    ->setJudgehost($judgehost)
                    ->setPriority(JudgeTask::PRIORITY_DEFAULT)
                    ->setCompileScriptId($compileExec->getImmutableExecId())
                    ->setCompileConfig(Utils::jsonEncode(['hash' => $compileExec->getHash()]));
                $this->em->persist($judgeTask);
                $cnt++;
            }
        }
        $this->em->flush();

        $this->addFlash('success', "Scheduled $cnt judgetasks to preheat judgehosts.");
        return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/finalize', name: 'jury_contest_finalize')]
    public function finalizeAction(Request $request, int $contestId): Response
    {
        /** @var Contest $contest */
        $contest  = $this->em->getRepository(Contest::class)->find($contestId);
        $blockers = [];
        if (Utils::difftime((float)$contest->getEndtime(), Utils::now()) > 0) {
            $blockers[] = sprintf('Contest not ended yet (will end at %s)',
                                  Utils::printtime($contest->getEndtime(), 'Y-m-d H:i:s (T)'));
        }

        /** @var int[] $submissionIds */
        $submissionIds = array_map(fn(array $data) => $data['submitid'], $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->join('s.judgings', 'j', Join::WITH, 'j.valid = 1')
            ->select('s.submitid')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.valid = true')
            ->andWhere('j.result IS NULL')
            ->setParameter('contest', $contest)
            ->orderBy('s.submitid')
            ->getQuery()
            ->getResult()
        );

        if (count($submissionIds) > 0) {
            $blockers[] = 'Unjudged submissions found: s' . implode(', s', $submissionIds);
        }

        /** @var int[] $clarificationIds */
        $clarificationIds = array_map(fn(array $data) => $data['clarid'], $this->em->createQueryBuilder()
            ->from(Clarification::class, 'c')
            ->select('c.clarid')
            ->andWhere('c.contest = :contest')
            ->andWhere('c.answered = false')
            ->setParameter('contest', $contest)
            ->getQuery()
            ->getResult()
        );
        if (count($clarificationIds) > 0) {
            $blockers[] = 'Unanswered clarifications found: ' . implode(', ', $clarificationIds);
        }

        if (empty($contest->getFinalizecomment())) {
            $contest->setFinalizecomment(sprintf('Finalized by: %s', $this->dj->getUser()->getName()));
        }
        $form = $this->createForm(FinalizeContestType::class, $contest);

        if (empty($blockers)) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $contest->setFinalizetime(Utils::now());
                $this->em->flush();
                $this->dj->auditlog('contest', $contest->getCid(), 'finalized',
                                                 $contest->getFinalizecomment());
                return $this->redirectToRoute('jury_contest', ['contestId' => $contest->getCid()]);
            }
        }

        return $this->render('jury/contest_finalize.html.twig', [
            'contest' => $contest,
            'blockers' => $blockers,
            'form' => $form,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/{time}/doNow', name: 'jury_contest_donow')]
    public function doNowAction(Request $request, int $contestId, string $time): Response
    {
        $times         = ['activate', 'start', 'freeze', 'end', 'unfreeze', 'finalize', 'deactivate'];
        $start_actions = ['delay_start', 'resume_start'];
        $actions       = array_merge($times, $start_actions);

        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        if (!in_array($time, $actions, true)) {
            throw new BadRequestHttpException(sprintf("Unknown value '%s' for timetype", $time));
        }

        if ($time === 'finalize') {
            return $this->redirectToRoute('jury_contest_finalize', ['contestId' => $contest->getCid()]);
        }

        $now       = (int)floor(Utils::now());
        $nowstring = date('Y-m-d H:i:s ', $now) . date_default_timezone_get();
        $this->dj->auditlog('contest', $contest->getCid(), $time . ' now', $nowstring);

        // Special case delay/resume start (only sets/unsets starttime_undefined).
        $maxSeconds = Contest::STARTTIME_UPDATE_MIN_SECONDS_BEFORE;
        if (in_array($time, $start_actions, true)) {
            $enabled = $time !== 'delay_start';
            if (Utils::difftime((float)$contest->getStarttime(false), $now) <= $maxSeconds) {
                $this->addFlash(
                    'error',
                    sprintf("Cannot '%s' less than %d seconds before contest start.",
                        $time, $maxSeconds)
                );
                return $this->redirectToRoute('jury_contests');
            }
            $contest->setStarttimeEnabled($enabled);
            $this->em->flush();
            $this->eventLogService->log(
                'contest',
                $contest->getCid(),
                EventLogService::ACTION_UPDATE,
                $contest->getCid()
            );
            $this->addFlash('scoreboard_refresh', 'After changing the contest start time, it may be '
                . 'necessary to recalculate any cached scoreboards.');
            return $this->redirectToRoute('jury_contests');
        }

        $juryTimeData = $contest->getDataForJuryInterface();
        if (!$juryTimeData[$time]['show_button']) {
            throw new BadRequestHttpException(
                sprintf("Cannot update '%s' time at this moment", $time)
            );
        }

        // starttime is special because other, relative times depend on it.
        if ($time == 'start') {
            if ($contest->getStarttimeEnabled() &&
                Utils::difftime((float)$contest->getStarttime(false),
                    $now) <= $maxSeconds) {
                $this->addFlash(
                    'danger',
                    sprintf("Cannot update starttime less than %d seconds before contest start.",
                        $maxSeconds)
                );
                return $this->redirectToRoute('jury_contests');
            }
            $contest
                ->setStarttime($now)
                ->setStarttimeString($nowstring)
                ->setStarttimeEnabled(true);
            $this->em->flush();

            $this->addFlash('scoreboard_refresh', 'After changing the contest start time, it may be '
                . 'necessary to recalculate any cached scoreboards.');
        } else {
            $method = sprintf('set%stimeString', $time);
            $contest->{$method}($nowstring);
            $this->em->flush();
        }
        $this->eventLogService->log(
            'contest',
            $contest->getCid(),
            EventLogService::ACTION_UPDATE,
            $contest->getCid()
        );

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }
        return $this->redirectToRoute('jury_contests');
    }

    #[Route(path: '/{contestId<\d+>}/request-remaining', name: 'jury_contest_request_remaining')]
    public function requestRemainingRunsWholeContestAction(int $contestId): RedirectResponse
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }
        $this->judgeRemaining(contestId: $contestId);
        return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
    }

    #[Route(path: '/{contestId<\d+>}/problems/{probId<\d+>}/request-remaining', name: 'jury_contest_problem_request_remaining')]
    public function requestRemainingRunsContestProblemAction(int $contestId, int $probId): RedirectResponse
    {
        $contestProblem = $this->em->getRepository(ContestProblem::class)->find([
            'contest' => $contestId,
            'problem' => $probId
        ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(
                sprintf('Contest problem with contest ID %s and problem ID %s not found',
                        $contestId, $probId)
            );
        }

        $this->judgeRemaining(contestId: $contestId, probId: $probId);
        return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
    }

    // Return null in case no error has been found.
    private function checkTimezones(FormInterface $form): ?Response
    {
        $formData = $form->getData();
        $timeZones = [];
        foreach (['Activate', 'Deactivate', 'Start', 'End', 'Freeze', 'Unfreeze'] as $timeString) {
            $tmpValue = $formData->{'get' . $timeString . 'timeString'}();
            if ($tmpValue !== '' && !is_null($tmpValue)) {
                $fields = explode(' ', $tmpValue);
                if (count($fields) > 1) {
                    $timeZones[] = $fields[2];
                }
            }
        }
        if (count(array_unique($timeZones)) > 1) {
            $this->addFlash('danger', 'Contest should not have multiple timezones.');
            return $this->render('jury/contest_add.html.twig', [
                'form' => $form,
            ]);
        }
        return null;
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/lock', name: 'jury_contest_lock')]
    public function lockAction(Request $request, int $contestId): Response
    {
        return $this->doLock($contestId, true);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{contestId<\d+>}/unlock', name: 'jury_contest_unlock')]
    public function unlockAction(Request $request, int $contestId): Response
    {
        return $this->doLock($contestId, false);
    }

    #[Route(path: '/{contestId<\d+>}/samples.zip', name: 'jury_contest_samples_data_zip')]
    public function samplesDataZipAction(Request $request, int $contestId): Response
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        return $this->dj->getSamplesZipForContest($contest);
    }

    private function doLock(int $contestId, bool $locked): Response
    {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found.', $contestId));
        }

        $this->dj->auditlog('contest', $contest->getCid(), $locked ? 'lock' : 'unlock');
        $contest->setIsLocked($locked);
        $this->em->flush();

        if ($locked) {
            $this->addFlash('info', 'Contest has been locked, modifications are no longer possible.');
        } else {
            $this->addFlash('danger', 'Contest has been unlocked, modifications are possible again.');
        }
        return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
    }

    #[Route(path: '/{contestId<\d+>}/{type<public|unfrozen>}-scoreboard.zip', name: 'jury_scoreboard_data_zip')]
    public function scoreboardDataZipAction(
        int $contestId,
        string $type,
        RequestStack $requestStack,
        Request $request,
        ScoreboardService $scoreboardService
    ): Response {
        $contest = $this->em->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }
        return $this->dj->getScoreboardZip($request, $requestStack, $contest, $scoreboardService, $type === 'unfrozen');
    }

    #[Route(path: '/{cid<\d+>}/problemset', name: 'jury_contest_problemset')]
    public function viewProblemsetAction(int $cid): StreamedResponse
    {
        $contest = $this->em->getRepository(Contest::class)->find($cid);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $cid));
        }

        return $contest->getContestProblemsetStreamedResponse();
    }
}
