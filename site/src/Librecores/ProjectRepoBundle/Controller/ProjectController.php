<?php

namespace Librecores\ProjectRepoBundle\Controller;

use Doctrine\ORM\NonUniqueResultException;
use Exception;
use FOS\UserBundle\Model\UserManagerInterface;
use Librecores\ProjectRepoBundle\Doctrine\ProjectMetricsProvider;
use Librecores\ProjectRepoBundle\Entity\ClassificationHierarchy;
use Librecores\ProjectRepoBundle\Entity\GitSourceRepo;
use Librecores\ProjectRepoBundle\Entity\LanguageStat;
use Librecores\ProjectRepoBundle\Entity\OrganizationMember;
use Librecores\ProjectRepoBundle\Entity\Project;
use Librecores\ProjectRepoBundle\Entity\ProjectClassification;
use Librecores\ProjectRepoBundle\Entity\User;
use Librecores\ProjectRepoBundle\Form\Type\ProjectType;
use Librecores\ProjectRepoBundle\RepoCrawler\GithubRepoCrawler;
use Librecores\ProjectRepoBundle\Repository\OrganizationRepository;
use Librecores\ProjectRepoBundle\Repository\ProjectRepository;
use Librecores\ProjectRepoBundle\Util\Dates;
use Librecores\ProjectRepoBundle\Util\GithubApiService;
use Librecores\ProjectRepoBundle\Util\QueueDispatcherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Class ProjectController
 */
class ProjectController extends AbstractController
{
    /**
     * Render the "New Project" page
     *
     * @param Request                $request
     *
     * @param GithubApiService       $githubApiService
     * @param QueueDispatcherService $queueDispatcherService
     *
     * @param OrganizationRepository $organizationRepository
     *
     * @param UserManagerInterface   $userManager
     *
     * @return Response
     *
     * @throws NonUniqueResultException
     * @throws \Http\Client\Exception
     */
    public function newAction(
        Request $request,
        GithubApiService $githubApiService,
        QueueDispatcherService $queueDispatcherService,
        OrganizationRepository $organizationRepository,
        UserManagerInterface $userManager
    ) {
        $p = new Project();
        $p->setParentUser($this->getUser());

        // construct choices for GitHub repository list
        $githubSourceRepoChoices = [];
        foreach ($this->getGithubRepos($githubApiService) as $repo) {
            $githubUsername = $repo['owner']['login'];
            $fullName = $repo['full_name'];
            $githubSourceRepoChoices[$githubUsername][$fullName] = $fullName;
        }

        // construct choices for the owner
        $username = $this->getUser()->getUsername();
        $parentChoices = array($username => 'u_'.$username);
        foreach ($this->getUser()->getOrganizationMemberships() as $organizationMembership) {
            if ($organizationMembership->getPermission() === OrganizationMember::PERMISSION_MEMBER ||
                $organizationMembership->getPermission() === OrganizationMember::PERMISSION_ADMIN) {
                $parentChoices[$organizationMembership->getOrganization()->getName()] =
                    'o_'.$organizationMembership->getOrganization()->getName();
            }
        }

        // build form
        $formBuilder = $this->createFormBuilder($p)
            ->add(
                'parentName',
                ChoiceType::class,
                [
                    'mapped' => false,
                    'choices' => $parentChoices,
                    'multiple' => false,
                ]
            )
            ->add('name')
            ->add('displayName')
            ->add(
                'gitSourceRepoUrl',
                UrlType::class,
                [
                    'mapped' => false,
                    'label' => 'Git URL',
                    'required' => false,
                    'constraints' => [
                        new NotBlank(['groups' => ["git_url"]]),
                        new Url(['groups' => ["git_url"]]),
                    ],
                ]
            )
            ->add(
                'saveGitSourceRepoUrl',
                SubmitType::class,
                [
                    'label' => 'Create Project from Git repository',
                    'attr' => [
                        'class' => 'btn-primary',
                    ],
                    'validation_groups' => ['Default', 'git_url'],
                ]
            );

        // only add GitHub repository selection if the user can actually select
        // something
        $isGithubConnected = $this->getUser()->isConnectedToOAuthService('github');
        $noGithubRepos = empty($githubSourceRepoChoices);
        if ($isGithubConnected && !$noGithubRepos) {
            $formBuilder
                ->add(
                    'githubSourceRepo',
                    ChoiceType::class,
                    [
                        'mapped' => false,
                        'choices' => $githubSourceRepoChoices,
                        'multiple' => false,
                        'required' => true,
                    ]
                )
                ->add(
                    'saveGithubSourceRepo',
                    SubmitType::class,
                    [
                        'label' => 'Import project from GitHub',
                        'attr' => ['class' => 'btn-primary'],
                        'validation_groups' => ['Default', 'github'],
                    ]
                );
        }

        $form = $formBuilder->getForm();

        $form->handleRequest($request);

        // save project and redirect to project page
        if ($form->isSubmitted() && $form->isValid()) {
            // populate Project with data from "special"/not mapped form fields
            $this->populateProjectFromForm(
                $p,
                $form,
                $githubApiService,
                $organizationRepository,
                $userManager
            );

            $em = $this->getDoctrine()->getManager();
            $em->persist($p);
            $em->flush();

            // queue data collection from repository
            $queueDispatcherService->updateProjectInfo($p);

            // redirect user to "view project" page
            return $this->redirectToRoute(
                'librecores_project_repo_project_view',
                array(
                    'parentName' => $p->getParentName(),
                    'projectName' => $p->getName(),
                )
            );
        }

        // determine which tab to show to select the source from
        $activeSourcePanel = 'git_url';
        if ($form->isSubmitted()) {
            $activeSourcePanel = $this->getSourceTypeFromForm($form);
        } else {
            if ($isGithubConnected) {
                $activeSourcePanel = 'github';
            }
        }

        return $this->render(
            'LibrecoresProjectRepoBundle:Project:new.html.twig',
            array(
                'project' => $p,
                'form' => $form->createView(),
                'isGithubConnected' => $isGithubConnected,
                'noGithubRepos' => $noGithubRepos,
                'activeSourcePanel' => $activeSourcePanel,
            )
        );
    }

