<?php

namespace Librecores\ProjectRepoBundle\Repository;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Librecores\ProjectRepoBundle\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ProjectRepositoryTest extends TestCase
{
    public function testProjectRepositoryIsMappedToProjectEntity()
    {
        $mockEntityManager = $this->createMock(EntityManagerInterface::class);
        $mockEntityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with(Project::class)
            ->willReturn(new ClassMetadata(Project::class));

        $mockRegistry = $this->createMock(RegistryInterface::class);
        $mockRegistry->expects($this->once())
            ->method('getManagerForClass')
            ->with(Project::class)
            ->willReturn($mockEntityManager);

        /** @var RegistryInterface $mockRegistry */
        new ProjectRepository($mockRegistry);
    }
}
