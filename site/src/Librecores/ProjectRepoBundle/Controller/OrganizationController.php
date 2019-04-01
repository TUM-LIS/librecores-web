<?php

namespace Librecores\ProjectRepoBundle\Controller;

use Librecores\ProjectRepoBundle\Entity\Organization;
use Librecores\ProjectRepoBundle\Entity\OrganizationMember;
use Librecores\ProjectRepoBundle\Form\Type\OrganizationType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    /**
     * List the organizations that current user belongs
     *
     * @return Response
     */
    public function listAction()
    {
        $organizations = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findAllByMemberOrderedByName($this->getUser());


        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:list.html.twig',
            array('organizations' => $organizations)
        );
    }

    /**
     * Render the "New Organization" page
     *
     * @param Request $request
     *
     * @return Response
     */
    public function newAction(Request $request)
    {
        $o = new Organization();

        $form = $this->createFormBuilder($o)
            ->add('name')
            ->add('displayName')
            ->add('description')
            ->add('save', SubmitType::class, array('label' => 'Create Organization'))
            ->getForm();

        $form->handleRequest($request);

        // Save organization and redirect to organization page
        if ($form->isValid()) {
            $user = $this->getUser();
            $em = $this->getDoctrine()->getManager();

            // Update new org
            $o->setCreator($user);
            $em->persist($o);

            // Create new membership
            $member = new OrganizationMember();
            $member->setOrganization($o);
            $member->setUser($user);
            $member->setPermission(OrganizationMember::PERMISSION_ADMIN);
            $em->persist($member);

            $em->flush();

            // Redirect user to "view organization" page
            return $this->redirectToRoute(
                'librecores_project_repo_user_org_view',
                array('userOrOrganization' => $o->getName())
            );
        }

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:new.html.twig',
            ['organization' => $o, 'form' => $form->createView()]
        );
    }

    /**
     * View an organization profile
     *
     * @param Organization $organization
     *
     * @return Response
     */
    public function viewAction(Organization $organization)
    {
        $requests = [];
        $denies = [];
        $supporters = [];
        $members = [];
        $admins = [];
        $userHasRequest = false;
        $userWasDenied = false;
        $userIsSupporter = false;
        $userIsMember = false;
        $userIsAdmin = false;

        $user = $this->getUser();

        foreach ($organization->getMembers() as $m) {
            if ($m->getPermission() === OrganizationMember::PERMISSION_REQUEST) {
                $requests[] = $m;
                if ($user === $m->getUser()) {
                    $userHasRequest = true;
                }
            } elseif ($m->getPermission() === OrganizationMember::PERMISSION_DENY) {
                $denies[] = $m;
                if ($user === $m->getUser()) {
                    $userWasDenied = true;
                }
            } elseif ($m->getPermission() === OrganizationMember::PERMISSION_SUPPORT) {
                $supporters[] = $m;
                if ($user === $m->getUser()) {
                    $userIsSupporter = true;
                }
            } elseif ($m->getPermission() === OrganizationMember::PERMISSION_MEMBER) {
                $members[] = $m;
                if ($user === $m->getUser()) {
                    $userIsMember = true;
                }
            } elseif ($m->getPermission() === OrganizationMember::PERMISSION_ADMIN) {
                $admins[] = $m;
                if ($user === $m->getUser()) {
                    $userIsAdmin = true;
                }
            }
        }

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:view.html.twig',
            array(
                'organization' => $organization,
                'user' => $user,
                'requests' => $requests,
                'denies' => $denies,
                'supporters' => $supporters,
                'members' => $members,
                'admins' => $admins,
                'userHasRequest' => $userHasRequest,
                'userWasDenied' => $userWasDenied,
                'userIsSupporter' => $userIsSupporter,
                'userIsMember' => $userIsMember,
                'userIsAdmin' => $userIsAdmin,
            )
        );
    }

    /**
     * Display the organization settings page
     *
     * @param Request      $request
     * @param Organization $organization the organization entity
     *
     * @return Response
     */
    public function settingsAction(Request $request, Organization $organization)
    {
        if (!$this->userIsMember($organization)) {
            throw $this->createAccessDeniedException(
                'You need to be a member of the organization in order to make changes.'
            );
        }

        // Create and show the form
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($organization);
            $em->flush();
        }

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:settings.html.twig',
            ['organization' => $organization, 'form' => $form->createView()]
        );
    }

    /**
     * Request to join an organization
     *
     * @param string $organizationName
     *
     * @return Response
     */
    public function joinAction($organizationName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException(
                'No organization found with that name.'
            );
        }

        $user = $this->getUser();

        // Create new organization membership
        $member = new OrganizationMember();
        $member->setOrganization($o);
        $member->setUser($user);
        $member->setPermission(OrganizationMember::PERMISSION_REQUEST);
        $em = $this->getDoctrine()->getManager();
        $em->persist($member);
        $em->flush();

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:join.html.twig',
            ['organization' => $o]
        );
    }

    /**
     * Leave an organization
     *
     * @param string $organizationName
     *
     * @return Response
     */
    public function leaveAction($organizationName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException(
                'No organization found with that name.'
            );
        }

        $user = $this->getUser();

        // TODO: Currently organization creators cannot leave their organization
        // TODO: We should revisit this when we properly implement the deletion of an organization

        if ($user === $o->getCreator()) {
            $userIsCreator = true;
        } else {
            // Remove the organization membership
            $userIsCreator = false;
            $member = $this->getDoctrine()
                ->getRepository('LibrecoresProjectRepoBundle:OrganizationMember')
                ->findOneBy(['organization' => $o, 'user' => $user]);
            $em = $this->getDoctrine()->getManager();
            $em->remove($member);
            $em->flush();
        }

        // Do not use the membership properties of $user or $o in the rest of the request
        // as they are now invalid and need to be fetched again if needed for use

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:leave.html.twig',
            ['organization' => $o, 'userIsCreator' => $userIsCreator]
        );
    }

    /**
     * Delete an organization
     *
     * @param string $organizationName
     *
     * @return Response
     */
    public function deleteAction($organizationName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException(
                'No organization found with that name.'
            );
        }

        if (!$this->userIsMember($o)) {
            throw $this->createAccessDeniedException(
                "You aren't a member of the organization in order to make changes."
            );
        }

        // We don't support this yet so just show 'this is an unsupported operation'
        // message to the user until we provide support
        //
        // $em = $this->getDoctrine()->getManager();
        // $em->remove($o);
        // $em->flush();

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:delete.html.twig',
            ['organization' => $o]
        );
    }


    /**
     * Approve an organization join request
     *
     * @param string $organizationName
     * @param string $userName
     *
     * @return Response
     */
    public function approveAction($organizationName, $userName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($userName);

        if (!$user) {
            throw $this->createNotFoundException(
                'No user found with that username.'
            );
        }

        // Update the organization membership
        $member = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:OrganizationMember')
            ->findOneBy(['organization' => $o, 'user' => $user]);
        $member->setPermission(OrganizationMember::PERMISSION_MEMBER);
        $em = $this->getDoctrine()->getManager();
        $em->persist($member);
        $em->flush();

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:approve.html.twig',
            ['organization' => $o, 'user' => $user]
        );
    }

    /**
     * Deny an organization join request
     *
     * @param string $organizationName
     * @param string $userName
     *
     * @return Response
     */
    public function denyAction($organizationName, $userName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException(
                'No organization found with that name.'
            );
        }

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($userName);

        if (!$user) {
            throw $this->createNotFoundException(
                'No user found with that username.'
            );
        }

        // Update the organization membership
        $member = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:OrganizationMember')
            ->findOneBy(['organization' => $o, 'user' => $user]);
        $member->setPermission(OrganizationMember::PERMISSION_DENY);
        $em = $this->getDoctrine()->getManager();
        $em->persist($member);
        $em->flush();

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:deny.html.twig',
            ['organization' => $o, 'user' => $user]
        );
    }

    /**
     * Remove an organization member
     *
     * @param string $organizationName
     * @param string $userName
     *
     * @return Response
     */
    public function removeAction($organizationName, $userName)
    {
        $o = $this->getDoctrine()
            ->getRepository('LibrecoresProjectRepoBundle:Organization')
            ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException(
                'No organization found with that name.'
            );
        }

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($userName);

        if (!$user) {
            throw $this->createNotFoundException(
                'No user found with that username'
            );
        }

        // TODO: Currently organization creators cannot be removed from their organization
        // TODO: We should revisit this when we properly implement the deletion of an organization

        if ($user === $o->getCreator()) {
            $memberIsCreator = true;
        } else {
            // Remove the organization membership
            $memberIsCreator = false;
            $member = $this->getDoctrine()
                ->getRepository('LibrecoresProjectRepoBundle:OrganizationMember')
                ->findOneBy(['organization' => $o, 'user' => $user]);
            $em = $this->getDoctrine()->getManager();
            $em->remove($member);
            $em->flush();
        }

        // Do not use the membership properties of $user or $o in the rest of
        // the request as they are now invalid and need to be fetched again if
        // needed for use

        return $this->render(
            'LibrecoresProjectRepoBundle:Organization:remove.html.twig',
            [
                'organization' => $o,
                'user' => $user,
                'memberIsCreator' => $memberIsCreator,
            ]
        );
    }

    /**
     * Test if the logged-in user is an organization
     * member with either MEMBER or ADMIN permission
     *
     * @param Organization $organization
     *
     * @return boolean
     */
    private function userIsMember(Organization $organization)
    {
        foreach ($organization->getMembers() as $m) {
            if (($m->getUser() === $this->getUser()) &&
                ($m->getPermission() === OrganizationMember::PERMISSION_MEMBER ||
                    $m->getPermission() === OrganizationMember::PERMISSION_ADMIN)) {
                return true;
            }
        }

        return false;
    }
}