    /**
     * Display the project
     *
     * @param Request                $request
     * @param string                 $parentName        URL component: name of
     *                                                  the parent (user or
     *                                                  organization)
     * @param string                 $projectName       URL component: name of
     *                                                  the project
     * @param ProjectRepository      $projectRepository
     * @param ProjectMetricsProvider $projectMetricsProvider
     *
     * @return Response
     *
     * @throws NonUniqueResultException
     */
    public function viewAction(
        Request $request,
        $parentName,
        $projectName,
        ProjectRepository $projectRepository,
        ProjectMetricsProvider $projectMetricsProvider
    ) {
        $p = $this->getProject($parentName, $projectName, $projectRepository);

        // redirect to wait page until processing is done
        if ($p->getInProcessing()) {
            $waitTemplate = 'LibrecoresProjectRepoBundle:Project:view_wait_processing.html.twig';
            $response = new Response(
                $this->renderView($waitTemplate, array('project' => $p)),
                Response::HTTP_OK
            );
            $response->headers->set('refresh', '5;url='.$request->getUri());

            return $response;
        }

        // fetch project metadata
        $current = new \DateTimeImmutable();

        $qualityScore = $projectMetricsProvider->getCodeQualityScore($p);
        $twoTimesQualityScore = (int) ($qualityScore * 2);
        $metadata = [
            'qualityScore' => [
                'fullStars' => (int) ($twoTimesQualityScore / 2),
                'halfStars' => $twoTimesQualityScore % 2,
                'value' => $qualityScore,
            ],
            'latestCommit' => $projectMetricsProvider->getLatestCommit($p),
            'firstCommit' => $projectMetricsProvider->getFirstCommit($p),
            'contributorCount' => $projectMetricsProvider->getContributorsCount($p),
            'commitCount' => $projectMetricsProvider->getCommitCount($p),
            'topContributors' => $projectMetricsProvider->getTopContributors($p),
            'activityGraph' => $this->makeActivityGraph(
                $projectMetricsProvider->getCommitHistogram(
                    $p,
                    Dates::INTERVAL_WEEK,
                    $current->sub(
                        \DateInterval::createFromDateString('1 year')
                    ),
                    $current
                )
            ),
            'commitGraph' => $this->makeGraph(
                $projectMetricsProvider->getCommitHistogram(
                    $p,
                    Dates::INTERVAL_YEAR
                )
            ),
            'languageGraph' => $this->makeGraph(
                $projectMetricsProvider->getMostUsedLanguages($p),
                false
            ),
            'contributorsGraph' => $this->makeGraph(
                $projectMetricsProvider->getContributorHistogram($p, Dates::INTERVAL_YEAR)
            ),
            'isHostedOnGithub' => GithubRepoCrawler::isGithubRepoUrl($p->getSourceRepo()->getUrl()),
        ];

        // Retrieve the project classifications for a project
        $classifications = $this->getDoctrine()->getManager()
            ->getRepository(ProjectClassification::class)
            ->findByProject($p);

        // the actual project page
        return $this->render(
            'LibrecoresProjectRepoBundle:Project:view.html.twig',
            [
                'project' => $p,
                'metadata' => $metadata,
                'classifications' => $classifications,
            ]
        );
    }

