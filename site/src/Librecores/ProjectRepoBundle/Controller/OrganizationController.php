<?php

namespace Librecores\ProjectRepoBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Librecores\ProjectRepoBundle\Entity\Organization;
use Librecores\ProjectRepoBundle\Form\Type\OrganizationType;

class OrganizationController extends Controller
{
    /**
     * List all the organizations
     * TODO: Maybe remove?
     * This listing might not be needed since the search will be
     * enough to find an organization to view / join etc.
     *
     * @return Response
     */
    public function indexAction()
    {
        return $this->render('LibrecoresProjectRepoBundle:Organization:index.html.twig');
    }

    /**
     * List the organizations that a user belongs
     *
     * @return Response
     */
    public function listAction()
    {
        $user = $this->getUser();

        $organizationsOwner = $this->getDoctrine()
                                   ->getRepository('LibrecoresProjectRepoBundle:Organization')
                                   ->findAllByOwnerOrderedByName($user);

        $organizationsMember = $this->getDoctrine()
                                    ->getRepository('LibrecoresProjectRepoBundle:Organization')
                                    ->findAllByMemberOrderedByName($user);


        return $this->render('LibrecoresProjectRepoBundle:Organization:list.html.twig',
            array('organizationsOwner'  => $organizationsOwner,
                  'organizationsMember' => $organizationsMember));
    }

    /**
     * Render the "New Organization" page
     *
     * @param Request $request
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
            $o->setOwner($user);
            $em = $this->getDoctrine()->getManager();
            $em->persist($o);
            $em->flush();

            // Redirect user to "view organization" page
            return $this->redirectToRoute('librecores_project_repo_user_org_view',
                array('userOrOrganization' => $o->getName()));
        }

        return $this->render('LibrecoresProjectRepoBundle:Organization:new.html.twig',
            array('organization' => $o,
                  'form' => $form->createView()));
    }

    /**
     * View an organization profile
     *
     * @param Organization $organization
     * @return Response
     */
    public function viewAction(Organization $organization)
    {
        return $this->render('LibrecoresProjectRepoBundle:Organization:view.html.twig',
            array('organization' => $organization));
    }

    /**
     * Display the organization settings page
     *
     * @param Request $request
     * @param Organization $organization  the organization entity
     * @return Response
     */
    public function settingsAction(Request $request, Organization $organization)
    {
        if ($this->getUser() != $organization->getOwner())
            throw $this->createAccessDeniedException("You don't own this organization in order to make changes");

        // create and show form
        $form = $this->createForm(OrganizationType::class, $organization);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($organization);
            $em->flush();
        }

        return $this->render('LibrecoresProjectRepoBundle:Organization:settings.html.twig',
            array('organization' => $organization,
                  'form' => $form->createView()));
    }

    /**
     * Remove an organization
     *
     * @param string $organizationName
     * @return Response
     */
    public function removeAction($organizationName)
    {
        $o = $this->getDoctrine()
                  ->getRepository('LibrecoresProjectRepoBundle:Organization')
                  ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        // Remove the organization

        if ($this->getUser() != $o->getOwner())
            throw $this->createAccessDeniedException("You don't own this organization in order to remove it");

        // TODO: Handle projects related to this organization!

        $em = $this->getDoctrine()->getManager();
        $em->remove($o);
        $em->flush();

        return $this->render('LibrecoresProjectRepoBundle:Organization:remove.html.twig',
            array('organization' => $o));
    }

    /**
     * Search for an organization
     *
     * @return Response
     */
    public function searchAction()
    {
        // Search for an organization

        return $this->render('LibrecoresProjectRepoBundle:Organization:search.html.twig');
    }

    /**
     * Request to join an organization
     *
     * @param string $organizationName
     * @return Response
     */
    public function joinAction($organizationName)
    {
        $o = $this->getDoctrine()
                  ->getRepository('LibrecoresProjectRepoBundle:Organization')
                  ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        // Join the organization

        $user = $this->getUser();
        $o->addRequest($user);
        $em = $this->getDoctrine()->getManager();
        $em->persist($o);
        $em->flush();

        return $this->render('LibrecoresProjectRepoBundle:Organization:join.html.twig',
            array('organization' => $o));
    }

    /**
     * Leave from an organization
     *
     * @param string $organizationName
     * @return Response
     */
    public function leaveAction($organizationName)
    {
        $o = $this->getDoctrine()
                  ->getRepository('LibrecoresProjectRepoBundle:Organization')
                  ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        // Leave the organization

        $user = $this->getUser();
        $o->removeRequest($user);
        $em = $this->getDoctrine()->getManager();
        $em->persist($o);
        $em->flush();

        return $this->render('LibrecoresProjectRepoBundle:Organization:leave.html.twig',
            array('organization' => $o));
    }

    /**
     * List the requests to join an organization
     *
     * @param string $organizationName
     * @return Response
     */
    public function requestsAction($organizationName)
    {
        $o = $this->getDoctrine()
                  ->getRepository('LibrecoresProjectRepoBundle:Organization')
                  ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        // List the requests for the organization

        $requests = $o->getRequests();

        return $this->render('LibrecoresProjectRepoBundle:Organization:join.html.twig',
            array('organization' => $o, 'requests' => $requests));
    }

    /**
     * Approve an organization join request
     *
     * @param string $organizationName
     * @param string $userName
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

        // Approve the organization join request

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($userName);
        if (!$user) {
            throw $this->createNotFoundException("No user found with that username");
        }

        $o->addMember($user);
        $o->removeRequest($user);
        $em = $this->getDoctrine()->getManager();
        $em->persist($o);
        $em->flush();

        return $this->render('LibrecoresProjectRepoBundle:Organization:approve.html.twig',
            array('organization' => $o));
    }

    /**
     * Deny an organization join request
     *
     * @param string $organizationName
     * @param string $userName
     * @return Response
     */
    public function denyAction($organizationName, $userName)
    {
        $o = $this->getDoctrine()
                  ->getRepository('LibrecoresProjectRepoBundle:Organization')
                  ->findOneByName($organizationName);

        if (!$o) {
            throw $this->createNotFoundException('No organization found with that name.');
        }

        // Deny the organization join request

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($userName);
        if (!$user) {
            throw $this->createNotFoundException("No user found with that username");
        }

        $o->removeRequest($user);
        $em = $this->getDoctrine()->getManager();
        $em->persist($o);
        $em->flush();

        return $this->render('LibrecoresProjectRepoBundle:Organization:deny.html.twig',
            array('organization' => $o));
    }
}