    /**
     * Display the project settings page
     *
     * @param Request           $request
     * @param string            $parentName  URL component: name of the parent
     *                                       (user or organization)
     * @param string            $projectName URL component: name of the project
     *
     * @param ProjectRepository $repository
     *
     * @return Response
     * @throws NonUniqueResultException
     */
    public function settingsAction(
        Request $request,
        $parentName,
        $projectName,
        ProjectRepository $repository
    ) {
        $p = $this->getProject($parentName, $projectName, $repository);

        // check permissions
        $this->denyAccessUnlessGranted('edit', $p);

        // create and show form
        $form = $this->createForm(ProjectType::class, $p);
        $form->handleRequest($request);
        $errorClassifications = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $projClassification = $request->request->get('classification');
            $deleteClassification = $request->request->get('deleteClassification');
            $em = $this->getDoctrine()->getManager();
            // Insert classifications
            if (isset($projClassification)) {
                foreach ($projClassification as $classification) {
                    if ($this->classificationValidation($classification)) {
                        $projectClassification = new ProjectClassification();
                        $projectClassification->setProject($p);
                        $projectClassification->setClassification($classification);
                        $projectClassification->setCreatedBy($this->getUser());
                        $em->persist($projectClassification);
                        $p->addClassification($projectClassification);
                    } else {
                        $errorClassifications[] = $classification;
                    }
                }
            }
            // Delete classifications
            if (isset($deleteClassification)) {
                foreach ($deleteClassification as $delete) {
                    $projectClassification = $em->getRepository(ProjectClassification::class)->find($delete);
                    $p->removeClassification($projectClassification);
                    $em->remove($projectClassification);
                }
            }
            $em->persist($p);
            $em->flush();
        }

        // Retrieve the project classifications for a project
        $classifications = $this->getDoctrine()->getManager()
            ->getRepository(ProjectClassification::class)
            ->findByProject($p);

        // Retrieve classification hierarchy
        $classificationCategories = $this->getDoctrine()->getManager()
            ->getRepository(ClassificationHierarchy::class)
            ->findAll();

        $classificationHierarchy = [];
        foreach ($classificationCategories as $category) {
            $temp = [
                "id" => $category->getId(),
                "parentId" => $category->getParent() ?
                    $category->getParent() : $category->getParent()->getId(),
                "name" => $category->getName(),
            ];
            $classificationHierarchy[] = $temp;
        }

        return $this->render(
            'LibrecoresProjectRepoBundle:Project:settings.html.twig',
            [
                'project' => $p,
                'form' => $form->createView(),
                'classificationHierarchy' => $classificationHierarchy,
                'classifications' => $classifications,
                'errorClassification' => $errorClassifications,
            ]
        );
    }

    /**
     * Render the project settings -> team page
     *
     * @param string            $parentName  URL component: name of the parent
     *                                       (user or organization)
     * @param string            $projectName URL component: name of the project
     *
     * @param ProjectRepository $repository
     *
     * @return Response
     * @throws NonUniqueResultException
     */
    public function settingsTeamAction(
        $parentName,
        $projectName,
        ProjectRepository $repository
    ) {
        $p = $this->getProject($parentName, $projectName, $repository);

        return $this->render(
            'LibrecoresProjectRepoBundle:Project:settings_team.html.twig',
            array('project' => $p)
        );
    }

    /**
     * List all projects available on LibreCores
     *
     * @param ProjectRepository $repository
     *
     * @return Response
     *
     * @todo paginate result
     */
    public function listAction(ProjectRepository $repository)
    {
        $projects = $repository->findAll();

        return $this->render(
            'LibrecoresProjectRepoBundle:Project:list.html.twig',
            ['projects' => $projects]
        );
    }

    /**
     * Update all data associated with the project from external sources
     *
     * This action triggers a re-scan of associated source repositories, and
     * gets other data from 3rd-party services as needed.
     *
     * Note that this action only *triggers* the update -- the update itself is
     * done asynchronously through RabbitMQ.
     *
     * @param Request                $req
     * @param string                 $parentName
     * @param string                 $projectName
     *
     * @param ProjectRepository      $repository
     * @param QueueDispatcherService $queueDispatcherService
     *
     * @return Response
     *
     * @throws NonUniqueResultException
     */
    public function updateAction(
        Request $req,
        $parentName,
        $projectName,
        ProjectRepository $repository,
        QueueDispatcherService $queueDispatcherService
    ) {
        if ($ghEvent = $req->headers->has('X-GitHub-Event')) {
            if ($ghEvent !== 'push') {
                // We only react to push events, all other events signal any
                // change we are interested in.
                return new Response();
            }
            // XXX: In the future, this could be extended to use information
            // contained in the notification directly.
        }

        // queue an update of the project's information
        $p = $this->getProject($parentName, $projectName, $repository);
        $queueDispatcherService->updateProjectInfo($p);

        return new Response(
            'project update queued',
            200,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * Get all GitHub repositories accessible by the current user
     *
     * @param GithubApiService $githubApiService
     *
     * @return array
     *
     * @throws \Http\Client\Exception
     */
    private function getGithubRepos(GithubApiService $githubApiService)
    {
        $githubClient = $githubApiService->getAuthenticatedClient();
        if (!$githubClient) {
            return [];
        }

        // As of today, the API does not provide a wrapper for this call using
        // the visibility/affiliation fields. We therefore manually issue the
        // request (c.f. Github\Api\AbstractApi::get() and
        // \Github\Api\CurrentUser)
        $path = '/user/repos';
        $parameters = array(
            'visibility' => 'all',
            'affiliation' => 'owner,collaborator,organization_member',
            'sort' => 'updated',
            'per_page' => 100,
        );
        if (count($parameters) > 0) {
            $path .= '?'.http_build_query($parameters);
        }

        $response = $githubClient->getHttpClient()->get($path);

        return \Github\HttpClient\Message\ResponseMediator::getContent($response);
    }

    /**
     * Map the special form fields to the Project object
     *
     * The "New project" form contains a number of special form fields, which
     * can not directly be mapped by Symfony. This method contains code to do
     * this mapping. In addition, this method sets all project properties which
     * are not part of the form, but filled with default values.
     *
     * @param Project                $p
     * @param FormInterface          $form
     * @param GithubApiService       $githubApiService
     * @param OrganizationRepository $organizationRepository
     * @param UserManagerInterface   $userManager
     *
     * @throws NonUniqueResultException
     */
    private function populateProjectFromForm(
        Project $p,
        FormInterface $form,
        GithubApiService $githubApiService,
        OrganizationRepository $organizationRepository,
        UserManagerInterface $userManager
    ) {
        // set parent (extract from string selection box)
        $this->projectSetParentFromForm(
            $p,
            $form,
            $organizationRepository,
            $userManager
        );

        $sourceType = $this->getSourceTypeFromForm($form);

        // Repository is specified as Git URL
        if ($sourceType === 'git_url') {
            $gitSourceRepoUrl = $form->get('gitSourceRepoUrl')->getData();
            $gitSourceRepo = new GitSourceRepo();
            $gitSourceRepo->setUrl($gitSourceRepoUrl);
            $p->setSourceRepo($gitSourceRepo);
        }

        // Repository is imported from GitHub
        if ($sourceType === 'github') {
            $githubSourceRepoName = $form->get('githubSourceRepo')->getData();
            if (!empty($githubSourceRepoName)) {
                [$owner, $name] = explode('/', $githubSourceRepoName);
                // populate the project with some data from GitHub
                $githubApiService->populateProject($p, $owner, $name);
                // and install a webhook to notify us of all pushes to the repo
                $githubApiService->installHook($p, $owner, $name);
            }
        }

        $p->setStatus(Project::STATUS_ASSIGNED);

        // Mark the project as "in processing". This shows the wait page
        // until the update task has been ran from the RabbitMQ queue
        $p->setInProcessing(true);
    }

    /**
     * Set the parent of the project from the submitted data in the form
     *
     * The parent can be both an organization or an user, both of which are
     * represented in one dropdown field. This method takes the submitted string
     * apart and constructs the right parent object out of it.
     *
     * @param Project                $p
     * @param FormInterface          $form
     * @param OrganizationRepository $organizationRepository
     * @param UserManagerInterface   $userManager
     *
     * @throws NonUniqueResultException
     * @throws Exception
     */
    private function projectSetParentFromForm(
        Project $p,
        FormInterface $form,
        OrganizationRepository $organizationRepository,
        UserManagerInterface $userManager
    ) {
        $formParent = $form->get('parentName')->getData();

        if (!preg_match('/^[uo]_.+$/', $formParent)) {
            throw new Exception("form manipulated");
        }

        list($formParentType, $formParentName) = explode('_', $formParent, 2);

        if ($formParentType === 'u') {
            /** @var User $user */
            $user = $userManager->findUserByUsername($formParentName);

            if (null === $user) {
                throw new Exception("form manipulated");
            }

            $p->setParentUser($user);
        } elseif ($formParentType === 'o') {
            $organization = $organizationRepository->findOneByName($formParentName);

            if (null === $organization) {
                throw new \Exception("form manipulated");
            }

            $p->setParentOrganization($organization);
        }
    }

    /**
     * Get the type of source (repository) from the form
     *
     * @param FormInterface $form
     *
     * @return NULL|string 'git_url' or 'github'
     */
    private function getSourceTypeFromForm(FormInterface $form)
    {
        $sourceType = null;
        if ($form->get('saveGitSourceRepoUrl')->isClicked()) {
            $sourceType = 'git_url';
        } elseif ($form->get('saveGithubSourceRepo')->isClicked()) {
            $sourceType = 'github';
        } else {
            new \Exception('No submit button with associated source type clicked?');
        }

        return $sourceType;
    }

    /**
     * Get project object from parentName and projectName
     *
     * @param string            $parentName
     * @param string            $projectName
     *
     * @param ProjectRepository $repository
     *
     * @return Project
     *
     * @throws NonUniqueResultException
     */
    private function getProject(
        $parentName,
        $projectName,
        ProjectRepository $repository
    ) {
        $p = $repository->findProjectWithParent($parentName, $projectName);

        if (!$p) {
            throw $this->createNotFoundException('No project found with that name.');
        }

        return $p;
    }

    /**
     * Discards any labels and retains the value of a histogram
     *
     * @param array $histogram
     *
     * @return array
     */
    private function makeActivityGraph($histogram)
    {
        $values = [];
        foreach ($histogram as $i) {
            foreach ($i as $j) {
                $values[] = $j[0];
            }
        }

        return $values;
    }

    /**
     * Make a graph data object suitable for rendering a
     * graph in frontend.
     *
     * Uses array keys as labels and values as series.
     *
     * @param LanguageStat[] $languages
     * @param bool           $multiDim  does the graph expect multidimensional
     *                                  series. Defaults to true
     *
     * @return array data object for graph
     */
    private function makeGraph($languages, $multiDim = true)
    {
        $graph = [
            'labels' => array_keys($languages),
            'series' => [array_values($languages)],
        ];
        if (!$multiDim) {
            $graph['series'] = $graph['series'][0];
        }

        return $graph;
    }

    /**
     * Validate a Project Classification
     *
     * @param string $classification holds a clssification for a project
     *
     * @return bool
     */
    private function classificationValidation($classification)
    {
        $em = $this->getDoctrine()->getManager()
            ->getRepository(ClassificationHierarchy::class);

        $classificationHierarchy = $em->findByParent(null);
        $classifications = [];
        foreach ($classificationHierarchy as $classHyc) {
            $classifications[$classHyc->getName()] = $classHyc;
        }
        $categories = explode('::', $classification);
        $i = 0;
        $count = count($categories);
        foreach ($categories as $category) {
            if (!array_key_exists($category, $classifications)) {
                return false;
            }
            $classificationHierarchy = $em->findByParent($classifications[$category]);
            $classifications = [];
            foreach ($classificationHierarchy as $classHyc) {
                $classifications[$classHyc->getName()] = $classHyc;
            }
        }

        return true;
    }
}
